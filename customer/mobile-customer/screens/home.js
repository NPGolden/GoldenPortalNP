/* screens/home.js — S-03 Home / Action Centre. */
window.Home = (function () {
  const { t, esc, $, render, pill } = UI;
  let vessel = "";
  async function show() {
    const me = (Api.me && Api.me.user) || {};
    const h = new Date().getHours(); const greet = t(h < 12 ? "home.greet.m" : h < 18 ? "home.greet.a" : "home.greet.e");
    const v = render(`<h1>${esc(greet)}${me.first_name ? ", " + esc(me.first_name) : ""}</h1><p class="lede" id="home-sub">${esc(me.company || "")}</p>
      <div class="grid2" id="stats"></div>
      <h2>${esc(t("home.action"))}</h2><p class="lede">${esc(t("home.action.lede"))}</p>
      <div class="segs" id="vsel"></div>
      <div id="actions">${UI.empty(t("app.loading"))}</div>`, t("app.name"));
    try {
      const [a, m] = await Promise.all([Api.cached("/cu/home"), Api.cached("/me")]);
      const c = a.counters;
      $("#stats").innerHTML = [["#/sign", "gold", c.sign, "home.tosign"], ["#/approvals", "navy", c.approve, "home.toapprove"], ["#/offers", "green", c.offers, "home.offers"], ["#/tickets", "blue", c.tickets, "home.tickets"]]
        .map(([h, cls, n, l]) => `<a class="stat ${cls}" href="${h}"><div class="n">${n}</div><div class="l">${esc(t(l))}</div></a>`).join("");
      App.setBadges({ ...c });
      const vessels = (m.vessels || []); if (vessels.length > 1) { $("#vsel").innerHTML = `<button data-v="" class="${vessel ? "" : "on"}">${esc(t("home.allvessels"))}</button>` + vessels.map(x => `<button data-v="${esc(x.name)}" class="${vessel === x.name ? "on" : ""}">${esc(x.name)}</button>`).join(""); $("#vsel").querySelectorAll("button").forEach(b => b.onclick = () => { vessel = b.dataset.v; show(); }); }
      const items = a.items.filter(i => !vessel || i.vessel === vessel);
      const map = { sign: ["act.sign", "gold", "#/sign/"], approve: ["act.review", "navy", "#/approvals/"], offer: ["act.check", "green", "#/offers/"], ticket: ["act.reply", "blue", "#/tickets/"] };
      $("#actions").innerHTML = items.length ? items.map(i => { const [l, cls, h] = map[i.type]; return `<a class="card tap row" href="${h}${i.id}" style="text-decoration:none;color:inherit"><div class="grow"><div class="title">${esc(i.ref)}</div><div class="sub">${esc(i.title)}${i.vessel ? " · " + esc(i.vessel) : ""}</div></div>${pill(t(l), cls)}</a>`; }).join("") : UI.empty(t("home.empty"));
    } catch (e) { $("#actions").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; }
  }
  return { show };
})();
