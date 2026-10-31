/* screens/approvals.js — documents submitted for Owner approval: the portal's three decisions (A approved · B approved with comments · D rejected) and the comment thread. */
window.Approvals = (function () {
  const { t, esc, $, render, pill, statusCls } = UI;
  const CODES = ["A", "B", "D"];
  async function list() {
    render(`<h1>${esc(t("appr.title"))}</h1><p class="lede">${esc(t("appr.lede"))}</p><div id="list">${UI.empty(t("app.loading"))}</div>`, t("appr.title"));
    try {
      const d = await Api.cached("/cu/approvals");
      const pend = d.items.filter(a => a.status === "sent_for_approval"), done = d.items.filter(a => a.status !== "sent_for_approval");
      const row = a => `<a class="card tap row" href="#/approvals/${a.id}" style="text-decoration:none;color:inherit"><div class="grow"><div class="title">${esc(a.doc_no)} ${esc(a.revision)}</div><div class="sub">${esc(a.title)}${a.vessel ? " · " + esc(a.vessel) : ""}</div><div class="meta">${esc(a.subproject || a.project || "")} · ${UI.date(a.decided_at || a.issued_at)}${a.due_at && !a.code ? " · " + esc(t("appr.due", { d: UI.date(a.due_at) })) : ""}</div></div>${pill(a.code ? a.code : t("act.review"), a.code ? statusCls[a.status] : "navy")}</a>`;
      $("#list").innerHTML = (pend.length ? pend.map(row).join("") : UI.empty(t("appr.empty"))) + (done.length ? `<h2>${esc(t("appr.decided"))}</h2>` + done.map(row).join("") : "");
    } catch (e) { $("#list").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; }
  }
  async function detail(id) {
    render(`${UI.back("#/approvals")}<div id="ap">${UI.empty(t("app.loading"))}</div>`, t("appr.title"));
    let a; try { a = await Api.cached("/cu/approvals/" + id); } catch (e) { $("#ap").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; return; }
    const can = a.status === "sent_for_approval";
    $("#ap").innerHTML = `<div class="row"><div class="grow"><h1 style="margin:0">${esc(a.doc_no)} ${esc(a.revision)}</h1><div class="sub">${esc(a.title)}${a.vessel ? " · " + esc(a.vessel) : ""}</div></div>${a.code ? pill(a.code, statusCls[a.status]) : ""}</div>
      ${a.has_file ? `<div class="pdfbox" id="pdf" style="margin-top:12px"></div>` : `<div class="notice" style="margin-top:12px">${esc(t("appr.nofile"))}</div>`}
      ${a.code ? `<div class="card"><dl class="kv"><dt>${esc(t("appr.code"))}</dt><dd><b>${a.code}</b> — ${esc(t("appr." + a.code))}</dd><dt>${esc(t("appr.comments"))}</dt><dd>${esc(a.comments || "—")}</dd><dt>${esc(t("appr.by"))}</dt><dd>${esc(a.decided_by || "")} · ${UI.datetime(a.decided_at)}</dd></dl></div>` : ""}
      ${(a.thread || []).length ? `<h2>${esc(t("appr.thread"))}</h2><div id="thread">${a.thread.map(c => `<div class="msg ${c.side === "customer" ? "me" : "gas"}"><div class="who">${esc(c.who)} · ${UI.datetime(c.at)}${c.kind !== "comment" ? " · " + esc(c.kind) : ""}</div>${esc(c.body || "")}</div>`).join("")}</div>` : ""}
      ${can ? `<div class="card"><h2 style="margin-top:0">${esc(t("appr.code"))}</h2><div class="codes" style="grid-template-columns:1fr 1fr 1fr">${CODES.map(c => `<button type="button" class="${c}" data-code="${c}"><b>${c}</b>${esc(t("appr." + c))}<br><small>${esc(t("appr." + c + ".s"))}</small></button>`).join("")}</div>
        <label class="field"><span data-i18n="appr.comments"></span><textarea id="a-comments" maxlength="4000"></textarea></label><div class="meta">${esc(t("appr.comments.req"))}</div>
        <button class="btn navy" id="btn-dec" disabled style="margin-top:10px">${esc(t("appr.btn"))}</button></div>`
      : `<div class="card"><label class="field"><span data-i18n="appr.comment_add"></span><textarea id="a-new" maxlength="4000"></textarea></label><button class="btn ghost sm" id="btn-cm">${esc(t("app.send"))}</button></div>`}`;
    I18N.apply($("#ap"));
    if (a.has_file) { try { const blob = await Api.pdf(`/cu/approvals/${id}/file`); await PdfView.mount($("#pdf"), blob); } catch (e) { $("#pdf").innerHTML = `<div class="pdf-fallback">${esc(UI.errMsg(e))}</div>`; } }
    if (can) {
      let code = null;
      const refresh = () => { $("#btn-dec").disabled = !(code && (code === "A" || $("#a-comments").value.trim())); };
      $("#ap").querySelectorAll("[data-code]").forEach(b => b.onclick = () => { code = b.dataset.code; $("#ap").querySelectorAll("[data-code]").forEach(x => x.classList.toggle("on", x === b)); refresh(); });
      $("#a-comments").addEventListener("input", refresh);
      $("#btn-dec").onclick = async () => {
        const btn = $("#btn-dec"); UI.busy(btn, true);
        try { const sf = await Auth.secondFactor(); if (!sf) return; const d = await Api.call("POST", `/cu/approvals/${id}/decide`, { code, comments: $("#a-comments").value.trim(), totp: sf.totp }); await Store.set("GET /cu/approvals/" + id, d); UI.toast(t("appr.done")); detail(id); App.refreshBadges(); }
        catch (e) { UI.toast(UI.errMsg(e)); } finally { UI.busy(btn, false); }
      };
    } else {
      $("#btn-cm").onclick = async () => { const b = $("#a-new").value.trim(); if (!b) return; try { const d = await Api.call("POST", `/cu/approvals/${id}/comment`, { body: b }); await Store.set("GET /cu/approvals/" + id, d); detail(id); } catch (e) { UI.toast(UI.errMsg(e)); } };
    }
  }
  return { list, detail };
})();
