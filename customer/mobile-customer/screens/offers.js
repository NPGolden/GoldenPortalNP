/* screens/offers.js — offers sent for the owner's decision: accept (signature + statement) or reject (note required). Amount as on the web portal, never cost data. */
window.Offers = (function () {
  const { t, esc, $, render, pill, statusCls } = UI;
  let seg = "open";
  const isOpen = o => o.status === "issued" || o.status === "expired";
  const stPill = (o) => pill(t("off.st." + o.status) === "off.st." + o.status ? o.portal_status_label || o.status : t("off.st." + o.status), statusCls[o.status] || "grey");
  async function list() {
    const v = render(`<h1>${esc(t("off.title"))}</h1><p class="lede">${esc(t("off.lede"))}</p><div class="segs"><button data-s="open" class="${seg === "open" ? "on" : ""}">${esc(t("off.open"))}</button><button data-s="decided" class="${seg === "decided" ? "on" : ""}">${esc(t("off.decided"))}</button></div><div id="list">${UI.empty(t("app.loading"))}</div>`, t("tab.offers"));
    v.querySelectorAll("[data-s]").forEach(b => b.onclick = () => { seg = b.dataset.s; list(); });
    try {
      const d = await Api.cached("/cu/offers"); const items = d.items.filter(o => seg === "open" ? isOpen(o) : !isOpen(o));
      $("#list").innerHTML = items.length ? items.map(o => `<a class="card tap row" href="#/offers/${o.id}" style="text-decoration:none;color:inherit"><div class="grow"><div class="title">${esc(o.offer_no)} ${esc(o.revision)}</div><div class="sub">${esc(o.title)}${o.vessel ? " · " + esc(o.vessel) : ""}</div><div class="meta">${UI.money(o.total_cents)} ${esc(o.currency)}${o.valid_until ? " · " + esc(t("off.valid", { d: UI.date(o.valid_until) })) : ""}</div></div>${stPill(o)}</a>`).join("") : UI.empty(t("off.empty"));
    } catch (e) { $("#list").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; }
  }
  async function detail(id) {
    render(`${UI.back("#/offers")}<div id="of">${UI.empty(t("app.loading"))}</div>`, t("tab.offers"));
    let o; try { o = await Api.cached("/cu/offers/" + id); } catch (e) { $("#of").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; return; }
    const me = Api.me || {}; const need2fa = !!(me.features && me.features.offer_2fa) && !(me.user && me.user.totp_enabled);
    const can = isOpen(o) && !need2fa, exp = o.status === "expired";
    $("#of").innerHTML = `<div class="row"><div class="grow"><h1 style="margin:0">${esc(o.offer_no)} ${esc(o.revision)}</h1><div class="sub">${esc(o.title)}${o.vessel ? " · " + esc(o.vessel) : ""}</div></div>${stPill(o)}</div>
      <div class="card" style="margin-top:12px"><table class="lines"><tr class="total"><td></td><td>${esc(t("off.total"))}${o.vat_pct ? ` <span class="meta">(${esc(t("off.vat", { v: o.vat_pct }))})</span>` : ""}</td><td class="v">${UI.money(o.total_cents)} ${esc(o.currency)}</td></tr></table>
        <div class="meta" style="margin-top:8px">${o.issued_at ? esc(t("off.sent", { d: UI.date(o.issued_at) })) : ""}${o.valid_until ? " · " + esc(t("off.valid", { d: UI.date(o.valid_until) })) : ""}${o.project ? " · " + esc(o.project) : ""}${o.ticket_no ? " · " + esc(o.ticket_no) : ""}</div></div>
      ${o.has_file ? `<h2>${esc(t("off.pdf"))}</h2><div class="pdfbox" id="pdf"></div>` : `<div class="notice">${esc(t("off.nofile"))}</div>`}
      ${!can && o.decided_at ? `<div class="card"><dl class="kv"><dt>${esc(t("appr.by"))}</dt><dd>${esc(o.decided_by || "")} · ${UI.datetime(o.decided_at)}</dd>${o.comments ? `<dt>${esc(t("off.comments"))}</dt><dd>${esc(o.comments)}</dd>` : ""}</dl></div>` : ""}
      ${isOpen(o) && need2fa ? `<div class="notice err">${esc(t("off.need_2fa"))}</div>` : ""}
      ${can ? `<div class="card"><label class="field"><span data-i18n="off.comments"></span><textarea id="o-comments" maxlength="4000"></textarea></label>
        ${exp ? `<div class="notice err">${esc(t("off.expired_note"))}</div>` : `<button class="btn green" id="o-accept">${esc(t("off.accept"))}</button>`}
        <button class="btn red" id="o-dec">${esc(t("off.decline"))}</button></div>` : ""}`;
    I18N.apply($("#of"));
    if (o.has_file) { try { const blob = await Api.pdf(`/cu/offers/${id}/file`); await PdfView.mount($("#pdf"), blob); } catch (e) { $("#pdf").innerHTML = `<div class="pdf-fallback">${esc(UI.errMsg(e))}</div>`; } }
    if (!can) return;
    const note = () => $("#o-comments").value.trim();
    const after = async (d, msg) => { await Store.set("GET /cu/offers/" + id, d); UI.toast(msg); detail(id); App.refreshBadges(); };
    if ($("#o-accept")) $("#o-accept").onclick = async () => {
      const sig = await Sign.sheet(t("off.accept")); if (!sig) return;
      const sf = await Auth.secondFactor(); if (!sf) return;
      try { await after(await Api.call("POST", `/cu/offers/${id}/decide`, { decision: "approved", signature_png: sig.png, strokes: sig.strokes, consent: true, note: note(), totp: sf.totp, device: Native.deviceInfo() }), t("off.accepted")); } catch (e) { UI.toast(UI.errMsg(e)); }
    };
    $("#o-dec").onclick = async () => { if (!note()) { UI.toast(t("off.reason.req")); $("#o-comments").focus(); return; } const sf = await Auth.secondFactor(); if (!sf) return; try { await after(await Api.call("POST", `/cu/offers/${id}/decide`, { decision: "rejected", note: note(), totp: sf.totp }), t("off.declined")); } catch (e) { UI.toast(UI.errMsg(e)); } };
  }
  return { list, detail };
})();
