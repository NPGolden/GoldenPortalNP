/* Golden A/S Service app — screens S-01 … S-18 (portal contract v0.5.0: numeric ids, codes for display). Each screen is an async function returning HTML and wiring events via UI.bind. */
window.SCREENS = (() => {
  const t = I18N.t;
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const fmt = (iso) => { if (!iso) return ""; const [y, m, d] = String(iso).slice(0, 10).split("-"); return `${d}/${m}/${y}`; };
  const today = () => new Date().toISOString().slice(0, 10);
  const prioClass = { urgent: "c-red", high: "c-red", normal: "c-amber", med: "c-amber", low: "c-grey" };
  const prioLbl = (p, st) => st === "closed" || st === "resolved" ? t("pr.done") : (t("pr." + p) === "pr." + p ? String(p).toUpperCase() : t("pr." + p));
  const stLbl = (s) => t("st." + s) === "st." + s ? String(s).replace(/_/g, " ") : t("st." + s);
  const stChip = (s) => `<span class="chip ${s === "invoiced" || s === "approved" || s === "included" ? "green" : s === "reported" || s === "pending" || s === "submitted" ? "amber" : s === "rejected" ? "red" : "grey"}">${esc(stLbl(s))}</span>`;
  const weekOf = (iso) => { const d = new Date(iso); const day = (d.getDay() + 6) % 7; d.setDate(d.getDate() - day + 3); const y1 = new Date(d.getFullYear(), 0, 4); return `W${String(1 + Math.round(((d - y1) / 86400000 - 3 + ((y1.getDay() + 6) % 7)) / 7)).padStart(2, "0")}`; };
  const isOpen = (x) => !["closed", "resolved"].includes(x.status);

  // ---------------------------------------------------------------- data helpers
  const ctx = {};
  async function load() {
    ctx.me = (await DB.get("meta", "me")) || {}; ctx.master = (await DB.get("meta", "master")) || { sections: [], engineers: [], stages: [], calendars: {}, isr_systems: [], isr_bank: {}, activities: ["site"] };
    ctx.projects = await DB.all("projects"); ctx.vessels = await DB.all("vessels"); ctx.equipment = await DB.all("equipment"); ctx.tickets = await DB.all("tickets");
  }
  const vesselName = (id) => (ctx.vessels.find((v) => v.id === +id) || {}).name || "";
  const project = (id) => ctx.projects.find((p) => p.id === +id);
  const projName = (id) => { const p = project(id); return p ? `${p.code} — ${p.name}` : ""; };
  const findSub = (id) => { for (const p of ctx.projects) { const s = (p.subprojects || []).find((x) => x.id === +id); if (s) return { ...s, project: p }; } return null; };
  const subName = (id) => { const s = findSub(id); return s ? `${s.code} — ${s.name}` : id ? `#${id}` : t("assign.service"); };
  const subCode = (id) => { const s = findSub(id); return s ? s.code : ""; };
  const ticketNo = (id) => (ctx.tickets.find((x) => x.id === +id) || {}).ticket_no || (id ? `TK${id}` : "");
  const subOptions = (projectId, selected, noService) => { const p = project(projectId); const opts = p ? p.subprojects.map((s) => `<option value="${s.id}" ${s.id === +selected ? "selected" : ""}>${esc(s.code)} — ${esc(s.name)}</option>`).join("") : ""; return (noService ? `<option value="">—</option>` : `<option value="" ${!selected ? "selected" : ""}>${esc(t("assign.service"))}</option>`) + opts; };
  const projOptions = (selected) => `<option value="">—</option>` + ctx.projects.map((p) => `<option value="${p.id}" ${p.id === +selected ? "selected" : ""}>${esc(p.code)} — ${esc(p.name)}</option>`).join("");

  // ---------------------------------------------------------------- S-01 login
  async function login() {
    UI.chrome(false);
    return { html: `<div class="login">
      <img src="img/logo.png" alt="Golden A/S">
      <h2>${t("login.title")}</h2>
      <label>${t("login.portal")}</label><input readonly value="goldensas.com/customer${API.mock ? " (demo data)" : ""}">
      <label>${t("login.email")}</label><input id="email" type="email" autocomplete="username" value="${API.mock ? "engineer@goldenas.com.gr" : ""}">
      <label>${t("login.password")}</label><input id="pw" type="password" autocomplete="current-password" value="${API.mock ? "demo" : ""}">
      <div id="totprow" class="hidden"><label>${t("login.totp")}</label><input id="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
      <p id="err" class="note red hidden"></p>
      <button class="btn gold" id="go">${t("login.signin")}</button>
      <button class="btn line" id="bio" ${API.signedIn ? "" : "disabled"}>${t("login.bio")}</button>
      <h2>${t("login.lang")}</h2><div class="lang">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}" class="${I18N.get() === c ? "on" : ""}">${l}</button>`).join("")}</div>
      <p class="muted" style="text-align:center;margin-top:16px">${ctx.me.home_branch || "GR"} • Golden A/S ${ctx.me.home_branch === "GIT" ? "Italian Branch" : "head office"} • v${API.VERSION}</p>
    </div>`, bind(root) {
      root.querySelectorAll(".lang button").forEach((b) => b.onclick = () => { I18N.set(b.dataset.l); UI.render(); });
      root.querySelector("#go").onclick = async () => {
        const b = root.querySelector("#go"); b.disabled = true; const er = root.querySelector("#err");
        try { await API.login(root.querySelector("#email").value.trim(), root.querySelector("#pw").value, root.querySelector("#totp").value.trim()); await SYNC.run(); location.hash = "#/home"; }
        catch (e) { const code = e.data && e.data.code; if (code === "totp_required") { root.querySelector("#totprow").classList.remove("hidden"); er.textContent = t("login.totphint"); }
          else er.textContent = e.status === 402 ? t("login.nolicence") : e.status === 423 ? t("login.locked") : e.status === 426 ? t("login.update") : e.status === 429 ? t("login.ratelimit") : t("login.failed"); er.classList.remove("hidden"); b.disabled = false; }
      };
      root.querySelector("#bio").onclick = async () => { if (await UI.biometric()) { await SYNC.run(); location.hash = "#/home"; } };
    } };
  }

  // ---------------------------------------------------------------- S-02 home
  async function home() {
    UI.chrome(true, t("tab.home"), "home");
    const tickets = ctx.tickets.filter((x) => x.assigned_to === ctx.me.uid && isOpen(x));
    const drafts = (await DB.all("fsr")).filter((f) => f.status === "draft");
    const ts = (await DB.all("timesheets")).filter((x) => x.date === today());
    const hrs = ts.reduce((a, x) => a + (x.hours != null ? +x.hours : (x.normal || 0) + (x.overtime || 0) + (x.holiday || 0) + (x.travel || 0)), 0);
    const assigns = (await DB.all("assignments")).filter((a) => a.status === "new");
    const todays = tickets.filter((x) => x.priority === "high" || x.priority === "urgent" || x.status === "in_progress").slice(0, 3);
    return { html: `
      <div class="stats"><div><b>${tickets.length}</b><span>${t("home.open")}</span></div><div><b class="gold">${drafts.length}</b><span>${t("home.drafts")}</span></div><div><b class="green">${hrs} h</b><span>${t("home.today")}</span></div></div>
      <h2>${t("home.assign")} (${assigns.length})</h2>
      ${assigns.length ? assigns.map((a) => `<div class="card tap c-gold" data-go="#/assign/${esc(a.id)}"><div class="t">${esc(a.ref)} <small>${a.kind === "ticket" ? "TICKET" : "WORK"}</small></div><div class="s">${esc(a.title)}</div><div class="s muted">${esc(a.vessel || vesselName(a.vessel_id))} • ${fmt(a.date)} • ${esc(a.by)}</div></div>`).join("") : `<p class="muted">${t("home.noassign")}</p>`}
      <h2>${t("home.todayList")} — ${fmt(today())}</h2>
      ${todays.map((x) => `<div class="card tap ${prioClass[x.priority] || "c-grey"}" data-go="#/ticket/${x.id}"><div class="t">${esc(x.ticket_no)} • ${esc(x.vessel)} <small>${prioLbl(x.priority, x.status)}</small></div><div class="s">${esc(x.title)}</div><div class="s muted">${esc(x.project_id ? subName(x.subproject_id) : t("assign.service"))}</div></div>`).join("") || `<p class="muted">—</p>`}
      <h2>${t("home.quick")}</h2>
      <button class="btn gold" data-go="#/fsr/new">${t("home.newfsr")}</button><button class="btn line" data-go="#/fsr/new?kind=inspection">${t("rep.newins")}</button>
      <button class="btn navy" data-go="#/hours">${t("home.hours")}</button>`, bind: UI.goLinks };
  }

  // ---------------------------------------------------------------- assignment → sub-project selection
  async function assign(id) {
    const a = await DB.get("assignments", id); if (!a) return { html: `<p class="list-empty">—</p>` };
    UI.chrome(true, t("assign.title"), null, true);
    const p = project(a.project_id); let sel = a.subproject_id || (p ? (p.subprojects[0] || {}).id || "" : "");
    const opts = (p ? p.subprojects.map((s) => ({ id: s.id, title: `${s.code} — ${s.name}`, sub: `${t("notes.stage")}: ${t("stage." + s.stage) === "stage." + s.stage ? s.stage : t("stage." + s.stage)}` })) : []).concat(a.kind === "ticket" ? [{ id: "", title: t("assign.service"), sub: `${a.vessel || vesselName(a.vessel_id)} — ${t("fsr.service")}` }] : []);
    return { html: `
      <div class="card c-gold"><div class="t">${esc(a.ref)} <small>${a.kind === "ticket" ? "TICKET" : "WORK"}</small></div><div class="s">${esc(a.title)}</div><div class="s muted">${esc(a.vessel || vesselName(a.vessel_id))}${p ? ` • ${esc(p.code)} ${esc(p.name)}` : ""} • ${fmt(a.date)} • ${esc(a.by)}${a.planned_hours ? ` • ${a.planned_hours} h` : ""}</div></div>
      <h2>${a.kind === "ticket" ? t("assign.choose") : t("assign.ack")}</h2>
      <div id="opts">${opts.map((o) => `<div class="opt ${String(o.id) === String(sel) ? "on" : ""}" data-id="${esc(o.id)}"><b>${esc(o.title)}</b><span>${esc(o.sub)}</span></div>`).join("")}</div>
      <p class="muted">${t("assign.hint")}</p>
      <button class="btn gold" id="ok">${a.kind === "ticket" ? t("assign.accept") : t("assign.acceptwork")}</button>`, bind(root) {
      root.querySelectorAll(".opt").forEach((o) => o.onclick = () => { sel = o.dataset.id; root.querySelectorAll(".opt").forEach((x) => x.classList.toggle("on", x === o)); });
      root.querySelector("#ok").onclick = async () => {
        const sid = sel ? +sel : null;
        await DB.put("assignments", { ...a, status: "accepted", subproject_id: sid });
        if (a.kind === "ticket") { const tk = await DB.get("tickets", a.ref_id); if (tk) await DB.put("tickets", { ...tk, status: "in_progress", subproject_id: sid, history: (tk.history || []).concat([{ at: today(), text: `${ctx.me.name} — accepted: ${sid ? subName(sid) : t("assign.service")}` }]) }); }
        await DB.enqueue("assignment_accept", { assignment_id: a.id, kind: a.kind, ref_id: a.ref_id, project_id: a.project_id, subproject_id: sid, vessel_id: a.vessel_id });
        SYNC.run(false); UI.toast(t("common.ok")); location.hash = a.kind === "ticket" ? `#/ticket/${a.ref_id}` : "#/home";
      };
    } };
  }

  // ---------------------------------------------------------------- S-03 tickets
  let tScope = "mine";
  async function tickets() {
    UI.chrome(true, t("tab.tickets"), "tickets");
    const all = ctx.tickets; const myVessels = new Set(all.filter((x) => x.assigned_to === ctx.me.uid).map((x) => x.vessel_id));
    const list = all.filter((x) => tScope === "mine" ? x.assigned_to === ctx.me.uid : tScope === "vessel" ? myVessels.has(x.vessel_id) : true).sort((a, b) => (!isOpen(a)) - (!isOpen(b)) || String(b.reported_at).localeCompare(String(a.reported_at)));
    const open = all.filter((x) => x.assigned_to === ctx.me.uid && isOpen(x)).length;
    UI.badge(`${open} open`);
    return { html: `<div class="seg">${["mine", "vessel", "all"].map((s) => `<button data-s="${s}" class="${tScope === s ? "on" : ""}">${t("tickets." + s)}</button>`).join("")}</div>
      ${list.map((x) => `<div class="card tap ${!isOpen(x) ? "c-green" : prioClass[x.priority] || "c-grey"}" data-go="#/ticket/${x.id}"><div class="t">${esc(x.ticket_no)} • ${esc(x.vessel)} <small>${prioLbl(x.priority, x.status)}</small></div><div class="s">${esc(x.title)}</div><div class="s muted">${esc(stLbl(x.status))} • ${fmt(x.reported_at)} • ${esc(x.project_id ? subName(x.subproject_id) : t("assign.service"))}${x.legacy ? ` • ${esc(x.legacy)}` : ""}</div></div>`).join("") || `<p class="list-empty">${t("tickets.none")}</p>`}`,
      bind(root) { UI.goLinks(root); root.querySelectorAll(".seg button").forEach((b) => b.onclick = () => { tScope = b.dataset.s; UI.render(); }); } };
  }
  // ---------------------------------------------------------------- S-04 ticket detail
  async function ticket(id) {
    const x = await DB.get("tickets", +id); if (!x) return { html: `<p class="list-empty">—</p>` };
    UI.chrome(true, x.ticket_no, "tickets", true);
    const pending = (await DB.all("assignments")).find((a) => a.kind === "ticket" && a.ref_id === x.id && a.status === "new");
    const mine = x.assigned_to === ctx.me.uid;
    return { html: `
      <div class="kv"><b>${t("ticket.vessel")}</b><span class="v">${esc(x.vessel)} • ${esc(x.customer)}</span></div>
      <div class="kv"><b>${t("ticket.system")}</b><span class="v">${esc(x.system || "—")}</span></div>
      <div class="kv"><b>${t("ticket.prio")}</b><span class="v">${esc(x.priority)} / ${esc(stLbl(x.status))}</span></div>
      <div class="kv"><b>${t("ticket.sub")}</b><span class="v">${esc(x.project_id ? `${projName(x.project_id)}${x.subproject_id ? " • " + subName(x.subproject_id) : ""}` : t("assign.service"))}</span></div>
      <div class="kv"><b>${t("ticket.by")}</b><span class="v">${esc(x.reported_by)}, ${fmt(x.reported_at)}${x.legacy ? ` • ${esc(x.legacy)}` : ""}</span></div>
      <h2>${t("ticket.desc")}</h2><p class="txt">${esc(x.description)}</p>
      ${(x.open_issues || []).length ? `<h2>${t("rep.issues")}</h2>${x.open_issues.map((i) => `<div class="card c-amber"><div class="s">${esc(i)}</div></div>`).join("")}` : ""}
      ${(x.fsrs || []).length ? `<h2>${t("ticket.fsrs")}</h2>${x.fsrs.map((i) => `<div class="card c-grey"><div class="s">${esc(i)}</div></div>`).join("")}` : ""}
      <h2>${t("ticket.history")}</h2>${(x.history || []).map((h) => `<div class="card c-grey"><div class="s"><b>${fmt(h.at)}</b> — ${esc(h.text)}</div></div>`).join("")}
      ${pending ? `<button class="btn gold" data-go="#/assign/${esc(pending.id)}">${t("ticket.accept")}</button>` : mine && x.status === "open" ? `<button class="btn gold" id="acc">${t("ticket.accept")}</button>` : ""}
      ${isOpen(x) ? `<div class="row2"><button class="btn ${pending ? "navy" : "gold"}" data-go="#/fsr/new?ticket=${x.id}">${t("ticket.startfsr")}</button><button class="btn navy" data-go="#/fsr/new?kind=inspection&ticket=${x.id}">${t("rep.newins")}</button></div>` : ""}
      <button class="btn line" id="note">${t("ticket.addnote")}</button><button class="btn line" id="ai">✦ ${t("ai.title")}</button>`, bind(root) {
      UI.goLinks(root);
      const tctx = { page: "ticket", title: `Ticket ${x.ticket_no}`, headline: x.title, fields: () => [{ name: "title", label: "Title", value: x.title }, { name: "description", label: "Description", value: x.description }, { name: "vessel", label: "Vessel", value: x.vessel }, { name: "system", label: "System", value: x.system || "" }, { name: "status", label: "Status", value: x.status }, { name: "history", label: "History", value: (x.history || []).map((h) => `${h.at} ${h.text}`).join("\n").slice(0, 1800) }, { name: "open_issues", label: "Open issues", value: (x.open_issues || []).join("\n") }].map((f) => ({ ...f, type: "text" })), getText: () => x.description, noFill: true };
      root.querySelector("#ai").onclick = () => ASSIST.open(tctx); UI.assistContext(tctx);
      const acc = root.querySelector("#acc"); if (acc) acc.onclick = () => UI.sheet(`<h3>${t("assign.choose")}</h3><select id="sp">${subOptions(x.project_id, x.subproject_id)}</select><button class="btn gold" id="ok">${t("assign.accept")}</button>`, async (sh) => {
        const sp = +sh.querySelector("#sp").value || null;
        await DB.put("tickets", { ...x, status: "in_progress", subproject_id: sp, history: (x.history || []).concat([{ at: today(), text: `${ctx.me.name} — accepted: ${sp ? subName(sp) : t("assign.service")}` }]) });
        await DB.enqueue("ticket_accept", { ticket_id: x.id, project_id: x.project_id, subproject_id: sp }); SYNC.run(false); UI.closeSheet(); UI.render();
      });
      root.querySelector("#note").onclick = () => UI.sheet(`<h3>${t("ticket.addnote")}</h3><div class="seg" data-k="vis"><button data-v="internal" class="on">${t("notes.int")}</button><button data-v="external">${t("notes.cust")}</button></div><textarea id="tx"></textarea><div class="photos" id="ph"></div><button class="btn gold" id="ok">${t("common.ok")}</button>`, async (sh) => {
        const uuid = DB.uuid(); const n = UI.photos.count(sh.querySelector("#ph")); await UI.photos.store(sh.querySelector("#ph"), uuid);
        const text = sh.querySelector("#tx").value.trim(); if (!text && !n) return; const vis = sh.querySelector(".seg .on").dataset.v;
        await DB.put("tickets", { ...x, history: (x.history || []).concat([{ at: today(), text: `${ctx.me.name} (${vis}): ${text}${n ? ` (${n} photo)` : ""}` }]) });
        await DB.enqueue("ticket_note", { ticket_id: x.id, text: text || `${n} photo(s)`, visibility: vis, photos: n }, uuid); SYNC.run(false); UI.closeSheet(); UI.render();
      }, (sh) => { UI.segs(sh); UI.photos.init(sh.querySelector("#ph")); });
    } };
  }

  // ---------------------------------------------------------------- reports (service / inspection) live in js/reports.js
  const fsrList = (...a) => REPORTS.list(...a); const fsrEdit = (...a) => REPORTS.edit(...a);

  // ---------------------------------------------------------------- S-09 / S-10 / S-18 hours (portal timesheets: one draft per project & week, lines reclassified by the work calendar)
  let hView = "day", hSub = false;
  async function hours(q) {
    if (!hSub) { hSub = true; let l0 = SYNC.state.last; SYNC.on((st) => { if (st.last !== l0) { l0 = st.last; if (/^#\/hours/.test(location.hash) && hView === "week" && !document.querySelector("#sheet")) UI.render(); } }); }   // the week table follows the outbox (queued → portal TS no)
    UI.chrome(true, t("hours.title"), "hours"); UI.badge(weekOf(today()));
    const all = (await DB.all("timesheets")).sort((a, b) => b.date.localeCompare(a.date));
    const budgets = await DB.all("budgets");
    const sections = ctx.master.sections || [];
    if (hView === "week") {
      // server lines (one per hour type) + local queued bookings (expanded) — grouped by date & sub-project
      const rows = {}; const key = (x) => `${x.date}|${x.subproject_id || x.project_id}|${x.ts_no || x.id}`;
      all.forEach((x) => { const r = rows[key(x)] = rows[key(x)] || { date: x.date, project_id: x.project_id, subproject_id: x.subproject_id, normal: 0, overtime: 0, holiday: 0, travel: 0, approval: x.approval || (x.status === "queued" ? "queued" : x.status), invoicing: x.invoicing, ts_no: x.ts_no, ts_id: x.ts_id, status: x.status, auto: 0 };
        if (x.hours != null) { if (/^Travel/.test(x.description || "")) r.travel += +x.hours; else r[x.hour_type] = (r[x.hour_type] || 0) + +x.hours; if (x.auto_type) r.auto++; } else { r.normal += x.normal || 0; r.overtime += x.overtime || 0; r.holiday += x.holiday || 0; r.travel += x.travel || 0; } });
      const list = Object.values(rows).sort((a, b) => b.date.localeCompare(a.date)).slice(0, 40);
      const drafts = {}; all.forEach((x) => { if (x.approval === "draft" && x.ts_id) drafts[x.ts_id] = { ts_no: x.ts_no, project_id: x.project_id, hours: (drafts[x.ts_id] || { hours: 0 }).hours + +x.hours }; });
      return { html: `<div class="seg"><button id="vd">${t("hours.day")}</button><button class="on">${t("hours.week")}</button></div>
        <div class="overflow"><table class="week"><tr><th>${t("hours.date")}</th><th>${t("hours.sub")}</th><th>N</th><th>OT</th><th>H</th><th>T</th><th>${t("hours.status")}</th></tr>
        ${list.map((x) => `<tr class="${x.invoicing === "invoiced" ? "lock" : ""}"><td>${fmt(x.date)}</td><td style="text-align:left">${esc(x.subproject_id ? subCode(x.subproject_id) : (project(x.project_id) || {}).code || "")}${x.ts_no ? `<br><span class="muted">${esc(x.ts_no)}</span>` : ""}</td><td>${x.normal || 0}</td><td>${x.overtime || 0}${x.auto ? "*" : ""}</td><td>${x.holiday || 0}</td><td>${x.travel || 0}</td><td>${x.status === "over_budget" ? '<span class="chip red">budget</span>' : x.status === "locked" ? `<span class="chip red" title="${esc(x.conflict || "")}">${t("st.locked")}</span>` : x.status === "queued" ? '<span class="chip amber">queued</span>' : stChip(x.approval || "draft")}${x.invoicing && x.invoicing !== "none" ? " " + stChip(x.invoicing) : ""}</td></tr>`).join("")}</table></div>
        <p class="muted">* ${t("hours.calhint")}</p>
        ${Object.keys(drafts).map((id) => `<button class="btn navy" data-submit="${id}">${t("hours.submitweek")} — ${esc(drafts[id].ts_no)} (${drafts[id].hours} h)</button>`).join("")}`,
        bind(root) { root.querySelector("#vd").onclick = () => { hView = "day"; UI.render(); }; root.querySelectorAll("[data-submit]").forEach((b) => b.onclick = async () => { const id = +b.dataset.submit; for (const x of all.filter((l) => l.ts_id === id)) await DB.put("timesheets", { ...x, approval: "submitted" }); await DB.enqueue("timesheet_submit", { id: "TS" + id, ts_id: id }); SYNC.run(false); UI.toast(t("hours.weeksubmitted")); UI.render(); }); } };
    }
    const last = all.find((x) => x.project_id) || {}; const def = { date: today(), project_id: +q.project || last.project_id || (ctx.projects[0] || {}).id, subproject_id: +q.sub || last.subproject_id || null, section_id: ctx.me.section_id || (sections[0] || {}).id, activity: (ctx.master.activities || ["site"])[0] };
    const cal = (ctx.master.calendars || {})[ctx.me.calendar || ctx.me.home_branch] || Object.values(ctx.master.calendars || {})[0] || { holidays: [] };
    const budgetHtml = (sub) => { const b = budgets.find((x) => x.subproject_id === +sub); if (!b) return `<p class="muted">—</p>`; return b.sections.map((s) => { const over = s.booked > s.budget; const pct = Math.min(s.booked, s.budget) / Math.max(s.budget, s.booked, 1) * 100; const ex = over ? (s.booked - s.budget) / Math.max(s.budget, s.booked, 1) * 100 : 0; return `<div class="bar"><div class="l"><b>${esc(s.name)}${s.extra ? ` (approved extra ${s.extra} h)` : ""}</b><span>${s.booked} / ${s.budget} h</span></div><div class="tr"><i class="${over ? "over" : ""}" style="width:${pct}%"></i>${ex ? `<i class="extra" style="width:${ex}%"></i>` : ""}</div></div>`; }).join(""); };
    return { html: `<div class="seg"><button class="on">${t("hours.day")}</button><button id="vw">${t("hours.week")}</button></div>
      <label>${t("hours.date")}</label><input id="date" type="date" value="${def.date}">
      <label>${t("hours.project")}</label><select id="project">${projOptions(def.project_id).replace('<option value="">—</option>', "")}</select>
      <label>${t("hours.sub")}</label><select id="sub">${subOptions(def.project_id, def.subproject_id, true)}</select>
      <div class="row2"><div><label>${t("hours.activity")}</label><select id="activity">${(ctx.master.activities || ["site"]).map((a) => `<option value="${a}" ${a === def.activity ? "selected" : ""}>${esc(a)}</option>`).join("")}</select></div><div><label>${t("hours.section")}</label><select id="section">${sections.map((s) => `<option value="${s.id}" ${s.id === def.section_id ? "selected" : ""}>${esc(s.name)}</option>`).join("")}</select></div></div>
      <h2>${t("hours.budget")}</h2><div id="budget">${budgetHtml(def.subproject_id)}</div>
      <div class="row2"><div><label>${t("hours.normal")}</label><input id="normal" type="number" step="0.5" min="0" value="8"></div><div><label>${t("hours.ot")}</label><input id="ot" type="number" step="0.5" min="0" value="0"></div></div>
      <div class="row2"><div><label>${t("hours.hol")}</label><input id="hol" type="number" step="0.5" min="0" value="0"></div><div><label>${t("hours.travel")}</label><input id="travel" type="number" step="0.5" min="0" value="0"></div></div>
      <p class="muted">${t("hours.calhint")}${cal.start ? ` (${cal.start}–${cal.end})` : ""}</p>
      <label>${t("hours.note")}</label><input id="note" value="">
      <div id="warn" class="note hidden">${t("hours.over")}</div>
      <button class="btn gold" id="save">${t("hours.save")}</button>
      <button class="btn gold hidden" id="extra">${t("hours.extra")}</button>`, bind(root) {
      const $ = (s) => root.querySelector(s);
      $("#vw").onclick = () => { hView = "week"; UI.render(); };
      const check = () => {
        const sub = +$("#sub").value, sec = +$("#section").value; const b = budgets.find((x) => x.subproject_id === sub); const s = b && b.sections.find((x) => x.id === sec);
        const h = (+$("#normal").value || 0) + (+$("#ot").value || 0) + (+$("#hol").value || 0); const over = $("#activity").value === "engineering" && s && s.booked + h > s.budget + s.extra;
        $("#warn").classList.toggle("hidden", !over); $("#save").classList.toggle("hidden", !!over); $("#extra").classList.toggle("hidden", !over);
        if (over) $("#extra").textContent = `${t("hours.extra")} (${h} h)`;
        if ($("#date").value && (cal.holidays || []).includes($("#date").value) && +$("#normal").value) { $("#hol").value = $("#normal").value; $("#normal").value = 0; }
      };
      $("#project").onchange = () => { $("#sub").innerHTML = subOptions(+$("#project").value, null, true); $("#budget").innerHTML = budgetHtml($("#sub").value); check(); };
      $("#sub").onchange = () => { $("#budget").innerHTML = budgetHtml($("#sub").value); check(); };
      ["#section", "#normal", "#ot", "#hol", "#date", "#activity"].forEach((s) => $(s).oninput = check); check();
      const entry = () => ({ date: $("#date").value, project_id: +$("#project").value || null, subproject_id: +$("#sub").value || null, section_id: +$("#section").value || null, activity: $("#activity").value, normal: +$("#normal").value || 0, overtime: +$("#ot").value || 0, holiday: +$("#hol").value || 0, travel: +$("#travel").value || 0, note: $("#note").value.trim() });
      $("#save").onclick = async () => { const e = entry(); if (!e.project_id) return UI.toast(t("rep.needproject")); if (!(e.normal + e.overtime + e.holiday + e.travel)) return UI.toast(t("hours.nohours")); const id = DB.uuid(); await DB.put("timesheets", { id, ...e, status: "queued" }); await DB.enqueue("timesheet", e, id); SYNC.run(false); UI.toast(t("hours.saved")); hView = "week"; UI.render(); };
      $("#extra").onclick = () => UI.sheet(`<h3>${t("hours.extra")}</h3><label>${t("hours.reason")}</label><select id="reason">${(ctx.master.reasons || ["misjudgement", "miscalculation"]).map((r) => `<option value="${r}">${t("hours." + (r === "misjudgement" ? "misjudge" : "miscalc"))}</option>`).join("")}</select><label>${t("hours.note")}</label><textarea id="rn"></textarea><button class="btn gold" id="ok">${t("hours.extra")}</button>`, async (sh) => {
        const e = entry(); await DB.enqueue("extra_hours", { subproject_id: e.subproject_id, section_id: e.section_id, hours: e.normal + e.overtime + e.holiday, reason: sh.querySelector("#reason").value, note: sh.querySelector("#rn").value.trim(), date: e.date }); SYNC.run(false); UI.closeSheet(); UI.toast(t("hours.sent"));
      });
    } };
  }

  // ---------------------------------------------------------------- S-11 weekly notes (portal sp_notes: per sub-project, internal / external = visible to the Owner)
  async function notes(q) {
    UI.chrome(true, t("notes.title"), "more", true);
    const all = (await DB.all("notes")).sort((a, b) => b.date.localeCompare(a.date));
    if (q.new !== undefined) {
      const def = { project_id: +q.project || (ctx.projects[0] || {}).id, subproject_id: +q.sub || null, visibility: "external" };
      return { html: `<label>${t("hours.project")}</label><select id="project">${projOptions(def.project_id).replace('<option value="">—</option>', "")}</select><label>${t("hours.sub")}</label><select id="sub">${subOptions(def.project_id, def.subproject_id, true)}</select>
        <label>${t("fsr.date")}</label><input id="date" type="date" value="${today()}">
        <h2>${t("notes.vis")}</h2><div class="seg" data-k="vis"><button data-v="internal">${t("notes.int")}</button><button data-v="external" class="on">${t("notes.cust")}</button></div>
        <label>${t("notes.text")}</label><textarea id="tx"></textarea><h2>${t("fsr.photos")}</h2><div class="photos" id="ph"></div><button class="btn gold" id="save">${t("notes.save")}</button>`, bind(root) {
        UI.segs(root); root.querySelector("#project").onchange = (e) => { root.querySelector("#sub").innerHTML = subOptions(+e.target.value, null, true); }; UI.photos.init(root.querySelector("#ph"));
        root.querySelector("#save").onclick = async () => { const id = DB.uuid(); const n = { id, date: root.querySelector("#date").value, project_id: +root.querySelector("#project").value, subproject_id: +root.querySelector("#sub").value || null, visibility: root.querySelector(".seg .on").dataset.v, text: root.querySelector("#tx").value.trim(), photos: UI.photos.count(root.querySelector("#ph")), status: "queued" };
          if (!n.subproject_id) return UI.toast(t("notes.needsub")); if (!n.text && !n.photos) return; await UI.photos.store(root.querySelector("#ph"), id); await DB.put("notes", n); await DB.enqueue("note", n, id); SYNC.run(false); UI.toast(t("common.ok")); location.hash = "#/notes"; };
      } };
    }
    return { html: `<button class="btn gold" data-go="#/notes?new">${t("notes.new")}</button>${all.map((n) => { const s = findSub(n.subproject_id); return `<div class="card ${n.visibility === "external" ? "" : "c-grey"}"><div class="t">${fmt(n.date)} • ${esc(s ? `${s.project.code} ${s.code}` : "")} <small>${weekOf(n.date)} • ${t(n.visibility === "external" ? "notes.cust" : "notes.int")}</small></div><div class="s">${esc(n.text)}</div><div class="s muted">${esc(s ? s.name : "")} • ${n.photos || 0} ${t("fsr.photos").toLowerCase()}${n.status === "queued" ? ' • <span class="chip amber">queued</span>' : ""}</div></div>`; }).join("") || `<p class="list-empty">—</p>`}`, bind: UI.goLinks };
  }
  // ---------------------------------------------------------------- S-12 documents (read-only, streamed by the portal with the project scope check)
  async function docs() {
    UI.chrome(true, t("docs.title"), "more", true);
    const all = await DB.all("documents"); const byP = {}; all.forEach((d) => { (byP[d.project_id] = byP[d.project_id] || []).push(d); });
    return { html: Object.keys(byP).map((pid) => `<h2>${esc(projName(pid) || pid)}</h2>${byP[pid].map((d) => `<div class="card tap" data-doc="${d.id}"><div class="t">${esc(d.category)} <small>${esc(d.size)}</small></div><div class="s">${esc(d.name)}</div><div class="s muted">${esc(d.subproject_id ? subName(d.subproject_id) : "")}</div></div>`).join("")}`).join("") || `<p class="list-empty">—</p>`,
      bind(root) { root.querySelectorAll("[data-doc]").forEach((c) => c.onclick = () => UI.openDocument(+c.dataset.doc)); } };
  }
  // ---------------------------------------------------------------- S-15 / S-16 equipment
  let eqMode = "sys", eqVessel = null, eqQ = "";
  async function equipment(q) {
    const all = ctx.equipment; eqVessel = q.vessel ? +q.vessel : (eqVessel || (all[0] || {}).vessel_id);
    if (q.id) {
      const e = all.find((x) => x.id === +q.id); if (!e) return { html: "—" };
      UI.chrome(true, e.tag, "more", true);
      return { html: `<div class="kv"><b>Tag / ${t("ticket.system")}</b><span class="v">${esc(e.tag)} • ${esc(e.system)}</span></div><div class="kv"><b>Maker / Model</b><span class="v">${esc(e.maker)} ${esc(e.model)}</span></div><div class="kv"><b>Kind / Serial</b><span class="v">${esc(e.kind)} • ${esc(e.serial)}</span></div><div class="kv"><b>Location</b><span class="v">${esc(e.location)}</span></div><div class="kv"><b>Installed / Warranty</b><span class="v">${esc(e.installed)} • ${esc(e.warranty)}</span></div><div class="kv"><b>${t("hours.status")}</b><span class="v" style="color:${e.status === "open" ? "var(--red)" : "var(--green)"}">${e.status === "open" ? "In service — open ticket" : "In service"}${e.last_service ? ` • last service ${fmt(e.last_service)}` : ""}</span></div>
        <h2>${t("equip.docs")}</h2>${(e.docs || []).map((d) => `<div class="card tap" data-doc="${esc(d.id || "")}"><div class="s">${esc(d.name || d)}</div></div>`).join("") || "<p class='muted'>—</p>"}
        <h2>${t("equip.hist")}</h2>${(e.history || []).map((h) => `<div class="card ${/open|fault/i.test(h) ? "c-red" : "c-green"}"><div class="s">${esc(h)}</div></div>`).join("") || "<p class='muted'>—</p>"}
        <div class="row2"><button class="btn navy" data-go="#/fsr/new?equipment=${e.id}&vessel=${e.vessel_id}">${t("equip.use")}</button><button class="btn navy" data-go="#/fsr/new?kind=inspection&equipment=${e.id}&vessel=${e.vessel_id}">${t("rep.newins")}</button></div><button class="btn line" id="corr">${t("equip.correct")}</button><button class="btn line" id="ai">✦ ${t("ai.title")}</button>`, bind(root) {
        UI.goLinks(root); root.querySelectorAll("[data-doc]").forEach((c) => c.onclick = () => c.dataset.doc && UI.openDocument(+c.dataset.doc));
        const ectx = { page: "equipment_edit", title: `Equipment ${e.tag}`, headline: `${e.maker} ${e.model}`, fields: () => [{ name: "system", label: "System", value: e.system }, { name: "maker", label: "Maker", value: e.maker }, { name: "model", label: "Model", value: e.model }, { name: "serial", label: "Serial", value: e.serial }, { name: "location", label: "Location", value: e.location }, { name: "history", label: "Service history", value: (e.history || []).join("\n").slice(0, 1500) }].map((f) => ({ ...f, type: "text" })), noFill: true };
        root.querySelector("#ai").onclick = () => ASSIST.open(ectx); UI.assistContext(ectx);
        root.querySelector("#corr").onclick = () => UI.sheet(`<h3>${t("equip.correct")}</h3><label>${t("equip.field")}</label><select id="fld"><option value="serial">Serial</option><option value="location">Location</option><option value="model">Model</option><option value="maker">Maker</option></select><label>${t("equip.new")}</label><input id="nv"><div class="photos" id="ph"></div><button class="btn gold" id="ok">${t("equip.send")}</button>`, async (sh) => {
          const id = DB.uuid(); await UI.photos.store(sh.querySelector("#ph"), id); const fld = sh.querySelector("#fld").value; await DB.enqueue("equipment_correction", { equipment_id: e.id, field: fld, old_value: e[fld], new_value: sh.querySelector("#nv").value.trim() }, id); SYNC.run(false); UI.closeSheet(); UI.toast(t("common.ok"));
        }, (sh) => UI.photos.init(sh.querySelector("#ph")));
      } };
    }
    UI.chrome(true, t("equip.title"), "more", true);
    let list = all.filter((x) => x.vessel_id === eqVessel); if (eqQ) list = list.filter((x) => `${x.tag} ${x.system} ${x.maker} ${x.model} ${x.location} ${x.serial}`.toLowerCase().includes(eqQ.toLowerCase()));
    UI.badge(`${list.length} items`);
    const groups = {}; list.forEach((x) => { const k = eqMode === "loc" ? String(x.location || "—").split(",")[0] : x.system || "—"; (groups[k] = groups[k] || []).push(x); });
    return { html: `<label>${t("fsr.vessel")}</label><select id="vessel">${ctx.vessels.map((v) => `<option value="${v.id}" ${v.id === eqVessel ? "selected" : ""}>${esc(v.name)} — ${esc(v.yard)}</option>`).join("")}</select>
      <div class="seg"><button data-m="sys" class="${eqMode === "sys" ? "on" : ""}">${t("equip.bysys")}</button><button data-m="loc" class="${eqMode === "loc" ? "on" : ""}">${t("equip.byloc")}</button></div><input class="search" id="q" placeholder="${t("equip.search")}" value="${esc(eqQ)}">
      ${Object.keys(groups).sort().map((g) => `<h2>${esc(g)}</h2>${groups[g].map((x) => `<div class="card tap ${x.status === "open" ? "c-red" : "c-green"}" data-go="#/equipment?id=${x.id}"><div class="t">${esc(x.tag)} • ${esc(x.maker)} ${esc(x.model)} <small>${x.status === "open" ? "OPEN" : "OK"}</small></div><div class="s">${esc(x.location)} • SN ${esc(x.serial)}</div><div class="s muted">${(x.history || []).slice(-1)[0] ? esc(x.history.slice(-1)[0]) : "No open tickets"}</div></div>`).join("")}`).join("") || `<p class="list-empty">—</p>`}`,
      bind(root) { UI.goLinks(root); root.querySelector("#vessel").onchange = (e) => { eqVessel = +e.target.value; UI.render(); }; root.querySelectorAll(".seg button").forEach((b) => b.onclick = () => { eqMode = b.dataset.m; UI.render(); }); let tm; root.querySelector("#q").oninput = (e) => { eqQ = e.target.value; clearTimeout(tm); tm = setTimeout(() => { UI.render(); }, 250); }; } };
  }
  // ---------------------------------------------------------------- S-14 more / settings
  async function more() {
    UI.chrome(true, t("tab.more"), "more");
    const ob = await DB.outbox();
    return { html: `<div class="card tap" data-go="#/notes"><div class="t">${t("more.notes")}</div></div><div class="card tap" data-go="#/docs"><div class="t">${t("more.docs")}</div></div><div class="card tap" data-go="#/equipment"><div class="t">${t("more.equip")}</div></div><div class="card tap c-gold" id="ai"><div class="t">✦ ${t("ai.title")}</div><div class="s muted">${ctx.me.ai || API.mock ? t("ai.help") : t("ai.disabled")}</div></div>
      <h2>${t("more.settings")}</h2>
      <div class="kv"><b>${t("set.account")}</b><span class="v">${esc(ctx.me.name)} • ${esc(ctx.me.discipline)}</span></div><div class="kv"><b>${t("set.branch")}</b><span class="v">${esc(ctx.me.home_branch)} ${ctx.me.home_branch === "GIT" ? "— GOLDEN AS ITALIAN" : "— Golden A/S Keratsini"}</span></div>
      <div class="kv"><b>${t("set.version")}</b><span class="v">${API.VERSION}${API.mock ? " (demo)" : ""}${ctx.me.min_app_version ? ` • portal min ${esc(ctx.me.min_app_version)}` : ""}</span></div><div class="kv"><b>${t("more.queue")}</b><span class="v">${ob.length}${ob.filter((o) => o.status === "rejected").length ? ` (${ob.filter((o) => o.status === "rejected").length} rejected)` : ""}</span></div>
      <label>${t("set.lang")}</label><div class="lang">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}" class="${I18N.get() === c ? "on" : ""}">${l}</button>`).join("")}</div>
      ${ob.filter((o) => o.status === "rejected").map((o) => `<div class="note red">${esc(o.kind)} — ${esc(o.error)} <button class="btn red sm" data-drop="${o.id}">Discard</button></div>`).join("")}
      <button class="btn navy" id="sync">${t("more.sync")}</button><button class="btn line" id="clear">${t("set.clear")}</button><button class="btn line" id="out">${t("more.signout")}</button>`, bind(root) {
      UI.goLinks(root); root.querAll = null; root.querySelectorAll(".lang button").forEach((b) => b.onclick = () => { I18N.set(b.dataset.l); I18N.apply(); UI.render(); });
      root.querySelector("#sync").onclick = () => SYNC.run().then(() => UI.render());
      root.querySelector("#ai").onclick = () => ASSIST.open({ page: "fsr_edit", title: "Service Engineer app — help", headline: "How do I…", fields: () => [], noFill: true });
      root.querySelector("#clear").onclick = async () => { if (window.caches) await caches.delete("gas-service-docs"); UI.toast(t("common.ok")); };
      root.querySelectorAll("[data-drop]").forEach((b) => b.onclick = async () => { await DB.del("outbox", b.dataset.drop); UI.render(); });
      root.querySelector("#out").onclick = async () => { await API.logout(); await DB.wipe(); location.hash = "#/login"; };
    } };
  }

  return { load, ctx, esc, fmt, today, subName, subCode, projName, ticketNo, subOptions, projOptions, vesselName, stChip, login, home, assign, tickets, ticket, fsrList, fsrEdit, hours, notes, docs, equipment, more };
})();
