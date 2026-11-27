/* Golden A/S — Delivery notes for the receiver (GAS-CP-R97-280): the notes that name the signed-in engineer and the transfers to a warehouse he holds.
 * Read model GET /delivery-notes (the last answer is kept on the phone, meta "dn", so the list also opens without signal); one note and the answer are live:
 * GET /delivery-notes/{id}, POST /delivery-notes/{id}/decide {decision: accepted|disputed, remark, signature}. The portal applies every rule. */
window.DNS = (() => {
  const t = I18N.t; const S = () => SCREENS; const esc = (x) => S().esc(x);
  const day = (s) => { try { return new Date(String(s).slice(0, 10) + "T12:00:00").toLocaleDateString(I18N.get(), { day: "numeric", month: "short", year: "numeric" }); } catch (e) { return s; } };
  const qty = (n) => String(Math.round(Number(n) * 1000) / 1000).replace(".", ",");
  const TONE = { accepted: "green", received: "green", disputed: "red", transit: "amber", sent: "amber", open: "amber" };
  const chip = (d) => `<span class="chip ${TONE[d.delivery] || "grey"}">${esc(t("dn.s_" + d.delivery))}</span>`;
  const where = (d) => d.kind === "transfer" ? `${esc(d.from)} → ${esc(d.to)}` : esc([d.project, d.vessel, d.company].filter(Boolean).join(" • ") || t("dn.k_" + d.kind));
  let busy = false;
  async function refresh() {
    if (busy) return false; busy = true;
    try { const p = await API.dnList(); await DB.put("meta", { id: "dn", ...p, fetched_at: new Date().toISOString() }); return true; } catch (e) { return false; } finally { busy = false; }
  }
  const row = (d) => `<div class="card tap ${d.waits ? "c-gold" : "c-grey"}" data-go="#/dn?id=${d.id}"><div class="t">🚚 ${esc(d.no)} <small>${esc(day(d.date))}</small></div><div class="s">${where(d)}</div><div class="s muted">${chip(d)} ${esc(t("dn.k_" + d.kind))} • ${d.lines} ${esc(t("dn.lines"))}${d.by ? " • " + esc(d.by) : ""}</div></div>`;
  function listHtml(p) {
    if (!p) return `<div class="card"><p class="muted">${t("dn.loading")}</p></div>`;
    if (p.enabled === false) return `<div class="card"><p class="muted">${t("dn.none")}</p></div>`;
    const w = p.waiting || [], r = p.recent || [];
    return `<div class="card"><p class="muted" style="margin:0">${t("dn.hint")}</p></div><h2>${t("dn.wait")} (${w.length})</h2>${w.length ? w.map(row).join("") : `<p class="muted">${t("dn.none")}</p>`}${r.length ? `<h2>${t("dn.recent")}</h2>${r.map(row).join("")}` : ""}`;
  }
  async function openPdf(id) {
    if (API.mock) return UI.toast("Demo: the portal streams the PDF to the document viewer");
    UI.toast("…"); try { const res = await API.dnPdf(id); const u = URL.createObjectURL(await res.blob()); window.open(u, "_blank"); setTimeout(() => URL.revokeObjectURL(u), 60000); } catch (e) { UI.toast(t("acc.offline")); }
  }
  function oneHtml(d) {
    const kv = (k, v) => v ? `<div class="kv"><b>${esc(k)}</b><span class="v">${esc(v)}</span></div>` : "";
    const items = (d.items || []).map((l) => `<div class="kv" style="align-items:flex-start"><b style="max-width:70%;font-weight:400;color:inherit">${l.seq}. ${esc(l.description)}${l.code ? `<br><small class="muted">${esc(l.code)}${l.maker ? " • " + esc(l.maker) : ""}</small>` : ""}${l.serials && l.serials.length ? `<br><small class="muted">${esc(t("dn.sn"))}: ${esc(l.serials.join(", "))}</small>` : ""}${l.note ? `<br><small class="muted">📝 ${esc(l.note)}</small>` : ""}</b><span class="v"><b>${qty(l.qty)}</b> ${esc(l.unit)}</span></div>`).join("");
    const tot = (d.totals || []).map((x, i) => `<div class="kv"><b>${i ? "" : esc(t("dn.total"))}</b><span class="v"><b>${qty(x.qty)} ${esc(x.unit)}</b></span></div>`).join("");
    const answer = d.can_answer ? `<h2>✍ ${t("dn.sign")}</h2><p class="muted">${t("dn.lede")}</p>
      <label>${t("dn.remark")}</label><textarea id="dn-remark" maxlength="2000"></textarea>
      <div class="sigbox"><canvas id="sig"></canvas><button class="clr" id="clr">${t("acc.c_clear")}</button><span class="lbl">${t("acc.c_here")}</span></div>
      <p class="note red hidden" id="dn-err"></p>
      <button class="btn gold" id="dn-ok">✓ ${t("dn.ok")}</button><button class="btn line" id="dn-no">↩ ${t("dn.no")}</button>`
      : `<div class="card ${d.delivery === "disputed" ? "c-red" : "c-green"}"><div class="t">${chip(d)}</div><div class="s muted">${esc(d.answered_by || "")}${d.answered_at ? " • " + esc(new Date(String(d.answered_at).replace(" ", "T")).toLocaleString(I18N.get(), { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })) : ""}</div>${d.remark ? `<div class="s">${esc(d.remark)}</div>` : ""}</div>`;
    return `<div class="card c-gold"><div class="t">🚚 ${esc(d.no)} <small>${esc(day(d.date))}</small></div><div class="s">${chip(d)} ${esc(t("dn.k_" + d.kind))}</div></div>
      ${d.kind === "transfer" ? kv(t("dn.from"), d.from) + kv(t("dn.to"), d.to) : kv(t("dn.from"), d.from)}${kv(t("fsr.project"), [d.project, d.subproject].filter(Boolean).join(" › "))}${kv(t("fsr.vessel"), d.vessel)}${kv(t("dn.by"), d.by)}${kv(t("dn.ref"), [d.ref, d.carrier].filter(Boolean).join(" • "))}${d.note ? `<p class="muted">📝 ${esc(d.note)}</p>` : ""}
      <h2>${t("dn.goods")} (${(d.items || []).length})</h2>${items}${tot}
      <button class="btn line sm" id="dn-pdf">⤓ ${t("dn.pdf")}</button>${answer}`;
  }
  function bindOne(root, d) {
    const $ = (s) => root.querySelector(s); const pdf = $("#dn-pdf"); if (pdf) pdf.onclick = () => openPdf(d.id);
    if (!d.can_answer) return; UI.sig.init(root, null); setTimeout(() => UI.sig.fit && UI.sig.fit(root), 50);
    const fail = (m) => { const e = $("#dn-err"); e.textContent = m; e.classList.remove("hidden"); e.scrollIntoView({ block: "center" }); };
    const send = async (decision) => {
      $("#dn-err").classList.add("hidden"); const remark = $("#dn-remark").value.trim(); let signature = "";
      if (decision === "disputed") { if (!remark) return fail(t("acc.e_remark")); } else { signature = UI.sig.data(root); if (!signature) return fail(t("acc.e_sign")); }
      $("#dn-ok").disabled = true; $("#dn-no").disabled = true;
      try { const r = await API.dnDecide(d.id, { decision, remark, signature }); UI.toast(t(r.state === "disputed" ? "dn.done_no" : "dn.done_ok"), 3000); await refresh(); const box = document.getElementById("dn-box"); if (box && r.note) { box.innerHTML = oneHtml(r.note); bindOne(box, r.note); window.scrollTo(0, 0); } }
      catch (e) { $("#dn-ok").disabled = false; $("#dn-no").disabled = false; fail(e.status === 422 || e.status === 403 || e.status === 404 || e.status === 409 ? String(e.message || "").replace(/\s*\((GET|POST) [^)]*\)$/, "") : t("acc.offline")); }
    };
    $("#dn-ok").onclick = () => send("accepted"); $("#dn-no").onclick = () => send("disputed");
  }
  async function page(q) {
    const id = +((q && q.id) || 0);
    UI.chrome(true, t("dn.title"), "more", false);
    if (id) {
      return { html: `<p class="muted"><a id="dn-back">‹ ${t("dn.title")}</a></p><div id="dn-box"><div class="card"><p class="muted">${t("dn.loading")}</p></div></div>`,
        bind(root) { root.querySelector("#dn-back").onclick = () => { location.hash = "#/dn"; };
          API.dnOne(id).then((d) => { const box = root.querySelector("#dn-box"); if (!box) return; box.innerHTML = oneHtml(d); bindOne(box, d); }).catch((e) => { const box = root.querySelector("#dn-box"); if (box) box.innerHTML = `<div class="card"><p class="muted">${esc(e.status === 404 ? t("dn.gone") : t("acc.offline"))}</p></div>`; }); } };
    }
    const have = await DB.get("meta", "dn");
    refresh().then((ok) => { if (ok && /^#\/dn$/.test(location.hash)) DB.get("meta", "dn").then((p) => { const box = document.getElementById("dn-box"); if (box) { box.innerHTML = listHtml(p); UI.goLinks && UI.goLinks(box); } }); });
    return { html: `<div id="dn-box">${listHtml(have)}</div>`, bind(root) { UI.goLinks(root); } };
  }
  async function waiting() { const p = await DB.get("meta", "dn"); return p && p.enabled !== false ? (p.waiting || []).length : 0; }
  /** The card for the first screen: shown only while something waits. */
  async function card() { const p = await DB.get("meta", "dn"); const w = p && p.enabled !== false ? (p.waiting || []) : []; if (!w.length) return ""; return `<div class="card tap c-gold" data-go="${w.length === 1 ? "#/dn?id=" + w[0].id : "#/dn"}"><div class="t">🚚 ${t("dn.title")} <small>${esc(t("dn.n_wait").replace("{n}", w.length))}</small></div><div class="s muted">${esc(w.slice(0, 3).map((d) => d.no).join(" • "))}</div></div>`; }
  return { page, refresh, card, waiting };
})();
