/* Golden A/S — My planning (GAS-CP-R97-221): what the planning board holds for the signed-in person, today and the next three weeks.
 * Read model GET /me/planning; the last answer is kept on the phone (meta "plan") so the page also opens without signal.
 * Nothing is written from here: the planning is made in the portal. */
window.PLAN = (() => {
  const t = I18N.t; const S = () => SCREENS; const esc = (x) => S().esc(x);
  const iso = (d) => d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  const dayName = (s) => { const d = new Date(s + "T12:00:00"); try { return d.toLocaleDateString(I18N.get(), { weekday: "long", day: "numeric", month: "short" }); } catch (e) { return s; } };
  const TONE = { work: "c-gold", office: "", travel: "", training: "", other: "" };
  let busy = false;
  async function refresh() {
    if (busy || API.mock && (await DB.get("meta", "plan"))) return false; busy = true;
    try { const p = await API.planning(); await DB.put("meta", { id: "plan", ...p, fetched_at: new Date().toISOString() }); return true; } catch (e) { return false; } finally { busy = false; }
  }
  const entry = (e) => `<div class="s" style="margin-top:6px"><b>${esc(e.label)}</b>${e.absence ? ` <span class="chip grey">${esc(t("pl.absence"))}</span>` : ""}${e.hours ? ` <span class="chip grey">${esc(String(e.hours))} h</span>` : ""}${e.detail ? `<div class="muted">${esc(e.detail)}</div>` : ""}${e.note ? `<div class="muted">📝 ${esc(e.note)}</div>` : ""}${e.car ? `<div class="muted">🚗 ${esc(e.car)}</div>` : ""}</div>`;
  function html(p) {
    if (!p) return `<div class="card"><p class="muted">${t("pl.loading")}</p></div>`;
    if (p.enabled === false) return `<div class="card"><p class="muted">${t("pl.disabled")}</p></div>`;
    const today = iso(new Date()), tom = iso(new Date(Date.now() + 864e5)); const days = (p.days || []).filter((d) => d.date >= today);
    const head = `<div class="card"><p class="muted" style="margin:0">${t("pl.lede")}</p>${p.fetched_at ? `<p class="muted" style="margin:6px 0 0"><small>${t("pl.asof")} ${esc(new Date(p.fetched_at).toLocaleString(I18N.get(), { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }))}</small></p>` : ""}</div>`;
    if (!days.some((d) => (d.entries || []).length)) return head + `<div class="card"><p class="muted">${t("pl.none")}</p></div>`;
    return head + days.filter((d) => (d.entries || []).length).map((d) => `<div class="card ${d.date === today || d.date === tom ? "c-gold" : ""}"><div class="t">${esc(dayName(d.date))}${d.date === today ? ` <small>${esc(t("pl.today"))}</small>` : d.date === tom ? ` <small>${esc(t("pl.tomorrow"))}</small>` : ""}</div>${d.entries.map(entry).join("")}</div>`).join("");
  }
  async function page() {
    UI.chrome(true, t("pl.title"), "plan", false);
    const have = await DB.get("meta", "plan");
    refresh().then((ok) => { if (ok && /^#\/plan/.test(location.hash)) DB.get("meta", "plan").then((p) => { const m = document.querySelector("main") || document.getElementById("view"); const box = document.getElementById("pl-box"); if (box) box.innerHTML = html(p); }); });
    return { html: `<div id="pl-box">${html(have)}</div>` };
  }
  /** One line for the home card: what is planned today or, after it, tomorrow. */
  async function summary() {
    const p = await DB.get("meta", "plan"); if (!p || p.enabled === false) return ""; const today = iso(new Date()), tom = iso(new Date(Date.now() + 864e5));
    const f = (dt) => { const d = (p.days || []).find((x) => x.date === dt); return d && d.entries && d.entries.length ? d.entries.map((e) => e.label).join(" • ") : ""; };
    const a = f(today), b = f(tom); return a ? `${t("pl.today")}: ${a}` : b ? `${t("pl.tomorrow")}: ${b}` : "";
  }
  /** The card for the first screen of the app. */
  async function card() { const p = await DB.get("meta", "plan"); if (p && p.enabled === false) return ""; const sum = await summary(); return `<div class="card tap c-gold" data-go="#/plan"><div class="t">📅 ${t("pl.title")}</div><div class="s muted">${sum ? esc(sum) : t("pl.hint")}</div></div>`; }
  return { page, refresh, summary, card };
})();
