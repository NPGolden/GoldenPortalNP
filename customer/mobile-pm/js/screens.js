/* Golden PM — screens: login, home, projects, project tree, sub-project, approvals (drawings / submittals), weekly notes, documents, inbox, sync log, more.
 * Each screen is an async function returning { html, bind(root) }; data comes from the IndexedDB read model (SYNC.pull), writes go to the outbox. */
window.SCREENS = (() => {
  const t = I18N.t;
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const fmt = (iso) => I18N.date(iso);
  const today = () => new Date().toISOString().slice(0, 10);
  const weekOf = (iso) => { const d = new Date(iso); const day = (d.getDay() + 6) % 7; d.setDate(d.getDate() - day + 3); const y1 = new Date(d.getFullYear(), 0, 4); return `W${String(1 + Math.round(((d - y1) / 86400000 - 3 + ((y1.getDay() + 6) % 7)) / 7)).padStart(2, "0")}`; };
  const firstName = (n) => String(n || "").split(" ")[0];
  const pstChip = (s) => `<span class="chip ${s === "active" ? "green" : s === "quoted" ? "amber" : s === "onhold" ? "red" : s === "completed" ? "navy" : "grey"}">${esc(I18N.has("pst." + s) ? t("pst." + s) : String(s).replace(/_/g, " "))}</span>`;
  const sstChip = (s) => `<span class="chip ${s === "approved" ? "green" : s === "approved_comments" ? "green" : s === "rejected" ? "red" : s === "submitted" || s === "resubmitted" ? "amber" : "grey"}">${esc(I18N.has("sst." + s) ? t("sst." + s) : s)}</span>`;
  const dstChip = (s) => `<span class="chip ${s === "received" ? "green" : s === "pending" ? "amber" : "grey"}">${esc(t("appr." + s))}</span>`;

  // ---------------------------------------------------------------- data helpers
  const ctx = {};
  async function load() {
    ctx.me = (await DB.get("meta", "me")) || {}; ctx.staff = ((await DB.get("meta", "staff")) || {}).list || []; ctx.stages = ((await DB.get("meta", "stages")) || {}).list || SYNC.STAGES;
    ctx.projects = (await DB.all("projects")).sort((a, b) => String(a.code).localeCompare(String(b.code)));
  }
  const project = (id) => ctx.projects.find((p) => p.id === +id);
  const projName = (id) => { const p = project(id); return p ? `${p.code} — ${p.name}` : ""; };
  const findSub = (id) => { for (const p of ctx.projects) { const s = (p.subprojects || []).find((x) => x.id === +id); if (s) return { ...s, project: p }; } return null; };
  const subName = (id) => { const s = findSub(id); return s ? `${s.code} — ${s.name}` : id ? `#${id}` : ""; };
  const staffName = (id) => (ctx.staff.find((s) => s.id === +id) || {}).name || `#${id}`;
  const subOptions = (projectId, selected) => { const p = project(projectId); return `<option value="">—</option>` + (p ? p.subprojects.map((s) => `<option value="${s.id}" ${s.id === +selected ? "selected" : ""}>${esc(s.code)} — ${esc(s.name)}</option>`).join("") : ""); };
  const projOptions = (selected) => ctx.projects.map((p) => `<option value="${p.id}" ${p.id === +selected ? "selected" : ""}>${esc(p.code)} — ${esc(p.name)}</option>`).join("");
  const subRow = (s, compactOnly) => `<div class="card tap prow ${s.conflict ? "c-red" : s.queued ? "c-amber" : s.overall >= 100 ? "c-green" : s.overall > 0 ? "c-gold" : "c-grey"}" data-go="#/sub/${s.id}">
      <div class="t"><span><span class="code">${esc(s.code)}</span> ${esc(s.name)}</span>${UI.opill(s.overall)}</div>
      ${UI.processLine(s, true)}
      <div class="chips">${s.system ? `<span class="chip grey">${esc(s.system)}</span>` : ""}${s.issues_open ? `<span class="chip red">${s.issues_open} ${t("proj.issues")}</span>` : ""}${s.deadline ? `<span class="chip ${s.deadline < today() && s.overall < 100 ? "red" : ""}">${t("sub.deadline")} ${fmt(s.deadline)}</span>` : ""}${s.queued ? `<span class="chip amber">${t("sub.queued")}</span>` : ""}${s.conflict ? `<span class="chip red">${t("sub.conflict")}</span>` : ""}${compactOnly ? "" : `<span class="chip">${esc(s.project.code)}</span>`}</div></div>`;

  // ---------------------------------------------------------------- login (TOTP field shown when the server answers 401 {totp:true})
  async function login() {
    UI.chrome(false);
    return { html: `<div class="login">
      <img src="img/logo.png" alt="Golden A/S">
      <h2>${t("login.title")} — Golden PM</h2>
      <label>${t("login.portal")}</label><input readonly value="goldensas.com/customer${API.mock ? " (demo data)" : ""}">
      <label>${t("login.email")}</label><input id="email" type="email" autocomplete="username" value="${API.mock ? "np@goldenas.com.gr" : ""}">
      <label>${t("login.password")}</label><input id="pw" type="password" autocomplete="current-password" value="${API.mock ? "demo" : ""}">
      <div id="totprow" class="hidden"><label>${t("login.totp")}</label><input id="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
      <p id="err" class="note red hidden"></p>
      <button class="btn gold" id="go">${t("login.signin")}</button>
      <button class="btn line" id="bio" ${API.signedIn ? "" : "disabled"}>${t("login.bio")}</button>${API.signedIn ? "" : (API.passkeyCapable ? `<button class="btn line" id="pkin">🔑 ${t("login.passkey")}</button>` : `<p class="muted" style="text-align:center;margin-top:-6px">${t("login.bio_hint")}</p>`)}
      <h2>${t("login.lang")}</h2><div class="lang">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}" class="${I18N.get() === c ? "on" : ""}">${l}</button>`).join("")}</div>
      <p class="muted" style="text-align:center;margin-top:16px">${ctx.me.home_branch || "GR"} • Golden A/S ${ctx.me.home_branch === "GIT" ? "Italian Branch" : "head office"} • Golden PM v${API.VERSION}</p>
    </div>`, bind(root) {
      root.querySelectorAll(".lang button").forEach((b) => b.onclick = () => { I18N.set(b.dataset.l); UI.render(); });
      root.querySelector("#go").onclick = async () => {
        const b = root.querySelector("#go"); b.disabled = true; const er = root.querySelector("#err");
        try { await API.login(root.querySelector("#email").value.trim(), root.querySelector("#pw").value, root.querySelector("#totp").value.trim()); await SYNC.run(); location.hash = "#/home"; }
        catch (e) { const d = e.data || {}; const totp = d.totp === true || d.code === "totp_required";
          if (totp) { root.querySelector("#totprow").classList.remove("hidden"); er.textContent = root.querySelector("#totp").value ? t("login.totpbad") : t("login.totphint"); root.querySelector("#totp").focus(); }
          else er.textContent = e.status === 402 ? t("login.nolicence") : e.status === 403 ? (d.error || t("login.pmonly")) : e.status === 423 ? t("login.locked") : e.status === 426 ? t("login.update") : e.status === 429 ? t("login.ratelimit") : e.status === 401 ? t("login.failed") : `${t("login.failed")} (${e.status || "offline"})`; er.classList.remove("hidden"); b.disabled = false; }
      };
      root.querySelector("#bio").onclick = async () => { if (await UI.biometric()) { await SYNC.run(); location.hash = "#/home"; } };
      const pkb = root.querySelector("#pkin"); if (pkb) pkb.onclick = async () => { pkb.disabled = true; try { await API.passkeyLogin(); await SYNC.run(); location.hash = "#/home"; } catch (e) { root.querySelector("#err").textContent = e && e.name === "NotAllowedError" ? t("pk.failed") : (e.data && e.data.error) || e.message || t("pk.failed"); root.querySelector("#err").classList.remove("hidden"); pkb.disabled = false; } };
    } };
  }

  // ---------------------------------------------------------------- home
  async function home() {
    UI.chrome(true, "Golden PM", "home");
    const me = ctx.me; const c = me.counts || {}; const team = (await DB.get("team", "team")) || { requests: [], timesheets: [] };
    const drawings = await DB.all("drawings"); const overdue = drawings.filter((d) => d.status === "pending" && d.overdue_days > 0).sort((a, b) => b.overdue_days - a.overdue_days);
    const reqs = (team.requests || []).filter((r) => r.decidable && !r.deciding); const ts = (team.timesheets || []).filter((x) => !x.deciding);
    const nSub = ctx.projects.reduce((a, p) => a + (p.subprojects || []).length, 0);
    const mine = ctx.projects.filter((p) => p.mine); const inbox = (await DB.all("inbox")).filter((n) => !n.read).sort((a, b) => String(b.created_at).localeCompare(String(a.created_at))).slice(0, 3);
    const needs = [
      ...reqs.map((r) => `<div class="card tap c-gold" data-go="#/team?tab=requests"><div class="t">${esc(r.person)} <small>${t("home.leave_req")}</small></div><div class="s">${esc(r.code)} ${fmt(r.date_from)}${r.date_to !== r.date_from ? " – " + fmt(r.date_to) : ""} • ${r.unit === "hour" ? r.hours + " h" : r.days + " " + t("hr.days")}</div></div>`),
      ...ts.map((x) => `<div class="card tap c-gold" data-go="#/team?tab=timesheets"><div class="t">${esc(x.user)} <small>${t("home.ts_appr")}</small></div><div class="s">${esc(x.ts_no)} • ${esc(x.project_code)} • ${x.hours_total} h</div></div>`),
      ...overdue.map((d) => `<div class="card tap c-red" data-go="#/approvals?tab=drawings&f=overdue"><div class="t">${esc(d.title)} <small>${t("appr.days_over", { n: d.overdue_days })}</small></div><div class="s">${esc((project(d.project_id) || {}).code || "")} ${esc((findSub(d.subproject_id) || {}).code || "")} • ${esc(d.responsible || "")}</div></div>`),
    ];
    return { html: `
      <div class="greet">${esc(t("home.hello", { name: firstName(me.name) }))}<small>${esc(t("home.sub", { n: nSub, p: ctx.projects.length }))} • ${fmt(today())}</small></div>
      <div class="kpi">
        <div data-go="#/projects"><b>${nSub}</b><span>${t("home.kpi_sub")}</span></div>
        <div data-go="#/approvals?tab=drawings&f=overdue"><b class="${overdue.length ? "red" : ""}">${c.drawings_overdue != null ? c.drawings_overdue : overdue.length}</b><span>${t("home.kpi_dr")}</span></div>
        <div data-go="#/approvals?tab=submittals"><b class="gold">${c.submittals_open != null ? c.submittals_open : (await DB.all("submittals")).filter((s) => s.open).length}</b><span>${t("home.kpi_ap")}</span></div>
        <div data-go="#/team"><b class="${reqs.length + ts.length ? "gold" : "green"}">${reqs.length + ts.length}</b><span>${t("home.kpi_team")}</span></div>
      </div>
      <h2>${t("home.needs")} (${needs.length})</h2>${needs.join("") || `<p class="muted">${t("home.nothing")}</p>`}
      <div class="sec-h"><h2>${t("home.myprojects")} (${mine.length})</h2><a href="#/projects">${t("home.allprojects")} ›</a></div>
      ${mine.map((p) => `<div class="card tap prow ${p.status === "active" ? "" : "c-amber"}" data-go="#/project/${p.id}"><div class="t"><span><span class="code">${esc(p.code)}</span> ${esc(p.vessel || p.name)}</span>${UI.opill(p.overall)}</div><div class="sub">${esc(p.name)}</div><div class="chips"><span class="chip gold">${esc(p.branch)}</span>${pstChip(p.status)}<span class="chip">${(p.counts || {}).subprojects || (p.subprojects || []).length} ${t("proj.sub_n")}</span>${(p.counts || {}).issues_open ? `<span class="chip red">${p.counts.issues_open} ${t("proj.issues")}</span>` : ""}${(p.counts || {}).drawings_overdue ? `<span class="chip red">${p.counts.drawings_overdue} ${t("proj.overdue")}</span>` : ""}</div></div>`).join("") || `<p class="muted">—</p>`}
      <div class="sec-h"><h2>${t("home.inbox")}</h2><a href="#/inbox">${t("common.all")} ›</a></div>
      <div class="inbox">${inbox.map((n) => `<div class="card unread tap" data-go="#/inbox"><div class="t">${esc(n.title)} <small>${esc(String(n.created_at).slice(0, 10) === today() ? String(n.created_at).slice(11, 16) : fmt(n.created_at))}</small></div><div class="s muted">${esc(n.body)}</div></div>`).join("") || `<p class="muted">${t("inbox.none")}</p>`}</div>`, bind: UI.goLinks };
  }

  // ---------------------------------------------------------------- projects list
  let pQ = "", pBranch = "", pStatus = "";
  async function projects(q) {
    UI.chrome(true, t("tab.projects"), "projects");
    const branches = [...new Set(ctx.projects.map((p) => p.branch))].sort();
    let list = ctx.projects.filter((p) => (!pBranch || p.branch === pBranch) && (!pStatus || (pStatus === "mine" ? p.mine : p.status === pStatus)));
    if (pQ) { const s = pQ.toLowerCase(); list = list.filter((p) => `${p.code} ${p.name} ${p.vessel} ${p.yard} ${p.customer} ${p.pm}`.toLowerCase().includes(s)); }
    UI.badge(`${list.length}`);
    return { html: `<input class="search" id="q" placeholder="${t("proj.search")}" value="${esc(pQ)}">
      <div class="chips">${[["", t("proj.allbranches")]].concat(branches.map((b) => [b, b])).map(([v, l]) => `<span class="chip tap ${pBranch === v ? "navy" : ""}" data-br="${v}">${esc(l)}</span>`).join("")}</div>
      <div class="chips">${[["", t("common.all")], ["mine", t("proj.mine")], ["active", t("proj.active")], ["quoted", t("proj.quoted")], ["onhold", t("proj.onhold")]].map(([v, l]) => `<span class="chip tap ${pStatus === v ? "navy" : ""}" data-st="${v}">${esc(l)}</span>`).join("")}</div>
      ${list.map((p) => `<div class="card tap prow ${p.status === "active" ? (p.mine ? "" : "c-grey") : "c-amber"}" data-go="#/project/${p.id}"><div class="t"><span><span class="code">${esc(p.code)}</span> ${esc(p.vessel || p.name)}</span>${UI.opill(p.overall)}</div><div class="sub">${esc(p.name)}</div><div class="s muted">${esc(p.yard || "")}${p.customer ? " • " + esc(p.customer) : ""} • ${t("proj.pm")} ${esc(p.pm || "")}</div>
        <div class="chips"><span class="chip gold">${esc(p.branch)}</span>${pstChip(p.status)}<span class="chip">${(p.counts || {}).subprojects || (p.subprojects || []).length} ${t("proj.sub_n")}</span>${(p.counts || {}).issues_open ? `<span class="chip red">${p.counts.issues_open} ${t("proj.issues")}</span>` : ""}${(p.counts || {}).drawings_overdue ? `<span class="chip red">${p.counts.drawings_overdue} ${t("proj.overdue")}</span>` : ""}${(p.counts || {}).submittals_open ? `<span class="chip amber">${p.counts.submittals_open} ${t("appr.submittals").toLowerCase()}</span>` : ""}</div></div>`).join("") || `<p class="list-empty">${t("proj.none")}</p>`}`,
      bind(root) { UI.goLinks(root); let tm; root.querySelector("#q").oninput = (e) => { pQ = e.target.value; clearTimeout(tm); tm = setTimeout(() => { UI.render(); root.querySelector("#q") && root.querySelector("#q").focus(); }, 250); };
        root.querySelectorAll("[data-br]").forEach((c) => c.onclick = () => { pBranch = c.dataset.br; UI.render(); }); root.querySelectorAll("[data-st]").forEach((c) => c.onclick = () => { pStatus = c.dataset.st; UI.render(); }); } };
  }

  // ---------------------------------------------------------------- project tree: main → sub-projects by parent_code
  async function projectScreen(id) {
    const p = project(id); if (!p) return { html: `<p class="list-empty">—</p>` };
    UI.chrome(true, p.code, "projects", true); UI.badge(p.branch);
    const subs = (p.subprojects || []).slice().sort((a, b) => String(a.code).localeCompare(String(b.code), undefined, { numeric: true }));
    const roots = subs.filter((s) => !s.parent_code || !subs.some((x) => x.code === s.parent_code)); const kids = (s) => subs.filter((x) => x.parent_code === s.code);
    const c = p.counts || {};
    return { html: `
      <div class="card ${p.status === "active" ? "" : "c-amber"}"><div class="t"><span>${esc(p.vessel || p.name)}</span>${UI.opill(p.overall)}</div><div class="s">${esc(p.name)}</div>
        <div class="kv"><b>${t("proj.yard")}</b><span class="v">${esc(p.yard || "—")}</span></div><div class="kv"><b>${t("proj.customer")}</b><span class="v">${esc(p.customer || "—")}</span></div><div class="kv"><b>${t("proj.pm")}</b><span class="v">${esc(p.pm || "—")}</span></div>
        <div class="chips">${pstChip(p.status)}<span class="chip gold">${esc(p.branch)}</span>${c.issues_open ? `<span class="chip red">${c.issues_open} ${t("proj.issues")}</span>` : ""}</div></div>
      <div class="row2"><button class="btn line sm" data-go="#/approvals?tab=drawings&project=${p.id}">${t("proj.drawings")}${c.drawings_overdue ? ` (${c.drawings_overdue} ${t("proj.overdue")})` : ""}</button><button class="btn line sm" data-go="#/approvals?tab=submittals&project=${p.id}">${t("proj.submittals")}${c.submittals_open ? ` (${c.submittals_open})` : ""}</button></div>
      <div class="row2"><button class="btn line sm" data-go="#/notes?project=${p.id}">${t("proj.notes")}</button><button class="btn line sm" data-go="#/team?tab=away&project=${p.id}">${t("proj.away")}</button></div>
      <h2>${t("proj.tree")} (${subs.length})</h2>
      <div class="tree">${roots.map((s) => subRow(s, true) + kids(s).map((k) => `<div class="lvl2">${subRow(k, true)}</div>`).join("")).join("") || `<p class="list-empty">—</p>`}</div>`, bind: UI.goLinks };
  }

  // ---------------------------------------------------------------- sub-project detail
  async function sub(id, q) {
    const base = findSub(id); if (!base) return { html: `<p class="list-empty">—</p>` };
    let sd = await DB.get("subs", +id);
    const canFetch = navigator.onLine || API.mock;
    if (!sd && canFetch) { try { sd = await SYNC.subproject(+id); sd = await DB.get("subs", +id); } catch (e) { UI.toast(e.message || t("sync.err")); } }
    else if (sd && canFetch && Date.now() - (sd.fetched_at || 0) > 120000) { SYNC.subproject(+id).then(() => { if (location.hash.startsWith(`#/sub/${id}`) && !document.querySelector("#sheet") && !document.activeElement.matches("textarea, input")) UI.render(); }).catch(() => {}); }
    const s = sd ? { ...sd.subproject, queued: base.queued, conflict: base.conflict, stages: base.queued ? base.stages : sd.subproject.stages, overall: base.queued ? base.overall : sd.subproject.overall } : base; const p = base.project;
    UI.chrome(true, `${p.code} ${s.code}`, "projects", true); UI.badge(p.branch);
    const issues = (sd && sd.issues) || []; const comments = (sd && sd.comments) || []; const notes = ((sd && sd.notes) || []).slice().sort((a, b) => String(b.date).localeCompare(String(a.date)));
    const drawings = (sd && sd.drawings) || (await DB.all("drawings")).filter((d) => d.subproject_id === +id); const subm = (sd && sd.submittals) || (await DB.all("submittals")).filter((d) => d.subproject_id === +id);
    const team = (sd && sd.team) || (s.team || []).map((uid) => ({ id: uid, name: staffName(uid) })); const abs = sd && sd.absences;
    const budgets = (sd && sd.budgets) || [];
    const budgetHtml = budgets.map((b) => { const over = b.booked > b.budget; const pct = Math.min(b.booked, b.budget) / Math.max(b.budget, b.booked, 1) * 100; const ex = over ? (b.booked - b.budget) / Math.max(b.budget, b.booked, 1) * 100 : 0; return `<div class="bar"><div class="l"><b>${esc(b.name)}${b.extra ? ` (+${b.extra} h)` : ""}</b><span>${b.booked} / ${b.budget} h</span></div><div class="tr"><i class="${over ? "over" : ""}" style="width:${pct}%"></i>${ex ? `<i class="extra" style="width:${ex}%"></i>` : ""}</div></div>`; }).join("");
    const issChip = (st) => `<span class="chip ${st === "closed" ? "green" : st === "in_progress" ? "amber" : "red"}">${t("iss." + st)}</span>`;
    return { html: `
      <div class="card ${s.conflict ? "c-red" : s.queued ? "c-amber" : s.overall >= 100 ? "c-green" : s.overall > 0 ? "c-gold" : "c-grey"}"><div class="t"><span>${esc(s.code)} — ${esc(s.name)}</span>${UI.opill(s.overall)}</div>
        <div class="s muted">${esc(p.vessel || p.name)}${s.system ? " • " + esc(s.system) : ""}${s.deadline ? ` • ${t("sub.deadline")} ${fmt(s.deadline)}` : ""}</div>
        <div class="chips">${pstChip(s.status || "active")}${s.queued ? `<span class="chip amber">${t("sub.queued")}</span>` : ""}${s.conflict ? `<span class="chip red">${t("sub.conflict")}</span>` : ""}</div>
        ${s.description ? `<div class="s">${esc(s.description)}</div>` : ""}</div>
      <h2>${t("sub.process")}</h2>${UI.processLine(s, false, true)}<p class="muted">${t("sub.set")}${(s.fed || []).length ? ` — ${(s.fed || []).map((k) => t("stage." + k)).join(", ")}: ${t("sub.fed").toLowerCase()}` : ""}</p>
      ${!sd ? `<p class="note">${t("sub.detail_offline")}</p>` : ""}
      <h2>${t("sub.issues")} (${issues.filter((i) => i.status !== "closed").length})</h2>
      ${issues.map((i) => `<div class="card tap ${i.status === "closed" ? "c-green" : i.priority === "high" || i.priority === "urgent" ? "c-red" : "c-amber"}" data-iss="${i.id}"><div class="t">${esc(i.title)} <small>${esc(String(i.priority || "").toUpperCase())}</small></div><div class="s">${esc(i.body || "")}</div><div class="s muted">${issChip(i.status)}${i.queued ? ` <span class="chip amber">${t("sub.queued")}</span>` : ""} ${esc(i.assignee || "")}${i.due_on ? ` • ${t("iss.due")} ${fmt(i.due_on)}` : ""} • ${t("iss.raised")} ${fmt(i.raised_on)} ${esc(i.raised_by || "")}</div></div>`).join("") || `<p class="muted">${t("sub.no_issues")}</p>`}
      <h2>${t("sub.team")}</h2><div class="chips">${team.map((m) => `<span class="chip">${esc(m.name)}${m.discipline ? ` <span class="muted">· ${esc(m.discipline)}</span>` : ""}</span>`).join("") || "—"}</div>
      ${abs ? `<h2>${t("sub.away")}</h2>${HR.matrixHtml(abs, true)}` : ""}
      <h2>${t("sub.comments")} (${comments.length})</h2>
      <div class="composer"><textarea id="ctx" placeholder="${t("sub.write")}" maxlength="4000"></textarea><button class="btn gold" id="csend">${t("sub.send")}</button></div>
      <div class="seg" data-k="vis"><button data-v="internal" class="on">${t("sub.internal")}</button><button data-v="external">${t("sub.external")}</button></div>
      ${comments.map((c) => `<div class="cmt ${c.visibility === "external" ? "ext" : ""}"><div class="m"><b>${esc(c.author)}</b><span>${esc(String(c.created_at).slice(0, 16))} • ${t(c.visibility === "external" ? "sub.external" : "sub.internal")}${c.queued ? ` • ${t("sub.queued")}` : ""}</span></div><div>${esc(c.body)}</div></div>`).join("")}
      <div class="sec-h"><h2>${t("sub.notes")} (${notes.length})</h2><a href="#/notes?new&project=${p.id}&sub=${s.id}">${t("sub.new_note")}</a></div>
      ${notes.map((n) => `<div class="card ${n.visibility === "external" ? "" : "c-grey"}"><div class="t">${fmt(n.date)} <small>${weekOf(n.date)} • ${t(n.visibility === "external" ? "notes.cust" : "notes.int")}</small></div><div class="s">${esc(n.text)}</div><div class="s muted">${esc(n.author || "")} • ${n.photos || 0} ${t("notes.photos")}</div></div>`).join("") || `<p class="muted">—</p>`}
      <h2>${t("sub.drawings")} (${drawings.length + subm.length})</h2>
      ${drawings.map((d) => `<div class="card tap ${d.status === "pending" && d.overdue_days > 0 ? "c-red" : d.status === "received" ? "c-green" : "c-grey"}" data-go="#/approvals?tab=drawings&project=${p.id}"><div class="t">${esc(d.title)} <small>${d.overdue_days > 0 && d.status === "pending" ? t("appr.days_over", { n: d.overdue_days }) : ""}</small></div><div class="s muted">${dstChip(d.status)} ${t("appr." + d.kind)} • ${t("appr.expected")} ${fmt(d.expected_at)}</div></div>`).join("")}
      ${subm.map((x) => `<div class="card tap ${x.open && x.overdue_days > 0 ? "c-red" : x.open ? "c-amber" : "c-green"}" data-go="#/approvals?tab=submittals&project=${p.id}"><div class="t">${esc(x.doc_no)} ${esc(x.title)} <small>${esc(x.revision)}</small></div><div class="s muted">${sstChip(x.status)} ${x.kind === "class" ? esc(x.society || "") : t("appr.owner")} • ${t("appr.due")} ${fmt(x.due_at)}</div></div>`).join("")}
      ${budgets.length ? `<h2>${t("sub.budgets")}</h2>${budgetHtml}` : ""}`, bind(root) {
      UI.goLinks(root); UI.segs(root);
      // stage stepper: tap a segment (not fed) → 0 / 25 / 50 / 75 / 100 / N/A → queue sp_stages
      root.querySelectorAll(".stepper .stp.tap").forEach((el) => el.onclick = () => { const k = el.dataset.stage; let val = s.stages[k] == null ? 0 : +s.stages[k];
        UI.sheet(`<h3>${t("stage." + k)} — ${esc(s.code)}</h3><div class="pct-opts">${[0, 25, 50, 75, 100].map((v) => `<button data-v="${v}" class="${val === v ? "on" : ""}">${v}%</button>`).join("")}<button data-v="-1" class="na ${val === -1 ? "on" : ""}">${t("sub.na")}</button></div><p class="muted">${t("sub.na_long")}</p><button class="btn gold" id="ok">${t("common.save")}</button>`, async () => {
          const stages = { ...s.stages, [k]: val }; const ov = SYNC.overall(stages);
          const pr = project(p.id); const ss = pr.subprojects.find((x) => x.id === s.id); ss.stages = stages; ss.overall = ov; ss.queued = true; delete ss.conflict; pr.overall = Math.round(pr.subprojects.reduce((a, x) => a + x.overall, 0) / pr.subprojects.length); await DB.put("projects", pr);
          if (sd) { sd.subproject.stages = stages; sd.subproject.overall = ov; await DB.put("subs", sd); }
          await DB.enqueue("sp_stages", { subproject_id: s.id, stages: { [k]: val } }); UI.closeSheet(); UI.toast(t("sub.saved")); await UI.render(); SYNC.run(false);
        }, (sh) => sh.querySelectorAll(".pct-opts button").forEach((b) => b.onclick = () => { val = +b.dataset.v; sh.querySelectorAll(".pct-opts button").forEach((x) => x.classList.toggle("on", x === b)); })); });
      // issue status
      root.querySelectorAll("[data-iss]").forEach((el) => el.onclick = () => { const i = issues.find((x) => x.id === +el.dataset.iss); let st = i.status;
        UI.sheet(`<h3>${esc(i.title)}</h3><p class="txt">${esc(i.body || "")}</p><h2>${t("sub.status")}</h2><div class="seg" data-k="st">${["open", "in_progress", "closed"].map((v) => `<button data-v="${v}" class="${st === v ? "on" : ""}">${t("iss." + v)}</button>`).join("")}</div><label>${t("iss.note")}</label><input id="inote" maxlength="500"><button class="btn gold" id="ok">${t("common.save")}</button>`, async (sh) => {
          if (st === i.status && !sh.querySelector("#inote").value.trim()) return UI.closeSheet(); i.status = st; i.queued = true; if (sd) { sd.subproject.issues_open = issues.filter((x) => x.status !== "closed").length; await DB.put("subs", sd); }
          await DB.enqueue("issue_status", { issue_id: i.id, subproject_id: s.id, status: st, note: sh.querySelector("#inote").value.trim() || undefined }); UI.closeSheet(); await UI.render(); SYNC.run(false);
        }, (sh) => UI.segs(sh, (k, v) => { st = v; })); });
      // comment composer (internal by default — R57-003)
      root.querySelector("#csend").onclick = async () => { const body = root.querySelector("#ctx").value.trim(); if (!body) return; const vis = root.querySelector('.seg[data-k="vis"] .on').dataset.v; const uuid = DB.uuid();
        if (sd) { sd.comments = [{ id: uuid, author: ctx.me.name, author_id: ctx.me.uid, visibility: vis, body, created_at: new Date().toISOString().slice(0, 16).replace("T", " "), parent_id: null, queued: true }].concat(sd.comments || []); await DB.put("subs", sd); }
        await DB.enqueue("sp_comment", { subproject_id: s.id, body, visibility: vis }, uuid); root.querySelector("#ctx").value = ""; UI.toast(t("common.ok")); await UI.render(); SYNC.run(false); };
      UI.assistContext({ page: "ticket", title: `${p.code} ${s.code} — ${s.name}`, headline: s.name, fields: () => [{ name: "stages", label: "Stages", value: SYNC.STAGES.map((k) => `${k} ${s.stages[k] === -1 ? "N/A" : s.stages[k] + "%"}`).join(", ") }, { name: "issues", label: "Open issues", value: issues.filter((i) => i.status !== "closed").map((i) => i.title).join("\n") }, { name: "notes", label: "Weekly notes", value: notes.slice(0, 5).map((n) => `${n.date} ${n.text}`).join("\n").slice(0, 1500) }].map((f) => ({ ...f, type: "text" })), getText: () => root.querySelector("#ctx").value, setText: (v) => { root.querySelector("#ctx").value = v; }, noFill: true });
    } };
  }

  // ---------------------------------------------------------------- approvals: drawings (editable) · submittals (read-only)
  let aTab = "drawings", aFilter = "all";
  async function approvals(q) {
    if (q.tab) aTab = q.tab; if (q.f) aFilter = q.f; const projId = q.project ? +q.project : null;
    UI.chrome(true, t("tab.approvals"), "approvals", !!projId); if (projId) UI.badge((project(projId) || {}).code || "");
    const nav = `<div class="seg">${[["drawings", t("appr.drawings")], ["submittals", t("appr.submittals")]].map(([k, l]) => `<button data-tab="${k}" class="${aTab === k ? "on" : ""}">${l}</button>`).join("")}</div>`;
    const pq = projId ? `&project=${projId}` : "";
    if (aTab === "drawings") {
      let list = (await DB.all("drawings")).filter((d) => !projId || d.project_id === projId);
      const counts = { all: list.length, overdue: list.filter((d) => d.status === "pending" && d.overdue_days > 0).length, pending: list.filter((d) => d.status === "pending").length, received: list.filter((d) => d.status === "received").length };
      list = list.filter((d) => aFilter === "all" || (aFilter === "overdue" ? d.status === "pending" && d.overdue_days > 0 : d.status === aFilter)).sort((a, b) => (b.status === "pending") - (a.status === "pending") || b.overdue_days - a.overdue_days || String(a.expected_at).localeCompare(String(b.expected_at)));
      return { html: `${nav}<div class="chips">${["all", "overdue", "pending", "received"].map((f) => `<span class="chip tap ${aFilter === f ? "navy" : f === "overdue" && counts.overdue ? "red" : ""}" data-f="${f}">${t("appr." + f)} ${counts[f]}</span>`).join("")}</div>
        ${list.map((d) => `<div class="card tap ${d.conflict ? "c-red" : d.queued ? "c-amber" : d.status === "pending" && d.overdue_days > 0 ? "c-red" : d.status === "received" ? "c-green" : d.status === "pending" ? "c-gold" : "c-grey"}" data-dr="${d.id}"><div class="t">${esc(d.title)} <small>${d.status === "pending" && d.overdue_days > 0 ? t("appr.days_over", { n: d.overdue_days }) : t("appr." + d.kind)}</small></div>
          <div class="s">${esc((project(d.project_id) || {}).code || "")} ${esc((findSub(d.subproject_id) || {}).code || "")} • ${esc(d.system || "")}${d.location ? " • " + esc(d.location) : ""}</div>
          <div class="s muted">${dstChip(d.status)}${d.queued ? ` <span class="chip amber">${t("sub.queued")}</span>` : ""}${d.conflict ? ` <span class="chip red">${t("sub.conflict")}</span>` : ""} ${t("appr.expected")} ${fmt(d.expected_at)}${d.received_at ? ` • ${t("appr.received")} ${fmt(d.received_at)}` : ""} • ${esc(d.responsible || "")}</div>${d.remarks ? `<div class="s muted">${esc(d.remarks)}</div>` : ""}</div>`).join("") || `<p class="list-empty">${t("appr.none")}</p>`}`, bind(root) {
        root.querySelectorAll("[data-tab]").forEach((b) => b.onclick = () => { location.hash = `#/approvals?tab=${b.dataset.tab}${pq}`; }); root.querySelectorAll("[data-f]").forEach((c) => c.onclick = () => { location.hash = `#/approvals?tab=drawings&f=${c.dataset.f}${pq}`; });
        root.querySelectorAll("[data-dr]").forEach((el) => el.onclick = () => { const d = list.find((x) => x.id === +el.dataset.dr);
          UI.sheet(`<h3>${esc(d.title)}</h3><div class="kv"><b>${t("appr.requested")}</b><span class="v">${fmt(d.request_sent)}</span></div><div class="kv"><b>${t("appr.expected")}</b><span class="v">${fmt(d.expected_at)}</span></div><div class="kv"><b>${t("appr.responsible")}</b><span class="v">${esc(d.responsible || "—")}</span></div><div class="kv"><b>${t("appr.location")}</b><span class="v">${esc(d.location || "—")} ${esc(d.location_status || "")}</span></div>
            <label>${t("appr.remarks")}</label><textarea id="rem" maxlength="1000">${esc(d.remarks || "")}</textarea>
            ${d.status !== "received" ? `<button class="btn gold" id="recv">${t("appr.mark_received")}</button>` : ""}${d.status !== "not_required" ? `<button class="btn line" id="nreq">${t("appr.not_required")}</button>` : ""}${d.status !== "pending" ? `<button class="btn line" id="pend">${t("appr.mark_pending")}</button>` : ""}<button class="btn navy sm" id="ok">${t("appr.save")}</button>`, null, (sh) => {
            const apply = async (status) => { const remarks = sh.querySelector("#rem").value.trim(); const dr = await DB.get("drawings", d.id); Object.assign(dr, { status, remarks, received_at: status === "received" ? today() : null, overdue_days: status === "pending" && dr.expected_at < today() ? Math.round((Date.now() - new Date(dr.expected_at)) / 86400000) : 0, queued: true }); delete dr.conflict; await DB.put("drawings", dr);
              const pl = { drawing_id: d.id, status, remarks }; if (status === "received") pl.received_at = today(); await DB.enqueue("drawing_update", pl); UI.closeSheet(); UI.toast(t("common.ok")); await UI.render(); SYNC.run(false); };
            const b1 = sh.querySelector("#recv"); if (b1) b1.onclick = () => apply("received"); const b2 = sh.querySelector("#nreq"); if (b2) b2.onclick = () => apply("not_required"); const b3 = sh.querySelector("#pend"); if (b3) b3.onclick = () => apply("pending"); sh.querySelector("#ok").onclick = () => apply(d.status); }); }); } };
    }
    const all = (await DB.all("submittals")).filter((x) => !projId || x.project_id === projId).sort((a, b) => (b.open - a.open) || (b.overdue_days - a.overdue_days) || String(a.due_at).localeCompare(String(b.due_at)));
    const row = (x) => `<div class="card ${x.open && x.overdue_days > 0 ? "c-red" : x.open ? "c-amber" : x.status === "rejected" ? "c-red" : "c-green"}"><div class="t">${esc(x.doc_no)} <small>${x.open && x.overdue_days > 0 ? t("appr.days_over", { n: x.overdue_days }) : esc(x.revision || "")}</small></div><div class="s">${esc(x.title)}</div>
      <div class="s muted">${sstChip(x.status)} ${esc((project(x.project_id) || {}).code || "")} ${esc((findSub(x.subproject_id) || {}).code || "")}${x.society ? ` • ${esc(x.society)}${x.class_ref ? " " + esc(x.class_ref) : ""}` : ""} • ${t("appr.submitted")} ${fmt(x.submitted_at)} • ${t("appr.due")} ${fmt(x.due_at)}${x.decided_at ? ` • ${t("appr.decided")} ${fmt(x.decided_at)}` : ""}</div>${x.comments ? `<div class="s muted">${esc(x.comments)}</div>` : ""}</div>`;
    const owner = all.filter((x) => x.kind === "owner"), cls = all.filter((x) => x.kind === "class");
    return { html: `${nav}<p class="note">${t("appr.portal_note")}</p><h2>${t("appr.owner")} (${owner.filter((x) => x.open).length}/${owner.length})</h2>${owner.map(row).join("") || `<p class="muted">—</p>`}<h2>${t("appr.class")} (${cls.filter((x) => x.open).length}/${cls.length})</h2>${cls.map(row).join("") || `<p class="muted">—</p>`}`,
      bind(root) { root.querySelectorAll("[data-tab]").forEach((b) => b.onclick = () => { location.hash = `#/approvals?tab=${b.dataset.tab}${pq}`; }); } };
  }

  // ---------------------------------------------------------------- weekly notes: last 12 weeks by ISO week across my projects + new note (photos after ACK with ref N{id})
  async function notes(q) {
    UI.chrome(true, t("notes.title"), "more", true);
    if (q.new !== undefined) {
      const def = { project_id: +q.project || (ctx.projects.find((p) => p.mine) || ctx.projects[0] || {}).id, subproject_id: +q.sub || null, visibility: "internal" };
      return { html: `<label>${t("hours.project")}</label><select id="project">${projOptions(def.project_id)}</select><label>${t("hours.sub")}</label><select id="sub">${subOptions(def.project_id, def.subproject_id)}</select>
        <label>${t("fsr.date")}</label><input id="date" type="date" value="${today()}">
        <h2>${t("notes.vis")}</h2><div class="seg" data-k="vis"><button data-v="internal" class="on">${t("notes.int")}</button><button data-v="external">${t("notes.cust")}</button></div>
        <label>${t("notes.text")}</label><textarea id="tx" maxlength="4000"></textarea>${ASSIST.button()}<h2>${t("fsr.photos")}</h2><div class="photos" id="ph"></div><button class="btn gold" id="save">${t("notes.save")}</button>`, bind(root) {
        UI.segs(root); root.querySelector("#project").onchange = (e) => { root.querySelector("#sub").innerHTML = subOptions(+e.target.value, null); }; UI.photos.init(root.querySelector("#ph"));
        const actx = { page: "fsr_edit", title: "Weekly note", headline: projName(def.project_id), fields: () => [], getText: () => root.querySelector("#tx").value, setText: (v) => { root.querySelector("#tx").value = v; } }; root.querySelector("[data-ai]").onclick = () => ASSIST.open(actx); UI.assistContext(actx);
        root.querySelector("#save").onclick = async () => { const id = DB.uuid(); const n = { id, date: root.querySelector("#date").value, project_id: +root.querySelector("#project").value, subproject_id: +root.querySelector("#sub").value || null, visibility: root.querySelector(".seg .on").dataset.v, text: root.querySelector("#tx").value.trim(), photos: UI.photos.count(root.querySelector("#ph")), author: ctx.me.name, status: "queued" };
          if (!n.subproject_id) return UI.toast(t("notes.needsub")); if (!n.text && !n.photos) return; await UI.photos.store(root.querySelector("#ph"), id); await DB.put("notes", n);
          const sd = await DB.get("subs", n.subproject_id); if (sd) { sd.notes = [{ ...n }].concat(sd.notes || []); await DB.put("subs", sd); }
          await DB.enqueue("note", { subproject_id: n.subproject_id, project_id: n.project_id, date: n.date, visibility: n.visibility, text: n.text, photos: n.photos }, id); SYNC.run(false); UI.toast(t("common.ok")); location.hash = q.sub ? `#/sub/${q.sub}` : "#/notes"; };
      } };
    }
    const since = new Date(); since.setDate(since.getDate() - 84); const sinceIso = since.toISOString().slice(0, 10);
    const all = (await DB.all("notes")).filter((n) => n.date >= sinceIso && (!q.project || n.project_id === +q.project)).sort((a, b) => String(b.date).localeCompare(String(a.date)));
    const weeks = {}; all.forEach((n) => { const w = `${String(n.date).slice(0, 4)} ${weekOf(n.date)}`; (weeks[w] = weeks[w] || []).push(n); });
    return { html: `<button class="btn gold" data-go="#/notes?new${q.project ? `&project=${q.project}` : ""}">${t("notes.new")}</button><p class="muted">${t("notes.weeks")}${q.project ? ` — ${esc((project(q.project) || {}).code || "")}` : ""}</p>
      ${Object.keys(weeks).map((w) => `<div class="week-h">${esc(w)}</div>${weeks[w].map((n) => { const s = findSub(n.subproject_id); return `<div class="card tap ${n.visibility === "external" ? "" : "c-grey"}" data-go="#/sub/${n.subproject_id}"><div class="t">${fmt(n.date)} • ${esc(s ? `${s.project.code} ${s.code}` : "")} <small>${t(n.visibility === "external" ? "notes.cust" : "notes.int")}</small></div><div class="s">${esc(n.text)}</div><div class="s muted">${esc(s ? s.name : "")} • ${esc(n.author || "")} • ${n.photos || 0} ${t("notes.photos")}${n.status === "queued" ? ` • <span class="chip amber">${t("sub.queued")}</span>` : ""}</div></div>`; }).join("")}`).join("") || `<p class="list-empty">${t("notes.none")}</p>`}`, bind: UI.goLinks };
  }
  // ---------------------------------------------------------------- documents (read-only, streamed by the portal with the project scope check)
  async function docs() {
    UI.chrome(true, t("docs.title"), "more", true);
    const all = await DB.all("documents"); const byP = {}; all.forEach((d) => { (byP[d.project_id] = byP[d.project_id] || []).push(d); });
    return { html: Object.keys(byP).map((pid) => `<h2>${esc(projName(pid) || pid)}</h2>${byP[pid].map((d) => `<div class="card tap" data-doc="${d.id}"><div class="t">${esc(d.category)} <small>${esc(d.size)}</small></div><div class="s">${esc(d.name)}</div><div class="s muted">${esc(d.subproject_id ? subName(d.subproject_id) : "")}</div></div>`).join("")}`).join("") || `<p class="list-empty">—</p>`,
      bind(root) { root.querySelectorAll("[data-doc]").forEach((c) => c.onclick = () => UI.openDocument(+c.dataset.doc)); } };
  }
  // ---------------------------------------------------------------- inbox (portal notifications, §9)
  async function inbox() {
    UI.chrome(true, t("inbox.title"), "more", true);
    const all = (await DB.all("inbox")).sort((a, b) => (a.read - b.read) || String(b.created_at).localeCompare(String(a.created_at))); const unread = all.filter((n) => !n.read);
    return { html: `${unread.length ? `<button class="btn line sm" id="readall">${t("inbox.read_all")} (${unread.length})</button>` : ""}<div class="inbox">${all.map((n) => `<div class="card ${n.read ? "read" : "unread"}"><div class="t">${esc(n.title)} <small>${esc(String(n.created_at).slice(0, 16))}</small></div><div class="s">${esc(n.body || "")}</div><div class="s muted">${esc(n.kind || "")}</div></div>`).join("") || `<p class="list-empty">${t("inbox.none")}</p>`}</div>`,
      bind(root) { const b = root.querySelector("#readall"); if (b) b.onclick = async () => { for (const n of unread) await DB.put("inbox", { ...n, read: true }); await DB.enqueue("nf_read", { ids: unread.map((n) => n.id) }); UI.render(); SYNC.run(false); }; } };
  }
  // ---------------------------------------------------------------- sync log (outbox)
  async function synclog() {
    UI.chrome(true, t("synclog.title"), "more", true);
    const ob = await DB.outbox();
    const label = (o) => I18N.has("hr.k_" + o.kind) ? t("hr.k_" + o.kind) : o.kind;
    return { html: `${ob.map((o) => `<div class="card ${o.status === "rejected" ? "c-red" : o.status === "needs_totp" ? "c-amber" : "c-gold"}"><div class="t">${esc(label(o))} <small>${esc(o.status === "needs_totp" ? t("synclog.needs_totp") : o.status)}</small></div><div class="s muted">${esc(String(o.client_ts).slice(0, 16).replace("T", " "))} • ${esc(JSON.stringify(o.payload).replace(/"totp":"\d+"/, '"totp":"••••••"').slice(0, 140))}</div>${o.error ? `<div class="s" style="color:var(--red)">${esc(o.error)}</div>` : ""}
      <div class="row2">${o.status === "needs_totp" ? `<button class="btn gold sm" data-code="${o.id}">${t("team.enter_code")}</button>` : o.status === "rejected" ? `<button class="btn navy sm" data-retry="${o.id}">${t("synclog.retry")}</button>` : ""}${o.status !== "queued" ? `<button class="btn red sm" data-drop="${o.id}">${t("synclog.drop")}</button>` : ""}</div></div>`).join("") || `<p class="list-empty">${t("synclog.none")}</p>`}<button class="btn navy" id="sync">${t("more.sync")}</button>`, bind(root) {
      root.querySelector("#sync").onclick = () => SYNC.run().then(() => UI.render());
      root.querySelectorAll("[data-drop]").forEach((b) => b.onclick = async () => { await DB.del("outbox", b.dataset.drop); await SYNC.refreshCount(); UI.render(); });
      root.querySelectorAll("[data-retry]").forEach((b) => b.onclick = async () => { const o = await DB.get("outbox", b.dataset.retry); await DB.put("outbox", { ...o, status: "queued", error: null }); await SYNC.run(false); UI.render(); });
      root.querySelectorAll("[data-code]").forEach((b) => b.onclick = () => UI.totpSheet(async (code) => { await SYNC.retryWithTotp(b.dataset.code, code); UI.render(); }));
    } };
  }
  // ---------------------------------------------------------------- lock screen (R70-005): the session stays on the device, Face ID / fingerprint opens it again
  function lock() {
    UI.chrome(false);
    return { html: `<div class="login"><img class="logo" src="img/logo.png" alt=""><h1 style="text-align:center">${t("lock.title")}</h1><p class="muted" style="text-align:center">${t("lock.lede")}</p>
      <button class="btn gold" id="unlock">${t("lock.unlock")}</button><button class="btn line" id="out">${t("lock.signout")}</button></div>`, bind(root) {
      const go = async () => { if (await UI.biometric()) { try { localStorage.removeItem("gpm.locked"); } catch (e) {} location.hash = "#/home"; } };
      root.querySelector("#unlock").onclick = go; root.querySelector("#out").onclick = async () => { await API.logout(); await DB.wipe(); try { localStorage.removeItem("gpm.locked"); } catch (e) {} location.hash = "#/login"; };
      if (!API.mock) setTimeout(go, 300);   // on a phone the Face ID prompt opens by itself
    } };
  }
  // ---------------------------------------------------------------- more / settings
  async function more() {
    UI.chrome(true, t("tab.more"), "more");
    const ob = await DB.outbox(); const hr = await DB.get("meta", "hr"); const hrUnread = hr ? (hr.messages || []).filter((m) => m.from_hr && !m.seen).length : 0; const inboxUnread = (await DB.all("inbox")).filter((n) => !n.read).length;
    let bio = false; try { bio = localStorage.getItem("gpm.bio") === "1"; } catch (e) {}
    const row = (go, title, sub, cls) => `<div class="card tap ${cls || ""}" data-go="${go}"><div class="t">${title}</div>${sub ? `<div class="s muted">${sub}</div>` : ""}</div>`;
    return { html: `${ctx.me.hr !== false ? row("#/hr", `${t("more.hr")}${hrUnread ? ` <small>${hrUnread} ${t("hr.new")}</small>` : ""}`, hr ? `${t("hr.available")}: ${(hr.balances || []).filter((b) => b.available != null && !b.via_annual).slice(0, 3).map((b) => `${b.code} ${b.available}`).join(" • ")}` : t("hr.none"), "c-gold") : ""}
      ${row("#/approvals?tab=drawings", t("more.drawings"))}${row("#/notes", t("more.notes"))}${row("#/docs", t("more.docs"))}${row("#/inbox", `${t("more.inbox")}${inboxUnread ? ` <small>${inboxUnread}</small>` : ""}`)}${row("#/synclog", t("more.synclog"), `${ob.length} ${t("sync.queued")}${ob.filter((o) => o.status === "rejected").length ? ` • ${ob.filter((o) => o.status === "rejected").length} rejected` : ""}${ob.filter((o) => o.status === "needs_totp").length ? ` • ${ob.filter((o) => o.status === "needs_totp").length} ${t("synclog.needs_totp")}` : ""}`, ob.some((o) => o.status !== "queued") ? "c-amber" : "")}
      <div class="card tap c-gold" id="ai"><div class="t">✦ ${t("ai.title")}</div><div class="s muted">${ctx.me.ai || API.mock ? t("ai.help") : t("ai.disabled")}</div></div>
      <h2>${t("more.settings")}</h2>
      <div class="kv"><b>${t("set.account")}</b><span class="v">${esc(ctx.me.name)} • ${esc(ctx.me.discipline || "")}</span></div><div class="kv"><b>${t("set.branch")}</b><span class="v">${esc(ctx.me.home_branch || "")} ${ctx.me.home_branch === "GIT" ? "— GOLDEN AS ITALIAN" : "— Golden A/S Keratsini"}</span></div>
      <div class="kv"><b>${t("set.version")}</b><span class="v">Golden PM ${API.VERSION}${API.mock ? " (demo)" : ""}${ctx.me.min_app_version ? ` • portal min ${esc(ctx.me.min_app_version)}` : ""}</span></div>
      <label>${t("more.lang")}</label><div class="lang">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}" class="${I18N.get() === c ? "on" : ""}">${l}</button>`).join("")}</div>
      <label>${t("more.bio")}</label><div class="seg" data-k="bio"><button data-v="1" class="${bio ? "on" : ""}">${t("more.bio_on")}</button><button data-v="0" class="${bio ? "" : "on"}">${t("more.bio_off")}</button></div>
      <h2>${t("more.about")}</h2><p class="muted">${t("more.about_txt")}</p>
      <button class="btn navy" id="sync">${t("more.sync")}</button><button class="btn line" id="clear">${t("set.clear")}</button><h2>🔑 ${t("pk.title")}</h2><p class="muted">${t("pk.hint")}</p><div id="pklist" class="muted">…</div>${API.passkeyCapable ? `<label>${t("pk.name")}</label><input id="pkname" maxlength="80" placeholder="iPhone"><div id="pkcoderow" class="hidden"><label>${t("pk.code")}</label><input id="pkcode" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></div><button class="btn line" id="pkadd">+ ${t("pk.add")}</button>` : `<p class="muted">${t("pk.unsupported")}</p>`}
      <button class="btn line" id="lock">🔒 ${t("more.lock")}</button><button class="btn line" id="out">${t("more.signout")}</button>`, bind(root) {
      UI.goLinks(root); root.querySelectorAll(".lang button").forEach((b) => b.onclick = () => { I18N.set(b.dataset.l); I18N.apply(); UI.render(); });
      UI.segs(root, (k, v) => { try { localStorage.setItem("gpm.bio", v); } catch (e) {} });
      root.querySelector("#sync").onclick = () => SYNC.run().then(() => UI.render());
      root.querySelector("#ai").onclick = () => ASSIST.open({ page: "fsr_edit", title: "Golden PM — help", headline: "How do I…", fields: () => [], noFill: true });
      root.querySelector("#clear").onclick = async () => { if (window.caches) await caches.delete("gas-pm-docs"); UI.toast(t("common.ok")); };
      root.querySelector("#out").onclick = async () => { await API.logout(); await DB.wipe(); location.hash = "#/login"; };
      const pkRender = async () => { const el = root.querySelector("#pklist"); if (!el) return; try { const r = await API.passkeys(); el.innerHTML = r.items.length ? r.items.map((k) => `<div class="kv"><b>${esc(k.name)}</b><span class="v">${k.last_used_at ? esc(k.last_used_at.slice(0, 10)) : "—"} <button class="btn red sm" data-pkrm="${k.id}">${t("pk.remove")}</button></span></div>`).join("") : t("pk.none"); el.querySelectorAll("[data-pkrm]").forEach((b) => b.onclick = async () => { try { await API.passkeyRemove(b.dataset.pkrm); } catch (e) {} pkRender(); }); } catch (e) { el.textContent = (e.data && e.data.error) || "—"; } };
      if (!API.mock) pkRender(); else { const el = root.querySelector("#pklist"); if (el) el.textContent = t("pk.none"); }
      const pka = root.querySelector("#pkadd"); if (pka) pka.onclick = async () => { pka.disabled = true; try { await API.passkeyRegister(root.querySelector("#pkname").value.trim(), root.querySelector("#pkcode").value.trim()); UI.toast(t("pk.added")); pkRender(); } catch (e) { if (e.data && e.data.totp) root.querySelector("#pkcoderow").classList.remove("hidden"); UI.toast(e && e.name === "InvalidStateError" ? t("pk.added") : (e.data && e.data.error) || e.message || t("pk.failed")); } pka.disabled = false; };
      root.querySelector("#lock").onclick = async () => { if (!(await UI.biometricAvailable())) { UI.toast(t("lock.need_bio")); await API.logout(); await DB.wipe(); location.hash = "#/login"; return; } try { localStorage.setItem("gpm.locked", "1"); } catch (e) {} location.hash = "#/lock"; };
    } };
  }

  return { load, ctx, esc, fmt, today, weekOf, subName, projName, findSub, project, staffName, pstChip, sstChip, login, lock, home, projects, project: projectScreen, sub, approvals, notes, docs, inbox, synclog, more };
})();
