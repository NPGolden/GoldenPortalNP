/* Golden A/S Employee app — synchronisation: pull read model, flush outbox, photos after record ACK. */
window.SYNC = (() => {
  let busy = false, listeners = [];
  const state = { last: null, queued: 0, online: navigator.onLine, error: null };
  const on = (fn) => listeners.push(fn);
  const emit = () => listeners.forEach((f) => f(state));

  async function pull() {   // Employee app: the account and its own HR record — nothing else is requested from the portal
    const me = await API.me(); await DB.put("meta", { ...me, uid: me.id, id: "me" });
    try { const hr = await API.hr(); await DB.put("meta", { id: "hr", ...hr }); await DB.put("meta", { id: "hr" + hr.year, ...hr }); } catch (e) { /* no HR record: the page says so */ }
  }

  async function flush() {
    const ops = (await DB.outbox()).filter((o) => o.kind !== "photo");
    if (!ops.length) return 0;
    const r = await API.sync(ops.map((o) => ({ id: o.id, kind: o.kind, payload: o.payload, client_ts: o.client_ts })));
    for (const res of r.results) {
      const op = ops.find((o) => o.id === res.uuid); if (!op) continue;
      if (res.status === "accepted") {
        await DB.del("outbox", op.id);
        if (op.kind === "fsr" || op.kind === "inspection") { const f = await DB.get("fsr", op.id); if (f) { await DB.del("fsr", op.id); await DB.put("fsr", { ...f, id: res.id || op.id, number: res.number, status: res.record_status || res.status_report || (op.kind === "inspection" ? "checking" : (op.payload.acceptance === "onsite" && op.payload.signature_png ? "accepted" : "submitted")), portal_id: +String(res.id || "").replace(/\D/g, "") || null }); } await uploadPhotos(op.id, res.id || op.id); }
        if (op.kind === "timesheet") { const t = await DB.get("timesheets", op.id); if (t) await DB.put("timesheets", { ...t, status: "synced", approval: "draft", ts_no: res.ts_no, ts_id: +String(res.id || "").replace(/\D/g, "") || null, reclassified: res.reclassified }); if (res.reclassified) UI.toast(I18N.t("hours.reclass", { n: res.reclassified })); }
        if (op.kind === "note") { const n = await DB.get("notes", op.id); if (n) await DB.put("notes", { ...n, id: res.id || n.id, status: "synced" }); if (res.id && res.id !== op.id) await DB.del("notes", op.id); await uploadPhotos(op.id, res.id || op.id); }
        if (op.kind === "ticket_note") await uploadPhotos(op.id, res.id || op.id);
      } else if (res.status === "conflict" && op.kind === "timesheet") {
        // over budget decided server-side: convert into an extra-hours request, keep the hours as a draft
        await DB.del("outbox", op.id);
        const t = await DB.get("timesheets", op.id); if (t) await DB.put("timesheets", { ...t, status: "over_budget", conflict: res.error });
        await DB.enqueue("extra_hours", { subproject_id: op.payload.subproject_id, section_id: op.payload.section_id, hours: (op.payload.normal || 0) + (op.payload.overtime || 0) + (op.payload.holiday || 0), reason: "misjudgement", note: "Auto-created from offline booking over budget", timesheet_uuid: op.id });
      } else if (res.status === "locked") {
        // 423: the portal record is closed for changes (week already submitted / invoiced, report issued) — the entry is kept locally as "locked" for the engineer to see, nothing is retried
        await DB.del("outbox", op.id);
        if (op.kind === "timesheet") { const t = await DB.get("timesheets", op.id); if (t) await DB.put("timesheets", { ...t, status: "locked", conflict: res.error }); }
        UI.toast(res.error || "Locked in the portal", 4000);
      } else {
        await DB.put("outbox", { ...op, status: "rejected", error: res.error, tries: op.tries + 1 });
      }
    }
    return r.results.length;
  }
  async function uploadPhotos(recordUuid, serverId) {
    const photos = (await DB.all("photos")).filter((p) => p.record === recordUuid);
    for (const p of photos) { try { await API.photo(serverId, p.blob, p.name, p.task || 1, p.caption); await DB.del("photos", p.id); } catch (e) { if (e.status && e.status < 500 && e.status !== 401 && e.status !== 429) await DB.del("photos", p.id); /* 5xx / offline: retried next sync */ } }
  }

  async function run(full) {
    if (busy || !API.signedIn) return; busy = true; state.error = null; emit();
    try {
      if (!navigator.onLine && !API.mock) throw Object.assign(new Error("offline"), { offline: true });
      const n = await flush(); if (full !== false || n) await pull();   // after accepted operations the read model is refreshed (numbers, statuses, ticket history)
      state.last = Date.now(); try { localStorage.setItem("gem.last", state.last); } catch (e) {}
    } catch (e) { state.error = e.offline ? null : (e.message || "error"); if (e.status === 426) state.error = "update-required"; }
    finally { busy = false; state.queued = (await DB.outbox()).length; state.online = navigator.onLine; emit(); }
  }
  async function refreshCount() { state.queued = (await DB.outbox()).length; state.online = navigator.onLine; emit(); }
  try { state.last = +localStorage.getItem("gem.last") || null; } catch (e) {}
  window.addEventListener("online", () => run()); window.addEventListener("offline", refreshCount);
  if (navigator.serviceWorker) navigator.serviceWorker.addEventListener("message", (e) => { if (e.data && e.data.type === "flush") run(false); });
  return { run, pull, flush, refreshCount, state, on };
})();
