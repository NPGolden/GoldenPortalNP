/* Golden HR — synchronisation: pull the HR read model (inbox, people, who is away, balances, threads), flush the outbox (POST /sync).
 * Decisions carry the authenticator code when the account has one (step-up): a missing / wrong code parks the operation as needs_totp. */
window.SYNC = (() => {
  let busy = false, again = false, listeners = [];
  const state = { last: null, queued: 0, online: navigator.onLine, error: null, needsTotp: 0 };
  const on = (fn) => listeners.push(fn);
  const emit = () => listeners.forEach((f) => f(state));
  const iso = (d) => d.toISOString().slice(0, 10);
  const plus = (n) => { const x = new Date(); x.setDate(x.getDate() + n); return iso(x); };

  async function pull() {
    const me = await API.me(); await DB.put("meta", { ...me, uid: me.id, id: "me" });
    const year = new Date().getFullYear();
    const [ib, pp, aw, th] = await Promise.all([API.inbox(), API.people(), API.away(plus(-7), plus(35)), API.threads().catch(() => ({ threads: [] }))]);
    const ob = await DB.outbox();
    const flag = (list, key, kind) => (list || []).forEach((row) => { const op = ob.find((o) => o.kind === kind && +o.payload[key] === row.id); if (op) { row.deciding = op.status === "queued"; if (op.status === "needs_totp") { row.needs_totp = op.id; row.wrong_code = /totp|code/i.test(op.error || "") && op.tries > 1; } } });
    flag(ib.requests, "request_id", "hr_app_decide"); flag(ib.trainings, "member_id", "hr_app_training"); flag(ib.timesheets, "ts_id", "hr_app_ts");
    await DB.put("inbox", { id: "inbox", ...ib }); await DB.put("people", { id: "people", ...pp }); await DB.put("away", { id: "away", ...aw }); await DB.put("threads", { id: "threads", ...th });
    try { const bl = await API.balances(year); await DB.put("balances", { id: "balances", ...bl }); } catch (e) {}
  }
  async function inboxRow(kind, id, fn) {
    const ib = await DB.get("inbox", "inbox"); if (!ib) return; const list = kind === "hr_app_decide" ? ib.requests : kind === "hr_app_training" ? ib.trainings : ib.timesheets; const i = (list || []).findIndex((x) => x.id === +id); if (i < 0) return;
    const r = fn(list[i]); if (r === null) list.splice(i, 1); await DB.put("inbox", ib);
  }
  const keyOf = (kind) => kind === "hr_app_decide" ? "request_id" : kind === "hr_app_training" ? "member_id" : "ts_id";

  async function flush() {
    const ops = (await DB.outbox()).filter((o) => o.status === "queued");
    if (!ops.length) return 0;
    const r = await API.sync(ops.map((o) => ({ id: o.id, kind: o.kind, payload: o.payload, client_ts: o.client_ts })));
    for (const res of r.results) {
      const op = ops.find((o) => o.id === res.uuid); if (!op) continue; const pl = op.payload || {}; const k = keyOf(op.kind);
      if (res.status === "accepted") { await DB.del("outbox", op.id); await inboxRow(op.kind, pl[k], () => null); }
      else if (res.code === 401 && res.totp) { const { totp, ...rest } = pl; await DB.put("outbox", { ...op, payload: rest, status: "needs_totp", error: res.error || "totp", tries: op.tries + 1 }); await inboxRow(op.kind, pl[k], (row) => { row.deciding = false; row.needs_totp = op.id; row.wrong_code = !!totp; }); }
      else if (res.status === "conflict" || res.code === 409 || res.code === 423) { await DB.del("outbox", op.id); UI.toast(res.error || "Changed in the portal", 4000); await inboxRow(op.kind, pl[k], (row) => { row.deciding = false; row.conflict = res.error || "conflict"; }); }
      else { await DB.put("outbox", { ...op, status: "rejected", error: res.error || `HTTP ${res.code || ""}`, tries: op.tries + 1 }); await inboxRow(op.kind, pl[k], (row) => { row.deciding = false; row.conflict = res.error || "rejected"; }); if (res.error) UI.toast(res.error, 4000); }
    }
    return r.results.length;
  }
  async function retryWithTotp(opId, totp) {
    const op = await DB.get("outbox", opId); if (!op) return; await DB.put("outbox", { ...op, payload: { ...op.payload, totp }, status: "queued", error: null });
    await inboxRow(op.kind, op.payload[keyOf(op.kind)], (row) => { row.deciding = true; row.needs_totp = null; row.wrong_code = false; });
    return run(false);
  }
  async function run(full) {
    if (!API.signedIn) return; if (busy) { again = true; return; } busy = true; state.error = null; emit();
    try {
      if (!navigator.onLine) throw Object.assign(new Error("offline"), { offline: true });
      const n = await flush(); if (full !== false || n) await pull();
      state.last = Date.now(); try { localStorage.setItem("ghr.last", state.last); } catch (e) {}
    } catch (e) { state.error = e.offline ? null : (e.message || "error"); if (e.status === 426) state.error = "update-required"; if (e.status === 403) state.error = "not-hr"; }
    finally { busy = false; await refreshCount(false); emit(); if (again) { again = false; run(false); } }
  }
  async function refreshCount(doEmit) { const ob = await DB.outbox(); state.queued = ob.filter((o) => o.status !== "needs_totp").length; state.needsTotp = ob.filter((o) => o.status === "needs_totp").length; state.online = navigator.onLine; if (doEmit !== false) emit(); }
  try { state.last = +localStorage.getItem("ghr.last") || null; } catch (e) {}
  window.addEventListener("online", () => run()); window.addEventListener("offline", () => refreshCount());
  return { run, pull, flush, refreshCount, retryWithTotp, state, on };
})();
