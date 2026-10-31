/* app.js — boot, hash router, tab badges, online/offline, resume refresh. */
window.App = (function () {
  const { $, $$, t } = UI;
  const routes = [
    [/^#\/login$/, () => Auth.login(), true],
    [/^#\/home$/, () => Home.show()],
    [/^#\/sign$/, () => Sign.list()], [/^#\/sign\/([^/]+)$/, (id) => Sign.detail(id)],
    [/^#\/approvals$/, () => Approvals.list()], [/^#\/approvals\/([^/]+)$/, (id) => Approvals.detail(id)],
    [/^#\/offers$/, () => Offers.list()], [/^#\/offers\/([^/]+)$/, (id) => Offers.detail(id)],
    [/^#\/tickets$/, () => Tickets.list()], [/^#\/tickets\/new$/, () => Tickets.create()], [/^#\/tickets\/([^/]+)$/, (id) => Tickets.thread(id)],
    [/^#\/more$/, () => More.menu()], [/^#\/vessels$/, () => More.vessels()], [/^#\/projects$/, () => More.projects()], [/^#\/documents$/, () => More.documents()], [/^#\/account$/, () => More.account()], [/^#\/help$/, () => More.help()], [/^#\/about$/, () => More.about()],
  ];
  const tabOf = (h) => (h.match(/^#\/(\w+)/) || [])[1];
  const tabMap = { home: "home", sign: "sign", approvals: "sign", offers: "offers", tickets: "tickets", more: "more", vessels: "more", projects: "more", documents: "more", signed: "more", account: "more", help: "more", about: "more" };
  function go(h) { if (location.hash === h) route(); else location.hash = h; }
  async function route() {
    const h = location.hash || "#/home";
    const r = routes.find(x => x[0].test(h)); if (!r) return go("#/home");
    if (!r[2] && !Api.hasSession()) return go("#/login");
    if (r[2] && Api.hasSession() && h === "#/login") return go("#/home");
    document.body.classList.toggle("hidden-nav", !!r[2]);
    const tab = tabMap[tabOf(h)] || "home"; $$("#tabbar a").forEach(a => a.classList.toggle("active", a.dataset.tab === tab));
    const m = h.match(r[0]); try { await r[1](m[1]); } catch (e) { UI.toast(UI.errMsg(e)); }
  }
  function setBadges(c) { const map = { sign: (c.sign || 0) + (c.approve || 0), offers: c.offers || 0, tickets: c.tickets_waiting || 0 }; $$("#tabbar a").forEach(a => { const n = map[a.dataset.tab]; let b = a.querySelector(".badge"); if (n) { if (!b) { b = document.createElement("span"); b.className = "badge"; a.appendChild(b); } b.textContent = n; } else if (b) b.remove(); }); }
  async function refreshBadges() { try { const a = await Api.call("GET", "/cu/home"); await Store.set("GET /cu/home", a); setBadges(a.counters); } catch (e) {} }
  function setTabs() { I18N.apply($("#tabbar")); }
  async function afterLogin() {
    try { const m = await Api.call("GET", "/me"); Api.me = m; await Store.set("GET /me", m); if (m.user.locale) I18N.set(m.user.locale); setTabs(); } catch (e) {}
    await Auth.offerBiometrics(); Native.pushRegister();
    go("#/home");
  }
  async function boot() {
    I18N.init(); setTabs();
    await Api.init();
    Api.onAuthLost = (e) => { UI.toast(t(e && e.status === 401 ? "app.revoked" : "login.title")); go("#/login"); };
    $("#btn-sync").onclick = async () => { $("#btn-sync").classList.add("spin"); await refreshBadges(); await route(); $("#btn-sync").classList.remove("spin"); };
    const net = () => { $("#offline-bar").hidden = navigator.onLine !== false; }; window.addEventListener("online", net); window.addEventListener("offline", net); net();
    window.addEventListener("hashchange", route);
    if (Api.hasSession()) {
      if (!(await Auth.unlockGate())) return;
      try { const m = await Api.call("GET", "/me"); Api.me = m; await Store.set("GET /me", m); } catch (e) { const c = await Store.get("GET /me"); if (c) Api.me = c.v; else if (e.status === 401 || e.status === 403) return go("#/login"); }
      refreshBadges();
    }
    Native.onResume(() => { if (Api.hasSession()) refreshBadges(); });
    if ("serviceWorker" in navigator && !Api.cfg.mock && location.protocol === "https:") { navigator.serviceWorker.register("sw.js").catch(() => {}); }
    // Deep links: goldenas://… and https://goldensas.com/customer/mobile-customer/#/… arrive as the hash.
    route();
  }
  document.addEventListener("DOMContentLoaded", boot);
  return { go, route, setBadges, refreshBadges, setTabs, afterLogin };
})();
