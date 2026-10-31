/* screens/tickets.js — support tickets: list, thread with reply (text / photo / file), close a resolved ticket, reopen, new ticket. Portal statuses. */
window.Tickets = (function () {
  const { t, esc, $, render, pill, statusCls } = UI;
  let filter = "all";
  const OPEN = ["open", "in_progress", "waiting_customer"];
  async function list() {
    const v = render(`<div class="row"><h1 class="grow">${esc(t("tk.title"))}</h1><a class="btn sm gold" href="#/tickets/new">+ ${esc(t("tk.new"))}</a></div><p class="lede">${esc(t("tk.lede"))}</p>
      <div class="segs">${["all", "open", "waiting_customer", "resolved", "closed"].map(s => `<button data-s="${s}" class="${filter === s ? "on" : ""}">${esc(s === "all" ? t("tk.all") : t("tk.st." + s))}</button>`).join("")}</div><div id="list">${UI.empty(t("app.loading"))}</div>`, t("tab.tickets"));
    v.querySelectorAll("[data-s]").forEach(b => b.onclick = () => { filter = b.dataset.s; list(); });
    try {
      const d = await Api.cached("/cu/tickets"); const items = d.items.filter(x => filter === "all" || (filter === "open" ? OPEN.includes(x.status) : x.status === filter));
      $("#list").innerHTML = items.length ? items.map(x => `<a class="card tap row" href="#/tickets/${x.id}" style="text-decoration:none;color:inherit"><div class="grow"><div class="title">${esc(x.ticket_no)} · ${esc(x.subject)}</div><div class="meta">${esc(x.vessel || "")}${x.priority && x.priority !== "normal" ? " · " + esc(t("tk.p." + x.priority)) : ""} · ${UI.datetime(x.updated_at)}</div></div>${pill(t("tk.st." + x.status), statusCls[x.status])}</a>`).join("") : UI.empty(t("tk.empty"));
    } catch (e) { $("#list").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; }
  }
  async function thread(id) {
    render(`${UI.back("#/tickets")}<div id="tk">${UI.empty(t("app.loading"))}</div>`, t("tab.tickets"));
    let x; try { x = await Api.cached("/cu/tickets/" + id); } catch (e) { $("#tk").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; return; }
    const fileUrl = (a) => /^https?:|^blob:/.test(a.url) ? a.url : Api.cfg.base + a.url;
    $("#tk").innerHTML = `<div class="row"><div class="grow"><h1 style="margin:0">${esc(x.ticket_no)}</h1><div class="sub" style="white-space:normal">${esc(x.subject)}${x.vessel ? " · " + esc(x.vessel) : ""}</div></div>${pill(t("tk.st." + x.status), statusCls[x.status])}</div>
      ${x.resolution ? `<div class="notice ok" style="margin-top:10px"><b>${esc(t("tk.resolution"))}:</b> ${esc(x.resolution)}</div>` : ""}
      <div id="msgs" style="margin-top:12px">${x.messages.map(m => `<div class="msg ${m.from === "customer" ? "me" : "gas"}"><div class="who">${esc(m.from === "customer" ? m.who || t("tk.me") : t("tk.gas"))} · ${UI.datetime(m.at)}</div>${esc(m.body).replace(/\n/g, "<br>")}${(m.attachments || []).map(a => /image/.test(a.type) ? `<img data-src="${esc(fileUrl(a))}" alt="${esc(a.name)}" class="att-img">` : `<a class="att" href="${esc(fileUrl(a))}" target="_blank" rel="noopener">${esc(a.name)}</a>`).join("")}</div>`).join("")}</div>
      ${x.can_reply ? `<div class="composer"><div class="thumbs" id="thumbs"></div><div class="row"><button class="iconbtn" id="tk-photo" title="${esc(t("tk.photo"))}">&#x1F4F7;</button><button class="iconbtn" id="tk-file" title="${esc(t("tk.file"))}">&#x1F4CE;</button><textarea id="tk-body" placeholder="${esc(t("tk.reply"))}" maxlength="8000"></textarea><button class="btn sm navy" id="tk-send">${esc(t("app.send"))}</button></div></div>` : ""}
      ${x.can_close ? `<button class="btn ghost sm" id="tk-close" style="margin-top:10px">${esc(t("tk.closeit"))}</button>` : ""}${x.can_reopen ? `<button class="btn ghost sm" id="tk-reopen" style="margin-top:10px">${esc(t("tk.reopen"))}</button>` : ""}`;
    // attachment images need the bearer token → fetched as blobs
    $("#tk").querySelectorAll("img.att-img").forEach(async img => { try { if (/^blob:/.test(img.dataset.src)) { img.src = img.dataset.src; return; } const b = await Api.call("GET", img.dataset.src.replace(Api.cfg.base, ""), null, { blob: true }); img.src = URL.createObjectURL(b); } catch (e) { img.remove(); } });
    window.scrollTo(0, document.body.scrollHeight);
    if ($("#tk-reopen")) $("#tk-reopen").onclick = async () => { try { await Api.call("POST", `/cu/tickets/${id}/status`, { status: "open" }); UI.toast(t("tk.reopened")); thread(id); App.refreshBadges(); } catch (e) { UI.toast(UI.errMsg(e)); } };
    if ($("#tk-close")) $("#tk-close").onclick = async () => { try { await Api.call("POST", `/cu/tickets/${id}/status`, { status: "closed" }); UI.toast(t("tk.closed")); thread(id); App.refreshBadges(); } catch (e) { UI.toast(UI.errMsg(e)); } };
    if (!x.can_reply) return;
    const files = [];
    const drawThumbs = () => { $("#thumbs").innerHTML = files.map((f, i) => /image/.test(f.blob.type) ? `<img src="${URL.createObjectURL(f.blob)}" data-i="${i}" alt="">` : `<span class="pill grey" data-i="${i}">${esc(f.name)}</span>`).join(""); $("#thumbs").querySelectorAll("[data-i]").forEach(e => e.onclick = () => { files.splice(+e.dataset.i, 1); drawThumbs(); }); };
    $("#tk-photo").onclick = async () => { const f = await Native.pickPhoto(); if (f) { files.push(f); drawThumbs(); } };
    $("#tk-file").onclick = async () => { const f = await Native.pickFile(); if (f) { if (f.blob.size > 20 * 1024 * 1024) return UI.toast("≤ 20 MB"); files.push(f); drawThumbs(); } };
    $("#tk-send").onclick = async () => { const body = $("#tk-body").value.trim(); if (!body && !files.length) return; UI.busy($("#tk-send"), true); try { await Api.upload(`/cu/tickets/${id}/comment`, { body }, files); UI.toast(t("tk.sent")); thread(id); App.refreshBadges(); } catch (e) { UI.toast(UI.errMsg(e)); UI.busy($("#tk-send"), false); } };
  }
  async function create() {
    const me = await Api.cached("/me");
    render(`${UI.back("#/tickets")}<h1>${esc(t("tk.new"))}</h1><form id="f-tk"><label class="field"><span data-i18n="tk.vessel"></span><select name="vessel_id">${(me.vessels || []).map(v => `<option value="${v.id}">${esc(v.name)}</option>`).join("")}</select></label>
      <label class="field"><span data-i18n="tk.category"></span><select name="category">${["fault", "service", "warranty", "spare_parts", "question", "complaint", "other"].map(c => `<option value="${c}">${esc(t("tk.c." + c))}</option>`).join("")}</select></label>
      <label class="field"><span data-i18n="tk.priority"></span><select name="priority">${["low", "normal", "high", "urgent"].map(c => `<option value="${c}" ${c === "normal" ? "selected" : ""}>${esc(t("tk.p." + c))}</option>`).join("")}</select></label>
      <label class="field"><span data-i18n="tk.subject"></span><input name="subject" maxlength="160" required></label><label class="field"><span data-i18n="tk.body"></span><textarea name="body" maxlength="8000" required></textarea></label><div class="thumbs" id="thumbs"></div><div class="row" style="margin-bottom:10px"><button type="button" class="iconbtn" id="tk-photo">&#x1F4F7;</button><button type="button" class="iconbtn" id="tk-file">&#x1F4CE;</button></div><button class="btn gold" type="submit" id="tk-create">${esc(t("tk.create"))}</button></form>`, t("tab.tickets"));
    I18N.apply($("#view"));
    const files = []; const drawThumbs = () => { $("#thumbs").innerHTML = files.map(f => /image/.test(f.blob.type) ? `<img src="${URL.createObjectURL(f.blob)}" alt="">` : `<span class="pill grey">${esc(f.name)}</span>`).join(""); };
    $("#tk-photo").onclick = async () => { const f = await Native.pickPhoto(); if (f) { files.push(f); drawThumbs(); } };
    $("#tk-file").onclick = async () => { const f = await Native.pickFile(); if (f) { files.push(f); drawThumbs(); } };
    $("#f-tk").onsubmit = async e => { e.preventDefault(); const f = e.target; UI.busy($("#tk-create"), true); try { const x = await Api.upload("/cu/tickets", { vessel_id: f.vessel_id.value, category: f.category.value, priority: f.priority.value, subject: f.subject.value.trim(), body: f.body.value.trim() }, files); UI.toast(t("tk.created")); App.go("#/tickets/" + x.id); } catch (ex) { UI.toast(UI.errMsg(ex)); UI.busy($("#tk-create"), false); } };
  }
  return { list, thread, create };
})();
