/* Golden A/S Service app — synchronisation: pull read model, flush outbox, photos after record ACK. */
window.SYNC = (() => {
  let busy = false, listeners = [];
  const state = { last: null, queued: 0, online: navigator.onLine, error: null };
  const on = (fn) => listeners.push(fn);
  const emit = () => listeners.forEach((f) => f(state));

  async function pull() {
    const [me, tk, as, pr, ve, eq, bu, ma, dc, st, fs, ts, no] = await Promise.all([
      API.me(), API.tickets(), API.assignments(), API.projects(), API.vessels(), API.equipment(), API.budgets(), API.master(), API.documents(), API.status(), API.fsrList(), API.timesheets(), API.notes()]);
    await DB.put("meta", { ...me, uid: me.id, id: "me" });
    await DB.put("meta", { id: "master", ...ma });
    await DB.putAll("tickets", tk.items); await DB.putAll("assignments", as.items); await DB.putAll("projects", pr.items);
    await DB.putAll("vessels", ve.items); await DB.putAll("equipment", eq.items);
    await DB.putAll("budgets", bu.items.map((b) => ({ id: b.subproject_id, ...b })));
    await DB.putAll("documents", dc.items); await DB.putAll("status", st.items.map((s, i) => ({ id: `${s.date}|${s.subproject_id}`, ...s })));
    // server copies replace local synced ones; local drafts (status draft/queued) are kept
    const localFsr = (await DB.all("fsr")).filter((f) => f.status === "draft" || f.status === "queued");
    await DB.clear("fsr"); await DB.putAll("fsr", fs.items.concat(localFsr));
    const localTs = (await DB.all("timesheets")).filter((x) => x.status === "queued" || x.status === "over_budget" || x.status === "locked");   // the portal creates the report hours itself (acc_sync) — nothing is mirrored locally
    await DB.clear("timesheets"); await DB.putAll("timesheets", ts.items.concat(localTs));
    const localNotes = (await DB.all("notes")).filter((n) => n.status === "queued");
    await DB.clear("notes"); await DB.putAll("notes", no.items.concat(localNotes));
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
      state.last = Date.now(); try { localStorage.setItem("gas.last", state.last); } catch (e) {}
    } catch (e) { state.error = e.offline ? null : (e.message || "error"); if (e.status === 426) state.error = "update-required"; }
    finally { busy = false; state.queued = (await DB.outbox()).length; state.online = navigator.onLine; emit(); }
  }
  async function refreshCount() { state.queued = (await DB.outbox()).length; state.online = navigator.onLine; emit(); }
  try { state.last = +localStorage.getItem("gas.last") || null; } catch (e) {}
  window.addEventListener("online", () => run()); window.addEventListener("offline", refreshCount);
  if (navigator.serviceWorker) navigator.serviceWorker.addEventListener("message", (e) => { if (e.data && e.data.type === "flush") run(false); });
  return { run, pull, flush, refreshCount, state, on };
})();
