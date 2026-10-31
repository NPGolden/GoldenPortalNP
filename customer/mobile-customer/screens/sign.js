/* screens/sign.js — service reports to accept ("sign"): list, report with PDF + signature pad; dispute with a remark. */
window.Sign = (function () {
  const { t, esc, $, render, pill, statusCls } = UI;
  let seg = "pending";
  const lbl = (r) => t(r.status === "sent_for_signature" ? "act.sign" : r.status === "signed" ? "sign.signed" : "sign.decline");
  async function list() {
    const v = render(`<h1>${esc(t("sign.title"))}</h1><p class="lede">${esc(t("sign.lede"))}</p>
      <div class="segs"><button data-s="pending" class="${seg === "pending" ? "on" : ""}">${esc(t("sign.pending"))}</button><button data-s="signed" class="${seg === "signed" ? "on" : ""}">${esc(t("sign.signed"))}</button></div><div id="list">${UI.empty(t("app.loading"))}</div>`, t("tab.sign"));
    v.querySelectorAll("[data-s]").forEach(b => b.onclick = () => { seg = b.dataset.s; list(); });
    try {
      const d = await Api.cached("/cu/reports?status=" + (seg === "pending" ? "sent_for_signature,returned_to_gas" : "signed"));
      $("#list").innerHTML = d.items.length ? d.items.map(r => `<a class="card tap row" href="#/sign/${r.id}" style="text-decoration:none;color:inherit"><div class="grow"><div class="title">${esc(r.doc_no)} ${esc(r.revision)}</div><div class="sub">${esc(r.title)} · ${esc(r.vessel)}</div><div class="meta">${UI.date(r.signed_at || r.issued_at)}${r.engineer ? " · " + esc(r.engineer) : ""}</div></div>${pill(lbl(r), statusCls[r.status])}</a>`).join("") : UI.empty(t("sign.empty"));
    } catch (e) { $("#list").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; }
  }
  async function detail(id) {
    render(`${UI.back("#/sign")}<div id="rep">${UI.empty(t("app.loading"))}</div>`, t("tab.sign"));
    let r; try { r = await Api.cached("/cu/reports/" + id); } catch (e) { $("#rep").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; return; }
    const canSign = r.status === "sent_for_signature";
    $("#rep").innerHTML = `<div class="row"><div class="grow"><h1 style="margin:0">${esc(r.doc_no)} ${esc(r.revision)}</h1><div class="sub">${esc(r.title)} · ${esc(r.vessel)} · ${UI.date(r.report_date || r.issued_at)}</div></div>${pill(lbl(r), statusCls[r.status])}</div>
      ${(r.works || []).length ? `<div class="card" style="margin-top:10px">${r.works.map(w => `<div class="meta"><b>${w.seq}.</b> ${esc(w.description)}${w.open_issues ? `<br><i>${esc(t("sign.open_issues"))}: ${esc(w.open_issues)}</i>` : ""}</div>`).join("")}${r.summary && r.summary.location ? `<div class="meta" style="margin-top:6px">${esc(r.summary.location)}${r.summary.pic ? " · " + esc(r.summary.pic) : ""}</div>` : ""}</div>` : ""}
      <div class="pdfbox" id="pdf" style="margin-top:12px"></div><div class="pdf-progress" id="pp"></div>
      ${r.remark && !canSign ? `<div class="notice">${esc(r.remark)}</div>` : ""}
      ${r.signature ? cert(r.signature) : ""}
      ${canSign ? `<div class="card" id="signcard"><h2 style="margin-top:0">${esc(t("sign.here"))}</h2><div id="pad"></div>
        <label class="field" style="margin-top:10px"><span data-i18n="sign.remark"></span><textarea id="s-remark" maxlength="2000"></textarea></label>
        <label class="check"><input type="checkbox" id="s-consent"><span>${esc(t("sign.consent"))}</span></label>
        <div class="notice" id="readnote">${esc(t("sign.readfirst"))}</div>
        <button class="btn gold" id="btn-sign" disabled style="margin-top:10px">${esc(t("sign.btn"))}</button>
        <button class="btn ghost" id="btn-decline">${esc(t("sign.decline"))}</button></div>` : ""}`;
    I18N.apply($("#rep"));
    let readEnd = false, padOk = false, pad = null;
    const refresh = () => { const b = $("#btn-sign"); if (b) b.disabled = !(readEnd && padOk && $("#s-consent").checked); };
    try { const blob = await Api.pdf(`/cu/reports/${id}/pdf`); await PdfView.mount($("#pdf"), blob, { onProgress: (p, n) => { $("#pp").textContent = t("sign.reading", { p, n }); }, onEnd: () => { readEnd = true; const n = $("#readnote"); if (n) n.hidden = true; refresh(); } }); }
    catch (e) { $("#pdf").innerHTML = `<div class="pdf-fallback">${esc(UI.errMsg(e))}</div>`; readEnd = true; refresh(); }
    if (!canSign) return;
    pad = SigPad.create($("#pad"), { onChange: ok => { padOk = ok; refresh(); } });
    $("#s-consent").addEventListener("input", refresh);
    $("#btn-sign").onclick = async () => {
      const btn = $("#btn-sign"); UI.busy(btn, true);
      try {
        const sf = await Auth.secondFactor(); if (!sf) return;
        const d = await Api.call("POST", `/cu/reports/${id}/accept`, { signature_png: pad.png(), strokes: pad.strokes(), consent: true, remark: $("#s-remark").value.trim(), totp: sf.totp, device: Native.deviceInfo() });
        UI.toast(t("sign.done")); await Store.set("GET /cu/reports/" + id, d); detail(id); App.refreshBadges();
      } catch (e) { UI.toast(UI.errMsg(e)); } finally { UI.busy(btn, false); }
    };
    $("#btn-decline").onclick = () => {
      const s = UI.sheet(`<h1>${esc(t("sign.decline"))}</h1><p class="lede">${esc(t("sign.decline.lede"))}</p><label class="field"><textarea id="dc" maxlength="2000"></textarea></label><button class="btn red" id="dc-ok">${esc(t("app.send"))}</button><button class="btn ghost" id="dc-no">${esc(t("app.cancel"))}</button>`);
      s.querySelector("#dc-no").onclick = s.close;
      s.querySelector("#dc-ok").onclick = async () => { const c = s.querySelector("#dc").value.trim(); if (!c) return UI.toast(t("app.required")); try { const sf = await Auth.secondFactor(); if (!sf) return; await Api.call("POST", `/cu/reports/${id}/dispute`, { remark: c, totp: sf.totp }); s.close(); UI.toast(t("sign.declined")); App.go("#/sign"); App.refreshBadges(); } catch (e) { UI.toast(UI.errMsg(e)); } };
    };
  }
  function cert(s) {
    return `<div class="card"><h2 style="margin-top:0">${esc(t("sign.cert"))}</h2><dl class="kv"><dt>${esc(t("sign.name"))}</dt><dd>${esc(s.name)}</dd><dt>UTC</dt><dd>${esc(s.signed_at_utc)}</dd>${s.ip ? `<dt>IP</dt><dd>${esc(s.ip)}</dd>` : ""}${s.report ? `<dt>${esc(t("sign.report"))}</dt><dd>${esc(s.report)}</dd>` : ""}</dl><div class="meta" style="margin-top:8px">${esc(t("sign.cert_note"))}</div></div>`;
  }
  /** Signature sheet reused by offer acceptance. Resolves {png, strokes} or null. */
  function sheet(title) {
    return new Promise(res => {
      const s = UI.sheet(`<h1>${esc(title)}</h1><div id="pad2"></div><label class="check"><input type="checkbox" id="s2-consent"><span>${esc(t("off.consent"))}</span></label>
        <button class="btn gold" id="s2-ok" disabled>${esc(t("off.accept"))}</button><button class="btn ghost" id="s2-no">${esc(t("app.cancel"))}</button>`);
      let ok = false; const pad = SigPad.create(s.querySelector("#pad2"), { onChange: v => { ok = v; s.querySelector("#s2-ok").disabled = !(ok && s.querySelector("#s2-consent").checked); } });
      s.querySelector("#s2-consent").onchange = () => { s.querySelector("#s2-ok").disabled = !(ok && s.querySelector("#s2-consent").checked); };
      s.querySelector("#s2-no").onclick = () => { s.close(); res(null); };
      s.querySelector("#s2-ok").onclick = () => { const r = { png: pad.png(), strokes: pad.strokes() }; s.close(); res(r); };
    });
  }
  return { list, detail, sheet, cert };
})();
