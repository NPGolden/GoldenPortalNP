/* Golden A/S — In / Out (GAS-CP-R97-182): arrival and departure with time and position.
 * Read model GET /inout (state, working time of today, known sites, the punches of the last 14 days).
 * A punch takes one position fix at that moment (no tracking), is sent with POST /inout/punch, and when there is no
 * signal it is kept in the outbox (sync kind io_punch) with the phone's time. The portal decides what is an exception. */
window.IO = (() => {
  const t = I18N.t; const S = () => SCREENS; const esc = (x) => S().esc(x); const fmt = (d) => S().fmt(d);
  const EXC = ["outside", "late", "early", "weakgps", "nogps", "noin", "double"];
  const getPos = () => new Promise((res) => {
    if (!navigator.geolocation) return res(null);
    let done = false; const fin = (v) => { if (!done) { done = true; res(v); } };
    try { navigator.geolocation.getCurrentPosition((p) => fin({ lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy || 0) }), () => fin(null), { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }); } catch (e) { fin(null); }
    setTimeout(() => fin(null), 17000);
  });
  const dist = (a, b, c, d) => { const R = 6371000, r = Math.PI / 180, x = Math.sin((c - a) * r / 2) ** 2 + Math.cos(a * r) * Math.cos(c * r) * Math.sin((d - b) * r / 2) ** 2; return Math.round(2 * R * Math.asin(Math.min(1, Math.sqrt(x)))); };
  const nearest = (io, p) => { let best = null; (io.sites || []).forEach((s) => { const m = dist(p.lat, p.lng, s.lat, s.lng); if (!best || m < best.m) best = { s, m }; }); return best; };
  const hhmm = (iso) => { const d = new Date(iso); return String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0"); };
  const chip = (st) => `<span class="chip ${st === "auto" || st === "approved" ? "green" : st === "pending" ? "amber" : st === "rejected" ? "red" : "grey"}">${esc(t("io.s_" + st))}</span>`;
  const flags = (f) => (f || []).map((x) => `<span class="chip ${EXC.includes(x) ? "red" : "amber"}">${esc(t("io.f_" + x))}</span>`).join(" ");
  let busy = false;
  async function model() {
    const io = (await DB.get("meta", "io")) || { enabled: true, state: "out", since: null, schedule: {}, sites: [], entries: [] };
    const q = (await DB.outbox()).filter((o) => o.kind === "io_punch" && o.status !== "rejected");
    let state = io.state, since = io.since; q.forEach((o) => { state = o.payload.kind === "in" ? "in" : "out"; since = o.payload.at; });
    return { io, q, state, since };
  }
  async function page() {
    const { io, q, state, since } = await model(); const sc = io.schedule || {};
    UI.chrome(true, t("io.title"), "io", false);
    if (io.enabled === false) return { html: `<div class="card"><p class="muted">${t("io.disabled")}</p></div>` };
    const rows = q.slice().reverse().map((o) => `<div class="card"><div class="t">${o.payload.kind === "in" ? "▶ " + t("io.in") : "■ " + t("io.out")} <small>${hhmm(o.payload.at)}</small> <span class="chip amber">${t("io.waiting")}</span></div><div class="s muted">${fmt(o.payload.at)}${o.payload.lat == null ? " • " + t("io.f_nogps") : ""}</div></div>`).join("")
      + (io.entries || []).map((e) => `<div class="card"><div class="t">${e.kind === "in" ? "▶ " + t("io.in") : "■ " + t("io.out")} <small>${esc(e.time)}</small> ${chip(e.status)} ${flags(e.flags)}</div>
        <div class="s muted">${fmt(e.day)}${e.site ? " • " + esc(e.site) + (e.dist != null ? " · " + e.dist + " m" : "") : (e.lat != null ? " • " + t("io.no_site") : "")}${e.acc != null && e.lat != null ? " (±" + e.acc + " m)" : ""}${e.late_sync ? " • ⏳" : ""}</div>
        ${e.note ? `<div class="s">${esc(e.note)}</div>` : ""}${e.map ? `<div class="s"><a href="${esc(e.map)}" target="_blank" rel="noopener noreferrer">📍 ${t("io.map")}</a></div>` : ""}</div>`).join("");
    return { html: `<div class="card io-now ${state === "in" ? "c-green" : ""}">
        <div class="io-state">${state === "in" ? "🟢 " + t("io.you_in") : "⚪ " + t("io.you_out")}${since ? ` <small>${t("io.since")} ${hhmm(since)}</small>` : ""}</div>
        <div class="s muted">${sc.workday === false ? t("io.offday") : (sc.start && sc.end ? `${t("io.sched")} ${esc(sc.start)}–${esc(sc.end)}` : "")}</div>
        <button class="btn ${state === "in" ? "navy" : "gold"} io-btn" id="punch">${state === "in" ? "■ " + t("io.btn_out") : "▶ " + t("io.btn_in")}</button>
        <p class="muted" id="iomsg">${t("io.hint")}</p></div>
      <h2>${t("io.entries")}</h2>${rows || `<p class="muted">${t("io.none")}</p>`}`,
      bind(root) {
        const msg = (s) => { const m = root.querySelector("#iomsg"); if (m) m.textContent = s; };
        root.querySelector("#punch").onclick = async () => {
          if (busy) return; busy = true; const b = root.querySelector("#punch"); b.disabled = true; msg(t("io.locating"));
          try {
            const kind = state === "in" ? "out" : "in"; const pos = await getPos();
            const send = async () => {
              const p = { kind, at: new Date().toISOString(), tz_min: -new Date().getTimezoneOffset(), lat: pos ? pos.lat : null, lng: pos ? pos.lng : null, acc: pos ? pos.acc : null, uuid: DB.uuid() };
              let sent = false;
              if (navigator.onLine || API.mock) { try { const r = await API.inoutPunch(p); const cur = (await DB.get("meta", "io")) || io; cur.state = r.state; cur.since = r.entry.at; cur.entries = [r.entry].concat((cur.entries || []).filter((x) => x.id !== r.entry.id)); await DB.put("meta", { id: "io", ...cur }); sent = true;
                  UI.toast(t(kind === "in" ? "io.done_in" : "io.done_out") + (r.entry.status === "pending" ? " — " + t("io.s_pending") : "")); }
                catch (e) { if (e && e.status) { UI.toast(e.message || "Error"); return; } } }   // an answer from the portal is final; no answer = no signal → keep it
              if (!sent) { await DB.enqueue("io_punch", p, p.uuid); SYNC.run(false); UI.toast(t("io.queued")); }
              UI.render();
            };
            if (pos) { const n = nearest(io, pos); msg(n ? `${n.s.name} · ${n.m} m (±${pos.acc} m)` : `±${pos.acc} m`); await send(); }
            else UI.sheet(`<h3>${t("io.no_pos_t")}</h3><p>${t("io.no_pos_q")}</p><button class="btn gold" id="ok">${t("io.anyway")}</button>`, async () => { UI.closeSheet(); await send(); });
          } finally { busy = false; b.disabled = false; }
        };
      } };
  }
  return { page };
})();
