/* Golden PM — HR on the phone.
 * HR.page  = My HR: the portal's "My leave" page for the signed-in PM (port of the Service app hr.js, GAS-CP-R64-001) —
 *            read model GET /hr?year=, writes through the hr_* sync kinds / POST /hr/request.
 * HR.team  = Team (CONTRACT §6): leave requests to decide (hr_decide), timesheets to approve (ts_decide), who is away (§7 matrix).
 *            Decisions need the step-up code when the account has 2FA; a 401 {totp:true} result puts the operation on hold as
 *            needs_totp and the row offers "Enter code". No personal / payroll data is ever shown (CS-24). */
window.HR = (() => {
  const t = I18N.t; const S = () => SCREENS; const esc = (x) => S().esc(x); const fmt = (d) => I18N.date(d);
  const fh = (v) => v == null ? "—" : String(Math.round(v * 100) / 100);
  const stChip = (s) => `<span class="chip ${s === "approved" || s === "acknowledged" || s === "attended" ? "green" : String(s).startsWith("pending") || s === "applied" || s === "final" ? "amber" : s === "rejected" ? "red" : "grey"}">${esc(I18N.has("hrs." + s) ? t("hrs." + s) : String(s).replace(/_/g, " "))}</span>`;

  // ---------------------------------------------------------------- absence matrix (§7): codes coloured by status, horizontal scroll
  function matrixHtml(m, compact) {
    if (!m || !m.days || !m.rows) return "";
    const today = S().today(); const dow = (iso) => "MTWTFSS"[(new Date(iso).getDay() + 6) % 7]; const isWe = (iso) => [0, 6].includes(new Date(iso).getDay());
    const rows = compact ? m.rows.filter((r) => Object.keys(r.cells || {}).length).concat(m.rows.filter((r) => !Object.keys(r.cells || {}).length)).slice(0, compact === true ? 8 : 999) : m.rows;
    return `<div class="matrix"><table><tr><th class="name"></th>${m.days.map((d) => `<th class="${d === today ? "wk" : ""}">${dow(d)}<br>${d.slice(8)}</th>`).join("")}</tr>
      ${rows.map((r) => `<tr><td class="name">${esc(r.name)}</td>${m.days.map((d) => { const c = (r.cells || {})[d]; return `<td class="${isWe(d) ? "we" : ""}${d === today ? " today" : ""}${c ? " " + esc(c.status) : ""}" title="${c ? esc(c.code + " · " + c.status) : ""}">${c ? esc(c.code).slice(0, 3) : ""}</td>`; }).join("")}</tr>`).join("") || `<tr><td class="name muted">${t("team.no_people")}</td></tr>`}</table>
      <div class="leg"><span><i style="background:var(--green)"></i>${t("hrs.approved")}</span><span><i style="background:var(--gold)"></i>${t("hr.pending").toLowerCase()}</span></div></div>`;
  }

  // ---------------------------------------------------------------- Team
  let tab = "requests";
  async function team(q) {
    if (q.tab) tab = q.tab;
    UI.chrome(true, t("tab.team"), "team", !!q.project);
    const me = S().ctx.me || {}; const tm = await DB.get("team", "team");
    const tabs = [["requests", `${t("team.requests")}${tm ? ` (${tm.requests.filter((r) => r.decidable && !r.deciding).length})` : ""}`], ["timesheets", `${t("team.timesheets")}${tm ? ` (${tm.timesheets.filter((x) => !x.deciding).length})` : ""}`], ["away", t("team.away")]];
    const nav = `<div class="seg">${tabs.map(([k, l]) => `<button data-t="${k}" class="${tab === k ? "on" : ""}">${l}</button>`).join("")}</div>`;
    if (!tm && !(me.hr_manager || me.ts_approver)) return { html: `${nav}<p class="list-empty">${t("team.no_people")}</p>`, bind: bindNav };
    if (!tm) return { html: `${nav}<p class="list-empty">${t("sub.detail_offline")}</p><button class="btn navy" id="rl">${t("more.sync")}</button>`, bind(root) { bindNav(root); root.querySelector("#rl").onclick = () => SYNC.run().then(() => UI.render()); } };
    UI.badge(String(tm.year || new Date().getFullYear()));
    const rowState = (r) => r.deciding ? `<span class="chip amber">${t("team.deciding")}</span>` : r.needs_totp ? `<span class="chip red">${t(r.wrong_code ? "team.code_bad" : "team.code_req")}</span>` : r.conflict ? `<span class="chip red">${esc(r.conflict)}</span>` : "";
    let body = "";
    if (tab === "requests") {
      const list = tm.requests.slice().sort((a, b) => (b.decidable - a.decidable) || String(a.date_from).localeCompare(String(b.date_from)));
      const person = (r) => tm.people.find((p) => p.person_id === r.person_id);
      body = `${me.totp_enabled ? `<p class="muted">${t("team.code_hint")}</p>` : ""}${list.map((r) => { const p = person(r); return `<div class="card ${r.conflict ? "c-red" : r.deciding || r.needs_totp ? "c-amber" : r.decidable ? "c-gold" : "c-grey"}" data-req="${r.id}"><div class="t">${esc(r.person)} <small>${esc(r.code)} • ${r.unit === "hour" ? fh(r.hours) + " h" : fh(r.days) + " " + t("hr.days")}</small></div>
        <div class="s">${esc(r.type)} • ${fmt(r.date_from)}${r.date_to !== r.date_from ? " – " + fmt(r.date_to) : ""}${r.time_from ? ` ${r.time_from}–${r.time_to || ""}` : ""}${r.reason ? ` • ${esc(r.reason)}` : ""}${r.doc ? ` • ${esc(r.doc.name)}` : ""}</div>
        <div class="s muted">${stChip(r.status)} ${rowState(r)} ${p && p.annual ? `${t("team.annual")} ${fh(p.annual.available)} / ${fh(p.annual.entitled)} ${t("hr.available").toLowerCase()}${p.annual.pending ? ` (${fh(p.annual.pending)} ${t("hr.pending").toLowerCase()})` : ""}` : ""} • ${esc(String(r.created_at || "").slice(0, 10) ? fmt(r.created_at) : "")}</div>
        ${r.needs_totp ? `<button class="btn gold sm" data-code="${r.needs_totp}">${t("team.enter_code")}</button>` : r.decidable && !r.deciding && !r.conflict ? `<div class="row2"><button class="btn gold sm" data-dec="${r.id}" data-ok="1">${t("team.approve")}</button><button class="btn line sm" data-dec="${r.id}" data-ok="0">${t("team.reject")}</button></div>` : !r.decidable ? `<div class="s muted">${t("team.not_decidable")}</div>` : ""}</div>`; }).join("") || `<p class="list-empty">${t("team.none_req")}</p>`}`;
    } else if (tab === "timesheets") {
      body = `${me.totp_enabled ? `<p class="muted">${t("team.code_hint")}</p>` : ""}${tm.timesheets.map((x) => `<div class="card ${x.conflict ? "c-red" : x.deciding || x.needs_totp ? "c-amber" : "c-gold"}" data-ts="${x.id}"><div class="t">${esc(x.user)} <small>${esc(x.ts_no)}</small></div>
        <div class="s">${esc(x.project_code)} • ${t("team.period")} ${fmt(x.period_start)} – ${fmt(x.period_end)}</div>
        <div class="s"><b>${fh(x.hours_total)} h</b> <span class="muted">— ${fh(x.hours_normal)} / ${fh(x.hours_overtime)} / ${fh(x.hours_holiday)} ${t("team.n_ot_h")}</span> ${rowState(x)}</div>
        <details><summary class="muted" style="font-size:12px">${t("team.lines")} (${(x.lines || []).length})</summary><div class="overflow"><table class="week"><tr><th style="text-align:left">${t("hours.date")}</th><th style="text-align:left">${t("hours.sub")}</th><th>h</th><th style="text-align:left">${t("hours.status")}</th></tr>
          ${(x.lines || []).map((l) => `<tr><td>${fmt(l.date)}</td><td style="text-align:left">${esc(l.subproject_code || "")}</td><td>${fh(l.hours)}${l.hour_type === "overtime" ? " OT" : l.hour_type === "holiday" ? " H" : ""}</td><td style="text-align:left">${esc(l.activity || "")}${l.description ? " — " + esc(l.description) : ""}</td></tr>`).join("")}</table></div></details>
        ${x.needs_totp ? `<button class="btn gold sm" data-code="${x.needs_totp}">${t("team.enter_code")}</button>` : !x.deciding && !x.conflict ? `<div class="row2"><button class="btn gold sm" data-tsd="${x.id}" data-ok="1">${t("team.approve")}</button><button class="btn line sm" data-tsd="${x.id}" data-ok="0">${t("team.reject")}</button></div>` : ""}</div>`).join("") || `<p class="list-empty">${t("team.none_ts")}</p>`}`;
    } else {
      let m = tm.absences; let title = t("team.people");
      if (q.project) { const pr = S().project(q.project); title = pr ? pr.code : title; try { if (navigator.onLine || API.mock) { m = await API.absences(+q.project, m.from, m.to); } } catch (e) {} }
      const away = (tm.people || []).filter((p) => p.away_today);
      body = `<h2>${esc(title)} — ${fmt(m.from)} → ${fmt(m.to)}</h2>${matrixHtml(m)}
        <h2>${t("team.people")} (${tm.people.length})</h2>${tm.people.map((p) => `<div class="kv"><b>${esc(p.name)}${p.away_today ? ` <span class="chip amber">${esc(p.away_today)} ${t("team.away_today")}</span>` : ""}</b><span class="v">${esc(p.discipline || "")}${p.annual ? ` • ${t("team.annual")} ${fh(p.annual.available)} / ${fh(p.annual.entitled)}` : ""}</span></div>`).join("")}${away.length ? "" : ""}`;
    }
    function bindNav(root) { root.querySelectorAll(".seg [data-t]").forEach((b) => b.onclick = () => { tab = b.dataset.t; location.hash = `#/team?tab=${tab}`; }); }
    return { html: nav + body, bind(root) {
      bindNav(root);
      const decide = (kind, row, approve) => {
        const title = kind === "hr_decide" ? `${approve ? t("team.approve") : t("team.reject")} — ${esc(row.person)} ${esc(row.code)} ${fmt(row.date_from)}` : `${approve ? t("team.approve") : t("team.reject")} — ${esc(row.user)} ${esc(row.ts_no)}`;
        UI.sheet(`<h3>${title}</h3><label>${t("team.note")}${approve ? "" : " *"}</label><textarea id="note" maxlength="500"></textarea>${UI.totpField(me)}<p id="err" class="note red hidden"></p><button class="btn ${approve ? "gold" : "red"}" id="ok">${approve ? t("team.approve") : t("team.reject")}</button>`, async (sh) => {
          const note = sh.querySelector("#note").value.trim(); const er = sh.querySelector("#err");
          if (!approve && !note) { er.textContent = t("team.note_req"); er.classList.remove("hidden"); return; }
          const totp = UI.totpValue(sh, me); if (totp === false) return;
          const payload = kind === "hr_decide" ? { request_id: row.id, approve, note } : { ts_id: row.id, approve, note }; if (totp) payload.totp = totp;
          row.deciding = true; delete row.conflict; await DB.put("team", tm); await DB.enqueue(kind, payload); UI.closeSheet(); UI.toast(t("team.decided")); await UI.render(); SYNC.run(false);
        }, (sh) => { const n = sh.querySelector("#note"); n.focus(); });
      };
      root.querySelectorAll("[data-dec]").forEach((b) => b.onclick = () => decide("hr_decide", tm.requests.find((r) => r.id === +b.dataset.dec), b.dataset.ok === "1"));
      root.querySelectorAll("[data-tsd]").forEach((b) => b.onclick = () => decide("ts_decide", tm.timesheets.find((r) => r.id === +b.dataset.tsd), b.dataset.ok === "1"));
      root.querySelectorAll("[data-code]").forEach((b) => b.onclick = () => { const row = tm.requests.concat(tm.timesheets).find((r) => r.needs_totp === b.dataset.code); UI.totpSheet(async (code) => { await SYNC.retryWithTotp(b.dataset.code, code); UI.render(); }, !!(row && row.wrong_code)); });
      UI.assistContext({ page: "timesheet_edit", title: "Team", headline: tab, fields: () => (tm.people || []).map((p) => ({ name: "p" + p.person_id, label: p.name, value: p.annual ? `ANN ${p.annual.available} available` : "—", type: "text" })), noFill: true });
    } };
  }

  // ---------------------------------------------------------------- My HR (port of the Service app page)
  let year = new Date().getFullYear(), htab = "leave";
  async function page(q) {
    UI.chrome(true, t("hr.title"), "more", true); if (q.year) year = +q.year;
    const h = (await DB.get("meta", "hr" + year)) || (await DB.get("meta", "hr"));
    const queued = (await DB.outbox()).filter((o) => o.kind.startsWith("hr_"));
    if (!h) return { html: `<p class="list-empty">${t("hr.none")}</p><button class="btn navy" id="rl">${t("more.sync")}</button>`, bind(root) { root.querySelector("#rl").onclick = () => SYNC.run().then(() => UI.render()); } };
    UI.badge(String(year));
    const unread = (h.messages || []).filter((m) => m.from_hr && !m.seen).length;
    const tabs = [["leave", t("hr.tab_leave")], ["requests", `${t("hr.tab_requests")} (${h.requests.length})`], ["training", t("hr.tab_training")], ["messages", `${t("hr.tab_messages")}${unread ? ` (${unread})` : ""}`]];
    let body = "";
    if (htab === "leave") {
      body = `<div class="kv"><b>${esc(h.person.name)}</b><span class="v">${esc(h.person.department)} • ${esc(h.person.service)}${h.person.contractor ? " • " + t("hr.contractor") : ""}</span></div>
        <div class="kv"><b>${t("hr.manager")}</b><span class="v">${esc(h.manager || t("hr.no_manager"))}</span></div>
        <h2>${t("hr.balances")} ${year} <select id="yr" style="width:auto;display:inline-block;margin-left:6px;padding:2px 6px">${h.years.map((y) => `<option ${y === year ? "selected" : ""}>${y}</option>`).join("")}</select></h2>
        ${h.balances.length ? `<div class="overflow"><table class="week"><tr><th style="text-align:left">${t("hr.type")}</th><th>${t("hr.entitled")}</th><th>${t("hr.taken")}</th><th>${t("hr.pending")}</th><th>${t("hr.available")}</th></tr>
        ${h.balances.map((b) => { const un = b.unit === "hour" ? " h" : ""; return `<tr><td style="text-align:left"><b>${esc(b.code)}</b> <span class="muted">${esc(b.name)}</span>${b.consumes_annual ? ` <span class="muted">(${t("hr.consumes_ann")})</span>` : ""}</td><td>${b.entitled == null ? "—" : fh(b.entitled) + un}</td><td>${fh(b.taken)}${un}</td><td>${b.pending > 0 ? fh(b.pending) + un : "—"}</td><td style="${b.available != null && b.available < 0 ? "color:var(--red)" : ""}"><b>${b.available == null ? "—" : fh(b.available) + un}</b></td></tr>`; }).join("")}</table></div>` : `<p class="muted">${t("hr.no_balances")}</p>`}
        ${h.periods.length ? `<h2>${t("hr.periods")}</h2>${h.periods.map((lp) => `<div class="card ${lp.joined ? "c-green" : lp.mandatory ? "c-red" : "c-gold"}"><div class="t">${esc(lp.name)} <small>${esc(lp.code)}${lp.mandatory ? " • " + t("hr.mandatory") : ""}</small></div><div class="s">${fmt(lp.date_from)} – ${fmt(lp.date_to)}${lp.note ? ` • ${esc(lp.note)}` : ""}</div><div class="s muted">${lp.n_approved}${lp.max_people ? " / " + lp.max_people : ""} ${t("hr.joined_n")}</div>
          ${lp.joined ? `<div class="s">${stChip(lp.joined.status)} ${fmt(lp.joined.date_from)} – ${fmt(lp.joined.date_to)}</div>` : lp.mandatory ? `<div class="s muted">${t("hr.mandatory_hint")}</div>` : lp.full ? `<div class="s">${stChip("full")}</div>` : `<div class="row2" style="margin-top:6px"><input type="date" class="pf" value="${lp.date_from}" min="${lp.date_from}" max="${lp.date_to}"><input type="date" class="pt" value="${lp.date_to}" min="${lp.date_from}" max="${lp.date_to}"></div><button class="btn gold sm" data-join="${lp.id}">${t("hr.join")}</button>`}</div>`).join("")}` : ""}
        <h2>${t("hr.new_request")}</h2>
        <label>${t("hr.type")}</label><select id="type">${h.types.map((x) => `<option value="${x.id}" data-unit="${x.unit}" data-reason="${+x.needs_reason}" data-doc="${+x.needs_doc}" data-hr="${+x.hr_only}">${esc(x.code)} · ${esc(x.name)}${x.unit === "hour" ? " (h)" : ""}</option>`).join("")}</select><p class="muted" id="thint"></p>
        <div class="row2"><div><label>${t("hours.date")}</label><input id="from" type="date" value="${S().today()}"></div><div class="hr-day"><label>${t("hr.to")}</label><input id="to" type="date"></div><div class="hr-hour hidden"><label>${t("hr.hours")}</label><input id="hrs" type="number" step="0.5" min="0.5" max="12" placeholder="2"></div></div>
        <div class="row2 hr-hour hidden"><div><label>${t("hr.time")}</label><input id="tf" type="time"></div><div><label>&nbsp;</label><input id="tt" type="time"></div></div>
        <label>${t("hr.reason")} <span id="rreq" class="muted hidden">*</span></label><input id="reason" maxlength="300" placeholder="${t("hr.reason_ph")}">
        <label>${t("hr.document")} <span id="dreq" class="muted hidden">*</span></label><input id="doc" type="file" accept=".pdf,image/*"><p class="muted">${t("hr.doc_hint")}</p>
        <button class="btn gold" id="send">${t("hr.send_request")}</button><p class="muted">${t("hr.request_flow")}</p>`;
    } else if (htab === "requests") {
      const list = h.requests.map((r) => `<div class="card ${r.status === "approved" ? "c-green" : r.status.startsWith("pending") ? "c-amber" : r.status === "rejected" ? "c-red" : "c-grey"}"><div class="t">${esc(r.code)} <span class="muted">${esc(r.type)}</span> <small>${r.unit === "hour" ? fh(r.hours) + " h" : fh(r.days) + " " + t("hr.days")}</small></div>
        <div class="s">${fmt(r.date_from)}${r.date_to !== r.date_from ? " – " + fmt(r.date_to) : ""}${r.time_from ? ` ${r.time_from}–${r.time_to || ""}` : ""}${r.reason ? ` • ${esc(r.reason)}` : ""}${r.doc ? ` • <a href="#" data-doc="${r.id}">${esc(r.doc.name)}</a>` : ""}</div>
        <div class="s">${stChip(r.status)}${!r.counted ? " " + stChip("history_only") : ""}${r.manager ? ` <span class="muted">${esc(r.manager.name)} ${fmt(r.manager.at)}${r.manager.note ? " — " + esc(r.manager.note) : ""}</span>` : ""}${r.hr ? ` <span class="muted">HR ${fmt(r.hr.at)}${r.hr.note ? " — " + esc(r.hr.note) : ""}</span>` : ""}</div>
        ${r.cancellable ? `<button class="btn line sm" data-cancel="${r.id}">${t("hr.cancel")}</button>` : ""}</div>`).join("");
      body = `${queued.map((o) => `<div class="card ${o.status === "rejected" ? "c-red" : "c-amber"}"><div class="t">${esc(I18N.has("hr.k_" + o.kind) ? t("hr.k_" + o.kind) : o.kind)} <small>${o.status === "rejected" ? esc(o.error || "rejected") : t("sub.queued")}</small></div><div class="s muted">${esc(o.payload && (o.payload.text || o.payload.comment || o.payload.reason || [o.payload.date_from, o.payload.date_to].filter(Boolean).join(" – ")) || "")}</div>${o.status === "rejected" ? `<button class="btn line sm" data-drop="${o.id}">${t("more.discard")}</button>` : ""}</div>`).join("")}${list || `<p class="list-empty">${t("hr.no_requests")}</p>`}`;
    } else if (htab === "training") {
      body = `<h2>${t("hr.tr_open")}</h2>${h.trainings.open.map((x) => `<div class="card ${x.mine ? "c-green" : "c-gold"}"><div class="t">${esc(x.title)} <small>${esc(t("hr.trk_" + x.kind))}</small></div><div class="s">${esc(x.provider)}${x.location ? " · " + esc(x.location) : ""} • ${x.date_from ? fmt(x.date_from) + (x.date_to && x.date_to !== x.date_from ? " – " + fmt(x.date_to) : "") : t("hr.tba")}${x.hours != null ? ` • ${fh(x.hours)} h` : ""}</div>${x.description ? `<div class="s muted" style="white-space:pre-wrap">${esc(x.description)}</div>` : ""}<div class="s muted">${t("hr.apply_by")} ${x.deadline ? fmt(x.deadline) : "—"} • ${x.n_in}${x.capacity ? " / " + x.capacity : ""} ${t("hr.joined_n")}</div>
          ${x.mine && x.mine !== "rejected" ? `<div class="s">${stChip(x.mine)}</div>` : x.full ? stChip("full") : x.late ? stChip("late") : `<input class="trnote" placeholder="${t("hr.notes")}" maxlength="300"><button class="btn gold sm" data-apply="${x.id}">${t("hr.apply")}</button>`}</div>`).join("") || `<p class="muted">${t("hr.tr_none")}</p>`}
        <h2>${t("hr.tr_mine")}</h2>${h.trainings.mine.map((m) => `<div class="card c-grey"><div class="t">${esc(m.title)} <small>${esc(t("hr.trk_" + m.kind))}</small></div><div class="s">${esc(m.provider)} • ${m.date_from ? fmt(m.date_from) : "—"} • ${fh(m.hours)} h</div><div class="s">${stChip(m.status)}${m.score ? ` ${t("hr.score")} ${esc(m.score)}` : ""}${m.cert_no ? ` • ${esc(m.cert_no)}${m.cert_expiry ? " " + t("hr.until") + " " + fmt(m.cert_expiry) : ""}` : ""}</div></div>`).join("") || `<p class="muted">—</p>`}
        ${(h.evaluations || []).length ? `<h2>${t("hr.evaluations")}</h2>${h.evaluations.map((e) => `<div class="card ${e.status === "acknowledged" ? "c-green" : "c-amber"}"><div class="t">${e.year} • ${t("hr.overall")} <b>${fh(e.overall)}</b> / 5 <small>${stChip(e.status)}</small></div><div class="s muted">${esc(e.evaluator)} • ${fmt(e.date)}</div>
          ${e.scores.map((c) => `<div class="kv"><span>${esc(c.label)}</span><span class="v">${c.score == null ? "—" : c.score + " / 5"}</span></div>`).join("")}
          ${["strengths", "improvements", "goals", "training_needs", "comments"].filter((k) => e[k]).map((k) => `<div class="s"><b>${t("hr.ev_" + k)}</b><br>${esc(e[k])}</div>`).join("")}
          ${e.status === "final" ? `<label>${t("hr.ev_comment")}</label><textarea class="evc" rows="2" maxlength="4000"></textarea><button class="btn gold sm" data-ack="${e.year}">${t("hr.ev_ack")}</button>` : e.employee_comment ? `<div class="s muted"><b>${t("hr.ev_comment")}:</b> ${esc(e.employee_comment)}</div>` : ""}</div>`).join("")}` : ""}`;
    } else {
      body = `<p class="muted">${t("hr.msg_hint")}</p>${h.messages.map((m) => `<div class="card ${m.from_hr ? "c-gold" : "c-grey"}"><div class="t">${esc(m.from)} <small>${esc(m.at.slice(0, 16))}${m.from_hr && !m.seen ? " • " + t("hr.new") : ""}</small></div><div class="s" style="white-space:pre-wrap">${esc(m.body)}</div></div>`).join("") || `<p class="muted">—</p>`}
        <label>${t("hr.msg_to_hr")}</label><textarea id="mtx" rows="3" maxlength="2000"></textarea><button class="btn gold" id="msend">${t("hr.msg_send")}</button>`;
    }
    return { html: `<div class="seg">${tabs.map(([k, l]) => `<button data-t="${k}" class="${htab === k ? "on" : ""}">${l}</button>`).join("")}</div>${body}`, bind(root) {
      const $ = (s) => root.querySelector(s);
      root.querySelectorAll(".seg [data-t]").forEach((b) => b.onclick = () => { htab = b.dataset.t; UI.render(); if (htab === "messages" && unread) { DB.enqueue("hr_seen", {}); SYNC.run(false); } });
      const after = async (msg) => { UI.toast(msg || t("common.ok")); await SYNC.run(); UI.render(); };
      if ($("#yr")) $("#yr").onchange = async (e) => { year = +e.target.value; try { const d = await API.hr(year); await DB.put("meta", { id: "hr" + year, ...d }); } catch (x) {} UI.render(); };
      if ($("#type")) { const ui = () => { const o = $("#type").selectedOptions[0]; const hour = o.dataset.unit === "hour"; root.querySelectorAll(".hr-day").forEach((x) => x.classList.toggle("hidden", hour)); root.querySelectorAll(".hr-hour").forEach((x) => x.classList.toggle("hidden", !hour)); $("#rreq").classList.toggle("hidden", o.dataset.reason !== "1"); $("#dreq").classList.toggle("hidden", o.dataset.doc !== "1"); $("#thint").textContent = (hour ? t("hr.hint_hour") : t("hr.hint_day")) + (o.dataset.hr === "1" ? " " + t("hr.hint_hr") : " " + t("hr.hint_mgr")); }; $("#type").onchange = ui; ui();
        $("#send").onclick = async () => { const o = $("#type").selectedOptions[0]; const p = { type_id: +$("#type").value, date_from: $("#from").value, date_to: $("#to").value || $("#from").value, reason: $("#reason").value.trim(), hours: $("#hrs").value, time_from: $("#tf").value, time_to: $("#tt").value };
          if (!p.date_from) return UI.toast(t("hr.need_date")); if (o.dataset.reason === "1" && !p.reason) return UI.toast(t("hr.need_reason")); if (o.dataset.unit === "hour" && !(+p.hours > 0)) return UI.toast(t("hr.need_hours"));
          const file = $("#doc").files[0]; if (o.dataset.doc === "1" && !file) return UI.toast(t("hr.need_doc"));
          $("#send").disabled = true;
          if (file) { if (!navigator.onLine && !API.mock) { $("#send").disabled = false; return UI.toast(t("hr.doc_online")); } try { const r = await API.hrRequest(p, file); UI.toast(`${t("hr.sent")} — ${r.record_status || r.status}`); htab = "requests"; await SYNC.run(); UI.render(); } catch (e) { UI.toast(e.message || t("ai.error"), 4000); $("#send").disabled = false; } return; }
          await DB.enqueue("hr_request", p); SYNC.run(false); UI.toast(t("hr.sent")); htab = "requests"; UI.render(); }; }
      root.querySelectorAll("[data-join]").forEach((b) => b.onclick = async () => { const card = b.closest(".card"); await DB.enqueue("hr_period_join", { period_id: +b.dataset.join, date_from: card.querySelector(".pf").value, date_to: card.querySelector(".pt").value }); b.disabled = true; after(t("hr.sent")); });
      root.querySelectorAll("[data-drop]").forEach((b) => b.onclick = async () => { await DB.del("outbox", b.dataset.drop); UI.render(); });   // R72-002: a rejected HR operation can be discarded here
      root.querySelectorAll("[data-cancel]").forEach((b) => b.onclick = () => UI.sheet(`<h3>${t("hr.cancel")}</h3><p>${t("hr.cancel_confirm")}</p><button class="btn red" id="ok">${t("hr.cancel")}</button>`, async () => { UI.closeSheet(); await DB.enqueue("hr_cancel", { request_id: +b.dataset.cancel }); after(); }));
      root.querySelectorAll("[data-doc]").forEach((a) => a.onclick = (e) => { e.preventDefault(); UI.openDocument(+a.dataset.doc, "hr"); });
      root.querySelectorAll("[data-apply]").forEach((b) => b.onclick = async () => { await DB.enqueue("hr_training_apply", { training_id: +b.dataset.apply, note: b.closest(".card").querySelector(".trnote").value.trim() }); b.disabled = true; after(t("hr.sent")); });
      root.querySelectorAll("[data-ack]").forEach((b) => b.onclick = async () => { await DB.enqueue("hr_eval_ack", { year: +b.dataset.ack, comment: b.closest(".card").querySelector(".evc").value.trim() }); b.disabled = true; after(); });
      if ($("#msend")) $("#msend").onclick = async () => { const tx = $("#mtx").value.trim(); if (!tx) return; await DB.enqueue("hr_message", { text: tx }); $("#mtx").value = ""; after(t("hr.sent")); };
      UI.assistContext({ page: "timesheet_edit", title: "My HR — leave", headline: h.person.name, fields: () => h.balances.map((b) => ({ name: "bal_" + b.code, label: `${b.code} ${b.name} available`, value: String(b.available ?? "—") + (b.unit === "hour" ? " h" : " days"), type: "text" })), noFill: true });
    } };
  }
  return { page, team, matrixHtml };
})();
