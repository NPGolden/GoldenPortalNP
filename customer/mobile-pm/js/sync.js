/* Golden PM — synchronisation: pull the PM read model, flush the outbox (POST /sync), photos after the note is ACKed.
 * Result envelope (CONTRACT §8): {uuid, status: accepted|rejected|conflict|locked, code?, error?, ...} — the record's own status
 * arrives as `record_status` (the module renames it; SE behaviour). Step-up failure = status rejected, code 401, totp:true. */
window.SYNC = (() => {
  let busy = false, again = false, listeners = [];
  const state = { last: null, queued: 0, online: navigator.onLine, error: null, needsTotp: 0 };
  const on = (fn) => listeners.push(fn);
  const emit = () => listeners.forEach((f) => f(state));
  const STAGES = ["engineering", "procurement", "production", "fat", "installation", "sathat", "commissioning"];
  const overall = (st) => { const v = STAGES.map((k) => st[k]).filter((x) => x !== -1 && x != null); return v.length ? Math.round(v.reduce((a, b) => a + b, 0) / v.length) : 0; };

  async function pull() {
    const me = await API.me();
    await DB.put("meta", { ...me, uid: me.id, id: "me" });
    const [pr, dr, sm, dc, nb, no] = await Promise.all([API.projects(), API.drawings(), API.submittals(), API.documents(), API.inbox().catch(() => ({ items: [] })), API.notes().catch(() => ({ items: [] }))]);
    await DB.put("meta", { id: "stages", list: pr.stages || STAGES }); await DB.put("meta", { id: "staff", list: pr.staff || [] });
    // local optimistic stage edits still queued are kept over the server copy until the server answers
    const queuedStages = (await DB.outbox()).filter((o) => o.kind === "sp_stages" && o.status === "queued");
    pr.items.forEach((p) => (p.subprojects || []).forEach((s) => { queuedStages.filter((o) => o.payload.subproject_id === s.id).forEach((o) => { Object.assign(s.stages, o.payload.stages); s.overall = overall(s.stages); s.queued = true; }); }));
    await DB.replace("projects", pr.items);
    await DB.replace("drawings", dr.items); await DB.replace("submittals", sm.items); await DB.replace("documents", dc.items);
    await DB.replace("inbox", nb.items || []); await DB.replace("notes", (no.items || []).map((n) => ({ ...n, id: String(n.id) })), (n) => n.status === "queued");
    if (me.hr_manager || me.ts_approver) { try { const tm = await API.team(); const ob = await DB.outbox(); const old = await DB.get("team", "team");
        // keep the local decision state of rows whose operation is still in the outbox (deciding / needs_totp) and conflict markers
        const flag = (list, key, kind) => (list || []).forEach((row) => { const op = ob.find((o) => o.kind === kind && +o.payload[key] === row.id); if (op) { row.deciding = op.status === "queued"; if (op.status === "needs_totp") { row.needs_totp = op.id; const o2 = ((old || {})[kind === "hr_decide" ? "requests" : "timesheets"] || []).find((x) => x.id === row.id); row.wrong_code = !!(o2 && o2.wrong_code); } } else { const o2 = ((old || {})[kind === "hr_decide" ? "requests" : "timesheets"] || []).find((x) => x.id === row.id); if (o2 && o2.conflict) row.conflict = o2.conflict; } });
        flag(tm.requests, "request_id", "hr_decide"); flag(tm.timesheets, "ts_id", "ts_decide"); await DB.put("team", { id: "team", ...tm }); } catch (e) { /* 403 / 503: Team tab says so */ } }
    if (me.hr !== false) { try { const hr = await API.hr(); await DB.put("meta", { id: "hr", ...hr }); await DB.put("meta", { id: "hr" + hr.year, ...hr }); } catch (e) { /* no HR module / no person card */ } }
  }
  /** refresh one sub-project detail (on demand, cached per id) */
  async function subproject(id) {
    const d = await API.subproject(id); await DB.put("subs", { id: +id, fetched_at: Date.now(), ...d });
    const projects = await DB.all("projects"); const p = projects.find((x) => (x.subprojects || []).some((s) => s.id === +id));
    if (p) { const s = p.subprojects.find((x) => x.id === +id); Object.assign(s, { stages: d.subproject.stages, fed: d.subproject.fed, overall: d.subproject.overall, issues_open: d.subproject.issues_open }); await DB.put("projects", p); }
    return d;
  }

  async function applyStages(subId, stages, overallPct) {
    const projects = await DB.all("projects"); const p = projects.find((x) => (x.subprojects || []).some((s) => s.id === subId));
    if (p) { const s = p.subprojects.find((x) => x.id === subId); s.stages = stages; s.overall = overallPct != null ? overallPct : overall(stages); delete s.queued; delete s.conflict; p.overall = Math.round(p.subprojects.reduce((a, x) => a + x.overall, 0) / Math.max(1, p.subprojects.length)); await DB.put("projects", p); }
    const sd = await DB.get("subs", subId); if (sd) { sd.subproject.stages = stages; sd.subproject.overall = overallPct != null ? overallPct : overall(stages); delete sd.subproject.queued; delete sd.subproject.conflict; await DB.put("subs", sd); }
  }
  async function markSub(subId, flag) {
    const projects = await DB.all("projects"); const p = projects.find((x) => (x.subprojects || []).some((s) => s.id === subId));
    if (p) { const s = p.subprojects.find((x) => x.id === subId); delete s.queued; delete s.conflict; if (flag) s[flag] = true; await DB.put("projects", p); }
    const sd = await DB.get("subs", subId); if (sd) { delete sd.subproject.queued; delete sd.subproject.conflict; if (flag) sd.subproject[flag] = true; await DB.put("subs", sd); }
  }
  async function teamRow(kind, id, fn) {
    const tm = await DB.get("team", "team"); if (!tm) return; const list = kind === "hr_decide" ? tm.requests : tm.timesheets; const i = list.findIndex((x) => x.id === +id); if (i < 0) return;
    const r = fn(list[i]); if (r === null) list.splice(i, 1); await DB.put("team", tm);
  }

  async function flush() {
    const ops = (await DB.outbox()).filter((o) => o.kind !== "photo" && o.status === "queued");
    if (!ops.length) return 0;
    const r = await API.sync(ops.map((o) => ({ id: o.id, kind: o.kind, payload: o.payload, client_ts: o.client_ts })));
    for (const res of r.results) {
      const op = ops.find((o) => o.id === res.uuid); if (!op) continue; const pl = op.payload || {};
      if (res.status === "accepted") {
        await DB.del("outbox", op.id);
        if (op.kind === "sp_stages") { if (res.stages) await applyStages(pl.subproject_id, res.stages, res.overall); else await markSub(pl.subproject_id, null); if (res.ignored && res.ignored.length) UI.toast(I18N.t("sub.ignored", { s: res.ignored.map((k) => I18N.t("stab." + k)).join(", ") }), 4000); }
        if (op.kind === "hr_decide" || op.kind === "ts_decide") { await teamRow(op.kind, op.kind === "hr_decide" ? pl.request_id : pl.ts_id, () => null); const me = await DB.get("meta", "me"); if (me && me.counts) { const k = op.kind === "hr_decide" ? "hr_pending" : "ts_pending"; me.counts[k] = Math.max(0, (me.counts[k] || 0) - 1); await DB.put("meta", me); } }
        if (op.kind === "note") { const n = await DB.get("notes", op.id); if (n) { await DB.del("notes", op.id); await DB.put("notes", { ...n, id: String(res.id || n.id), status: "synced" }); } await uploadPhotos(op.id, res.id || op.id); }
        if (op.kind === "sp_comment") { const sd = await DB.get("subs", pl.subproject_id); if (sd) { const c = (sd.comments || []).find((x) => x.id === op.id); if (c) { c.id = res.id || c.id; delete c.queued; await DB.put("subs", sd); } } }
        if (op.kind === "drawing_update") { const dr = await DB.get("drawings", pl.drawing_id); if (dr) { delete dr.queued; delete dr.conflict; if (res.record_status) dr.status = res.record_status; if (res.received_at !== undefined) dr.received_at = res.received_at; dr.overdue_days = dr.status === "pending" && dr.expected_at && dr.expected_at < new Date().toISOString().slice(0, 10) ? Math.round((Date.now() - new Date(dr.expected_at)) / 86400000) : 0; await DB.put("drawings", dr); } }
        if (op.kind === "issue_status") { const sd = await DB.get("subs", pl.subproject_id); if (sd) { const i = (sd.issues || []).find((x) => x.id === +pl.issue_id); if (i) { i.status = res.record_status || pl.status; delete i.queued; sd.subproject.issues_open = sd.issues.filter((x) => x.status !== "closed").length; await DB.put("subs", sd); } } }
      } else if (res.code === 401 && res.totp && (op.kind === "hr_decide" || op.kind === "ts_decide")) {
        // step-up failed (missing / wrong code): the operation waits for a new code — the Team screen offers "Enter code"
        const { totp, ...rest } = pl; await DB.put("outbox", { ...op, payload: rest, status: "needs_totp", error: res.error || "totp", tries: op.tries + 1 });
        await teamRow(op.kind, op.kind === "hr_decide" ? pl.request_id : pl.ts_id, (row) => { row.deciding = false; row.needs_totp = op.id; row.wrong_code = !!totp; });
      } else if (res.status === "conflict" || res.status === "locked" || res.code === 409 || res.code === 423) {
        // the portal record changed (request no longer pending, timesheet already decided, sub-project locked): drop the op, keep a marker on the row
        await DB.del("outbox", op.id); UI.toast(res.error || (res.status === "locked" ? "Locked in the portal" : "Changed in the portal"), 4000);
        if (op.kind === "sp_stages") await markSub(pl.subproject_id, "conflict");
        if (op.kind === "hr_decide" || op.kind === "ts_decide") await teamRow(op.kind, op.kind === "hr_decide" ? pl.request_id : pl.ts_id, (row) => { row.deciding = false; row.conflict = res.error || "conflict"; });
        if (op.kind === "drawing_update") { const dr = await DB.get("drawings", pl.drawing_id); if (dr) { delete dr.queued; dr.conflict = res.error || "conflict"; await DB.put("drawings", dr); } }
      } else {
        await DB.put("outbox", { ...op, status: "rejected", error: res.error || `HTTP ${res.code || ""}`, tries: op.tries + 1 });
        if (op.kind === "hr_decide" || op.kind === "ts_decide") await teamRow(op.kind, op.kind === "hr_decide" ? pl.request_id : pl.ts_id, (row) => { row.deciding = false; row.conflict = res.error || "rejected"; });
        if (op.kind === "sp_stages") await markSub(pl.subproject_id, "conflict");
        if (op.kind === "drawing_update") { const dr = await DB.get("drawings", pl.drawing_id); if (dr) { delete dr.queued; dr.conflict = res.error || "rejected"; await DB.put("drawings", dr); } }
        if (res.error) UI.toast(res.error, 4000);
      }
    }
    return r.results.length;
  }
  async function uploadPhotos(recordUuid, serverId) {
    const photos = (await DB.all("photos")).filter((p) => p.record === recordUuid);
    for (const p of photos) { try { await API.photo(serverId, p.blob, p.name, p.task || 1, p.caption); await DB.del("photos", p.id); } catch (e) { if (e.status && e.status < 500 && e.status !== 401 && e.status !== 429) await DB.del("photos", p.id); /* 5xx / offline: retried next sync */ } }
  }
  /** retry an operation waiting for a step-up code */
  async function retryWithTotp(opId, totp) {
    const op = await DB.get("outbox", opId); if (!op) return; await DB.put("outbox", { ...op, payload: { ...op.payload, totp }, status: "queued", error: null });
    await teamRow(op.kind, op.kind === "hr_decide" ? op.payload.request_id : op.payload.ts_id, (row) => { row.deciding = true; row.needs_totp = null; row.wrong_code = false; });
    return run(false);
  }

  async function run(full) {
    if (!API.signedIn) return; if (busy) { again = true; return; } busy = true; state.error = null; emit();
    try {
      if (!navigator.onLine) throw Object.assign(new Error("offline"), { offline: true });
      const n = await flush(); if (full !== false || n) await pull();   // after accepted operations the read model is refreshed
      state.last = Date.now(); try { localStorage.setItem("gpm.last", state.last); } catch (e) {}
    } catch (e) { state.error = e.offline ? null : (e.message || "error"); if (e.status === 426) state.error = "update-required"; if (e.status === 403 && /project manager/i.test(e.message || "")) state.error = "not-pm"; }
    finally { busy = false; await refreshCount(false); emit(); if (again) { again = false; run(false); } }   // a run requested while busy flushes what was queued meanwhile
  }
  async function refreshCount(doEmit) { const ob = await DB.outbox(); state.queued = ob.filter((o) => o.status !== "needs_totp").length; state.needsTotp = ob.filter((o) => o.status === "needs_totp").length; state.online = navigator.onLine; if (doEmit !== false) emit(); }
  try { state.last = +localStorage.getItem("gpm.last") || null; } catch (e) {}
  window.addEventListener("online", () => run()); window.addEventListener("offline", () => refreshCount());
  if (navigator.serviceWorker) navigator.serviceWorker.addEventListener("message", (e) => { if (e.data && e.data.type === "flush") run(false); });
  return { run, pull, flush, subproject, refreshCount, retryWithTotp, overall, state, on, STAGES };
})();
