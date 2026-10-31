/* screens/more.js — More (approvals, vessels, projects, documents, account, help, about), Account (language, biometric, devices). */
window.More = (function () {
  const { t, esc, $, render, pill } = UI;
  function menu() {
    const items = [["#/approvals", "appr.title"], ["#/vessels", "more.vessels"], ["#/projects", "more.projects"], ["#/documents", "more.docs"], ["#/account", "more.account"], ["#/help", "more.help"], ["#/about", "more.about"]];
    render(`<h1>${esc(t("more.title"))}</h1><div class="card" style="padding:0 14px">${items.map(([h, k]) => `<a class="list-item" href="${h}" style="text-decoration:none;color:inherit"><span class="grow">${esc(t(k))}</span><span style="color:var(--gold)">&#x203A;</span></a>`).join("")}</div>`, t("tab.more"));
  }
  async function vessels() {
    render(`${UI.back("#/more")}<h1>${esc(t("ves.title"))}</h1><div id="l">${UI.empty(t("app.loading"))}</div>`, t("more.vessels"));
    try { const d = await Api.cached("/cu/vessels"); $("#l").innerHTML = d.items.length ? d.items.map(v => `<div class="card"><div class="title">${esc(v.name)}</div><div class="sub">${esc(v.type || "")}${v.yard ? (v.type ? " · " : "") + esc(v.yard) : ""}${v.imo ? " · IMO " + esc(v.imo) : ""}</div></div>`).join("") : UI.empty(t("ves.empty")); } catch (e) { $("#l").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; }
  }
  async function projects() {
    render(`${UI.back("#/more")}<h1>${esc(t("prj.title"))}</h1><div id="l">${UI.empty(t("app.loading"))}</div>`, t("more.projects"));
    try { const d = await Api.cached("/cu/projects"); $("#l").innerHTML = d.items.length ? d.items.map(p => `<div class="card"><div class="title">${esc(p.code)}${p.code ? " · " : ""}${esc(p.name)}</div><div class="sub">${esc(p.vessel)}</div>${(p.subprojects || []).map(s => `<div style="margin-top:10px"><div class="meta"><b>${esc(s.code)}</b> ${esc(s.name)} · ${s.overall} %</div>${UI.stages(s.stage)}</div>`).join("")}</div>`).join("") : UI.empty(t("prj.empty")); } catch (e) { $("#l").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; }
  }
  async function documents() {
    render(`${UI.back("#/more")}<h1>${esc(t("doc.title"))}</h1><div id="l">${UI.empty(t("app.loading"))}</div>`, t("more.docs"));
    try { const d = await Api.cached("/cu/documents"); const cats = {}; d.items.forEach(x => (cats[x.category] = cats[x.category] || []).push(x));
      $("#l").innerHTML = d.items.length ? Object.keys(cats).map(c => `<h2>${esc(t("doc.cat." + c) === "doc.cat." + c ? c : t("doc.cat." + c))}</h2>` + cats[c].map(x => `<div class="card row"><div class="grow"><div class="title">${esc(x.doc_no || x.title)}${x.revision ? " " + esc(x.revision) : ""}</div><div class="sub">${x.doc_no ? esc(x.title) + " · " : ""}${esc(x.vessel)}</div><div class="meta">${UI.date(x.date)} · ${(x.size / 1048576).toFixed(1)} MB</div></div>${/pdf|image/.test(x.mime) ? `<button class="btn sm ghost" data-doc="${x.id}">${esc(t("app.view"))}</button>` : `<a class="btn sm ghost" href="${Api.cfg.base}/cu/documents/${x.id}" target="_blank" rel="noopener">${esc(t("app.download"))}</a>`}</div>`).join("")).join("") : UI.empty(t("doc.empty"));
      $("#l").querySelectorAll("[data-doc]").forEach(b => b.onclick = async () => { UI.busy(b, true); try { const blob = await Api.pdf(`/cu/documents/${b.dataset.doc}`); const s = UI.sheet(`<div class="pdfbox" id="pdfm" style="max-height:70vh"></div><button class="btn ghost" id="pm-close" style="margin-top:10px">${esc(t("app.close"))}</button>`); if (blob.type.startsWith("image/")) s.querySelector("#pdfm").innerHTML = `<img src="${URL.createObjectURL(blob)}" style="max-width:100%">`; else await PdfView.mount(s.querySelector("#pdfm"), blob); s.querySelector("#pm-close").onclick = () => s.close(); } catch (e) { UI.toast(UI.errMsg(e)); } finally { UI.busy(b, false); } });
    } catch (e) { $("#l").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; }
  }
  async function account() {
    render(`${UI.back("#/more")}<h1>${esc(t("acc.title"))}</h1><div id="l">${UI.empty(t("app.loading"))}</div>`, t("more.account"));
    let m; try { m = await Api.call("GET", "/me"); await Store.set("GET /me", m); Api.me = m; } catch (e) { $("#l").innerHTML = `<div class="notice err">${esc(UI.errMsg(e))}</div>`; return; }
    const u = m.user;
    $("#l").innerHTML = `<div class="card"><div class="title">${esc(u.name)}</div><div class="sub">${esc(u.email)} · ${esc(u.company || "")}</div><div class="meta" style="margin-top:4px">${esc(t(u.totp_enabled ? "acc.totp_on" : "acc.totp_off"))}</div></div>
      <h2>${esc(t("acc.lang"))}</h2><div class="langs" style="justify-content:flex-start">${I18N.LANGS.map(([c, n]) => `<button data-lang="${c}" class="${I18N.get() === c ? "on" : ""}">${n}</button>`).join("")}</div>
      <h2>${esc(t("acc.bio"))}</h2><div class="card row"><span class="grow">${esc(t("acc.bio"))}</span><button class="switch ${Auth.bioOn() ? "on" : ""}" id="bio-sw"></button></div>
      <h2>${esc(t("acc.devices"))}</h2><div class="card" style="padding:0 14px">${(m.devices || []).map(d => `<div class="list-item"><div class="grow"><div>${esc(d.model || d.platform)} ${d.this ? `<span class="meta">(${esc(t("acc.thisdev"))})</span>` : ""}</div><div class="meta">${esc(t("acc.lastseen", { d: UI.datetime(d.last_seen_at) }))} · v${esc(d.app_version || "")}</div></div>${!d.this ? `<button class="btn sm red" data-rev="${d.id}">${esc(t("acc.revoke"))}</button>` : ""}</div>`).join("") || `<div class="list-item meta">—</div>`}</div>
      <button class="btn ghost" id="signout" style="margin-top:16px">${esc(t("acc.signout"))}</button><p class="meta" style="text-align:center">${esc(t("acc.version"))} ${Api.APP_VERSION}${m.branch ? " · " + esc(m.branch.name) : ""}</p>`;
    $("#l").querySelectorAll("[data-lang]").forEach(b => b.onclick = async () => { I18N.set(b.dataset.lang); try { await Api.call("POST", "/cu/locale", { locale: b.dataset.lang }); } catch (e) {} App.setTabs(); account(); });
    $("#bio-sw").onclick = async () => { const on = !Auth.bioOn(); if (on) { if (!(await Native.biometricAvailable())) return UI.toast(t("bio.failed")); if (!(await Native.biometricVerify(t("bio.enable")))) return UI.toast(t("bio.failed")); } try { localStorage.setItem("gcu.bio_on", on ? "1" : "0"); localStorage.setItem("gcu.bio_asked", "1"); } catch (e) {} $("#bio-sw").classList.toggle("on", on); };
    $("#l").querySelectorAll("[data-rev]").forEach(b => b.onclick = async () => { try { await Api.call("POST", "/cu/devices/" + b.dataset.rev + "/revoke", {}); UI.toast(t("acc.revoked")); account(); } catch (e) { UI.toast(UI.errMsg(e)); } });
    $("#signout").onclick = async () => { await Api.logout(); App.go("#/login"); };
  }
  function help() { render(`${UI.back("#/more")}<h1>${esc(t("help.title"))}</h1><div class="card"><p>${esc(t("about.text"))}</p><p><a href="../?p=manual" target="_blank" rel="noopener">${esc(t("help.manual"))}</a></p><p class="meta">support@goldenas.com.gr</p></div>`, t("more.help")); }
  function about() { render(`${UI.back("#/more")}<h1>${esc(t("app.name"))}</h1><div class="card"><img src="img/logo.png" style="width:200px;display:block;margin:0 auto 12px" alt=""><p>${esc(t("about.text"))}</p><dl class="kv"><dt>${esc(t("acc.version"))}</dt><dd>${Api.APP_VERSION}</dd><dt>API</dt><dd>${esc(Api.cfg.mock ? "demo (mock)" : Api.cfg.base)}</dd></dl></div>`, t("more.about")); }
  return { menu, vessels, projects, documents, account, help, about };
})();
