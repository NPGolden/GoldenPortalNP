/* Golden HR — screens: login, lock, inbox (requests / trainings / timesheets + history), people (directory + alerts), person, messages, thread,
 * calendar (who is away, holidays), balances, more. Each screen returns { html, bind(root) }; data comes from IndexedDB (SYNC.pull), decisions go to the outbox. */
window.SCREENS = (() => {
  const t = I18N.t;
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const fmt = (iso) => iso ? I18N.date(iso) : "";
  const today = () => new Date().toISOString().slice(0, 10);
  const num = (v) => v == null ? "—" : (Math.round(v * 100) / 100).toString();
  const ctx = {};
  async function load() {
    ctx.me = (await DB.get("meta", "me")) || {}; ctx.inbox = (await DB.get("inbox", "inbox")) || { requests: [], trainings: [], timesheets: [] };
    ctx.people = (await DB.get("people", "people")) || { people: [], alerts: [], departments: [] }; ctx.away = (await DB.get("away", "away")) || { days: [], rows: [], holidays: [], types: [] };
    ctx.threads = ((await DB.get("threads", "threads")) || { threads: [] }).threads; ctx.balances = (await DB.get("balances", "balances")) || { people: [] };
  }
  const person = (id) => ctx.people.people.find((p) => p.person_id === +id);
  const stChip = (s) => `<span class="chip ${s === "approved" ? "green" : s === "rejected" || s === "cancelled" ? "red" : s === "pending_hr" ? "amber" : s === "pending_mgr" ? "grey" : "grey"}">${esc(I18N.has("st." + s) ? t("st." + s) : s)}</span>`;
  const empty = (k) => `<p class="list-empty">${t(k)}</p>`;

  // ---------------------------------------------------------------- login
  async function login() {
    UI.chrome(false);
    return { html: `<div class="login">
      <img src="img/logo.png" alt="Golden A/S">
      <h2>${t("login.title")} — Golden HR</h2>
      <label>${t("login.portal")}</label><input readonly value="goldensas.com/customer">
      <label>${t("login.email")}</label><input id="email" type="email" autocomplete="username">
      <label>${t("login.password")}</label><input id="pw" type="password" autocomplete="current-password">
      <div id="totprow" class="hidden"><label>${t("login.totp")}</label><input id="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
      <p id="err" class="note red hidden"></p>
      <button class="btn gold" id="go">${t("login.signin")}</button>
      ${API.passkeyCapable ? `<button class="btn line" id="pkin">🔑 ${t("login.passkey")}</button>` : ""}
      <p class="muted" style="text-align:center">${t("login.hronly")}</p>
      <h2>${t("login.lang")}</h2><div class="lang">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}" class="${I18N.get() === c ? "on" : ""}">${l}</button>`).join("")}</div>
      <p class="muted" style="text-align:center;margin-top:16px">Golden A/S • Golden HR v${API.VERSION}</p>
    </div>`, bind(root) {
      root.querySelectorAll(".lang button").forEach((b) => b.onclick = () => { I18N.set(b.dataset.l); UI.render(); });
      const fail = (e) => { const er = root.querySelector("#err"); const d = e.data || {}; const totp = d.totp === true || d.code === "totp_required";
        if (totp) { root.querySelector("#totprow").classList.remove("hidden"); er.textContent = root.querySelector("#totp").value ? t("login.totpbad") : t("login.totphint"); root.querySelector("#totp").focus(); }
        else er.textContent = e.status === 403 ? (d.error || t("login.hronly")) : e.status === 423 ? t("login.locked") : e.status === 426 ? t("login.update") : e.status === 429 ? t("login.ratelimit") : e.status === 401 ? t("login.failed") : e && e.name === "NotAllowedError" ? t("pk.failed") : `${t("login.failed")} (${e.status || "offline"})`; er.classList.remove("hidden"); };
      root.querySelector("#go").onclick = async () => { const b = root.querySelector("#go"); b.disabled = true;
        try { await API.login(root.querySelector("#email").value.trim(), root.querySelector("#pw").value, root.querySelector("#totp").value.trim()); await UI.afterSignIn(); } catch (e) { fail(e); b.disabled = false; } };
      const pkb = root.querySelector("#pkin"); if (pkb) pkb.onclick = async () => { pkb.disabled = true; try { await API.passkeyLogin(); await UI.afterSignIn(); } catch (e) { fail(e); pkb.disabled = false; } };
    } };
  }
  function lock() {
    UI.chrome(false);
    return { html: `<div class="login"><img class="logo" src="img/logo.png" alt=""><h1 style="text-align:center">${t("lock.title")}</h1><p class="muted" style="text-align:center">${t("lock.lede")}</p>
      <button class="btn gold" id="unlock">${t("lock.unlock")}</button><button class="btn line" id="out">${t("lock.signout")}</button></div>`, bind(root) {
      const go = async () => { if (await UI.biometric()) { UI.unlock(); location.hash = "#/inbox"; } };
      root.querySelector("#unlock").onclick = go; root.querySelector("#out").onclick = () => UI.signOut();
      setTimeout(go, 300);
    } };
  }

  // ---------------------------------------------------------------- inbox
  let itab = "requests";
  async function inbox(q) {
    UI.chrome(true, t("tab.inbox"), "inbox"); if (q && q.tab) itab = q.tab;
    const ib = ctx.inbox; const n = { requests: ib.requests.length, trainings: ib.trainings.length, timesheets: ib.timesheets.length };
    const act = (row, kind) => row.conflict ? `<p class="note red">${esc(row.conflict)}</p>` : row.needs_totp ? `<p class="note red">${t("dec.needs_code")}</p><button class="btn gold sm" data-code="${row.needs_totp}">${t("dec.enter_code")}</button>` : row.deciding ? `<p class="muted">${t("dec.queued")}</p>` :
      `<div class="row2"><button class="btn navy sm" data-dec="${kind}:${row.id}:1">${t("dec.approve")}</button><button class="btn red sm" data-dec="${kind}:${row.id}:0">${t("dec.reject")}</button></div>`;
    const req = (r) => `<div class="card ${r.stage === "hr" ? "c-gold" : "c-grey"}"><div class="t"><span>${esc(r.person)}</span><small>${esc(r.code)}</small></div>
      <div class="s">${esc(r.type)} · ${fmt(r.from)}${r.to && r.to !== r.from ? ` → ${fmt(r.to)}` : ""}${r.time_from ? ` ${esc(r.time_from.slice(0, 5))}–${esc((r.time_to || "").slice(0, 5))}` : ""} · <b>${num(r.unit === "hours" ? r.hours : r.days)} ${t(r.unit === "hours" ? "unit.h" : "unit.d")}</b></div>
      ${r.reason ? `<div class="s muted">${esc(r.reason)}</div>` : ""}${r.doc ? `<div class="s muted">📎 ${esc(r.doc.name)}</div>` : ""}
      <div class="s muted">${r.stage === "manager" ? `${t("dec.at_manager")}${r.manager && r.manager.name ? ` — ${esc(r.manager.name)}` : ""}` : `${t("dec.at_hr")}${r.manager && r.manager.at ? ` · ${t("dec.mgr_ok")} ${esc(r.manager.name)} ${fmt(r.manager.at)}${r.manager.note ? ` — ${esc(r.manager.note)}` : ""}` : ""}`} · ${t("dec.asked")} ${fmt(r.created_at)}</div>${act(r, "hr_app_decide")}</div>`;
    const tr = (m) => `<div class="card c-gold"><div class="t"><span>${esc(m.person)}</span><small>${esc(m.kind)}</small></div><div class="s">${esc(m.title)}${m.date ? ` · ${fmt(m.date)}` : ""}</div>${m.note ? `<div class="s muted">${esc(m.note)}</div>` : ""}<div class="s muted">${t("dec.asked")} ${fmt(m.applied_at)}</div>${act(m, "hr_app_training")}</div>`;
    const ts = (x) => `<div class="card c-gold"><div class="t"><span>${esc(x.user)}</span><small>${esc(x.ts_no)}</small></div><div class="s">${esc(x.project_code)} ${esc(x.project)} · ${fmt(x.period_start)} → ${fmt(x.period_end)}</div><div class="s">${num(x.hours_normal)} h ${t("ts.normal")} · ${num(x.hours_overtime)} h ${t("ts.overtime")} · ${num(x.hours_holiday)} h ${t("ts.holiday")}</div>${act(x, "hr_app_ts")}</div>`;
    const list = itab === "requests" ? (ib.requests.length ? ib.requests.map(req).join("") : empty("inbox.empty")) : itab === "trainings" ? (ib.trainings.length ? ib.trainings.map(tr).join("") : empty("inbox.empty")) : itab === "timesheets" ? (ib.timesheets.length ? ib.timesheets.map(ts).join("") : empty("inbox.empty_ts")) : "";
    let hist = ""; if (itab === "history") { const h = ctx.history || null; hist = h ? (h.requests.length ? h.requests.map((r) => `<div class="card ${r.status === "approved" ? "c-green" : r.status === "rejected" ? "c-red" : "c-grey"}"><div class="t"><span>${esc(r.person)}</span>${stChip(r.status)}</div><div class="s">${esc(r.type)} · ${fmt(r.from)}${r.to !== r.from ? ` → ${fmt(r.to)}` : ""} · ${num(r.unit === "hours" ? r.hours : r.days)} ${t(r.unit === "hours" ? "unit.h" : "unit.d")}</div>${r.hr && r.hr.note ? `<div class="s muted">${esc(r.hr.note)}</div>` : ""}</div>`).join("") : empty("inbox.empty")) : `<p class="muted">${t("sync.loading")}</p>`; }
    return { html: `<div class="seg" data-k="itab"><button data-v="requests" class="${itab === "requests" ? "on" : ""}">${t("inbox.requests")}${n.requests ? ` (${n.requests})` : ""}</button><button data-v="trainings" class="${itab === "trainings" ? "on" : ""}">${t("inbox.trainings")}${n.trainings ? ` (${n.trainings})` : ""}</button><button data-v="timesheets" class="${itab === "timesheets" ? "on" : ""}">${t("inbox.timesheets")}${n.timesheets ? ` (${n.timesheets})` : ""}</button><button data-v="history" class="${itab === "history" ? "on" : ""}">${t("inbox.history")}</button></div>
      ${list}${hist}`, bind(root) {
      UI.segs(root, async (k, v) => { itab = v; if (v === "history" && !ctx.history) { try { ctx.history = await API.history(new Date().getFullYear()); } catch (e) { ctx.history = { requests: [] }; } } UI.render(); });
      root.querySelectorAll("[data-dec]").forEach((b) => b.onclick = () => { const [kind, id, ok] = b.dataset.dec.split(":"); const list = kind === "hr_app_decide" ? ib.requests : kind === "hr_app_training" ? ib.trainings : ib.timesheets; decide(kind, list.find((r) => r.id === +id), ok === "1"); });
      root.querySelectorAll("[data-code]").forEach((b) => b.onclick = () => { const row = ib.requests.concat(ib.trainings, ib.timesheets).find((r) => r.needs_totp === b.dataset.code); UI.totpSheet(async (code) => { await SYNC.retryWithTotp(b.dataset.code, code); UI.render(); }, !!(row && row.wrong_code)); });
    } };
  }
  function decide(kind, row, approve) {
    if (!row) return;
    const who = row.person || row.user; const what = kind === "hr_app_decide" ? `${row.code} ${fmt(row.from)}` : kind === "hr_app_training" ? row.title : row.ts_no;
    UI.sheet(`<h3>${approve ? t("dec.approve") : t("dec.reject")} — ${esc(who)}</h3><p class="muted">${esc(what)}</p><label>${t("dec.note")}${!approve ? " *" : ""}</label><textarea id="note" rows="3" maxlength="300"></textarea>${UI.totpField()}<p id="err" class="note red hidden"></p><button class="btn ${approve ? "navy" : "red"}" id="ok">${approve ? t("dec.approve") : t("dec.reject")}</button>`,
      async (sh) => { const note = sh.querySelector("#note").value.trim(); const er = sh.querySelector("#err"); if (!approve && !note) { er.textContent = t("dec.note_req"); er.classList.remove("hidden"); return; }
        const totp = UI.totpValue(sh); if (totp === false) return;
        const payload = kind === "hr_app_decide" ? { request_id: row.id, approve, note } : kind === "hr_app_training" ? { member_id: row.id, approve, note } : { ts_id: row.id, approve, note }; if (totp) payload.totp = totp;
        row.deciding = true; delete row.conflict; await DB.put("inbox", ctx.inbox); await DB.enqueue(kind, payload); UI.closeSheet(); UI.toast(t("dec.sent")); await UI.render(); SYNC.run(false); });
  }

  // ---------------------------------------------------------------- people
  let pq = "", pdep = "";
  async function people() {
    UI.chrome(true, t("tab.people"), "people");
    const al = ctx.people.alerts || []; const f = pq.toLowerCase();
    const list = ctx.people.people.filter((p) => (!pdep || p.department === pdep) && (!f || `${p.name} ${p.name_el} ${p.job_title} ${p.department} ${p.discipline} ${p.code}`.toLowerCase().includes(f)));
    const away = (p) => p.away_today ? `<span class="chip ${p.away_today.status === "approved" ? "amber" : "grey"}">${esc(p.away_today.code)}</span>` : "";
    return { html: `${al.length ? `<div class="card c-amber"><div class="t">${t("people.alerts")}</div>${al.map((a) => `<div class="s">${a.kind === "contract" ? "📄" : "🎓"} <b>${esc(a.person)}</b> — ${a.kind === "contract" ? t("people.contract_end") : esc(a.title)} ${fmt(a.date)} (${a.days} ${t("unit.d")})</div>`).join("")}</div>` : ""}
      <input id="pq" type="search" placeholder="${t("people.search")}" value="${esc(pq)}" autocomplete="off">
      <select id="pdep" data-nosearch><option value="">${t("people.all_dep")}</option>${ctx.people.departments.map((d) => `<option ${d === pdep ? "selected" : ""}>${esc(d)}</option>`).join("")}</select>
      <p class="muted">${list.length} ${t("people.count")} · ${ctx.people.people.filter((p) => p.away_today).length} ${t("people.away_today")}</p>
      ${list.map((p) => `<div class="card tap ${p.away_today ? "c-amber" : "c-navy"}" data-go="#/person/${p.person_id}"><div class="t"><span>${esc(p.name)}</span>${away(p)}</div><div class="s">${[p.job_title, p.department, p.branch].filter(Boolean).map(esc).join(" · ")}</div></div>`).join("") || empty("people.none")}`, bind(root) {
      UI.goLinks(root); const inp = root.querySelector("#pq"); inp.oninput = () => { pq = inp.value; clearTimeout(inp._t); inp._t = setTimeout(() => { const v = inp.value; UI.render().then(() => { const i2 = document.querySelector("#pq"); if (i2) { i2.focus(); i2.setSelectionRange(v.length, v.length); } }); }, 250); };
      root.querySelector("#pdep").onchange = (e) => { pdep = e.target.value; UI.render(); };
    } };
  }
  async function personScreen(id) {
    const p = person(id); if (!p) return { html: empty("people.none") };
    UI.chrome(true, p.name, "people", true);
    const bal = (ctx.balances.people.find((x) => x.person_id === p.person_id) || {}).balances || [];
    const th = ctx.threads.find((x) => x.person_id === p.person_id);
    const kv = (k, v, href) => v ? `<div class="kv"><b>${t(k)}</b><span class="v">${href ? `<a href="${href}">${esc(v)}</a>` : esc(v)}</span></div>` : "";
    const aw = (ctx.away.rows.find((r) => r.person_id === p.person_id) || {}).cells || {}; const next = Object.keys(aw).filter((d) => d >= today()).sort().slice(0, 10);
    return { html: `<div class="card c-navy"><div class="t">${esc(p.name)}${p.name_el ? ` <small>${esc(p.name_el)}</small>` : ""}</div><div class="s">${[p.job_title, p.department, p.discipline !== p.department ? p.discipline : ""].filter(Boolean).map(esc).join(" · ")}</div>
      <div class="chips">${p.branch ? `<span class="chip navy">${esc(p.branch)}</span>` : ""}${p.jurisdiction ? `<span class="chip grey">${esc(p.jurisdiction)}</span>` : ""}${p.part_time ? `<span class="chip amber">${t("people.part_time")}</span>` : ""}${p.technical ? `<span class="chip gold">${t("people.technical")}</span>` : ""}${p.away_today ? `<span class="chip amber">${t("people.away_today")}: ${esc(p.away_today.code)}</span>` : ""}</div></div>
      ${kv("people.email", p.email, `mailto:${p.email}`)}${kv("people.phone_mobile", p.phone_mobile, `tel:${p.phone_mobile}`)}${kv("people.phone_work", p.phone_work + (p.extension ? ` / ${p.extension}` : ""), `tel:${p.phone_work}`)}${kv("people.manager", p.manager)}${kv("people.hire_date", fmt(p.hire_date))}${kv("people.contract", p.contract_type)}${kv("people.contract_end", fmt(p.contract_end))}${kv("people.code", p.code)}
      <h2>${t("tab.balances")} ${new Date().getFullYear()}</h2>${bal.filter((b) => b.entitled || b.taken || b.pending).length ? bal.filter((b) => b.entitled || b.taken || b.pending).map((b) => `<div class="kv"><b>${esc(b.type)}</b><span class="v"><b>${num(b.available)}</b> / ${num(b.entitled)} ${t(b.unit === "hours" ? "unit.h" : "unit.d")}${b.pending ? ` · ${num(b.pending)} ${t("bal.pending")}` : ""}</span></div>`).join("") : `<p class="muted">${t("bal.none")}</p>`}
      <h2>${t("people.next_absences")}</h2>${next.length ? next.map((d) => `<div class="kv"><b>${fmt(d)}</b><span class="v">${esc(aw[d].code)} <span class="chip ${aw[d].status === "approved" ? "green" : "amber"}">${esc(I18N.has("st." + aw[d].status) ? t("st." + aw[d].status) : aw[d].status)}</span></span></div>`).join("") : `<p class="muted">${t("people.no_absences")}</p>`}
      <button class="btn navy" data-go="#/thread/${p.person_id}">✉ ${t("msg.open")}${th && th.unread ? ` (${th.unread})` : ""}</button>`, bind(root) { UI.goLinks(root); } };
  }

  // ---------------------------------------------------------------- messages
  async function messages() {
    UI.chrome(true, t("tab.messages"), "messages");
    const th = ctx.threads;
    return { html: `<button class="btn line" id="new">+ ${t("msg.new")}</button>${th.length ? th.map((x) => `<div class="card tap ${x.unread ? "c-gold" : "c-grey"}" data-go="#/thread/${x.person_id}"><div class="t"><span>${esc(x.person)}${x.unread ? ` <small>${x.unread} ${t("msg.unread")}</small>` : ""}</span><small>${fmt(x.last_at)}</small></div><div class="s muted">${x.last_from_hr ? "HR: " : ""}${esc(x.last)}</div></div>`).join("") : empty("msg.none")}`, bind(root) {
      UI.goLinks(root); root.querySelector("#new").onclick = () => UI.sheet(`<h3>${t("msg.new")}</h3><label>${t("people.one")}</label><select id="pp" data-search>${ctx.people.people.map((p) => `<option value="${p.person_id}">${esc(p.name)}</option>`).join("")}</select><button class="btn navy" id="ok">${t("msg.open")}</button>`, (sh) => { const v = sh.querySelector("#pp").value; UI.closeSheet(); location.hash = `#/thread/${v}`; });
    } };
  }
  async function thread(pid) {
    const p = person(pid); UI.chrome(true, p ? p.name : t("tab.messages"), "messages", true);
    let th = null; try { th = await API.thread(+pid); } catch (e) {}
    const items = th ? th.items : []; const me = ctx.threads.find((x) => x.person_id === +pid); if (me && me.unread) { me.unread = 0; await DB.put("threads", { id: "threads", threads: ctx.threads }); }
    return { html: `<div class="msgs">${items.length ? items.map((m) => `<div class="msg ${m.from_hr ? "me" : ""}"><div class="b">${esc(m.body)}</div><div class="w">${m.from_hr ? esc(m.from || "HR") : esc(p ? p.name : "")} · ${fmt(m.at)}${m.from_hr && m.seen ? " ✓✓" : ""}</div></div>`).join("") : `<p class="list-empty">${th ? t("msg.empty") : t("sync.off")}</p>`}</div>
      <label>${t("msg.write")}</label><textarea id="body" rows="3" maxlength="2000"></textarea><button class="btn gold" id="send">${t("msg.send")}</button>`, bind(root) {
      root.querySelector("#send").onclick = async () => { const b = root.querySelector("#body"); const v = b.value.trim(); if (!v) return; root.querySelector("#send").disabled = true; try { await API.send(+pid, v); UI.toast(t("msg.sent")); await SYNC.run(false); UI.render(); } catch (e) { UI.toast(e.message || t("sync.err")); root.querySelector("#send").disabled = false; } };
      window.scrollTo(0, document.body.scrollHeight);
    } };
  }

  // ---------------------------------------------------------------- calendar (who is away) + balances
  async function calendar(q) {
    UI.chrome(true, t("tab.calendar"), "calendar");
    const aw = ctx.away; const start = today(); const days = aw.days.filter((d) => d >= start).slice(0, 28);
    const hol = {}; (aw.holidays || []).forEach((h) => { (hol[h.date] = hol[h.date] || []).push(h); });
    const byDay = {}; aw.rows.forEach((r) => Object.keys(r.cells).forEach((d) => { if (d >= start) (byDay[d] = byDay[d] || []).push({ name: r.name, dep: r.department, ...r.cells[d] }); }));
    const dow = (d) => I18N.t("dow." + new Date(d).getDay());
    const tname = (code) => (aw.types.find((x) => x.code === code) || {}).name || code;
    return { html: `<div class="row2"><button class="btn line sm" data-go="#/balances">${t("tab.balances")}</button><button class="btn line sm" id="refresh">${t("more.sync")}</button></div>
      ${days.map((d) => { const list = byDay[d] || []; const h = hol[d] || []; const wk = [0, 6].includes(new Date(d).getDay()); if (!list.length && !h.length && wk) return ""; return `<div class="card ${h.length ? "c-gold" : list.length ? "c-amber" : "c-grey"}"><div class="t"><span>${dow(d)} ${fmt(d)}</span><small>${list.length ? `${list.length} ${t("cal.away")}` : ""}</small></div>
        ${h.map((x) => `<div class="s">🎌 ${esc(x.name)} <span class="chip gold">${esc(x.calendar)}</span></div>`).join("")}
        ${list.map((x) => `<div class="s">${esc(x.name)} <span class="chip ${x.status === "approved" ? "green" : "amber"}" title="${esc(tname(x.code))}">${esc(x.code)}</span>${x.status !== "approved" ? ` <span class="muted">${t("cal.pending")}</span>` : ""}${x.dep ? ` <span class="muted">· ${esc(x.dep)}</span>` : ""}</div>`).join("")}</div>`; }).join("")}
      <p class="muted">${t("cal.legend")}: ${aw.types.map((x) => `${esc(x.code)} = ${esc(x.name)}`).join(" · ")}</p>`, bind(root) { UI.goLinks(root); root.querySelector("#refresh").onclick = () => SYNC.run().then(() => UI.render()); } };
  }
  let bq = "";
  async function balances() {
    UI.chrome(true, `${t("tab.balances")} ${ctx.balances.year || ""}`, "calendar", true);
    const f = bq.toLowerCase(); const list = ctx.balances.people.filter((p) => !f || `${p.name} ${p.department}`.toLowerCase().includes(f));
    return { html: `<input id="bq" type="search" placeholder="${t("people.search")}" value="${esc(bq)}" autocomplete="off">${list.map((p) => `<div class="card tap c-navy" data-go="#/person/${p.person_id}"><div class="t"><span>${esc(p.name)}</span><small>${esc(p.department || "")}</small></div>${p.balances.filter((b) => b.entitled || b.taken || b.pending).map((b) => `<div class="s">${esc(b.code)} ${esc(b.type)}: <b>${num(b.available)}</b> ${t("bal.available")} · ${num(b.taken)} ${t("bal.taken")}${b.pending ? ` · ${num(b.pending)} ${t("bal.pending")}` : ""} / ${num(b.entitled)}</div>`).join("") || `<div class="s muted">${t("bal.none")}</div>`}</div>`).join("") || empty("people.none")}`, bind(root) {
      UI.goLinks(root); const inp = root.querySelector("#bq"); inp.oninput = () => { bq = inp.value; clearTimeout(inp._t); inp._t = setTimeout(() => { const v = inp.value; UI.render().then(() => { const i2 = document.querySelector("#bq"); if (i2) { i2.focus(); i2.setSelectionRange(v.length, v.length); } }); }, 250); };
    } };
  }

  // ---------------------------------------------------------------- more
  async function more() {
    UI.chrome(true, t("tab.more"), "more"); const ob = await DB.outbox();
    return { html: `<div class="kv"><b>${t("set.account")}</b><span class="v">${esc(ctx.me.name)} • ${esc(ctx.me.discipline || "")}</span></div><div class="kv"><b>${t("set.branch")}</b><span class="v">${esc(ctx.me.home_branch || "")}</span></div>
      <div class="kv"><b>${t("set.version")}</b><span class="v">Golden HR ${API.VERSION}${ctx.me.min_app_version ? ` • portal min ${esc(ctx.me.min_app_version)}` : ""}</span></div>
      <div class="kv"><b>${t("more.outbox")}</b><span class="v">${ob.length} ${t("sync.queued")}${ob.filter((o) => o.status === "rejected").length ? ` • ${ob.filter((o) => o.status === "rejected").length} ${t("sync.rejected")}` : ""}</span></div>
      <label>${t("more.lang")}</label><div class="lang">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}" class="${I18N.get() === c ? "on" : ""}">${l}</button>`).join("")}</div>
      <h2>🔒 ${t("more.security")}</h2><p class="muted">${t("more.security_txt")}</p>
      <h2>🔑 ${t("pk.title")}</h2><p class="muted">${t("pk.hint")}</p><div id="pklist" class="muted">…</div>${API.passkeyCapable ? `<label>${t("pk.name")}</label><input id="pkname" maxlength="80" placeholder="iPhone"><div id="pkcoderow" class="hidden"><label>${t("pk.code")}</label><input id="pkcode" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></div><button class="btn line" id="pkadd">${t("pk.add")}</button>` : ""}
      <h2>${t("more.about")}</h2><p class="muted">${t("more.about_txt")}</p>
      <button class="btn navy" id="sync">${t("more.sync")}</button>${ob.filter((o) => o.status === "rejected").length ? `<button class="btn line" id="clearob">${t("more.clear_rejected")}</button>` : ""}
      <button class="btn line" id="lock">🔒 ${t("more.lock")}</button><button class="btn line" id="out">${t("more.signout")}</button>`, bind(root) {
      root.querySelectorAll(".lang button").forEach((b) => b.onclick = () => { I18N.set(b.dataset.l); I18N.apply(); UI.render(); });
      root.querySelector("#sync").onclick = () => SYNC.run().then(() => UI.render());
      const co = root.querySelector("#clearob"); if (co) co.onclick = async () => { for (const o of ob) if (o.status === "rejected") await DB.del("outbox", o.id); await SYNC.refreshCount(); UI.render(); };
      root.querySelector("#out").onclick = () => UI.signOut(); root.querySelector("#lock").onclick = () => { UI.lockNow(); location.hash = "#/lock"; };
      const pkRender = async () => { const el = root.querySelector("#pklist"); if (!el) return; try { const r = await API.passkeys(); el.innerHTML = r.items.length ? r.items.map((k) => `<div class="kv"><b>${esc(k.name)}</b><span class="v">${k.last_used_at ? esc(k.last_used_at.slice(0, 10)) : "—"} <button class="btn red sm" data-pkrm="${k.id}">${t("pk.remove")}</button></span></div>`).join("") : t("pk.none"); el.querySelectorAll("[data-pkrm]").forEach((b) => b.onclick = async () => { await API.passkeyRemove(b.dataset.pkrm); pkRender(); }); } catch (e) { el.textContent = e.message || "—"; } };
      pkRender();
      const pka = root.querySelector("#pkadd"); if (pka) pka.onclick = async () => { pka.disabled = true; try { await API.passkeyRegister(root.querySelector("#pkname").value.trim(), root.querySelector("#pkcode").value.trim()); UI.toast(t("pk.added")); pkRender(); } catch (e) { if (e.data && e.data.totp) root.querySelector("#pkcoderow").classList.remove("hidden"); UI.toast(e && e.name === "InvalidStateError" ? t("pk.already") : (e.data && e.data.error) || e.message || t("pk.failed"), 4000); } pka.disabled = false; };
    } };
  }
  return { load, ctx, esc, fmt, login, lock, inbox, people, person: personScreen, messages, thread, calendar, balances, more };
})();
