/* Golden A/S apps — Parts: purchase requisitions from the phone (GAS-CP-R82-003). Shared by the Service Engineer, Golden PM and Golden HR apps.
 * Online read model (GET /requisitions, /requisitions/{id}, /requisitions/options, /items?q=), cached in DB meta "parts" for reading offline;
 * writes (create + submit, cancel, procurement decision) go straight to the portal, which applies its own rules (who may request, budget, reservation, notifications). */
window.PARTS = (() => {
  const t = I18N.t; const S = () => SCREENS; const esc = (x) => S().esc(x); const fmt = (d) => S().fmt(d);
  const tone = (s) => ({ draft: "c-grey", submitted: "c-gold", mgmt: "c-amber", approved: "c-green", rejected: "c-red", closed: "c-grey", cancelled: "c-grey" }[s] || "c-grey");
  const chip = (s) => `<span class="chip ${s === "approved" || s === "reserved" || s === "received" ? "green" : s === "submitted" || s === "mgmt" || s === "to_buy" ? "amber" : s === "rejected" ? "red" : ""}">${t("parts.s_" + s)}</span>`;
  const money = (v, c) => v == null ? "" : `${Number(v).toLocaleString("el-GR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${c || "EUR"}`;
  let status = "open";
  async function cached(k) { try { return await DB.get("meta", "parts:" + k); } catch (e) { return null; } }
  async function keep(k, v) { try { await DB.put("meta", { id: "parts:" + k, ...v }); } catch (e) {} }
  async function load(k, fn) { if (navigator.onLine) { try { const d = await fn(); await keep(k, { data: d }); return d; } catch (e) { const c = await cached(k); if (c && c.data) return c.data; throw e; } } const c = await cached(k); if (c && c.data) return c.data; throw new Error(t("parts.offline")); }

  async function list(q) {
    UI.chrome(true, t("parts.title"), "parts", true); if (q && q.status) status = q.status;
    let d; try { d = await load("list:" + status, () => API.requisitions(status)); } catch (e) { return { html: `<p class="list-empty">${esc(e.message)}</p>` }; }
    const tabs = [["open", t("parts.f_open")], ["mine", t("parts.f_mine")], ["pending", t("parts.f_pending")], ["all", t("parts.f_all")]];
    const items = status === "mine" ? d.items.filter((x) => x.mine) : d.items;
    return { html: `
      <div class="tabs">${tabs.filter(([k]) => k !== "pending" || d.can_approve).map(([k, l]) => `<button class="${k === status ? "on" : ""}" data-st="${k}">${l}</button>`).join("")}</div>
      <button class="btn gold" data-go="#/parts/new">${t("parts.new")}</button>
      ${items.length ? items.map((r) => `<div class="card tap ${tone(r.status)}" data-go="#/parts/${r.id}"><div class="t">${esc(r.pr_no)} <small>${chip(r.status)}${r.over_budget && ["submitted", "mgmt", "approved"].includes(r.status) ? ` <span class="chip red">!</span>` : ""}</small></div>
        <div class="s">${esc(r.title || t("parts.p_" + r.purpose))} • ${esc(r.project)}${r.subproject ? " › " + esc(r.subproject) : ""}</div>
        <div class="s muted">${r.n_lines} ${t("parts.lines")}${r.n_to_buy ? ` • ${r.n_to_buy} ${t("parts.to_buy")}` : ""}${r.need_date ? ` • ${t("parts.need")} ${fmt(r.need_date)}` : ""}${r.estimate != null ? ` • ${money(r.estimate, r.currency)}` : ""}${r.mine ? "" : ` • ${esc(r.requester || "")}`}</div></div>`).join("") : `<p class="list-empty">${t("parts.none")}</p>`}`,
      bind(root) { UI.goLinks(root); root.querySelectorAll("[data-st]").forEach((b) => b.onclick = () => { location.hash = `#/parts?status=${b.dataset.st}`; }); } };
  }

  async function one(id) {
    UI.chrome(true, t("parts.title"), "parts", true);
    let r; try { r = await load("one:" + id, () => API.requisition(id)); } catch (e) { return { html: `<p class="list-empty">${esc(e.message)}</p>` }; }
    const lineRow = (l) => `<div class="card"><div class="t">${l.code ? `<span class="mono">${esc(l.code)}</span> ` : ""}${esc(l.description)} <small>${chip(l.status)}</small></div>
      <div class="s">${l.qty} ${esc(l.unit)}${l.maker || l.part_no ? ` • ${esc([l.maker, l.part_no].filter(Boolean).join(" "))}` : ""}${l.est_price != null ? ` • ${money(l.est_price)}` : ""}</div>
      <div class="s muted">${t("parts.reserved")} ${l.reserved} • ${t("parts.ordered")} ${l.ordered} • ${t("parts.received")} ${l.received} • ${t("parts.issued")} ${l.issued}${l.note ? ` • ${esc(l.note)}` : ""}</div></div>`;
    return { html: `
      <div class="card ${tone(r.status)}"><div class="t">${esc(r.pr_no)} <small>${chip(r.status)}</small></div><div class="s">${esc(r.title || t("parts.p_" + r.purpose))}</div>
        <div class="s muted">${esc(r.project)}${r.subproject ? " › " + esc(r.subproject) : ""} • ${t("parts.p_" + r.purpose)}${r.need_date ? ` • ${t("parts.need")} ${fmt(r.need_date)}` : ""} • ${esc(r.requester || "")}</div>
        ${r.estimate != null ? `<div class="s"><b>${money(r.estimate, r.currency)}</b>${r.over_budget ? ` <span class="chip red">${t("parts.over")}</span>` : ""}</div>` : ""}
        ${r.approval_note ? `<div class="s">${t("parts.procurement")}: ${esc(r.approved_name || "")} — ${esc(r.approval_note)}</div>` : ""}${r.mgmt_note ? `<div class="s">${t("parts.management")}: ${esc(r.mgmt_name || "")} — ${esc(r.mgmt_note)}</div>` : ""}${r.note ? `<div class="s muted">${esc(r.note)}</div>` : ""}</div>
      <h2>${t("parts.lines")} (${r.lines.length})</h2>${r.lines.map(lineRow).join("")}
      ${r.status === "submitted" && (await API.requisitions("open")).can_approve ? `<h2>${t("parts.decide")}</h2><input id="dnote" maxlength="400" placeholder="${t("parts.reason")}"><div class="row2"><button class="btn gold" id="ok">${t("parts.approve")}</button><button class="btn red" id="no">${t("parts.reject")}</button></div>` : ""}
      ${r.can_cancel ? `<button class="btn line" id="cancel">${t("parts.cancel")}</button>` : ""}
      <h2>${t("parts.log")}</h2>${r.events.map((e) => `<div class="kv"><b>${esc(e.who || "")}</b><span class="v">${t("parts.ev_" + e.event)}${e.note ? ` — ${esc(e.note)}` : ""}<br><small class="muted">${esc(e.at)}</small></span></div>`).join("")}`,
      bind(root) {
        const c = root.querySelector("#cancel"); if (c) c.onclick = async () => { const n = prompt(t("parts.cancel_q")); if (n === null) return; try { await API.requisitionCancel(r.id, n); UI.toast(t("common.ok")); location.hash = "#/parts"; } catch (e) { UI.toast(e.message); } };
        const ok = root.querySelector("#ok"), no = root.querySelector("#no"); const dec = async (d) => { const n = root.querySelector("#dnote").value.trim(); if (d === "reject" && !n) { UI.toast(t("parts.need_reason")); return; } try { await API.requisitionDecide(r.id, d, n); UI.toast(t("common.ok")); location.hash = "#/parts"; } catch (e) { UI.toast(e.message); } };
        if (ok) ok.onclick = () => dec("approve"); if (no) no.onclick = () => dec("reject");
      } };
  }

  async function create() {
    UI.chrome(true, t("parts.new"), "parts", true);
    if (!navigator.onLine) return { html: `<p class="list-empty">${t("parts.offline")}</p>` };
    let o; try { o = await API.requisitionOptions(); } catch (e) { return { html: `<p class="list-empty">${esc(e.message)}</p>` }; }
    const lines = []; const canScan = typeof window.scanBarcode === "function" || (typeof REPORTS !== "undefined" && REPORTS.scanBarcode);
    const lineHtml = (l, i) => `<div class="card"><div class="t">${l.code ? `<span class="mono">${esc(l.code)}</span> ` : ""}${esc(l.description)} <small><button class="btn red sm" style="width:auto;padding:2px 8px;margin:0" data-rm="${i}">×</button></small></div><div class="s">${l.qty} ${esc(l.unit)}${l.maker || l.part_no ? ` • ${esc([l.maker, l.part_no].filter(Boolean).join(" "))}` : ""}</div></div>`;
    return { html: `
      <label>${t("parts.project")} *</label><select id="pj"><option value="">—</option>${o.projects.map((p) => `<option value="${p.id}">${esc(p.code || "")} ${esc(p.name)}</option>`).join("")}</select>
      <label>${t("parts.subproject")}</label><select id="sp"><option value="">—</option></select>
      <div class="row2"><div><label>${t("parts.purpose")}</label><select id="pu">${o.purposes.map((p) => `<option value="${p}">${t("parts.p_" + p)}</option>`).join("")}</select></div><div><label>${t("parts.need")}</label><input id="nd" type="date"></div></div>
      <label>${t("parts.r_title")}</label><input id="ti" maxlength="160">
      <h2>${t("parts.lines")}</h2><div id="lines"><p class="muted" id="nolines">${t("parts.no_lines")}</p></div>
      <div class="card c-gold"><div class="row2"><input id="q" placeholder="${t("parts.item_ph")}" autocomplete="off">${canScan ? `<button type="button" class="btn line sm" id="scan" style="width:auto;margin:0;padding:4px 10px">📷</button>` : ""}</div><div id="hits" class="muted s"></div>
        <input id="desc" placeholder="${t("parts.desc")} *" maxlength="255"><div class="row2"><input id="mk" placeholder="${t("parts.maker")}" maxlength="80"><input id="pn" placeholder="${t("parts.part_no")}" maxlength="80"></div>
        <div class="row2"><input id="qty" type="number" step="0.001" min="0.001" value="1" placeholder="${t("parts.qty")}"><select id="un">${o.units.map((u) => `<option value="${u}">${u}</option>`).join("")}</select></div><input type="hidden" id="iid">
        <button type="button" class="btn navy" id="add">${t("parts.add_line")}</button></div>
      <label>${t("parts.note")}</label><input id="note" maxlength="400">
      <button class="btn gold" id="send">${t("parts.submit")}</button><p class="muted">${t("parts.flow")}</p>`,
      bind(root) {
        const $ = (s) => root.querySelector(s); const pj = $("#pj"), sp = $("#sp"); const fill = () => { const pid = pj.value; sp.innerHTML = `<option value="">—</option>` + o.subprojects.filter((s) => String(s.project_id) === pid).map((s) => `<option value="${s.id}">${esc(s.code || "")} ${esc(s.name)}</option>`).join(""); }; pj.onchange = fill;
        const q = $("#q"), hits = $("#hits"), iid = $("#iid"), desc = $("#desc"), mk = $("#mk"), pn = $("#pn"), un = $("#un"); let tm = null;
        const pick = (it) => { iid.value = it.id; q.value = it.code; desc.value = it.desc || ""; mk.value = it.maker || ""; pn.value = it.pn || ""; un.value = it.unit || "pcs"; hits.textContent = `${it.code} · ${it.desc} · ${t("parts.free")} ${it.free}`; };
        const lookup = async (v, auto, list) => { if (v.length < 2 && !list) { hits.textContent = ""; return; } try { const r = await API.items(v); const its = r.items || []; if (!its.length) { hits.textContent = t("parts.not_found"); iid.value = ""; return; } if (auto || (its.length === 1 && !list)) { pick(its.find((x) => x.code === v || x.barcode === v) || its[0]); return; } hits.innerHTML = its.slice(0, 8).map((x, i) => `<a href="#" data-i="${i}">${esc(x.code)}</a> ${esc(x.desc)} <span class="muted">(${x.free})</span>`).join("<br>"); hits.querySelectorAll("a").forEach((a) => a.onclick = (e) => { e.preventDefault(); pick(its[+a.dataset.i]); }); } catch (e) { hits.textContent = ""; } };
        q.oninput = () => { iid.value = ""; clearTimeout(tm); tm = setTimeout(() => lookup(q.value.trim(), false), 350); };
        q.onfocus = () => { if (q.value.trim().length < 2) lookup("", false, true); };   // R86-003: recent items as a drop-down
        const sc = $("#scan"); if (sc) sc.onclick = async () => { const fn = window.scanBarcode || (REPORTS && REPORTS.scanBarcode); const code = await fn(); if (code) { q.value = code; lookup(code, true); } };
        const draw = () => { const box = $("#lines"); box.innerHTML = lines.length ? lines.map(lineHtml).join("") : `<p class="muted">${t("parts.no_lines")}</p>`; box.querySelectorAll("[data-rm]").forEach((b) => b.onclick = () => { lines.splice(+b.dataset.rm, 1); draw(); }); };
        $("#add").onclick = () => { const qty = +$("#qty").value; if (!desc.value.trim() && !iid.value) { UI.toast(t("parts.need_desc")); return; } if (!(qty > 0)) { UI.toast(t("parts.need_qty")); return; } lines.push({ item_id: iid.value ? +iid.value : null, code: iid.value ? q.value : null, description: desc.value.trim(), maker: mk.value.trim(), part_no: pn.value.trim(), qty, unit: un.value }); draw(); iid.value = ""; q.value = ""; desc.value = ""; mk.value = ""; pn.value = ""; $("#qty").value = 1; hits.textContent = ""; };
        $("#send").onclick = async () => { if (!pj.value) { UI.toast(t("parts.need_project")); return; } if (!lines.length) { UI.toast(t("parts.need_lines")); return; } $("#send").disabled = true;
          try { const r = await API.requisitionCreate({ project_id: +pj.value, subproject_id: sp.value ? +sp.value : null, purpose: $("#pu").value, need_date: $("#nd").value || null, title: $("#ti").value.trim(), note: $("#note").value.trim(), lines }); UI.toast(t("parts.sent")); location.hash = `#/parts/${r.id}`; } catch (e) { UI.toast(e.message); $("#send").disabled = false; } };
      } };
  }
  return { list, one, create };
})();
