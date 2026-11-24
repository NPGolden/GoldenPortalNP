/* Golden HR 1.1.0 — Today, recruiting (candidates, CV reader, evaluations, interviews), job-description approvals, the HR year,
 * expiring documents and equipment handovers. GAS-CP-R97-156.
 * These lists are read live from the portal and held in memory only — nothing of them is written to the phone (candidates are
 * personal data under the recruiting retention rules). They need a connection; Today shows the last counts when offline. */
window.HRX = (() => {
  const t = I18N.t, esc = SCREENS.esc, fmt = SCREENS.fmt, ctx = SCREENS.ctx;
  const mem = {};   // key -> { at, data }
  async function live(key, fn, maxAgeMs) {
    const hit = mem[key]; if (hit && Date.now() - hit.at < (maxAgeMs == null ? 45000 : maxAgeMs)) return hit.data;
    try { const data = await fn(); mem[key] = { at: Date.now(), data }; return data; } catch (e) { if (hit) return hit.data; return { _err: e.status === 403 ? (e.data && e.data.error) || t("x.noaccess") : t("x.offline") }; }
  }
  const drop = (...keys) => keys.forEach((k) => { Object.keys(mem).forEach((m) => { if (m === k || m.startsWith(k + ":")) delete mem[m]; }); });
  const wipe = () => Object.keys(mem).forEach((k) => delete mem[k]);
  const errBox = (m) => `<p class="note red">${esc(m)}</p><button class="btn line" id="retry">${t("x.retry")}</button>`;
  const bindRetry = (root, key) => { const b = root.querySelector("#retry"); if (b) b.onclick = () => { drop(key); UI.render(); }; };
  const dt = (s) => s ? `${fmt(s)} ${String(s).slice(11, 16)}` : "";
  const empty = (k) => `<p class="list-empty">${t(k)}</p>`;
  const days = (n) => `${n} ${t("unit.d")}`;
  /** Runs a decision call from a sheet; shows the authenticator / validation error inside the sheet. */
  async function act(sh, fn, after) {
    const ok = sh.querySelector("#ok"), er = sh.querySelector("#err"); ok.disabled = true;
    try { const r = await fn(); UI.closeSheet(); UI.toast(t("x.saved")); if (after) await after(r); UI.render(); SYNC.run(false); }
    catch (e) { ok.disabled = false; const d = e.data || {}; er.textContent = d.totp ? t("dec.code_bad") : d.error || (e.status ? `HTTP ${e.status}` : t("x.offline")); er.classList.remove("hidden"); if (e.status === 409) { wipe(); } }
  }

  // ---------------------------------------------------------------- today
  async function today() {
    UI.chrome(true, t("tab.today"), "today");
    const me = ctx.me || {}; const d = me.today || {}; const c = me.counts || {};
    const tile = (go, ico, label, n, sub, tone) => `<div class="tile ${tone || ""}" data-go="${go}"><span class="ico">${ico}</span><b class="n">${n == null ? "" : n}</b><span class="l">${label}</span>${sub ? `<small>${sub}</small>` : ""}</div>`;
    const tone = (n, bad) => !n ? "" : bad ? "t-red" : "t-gold";
    const rc = d.recruiting, jd = d.jd, yr = d.year, ex = d.expiring, eq = d.equipment;
    const tiles = [
      tile("#/inbox", "✅", t("today.inbox"), c.inbox || 0, `${c.requests || 0} ${t("inbox.requests").toLowerCase()} · ${c.trainings || 0} ${t("inbox.trainings").toLowerCase()} · ${c.timesheets || 0} ${t("inbox.timesheets").toLowerCase()}`, tone(c.inbox)),
      tile("#/messages", "✉", t("tab.messages"), c.unread || 0, t("today.unread"), tone(c.unread)),
      rc ? tile("#/recruiting", "🧲", t("tab.recruiting"), rc.new, `${t("today.new_cv")} · ${rc.active} ${t("today.in_process")}${rc.interviews_today ? ` · ${rc.interviews_today} ${t("today.int_today")}` : ""}`, tone(rc.new + rc.interviews_today)) : "",
      jd ? tile("#/jds", "📄", t("jd.title"), jd.mine, `${t("today.jd_mine")} · ${jd.review} ${t("today.jd_review")}`, tone(jd.mine)) : "",
      yr ? tile("#/year", "🗓", t("yr.title"), yr.late, `${t("today.late")} · ${yr.due} ${t("today.due")}`, tone(yr.late, true) || tone(yr.due)) : "",
      ex ? tile("#/expiring", "⏳", t("exp.title"), ex.d30, `${t("today.d30")} · ${ex.d60} ${t("today.d60")}${ex.expired ? ` · ${ex.expired} ${t("exp.expired")}` : ""}`, tone(ex.expired, true) || tone(ex.d30)) : "",
      eq ? tile("#/equipment", "🧰", t("eq.title"), eq.returns, `${t("today.eq_returns")} · ${eq.issues} ${t("today.eq_issues")}${eq.leavers ? ` · ${eq.leavers} ${t("today.eq_leavers")}` : ""}`, tone(eq.returns + eq.leavers)) : "",
      tile("#/people", "👥", t("tab.people"), c.away_today || 0, t("people.away_today"), ""),
      tile("#/calendar", "📅", t("tab.calendar"), null, t("today.calendar"), ""),
      tile("#/balances", "🏖", t("tab.balances"), null, t("today.balances"), ""),
    ].join("");
    return { html: `<p class="muted">${esc(me.name || "")}${d.inbox == null && !navigator.onLine ? ` · ${t("sync.off")}` : ""}</p><div class="tiles">${tiles}</div><p class="muted" style="text-align:center">${t("today.hint")}</p>`, bind(root) { UI.goLinks(root); } };
  }

  // ---------------------------------------------------------------- recruiting
  let rtab = "candidates", rst = "", rvac = "", rq = "";
  const stTone = (s) => s === "offer" ? "green" : s === "interview" ? "gold" : s === "shortlisted" ? "navy" : s === "rejected" || s === "withdrawn" ? "red" : s === "cv_received" ? "amber" : "grey";
  const stChip = (a) => `<span class="chip ${stTone(a.status)}">${esc(a.status_label || a.status)}</span>`;
  const score = (a) => `${a.score != null ? `${t("rc.screen")} ${a.score}/${a.max_score}` : ""}${a.eval_count ? ` · ${t("rc.eval")} ${a.eval_avg} (${a.eval_count})` : ""}`;
  async function recruiting(q) {
    UI.chrome(true, t("tab.recruiting"), "recruiting"); if (q && q.tab) rtab = q.tab; if (q && q.vac !== undefined) { rvac = q.vac; rtab = "candidates"; }
    const R = await live("rc", API.recruiting); if (R._err) return { html: errBox(R._err), bind(root) { bindRetry(root, "rc"); } };
    const seg = `<div class="seg" data-k="rtab"><button data-v="candidates" class="${rtab === "candidates" ? "on" : ""}">${t("rc.candidates")} (${R.applications.length})</button><button data-v="interviews" class="${rtab === "interviews" ? "on" : ""}">${t("rc.interviews")}${R.interviews.length ? ` (${R.interviews.length})` : ""}</button><button data-v="vacancies" class="${rtab === "vacancies" ? "on" : ""}">${t("rc.vacancies")} (${R.vacancies.length})</button></div>`;
    let body = "";
    if (rtab === "candidates") {
      const f = rq.toLowerCase(); const sts = ["", "cv_received", "shortlisted", "interview", "offer"];
      const list = R.applications.filter((a) => (!rst || (rst === "gen" ? a.general : a.status === rst)) && (!rvac || String(a.vacancy_id) === String(rvac)) && (!f || `${a.name} ${a.code} ${a.vacancy}`.toLowerCase().includes(f)));
      body = `<div class="chips">${sts.map((s) => `<span class="chip tap ${rst === s ? "on" : ""}" data-st="${s}">${s ? esc(R.labels.status[s]) : t("rc.all")} ${R.applications.filter((a) => !s || a.status === s).length}</span>`).join("")}${R.applications.some((a) => a.general) ? `<span class="chip tap ${rst === "gen" ? "on" : ""}" data-st="gen">📄 ${t("rc.general")} ${R.applications.filter((a) => a.general).length}</span>` : ""}</div>
        <select id="rvac" data-nosearch><option value="">${t("rc.all_vac")}</option>${R.vacancies.map((v) => `<option value="${v.id}" ${String(v.id) === String(rvac) ? "selected" : ""}>${esc(v.code)} · ${esc(v.title)}</option>`).join("")}</select>
        <input id="rq" type="search" placeholder="${t("rc.search")}" value="${esc(rq)}" autocomplete="off">
        ${list.map((a) => `<div class="card tap c-${stTone(a.status) === "navy" ? "navy" : stTone(a.status)}" data-go="#/candidate/${a.id}"><div class="t"><span>${esc(a.name)}</span>${stChip(a)}</div><div class="s">${a.general ? `<span class="chip navy">${esc(a.code)}</span> ` : ""}${esc(a.vacancy)}</div><div class="s muted">${score(a)}${score(a) ? " · " : ""}${days(a.days)} ${t("rc.in_stage")}${a.has_cv ? " · 📎 CV" : ""}</div></div>`).join("") || empty("rc.none")}`;
    } else if (rtab === "interviews") {
      const now = new Date().toISOString().slice(0, 10);
      body = R.interviews.map((i) => `<div class="card tap ${i.at.slice(0, 10) === now ? "c-gold" : i.at.slice(0, 10) < now ? "c-red" : "c-navy"}" data-go="#/candidate/${i.application_id}"><div class="t"><span>${esc(i.name)}</span><small>${esc(dt(i.at))}</small></div><div class="s">${esc(i.stage_label)} · ${esc(i.mode_label)}${i.location ? ` · ${esc(i.location)}` : ""} · ${i.duration} min</div><div class="s muted">${esc(i.vacancy)}${i.interviewers ? ` · ${esc(i.interviewers)}` : ""}${i.at.slice(0, 10) < now ? ` · ${t("rc.int_open")}` : ""}</div></div>`).join("") || empty("rc.no_interviews");
    } else {
      body = R.vacancies.map((v) => `<div class="card tap c-navy" data-go="#/recruiting?vac=${v.id}"><div class="t"><span>${esc(v.title)}</span><small>${esc(v.code)}</small></div><div class="s">${[v.department, v.branch, v.hiring_manager].filter(Boolean).map(esc).join(" · ")}</div>
        <div class="chips">${["cv_received", "shortlisted", "interview", "offer", "hired", "rejected"].filter((s) => v.stages[s]).map((s) => `<span class="chip ${stTone(s)}">${esc(R.labels.status[s])} ${v.stages[s]}</span>`).join("") || `<span class="chip grey">${t("rc.no_apps")}</span>`}</div>
        <div class="s muted">${v.n_apps} ${t("rc.applications")} · ${v.n_passed} ${t("rc.passed")}${v.closes ? ` · ${t("rc.closes")} ${fmt(v.closes)}` : ""}</div></div>`).join("") || empty("rc.no_vacancies");
    }
    return { html: seg + body, bind(root) {
      UI.goLinks(root); UI.segs(root, (k, v) => { rtab = v; UI.render(); }); bindRetry(root, "rc");
      root.querySelectorAll("[data-st]").forEach((c) => c.onclick = () => { rst = c.dataset.st; UI.render(); });
      const sv = root.querySelector("#rvac"); if (sv) sv.onchange = () => { rvac = sv.value; UI.render(); };
      const inp = root.querySelector("#rq"); if (inp) inp.oninput = () => { rq = inp.value; clearTimeout(inp._t); inp._t = setTimeout(() => { const v = inp.value; UI.render().then(() => { const i2 = document.querySelector("#rq"); if (i2) { i2.focus(); i2.setSelectionRange(v.length, v.length); } }); }, 250); };
    } };
  }
  async function candidate(id) {
    const R = await live("rc", API.recruiting); const A = await live("app:" + id, () => API.application(id), 20000);
    if (A._err || R._err) { UI.chrome(true, t("tab.recruiting"), "recruiting", true); return { html: errBox(A._err || R._err), bind(root) { bindRetry(root, "app:" + id); } }; }
    UI.chrome(true, A.name, "recruiting", true);
    const kv = (k, v, href) => v ? `<div class="kv"><b>${t(k)}</b><span class="v wrap">${href ? `<a href="${href}">${esc(v)}</a>` : esc(v)}</span></div>` : "";
    const closed = ["hired", "withdrawn"].includes(A.status); const can = (R.labels.can_set || []).filter((s) => s !== A.status);
    const btnTone = { shortlisted: "navy", interview: "navy", offer: "gold", rejected: "red" };
    return { html: `<div class="card c-${stTone(A.status) === "navy" ? "navy" : stTone(A.status)}"><div class="t"><span>${esc(A.name)}</span>${stChip(A)}</div><div class="s">${esc(A.vacancy_code)} · ${esc(A.vacancy)}</div>${A.general ? `<div class="s"><span class="chip navy">📄 ${t("rc.general_app")}</span></div>` : ""}<div class="s muted">${esc(A.code)} · ${t("rc.applied")} ${fmt(A.applied)} · ${days(A.days)} ${t("rc.in_stage")}</div><div class="s muted">${score(A)}</div></div>
      ${A.has_cv ? (A.cv_pdf ? `<button class="btn gold" data-go="#/cv/${A.id}">📎 ${t("rc.read_cv")}</button>` : `<p class="note">📎 ${esc(A.cv_name)} — ${t("rc.cv_not_pdf")}</p>`) : `<p class="muted">${t("rc.no_cv")}</p>`}
      ${kv("people.email", A.email, `mailto:${A.email}`)}${kv("people.phone_mobile", A.phone, `tel:${A.phone}`)}${kv("rc.interest", A.department_interest)}
      ${A.cover_note ? `<h2>${t("rc.cover")}</h2><p class="pre">${esc(A.cover_note)}</p>` : ""}${A.hr_notes ? `<h2>${t("rc.hr_notes")}</h2><p class="pre">${esc(A.hr_notes)}</p>` : ""}
      ${closed ? "" : `<h2>${t("rc.move")}</h2><div class="ai-actions">${can.map((s) => `<button class="btn ${btnTone[s] || "line"} sm" data-move="${s}">${esc(R.labels.status[s])}</button>`).join("")}</div><p class="muted">${t("rc.hired_portal")}</p>`}
      <h2>${t("rc.interviews")}</h2>${A.interviews.length ? A.interviews.map((i) => `<div class="card ${i.status === "planned" ? "c-gold" : i.status === "done" ? "c-green" : "c-grey"}"><div class="t"><span>${esc(i.stage_label)}</span><small>${esc(dt(i.at))}</small></div><div class="s">${esc(i.mode_label)}${i.location ? ` · ${esc(i.location)}` : ""} · ${i.duration} min${i.interviewers ? ` · ${esc(i.interviewers)}` : ""}</div>
        ${i.status === "planned" ? `<div class="s"><span class="chip ${i.notified ? "green" : "amber"}">✉ ${i.notified ? `${t("rc.int_notified")} ${esc(dt(i.notified))}` : t("rc.int_not_notified")}</span></div>${(A.int_opts || {}).can_edit ? `<div class="row2"><button class="btn gold sm" data-inted="${i.id}">✎ ${t("rc.int_edit")}</button><button class="btn line sm" data-intml="${i.id}">✉ ${t("rc.int_invite")}</button></div>` : ""}<div class="row2"><button class="btn navy sm" data-int="${i.id}:done">${t("rc.int_done")}</button><button class="btn line sm" data-int="${i.id}:no_show">${t("rc.int_noshow")}</button></div>` : `<div class="s muted">${t("rc.int_" + i.status)}</div>`}</div>`).join("") : `<p class="muted">${t("rc.no_interviews")} ${t("rc.plan_portal")}</p>`}
      <h2>${t("rc.evaluations")}</h2>${A.evaluations.map((e) => `<div class="card c-navy"><div class="t"><span>${esc(e.stage_label)} — ${esc(e.by)}</span><small>${e.total} / 100</small></div><div class="s"><span class="chip ${e.reco === "no_hire" ? "red" : e.reco === "hold" ? "amber" : "green"}">${esc(e.reco_label)}</span> <span class="muted">${fmt(e.at)}</span></div>${e.strengths ? `<div class="s">＋ ${esc(e.strengths)}</div>` : ""}${e.concerns ? `<div class="s">－ ${esc(e.concerns)}</div>` : ""}${e.notes ? `<div class="s muted">${esc(e.notes)}</div>` : ""}</div>`).join("") || `<p class="muted">${t("rc.no_eval")}</p>`}
      ${R.criteria.length && !closed ? `<button class="btn line" id="addeval">＋ ${t("rc.add_eval")}</button>` : ""}`, bind(root) {
      UI.goLinks(root);
      root.querySelectorAll("[data-move]").forEach((b) => b.onclick = () => moveSheet(A, b.dataset.move, R));
      root.querySelectorAll("[data-int]").forEach((b) => b.onclick = async () => { const [iid, st] = b.dataset.int.split(":"); b.disabled = true; try { await API.interviewStatus(iid, st); drop("rc", "app:" + A.id); UI.toast(t("x.saved")); } catch (e) { UI.toast((e.data && e.data.error) || t("x.offline"), 4000); } UI.render(); });
      root.querySelectorAll("[data-inted]").forEach((b) => b.onclick = () => intEditSheet(A, A.interviews.find((x) => String(x.id) === b.dataset.inted)));
      root.querySelectorAll("[data-intml]").forEach((b) => b.onclick = () => intMailSheet(A, A.interviews.find((x) => String(x.id) === b.dataset.intml), b));
      const ae = root.querySelector("#addeval"); if (ae) ae.onclick = () => evalSheet(A, R);
    } };
  }
  /* R97-230: change a planned interview; read, adjust and send its invitation (the portal's e-mail template) */
  function intEditSheet(A, i) {
    if (!i) return; const o = A.int_opts || { stage: {}, mode: {} }; const opt = (m, sel) => Object.keys(m).map((k) => `<option value="${k}" ${k === sel ? "selected" : ""}>${esc(m[k])}</option>`).join("");
    UI.sheet(`<h3>${t("rc.int_edit")} — ${esc(A.name)}</h3><label>${t("rc.stage")}</label><select id="stage" data-nosearch>${opt(o.stage, i.stage)}</select>
      <label>${t("rc.int_when")}</label><input type="datetime-local" id="at" value="${esc(String(i.at).slice(0, 16).replace(" ", "T"))}">
      <label>${t("rc.int_duration")}</label><input type="number" id="dur" min="15" max="480" step="15" value="${i.duration}">
      <label>${t("rc.int_mode")}</label><select id="mode" data-nosearch>${opt(o.mode, i.mode)}</select>
      <label>${t("rc.int_place")}</label><input id="loc" maxlength="200" value="${esc(i.location)}"><label>${t("rc.int_who")}</label><input id="who" maxlength="300" value="${esc(i.interviewers)}">
      <p class="muted">${t("rc.int_edit_hint")}</p><p id="err" class="note red hidden"></p><button class="btn navy" id="ok">${t("x.save")}</button>`,
      (sh) => { const v = (id) => sh.querySelector(id).value.trim(); const er = sh.querySelector("#err"); if (!v("#at")) { er.textContent = t("rc.int_when"); er.classList.remove("hidden"); return; }
        act(sh, () => API.interviewUpdate(i.id, { at: v("#at"), stage: v("#stage"), mode: v("#mode"), duration: +v("#dur") || 60, location: v("#loc"), interviewers: v("#who") }), () => drop("rc", "app:" + A.id)); });
  }
  async function intMailSheet(A, i, btn) {
    if (!i) return; let d; if (btn) btn.disabled = true;
    try { d = await API.interviewInvitation(i.id); } catch (e) { UI.toast((e.data && e.data.error) || t("x.offline"), 4000); if (btn) btn.disabled = false; return; }
    if (btn) btn.disabled = false;
    UI.sheet(`<h3>✉ ${t("rc.int_invite")} — ${esc(A.name)}</h3><p class="muted">${esc(d.to)}</p><label>${t("rc.int_subject")}</label><input id="sub" maxlength="200" value="${esc(d.subject)}">
      <label>${t("rc.int_text")}</label><textarea id="txt" rows="12" maxlength="8000">${esc(d.body)}</textarea><p class="muted">${t("rc.int_mail_hint")}</p><p id="err" class="note red hidden"></p><button class="btn gold" id="ok">✉ ${t(i.notified ? "rc.int_send_again" : "rc.int_send")}</button>`,
      async (sh) => { const ok = sh.querySelector("#ok"), er = sh.querySelector("#err"); ok.disabled = true;
        try { const r = await API.interviewInvite(i.id, { subject: sh.querySelector("#sub").value, body: sh.querySelector("#txt").value }); UI.closeSheet(); drop("rc", "app:" + A.id); UI.toast(t(r.sent ? "rc.int_sent" : "rc.int_not_sent"), r.sent ? 2500 : 6000); UI.render(); }
        catch (e) { ok.disabled = false; er.textContent = (e.data && e.data.error) || (e.status ? `HTTP ${e.status}` : t("x.offline")); er.classList.remove("hidden"); } });
  }
  function moveSheet(A, st, R) {
    const rej = st === "rejected"; const mail = ["shortlisted", "offer", "rejected"].includes(st);
    UI.sheet(`<h3>${esc(R.labels.status[st])} — ${esc(A.name)}</h3><p class="muted">${esc(A.vacancy)}</p><label>${t(rej ? "rc.reason" : "rc.note")}${rej ? " *" : ""}</label><textarea id="note" rows="3" maxlength="500"></textarea>
      ${mail ? `<label class="check"><input type="checkbox" id="notify"> ${t("rc.notify")}</label>` : ""}${UI.totpField()}<p id="err" class="note red hidden"></p><button class="btn ${rej ? "red" : "navy"}" id="ok">${esc(R.labels.status[st])}</button>`,
      (sh) => { const note = sh.querySelector("#note").value.trim(); const er = sh.querySelector("#err"); if (rej && !note) { er.textContent = t("rc.reason_req"); er.classList.remove("hidden"); return; }
        const totp = UI.totpValue(sh); if (totp === false) return; const nf = sh.querySelector("#notify");
        act(sh, () => API.appStatus(A.id, { status: st, from: A.status, note, notify: !!(nf && nf.checked), totp: totp || undefined }), () => drop("rc", "app:" + A.id)); });
  }
  function evalSheet(A, R) {
    const opt = (o, sel) => Object.keys(o).map((k) => `<option value="${k}" ${k === sel ? "selected" : ""}>${esc(o[k])}</option>`).join("");
    UI.sheet(`<h3>${t("rc.add_eval")} — ${esc(A.name)}</h3><label>${t("rc.stage")}</label><select id="stage" data-nosearch>${opt(R.labels.stage, A.status === "interview" ? "technical" : "cv_review")}</select>
      ${R.criteria.map((c) => `<label>${esc(c.label)} <small class="muted">${c.weight}%</small></label><div class="seg sc" data-c="${esc(c.key)}">${[1, 2, 3, 4, 5].map((n) => `<button type="button" data-v="${n}">${n}</button>`).join("")}</div>`).join("")}
      <label>${t("rc.reco")}</label><select id="reco" data-nosearch>${opt(R.labels.reco, "hold")}</select>
      <label>${t("rc.strengths")}</label><textarea id="str" rows="2" maxlength="2000"></textarea><label>${t("rc.concerns")}</label><textarea id="con" rows="2" maxlength="2000"></textarea><label>${t("rc.notes")}</label><textarea id="nts" rows="2" maxlength="4000"></textarea>
      <p id="err" class="note red hidden"></p><button class="btn navy" id="ok">${t("x.save")}</button>`,
      (sh) => { const sc = {}; sh.querySelectorAll(".seg.sc").forEach((s) => { const on = s.querySelector("button.on"); if (on) sc[s.dataset.c] = +on.dataset.v; }); const er = sh.querySelector("#err");
        if (Object.keys(sc).length < R.criteria.length) { er.textContent = t("rc.score_all"); er.classList.remove("hidden"); return; }
        act(sh, () => API.appEval(A.id, { stage: sh.querySelector("#stage").value, score: sc, recommendation: sh.querySelector("#reco").value, strengths: sh.querySelector("#str").value, concerns: sh.querySelector("#con").value, notes: sh.querySelector("#nts").value }), () => drop("rc", "app:" + A.id)); },
      (sh) => sh.querySelectorAll(".seg.sc").forEach((s) => s.querySelectorAll("button").forEach((b) => b.onclick = () => s.querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b)))));
  }
  let pdfHandle = null;
  async function cv(id) {
    const A = mem["app:" + id] ? mem["app:" + id].data : await live("app:" + id, () => API.application(id));
    UI.chrome(true, (A && A.name) || "CV", "recruiting", true);
    return { html: `<p class="muted">${t("rc.cv_hint")}</p><div id="pdfbox" class="pdfbox"><p class="list-empty">${t("sync.loading")}</p></div>`, async bind(root) {
      const box = root.querySelector("#pdfbox"); if (pdfHandle) { try { pdfHandle.destroy(); } catch (e) {} pdfHandle = null; }
      try { const blob = await API.cvBlob(id); if (!document.body.contains(box)) return; pdfHandle = await PDFV.mount(box, blob); }
      catch (e) { box.innerHTML = `<p class="note red">${esc((e.data && e.data.error) || (e.status === 404 ? t("rc.no_cv") : t("x.offline")))}</p>`; }
    } };
  }

  // ---------------------------------------------------------------- job descriptions in review
  const stepChips = (j) => `<div class="chips">${j.steps.map((s) => `<span class="chip ${s.state === "done" ? "green" : s.next ? (s.mine ? "gold" : "amber") : "grey"}">${s.state === "done" ? "✓ " : s.state === "skip" ? "– " : s.next ? "▶ " : ""}${esc(s.label)}${s.state === "done" && s.name ? ` · ${esc(s.name)}` : ""}</span>`).join("")}</div>`;
  const jdBtns = (j) => j.mine ? `<div class="row2"><button class="btn navy sm" data-jd="${j.id}:1">${t("dec.approve")}</button><button class="btn red sm" data-jd="${j.id}:0">${t("jd.return")}</button></div>` : `<p class="muted">${t("jd.not_mine")}</p>`;
  function jdBind(root, J) { root.querySelectorAll("[data-jd]").forEach((b) => b.onclick = (e) => { e.stopPropagation(); const [id, ok] = b.dataset.jd.split(":"); const j = J.items.find((x) => x.id === +id); if (j) jdSheet(j, ok === "1"); }); }
  function jdSheet(j, approve) {
    UI.sheet(`<h3>${approve ? t("dec.approve") : t("jd.return")} — ${esc(j.code)} ${esc(j.rev)}</h3><p class="muted">${esc(j.title)}${j.level ? ` · ${esc(j.level)}` : ""}</p>${approve ? `<p class="note">${t("jd.sign_hint")}</p>` : `<label>${t("jd.return_note")} *</label><textarea id="note" rows="3" maxlength="500"></textarea>`}${UI.totpField()}<p id="err" class="note red hidden"></p><button class="btn ${approve ? "navy" : "red"}" id="ok">${approve ? t("dec.approve") : t("jd.return")}</button>`,
      (sh) => { const n = sh.querySelector("#note"); const note = n ? n.value.trim() : ""; const er = sh.querySelector("#err"); if (!approve && !note) { er.textContent = t("dec.note_req"); er.classList.remove("hidden"); return; }
        const totp = UI.totpValue(sh); if (totp === false) return;
        act(sh, () => API.jdDecide(j.id, { approve, note, totp: totp || undefined }), () => { drop("jds"); if (/^#\/jd\//.test(location.hash)) location.hash = "#/jds"; }); });
  }
  async function jds() {
    UI.chrome(true, t("jd.title"), "today");
    const J = await live("jds", API.jds); if (J._err) return { html: errBox(J._err), bind(root) { bindRetry(root, "jds"); } };
    const items = J.items.slice().sort((a, b) => (b.mine - a.mine) || (b.days - a.days));
    return { html: `<p class="muted">${t("jd.lede")}</p>${items.map((j) => `<div class="card tap ${j.mine ? "c-gold" : "c-grey"}" data-go="#/jd/${j.id}"><div class="t"><span>${esc(j.title)}</span><small>${esc(j.code)} ${esc(j.rev)}</small></div><div class="s">${[j.level, j.discipline].filter(Boolean).map(esc).join(" · ")}${j.rev_title ? ` — ${esc(j.rev_title)}` : ""}</div><div class="s muted">${t("jd.sent")} ${esc(j.submitted_by)} · ${days(j.days)}${j.people ? ` · ${j.people} ${t("jd.people")}` : ""}</div>${stepChips(j)}${jdBtns(j)}</div>`).join("") || empty("jd.none")}`, bind(root) { UI.goLinks(root); jdBind(root, J); } };
  }
  async function jd(id) {
    const J = await live("jds", API.jds); const j = !J._err && J.items.find((x) => x.id === +id);
    UI.chrome(true, j ? `${j.code} ${j.rev}` : t("jd.title"), "today", true);
    if (!j) return { html: J._err ? errBox(J._err) : empty("jd.gone"), bind(root) { bindRetry(root, "jds"); } };
    return { html: `<div class="card ${j.mine ? "c-gold" : "c-grey"}"><div class="t"><span>${esc(j.title)}</span><small>${esc(j.rev)}</small></div><div class="s">${[j.level, j.discipline].filter(Boolean).map(esc).join(" · ")}</div>${j.reports_to ? `<div class="s muted">${t("jd.reports_to")} ${esc(j.reports_to)}</div>` : ""}${stepChips(j)}</div>
      ${j.purpose ? `<h2>${t("jd.purpose")}</h2><p class="pre">${esc(j.purpose)}</p>` : ""}${j.sections.map((s) => `<h2>${t("jd.sec_" + s.key)}</h2><ul class="lines">${s.lines.map((l) => `<li>${esc(l)}</li>`).join("")}</ul>`).join("")}${jdBtns(j)}`, bind(root) { jdBind(root, J); } };
  }

  // ---------------------------------------------------------------- the HR year
  async function year() {
    UI.chrome(true, t("yr.title"), "today");
    const Y = await live("year", API.year); if (Y._err) return { html: errBox(Y._err), bind(root) { bindRetry(root, "year"); } };
    const chip = (s) => `<span class="chip ${s.state === "done" ? "green" : s.state === "late" ? "red" : s.state === "due" ? "gold" : "grey"}">${s.state === "late" ? `${t("yr.late")} ${days(s.late_days)}` : t("yr.s_" + s.state)}</span>`;
    return { html: Y.years.length ? Y.years.map((y) => `<h2>${y.year}</h2>${y.steps.map((s) => `<div class="card ${s.state === "done" ? "c-green" : s.state === "late" ? "c-red" : s.state === "due" ? "c-gold" : "c-grey"}"><div class="t"><span>${esc(s.label)}</span>${chip(s)}</div><div class="s">${esc(s.month)}${s.figure ? ` · ${esc(s.figure)}` : ""}</div>
      ${s.tick ? `<div class="s muted">✓ ${esc(s.tick.by)} ${fmt(s.tick.at)}${s.tick.note ? ` — ${esc(s.tick.note)}` : ""}</div><button class="btn line sm" data-undo="${y.year}:${s.key}">${t("yr.undo")}</button>` : s.state === "done" ? `<div class="s muted">${t("yr.auto")}</div>` : s.state === "later" ? "" : `<button class="btn navy sm" data-tick="${y.year}:${s.key}">${t("yr.mark")}</button>`}</div>`).join("")}`).join("") : empty("yr.none"), bind(root) {
      root.querySelectorAll("[data-tick]").forEach((b) => b.onclick = () => { const [yy, key] = b.dataset.tick.split(":"); const s = Y.years.find((x) => x.year === +yy).steps.find((x) => x.key === key);
        UI.sheet(`<h3>${t("yr.mark")} — ${esc(s.label)} ${yy}</h3><label>${t("rc.note")}</label><textarea id="note" rows="2" maxlength="300"></textarea><p id="err" class="note red hidden"></p><button class="btn navy" id="ok">${t("yr.mark")}</button>`, (sh) => act(sh, () => API.yearTick({ year: +yy, step: key, note: sh.querySelector("#note").value.trim() }), () => drop("year"))); });
      root.querySelectorAll("[data-undo]").forEach((b) => b.onclick = async () => { const [yy, key] = b.dataset.undo.split(":"); b.disabled = true; try { await API.yearTick({ year: +yy, step: key, undo: true }); drop("year"); SYNC.run(false); } catch (e) { UI.toast((e.data && e.data.error) || t("x.offline"), 4000); } UI.render(); });
    } };
  }

  // ---------------------------------------------------------------- documents that expire
  let xdays = 60;
  async function expiring() {
    UI.chrome(true, t("exp.title"), "today");
    const X = await live("exp:" + xdays, () => API.expiring(xdays)); if (X._err) return { html: errBox(X._err), bind(root) { bindRetry(root, "exp:" + xdays); } };
    const ico = { contract: "📄", certificate: "🎓", permit: "🛂", equipment: "🦺" };
    return { html: `<div class="seg" data-k="xdays">${[30, 60, 90].map((d) => `<button data-v="${d}" class="${xdays === d ? "on" : ""}">${d} ${t("exp.days")}</button>`).join("")}</div>
      ${X.items.map((i) => `<div class="card ${i.person_id ? "tap" : ""} ${i.days < 0 ? "c-red" : i.days <= 30 ? "c-amber" : "c-grey"}" ${i.person_id ? `data-go="#/person/${i.person_id}"` : ""}><div class="t"><span>${ico[i.kind] || ""} ${esc(i.person)}</span><small>${fmt(i.date)}</small></div><div class="s">${t("exp.k_" + i.kind)}${i.title ? ` — ${esc(i.title)}` : ""}</div><div class="s muted">${i.days < 0 ? `${t("exp.expired")} ${days(-i.days)}` : `${t("exp.in")} ${days(i.days)}`}</div></div>`).join("") || empty("exp.none")}`, bind(root) { UI.goLinks(root); UI.segs(root, (k, v) => { xdays = +v; UI.render(); }); } };
  }

  // ---------------------------------------------------------------- equipment
  async function equipment() {
    UI.chrome(true, t("eq.title"), "today");
    const E = await live("eq", API.equipment); if (E._err) return { html: errBox(E._err), bind(root) { bindRetry(root, "eq"); } };
    const doc = (d) => `<div class="card tap ${d.status === "disputed" ? "c-red" : "c-gold"}" data-go="#/eqdoc/${d.id}"><div class="t"><span>${esc(d.person)}</span><small>${esc(d.doc_no)}</small></div><div class="s">${t("eq.s_" + d.status)} · ${fmt(d.date)} · ${days(d.days)}${d.active ? "" : ` · ${t("eq.left")}`}</div>${d.emp_remark ? `<div class="s muted">“${esc(d.emp_remark)}”</div>` : ""}</div>`;
    return { html: `<h2>${t("eq.returns")} (${E.returns.length})</h2>${E.returns.map(doc).join("") || `<p class="muted">${t("eq.none")}</p>`}
      <h2>${t("eq.issues")} (${E.issues.length})</h2>${E.issues.map(doc).join("") || `<p class="muted">${t("eq.none")}</p>`}
      <h2>${t("eq.leavers")} (${E.leavers.length})</h2>${E.leavers.map((p) => `<div class="card tap c-red" data-go="#/person/${p.person_id}"><div class="t"><span>${esc(p.person)}</span><small>${p.n} ${t("eq.lines")}</small></div><div class="s muted">${t("eq.left")} ${fmt(p.left)}</div></div>`).join("") || `<p class="muted">${t("eq.none")}</p>`}
      <h2>${t("eq.new")} (${E.new.length})</h2>${E.new.map((p) => `<div class="card tap c-grey" data-go="#/person/${p.person_id}"><div class="t"><span>${esc(p.person)}</span><small>${fmt(p.hired)}</small></div><div class="s muted">${t("eq.nothing")}</div></div>`).join("") || `<p class="muted">${t("eq.none")}</p>`}`, bind(root) { UI.goLinks(root); } };
  }
  async function eqdoc(id) {
    const E = await live("eq", API.equipment); const D = await live("eqdoc:" + id, () => API.eqDoc(id), 15000);
    UI.chrome(true, D._err ? t("eq.title") : D.doc_no, "today", true);
    if (D._err || E._err) return { html: errBox(D._err || E._err), bind(root) { bindRetry(root, "eqdoc:" + id); } };
    const recv = D.kind === "return" && D.status === "open"; const paper = D.kind === "issue" && ["sent", "disputed"].includes(D.status);
    return { html: `<div class="card ${D.status === "disputed" ? "c-red" : D.status === "accepted" ? "c-green" : "c-gold"}"><div class="t"><span>${esc(D.person)}</span><small>${esc(D.doc_no)}</small></div><div class="s">${t(D.kind === "issue" ? "eq.issue_list" : "eq.return_list")} · ${fmt(D.date)} · ${t("eq.s_" + D.status)}</div>${D.emp_at ? `<div class="s muted">${t("eq.emp_confirmed")} ${esc(D.emp_name)} ${fmt(D.emp_at)}</div>` : ""}${D.emp_remark ? `<div class="s">“${esc(D.emp_remark)}”</div>` : ""}${D.note ? `<div class="s muted">${esc(D.note)}</div>` : ""}</div>
      ${D.items.map((i) => `<div class="card c-navy"><div class="t"><span>${i.icon} ${esc(i.description)}</span><small>${esc(i.qty)}</small></div><div class="s muted">${[i.category, i.serial_no && `S/N ${i.serial_no}`, i.asset_tag && `No. ${i.asset_tag}`, i.size, i.cond_out].filter(Boolean).map(esc).join(" · ")}</div>
        ${recv ? `<select data-res="${i.id}" data-nosearch>${Object.keys(E.results).map((k) => `<option value="${k}">${esc(E.results[k])}</option>`).join("")}</select><input data-rnote="${i.id}" maxlength="300" placeholder="${t("eq.line_note")}">` : ""}</div>`).join("")}
      ${recv ? `<button class="btn navy" id="recv">${t("eq.receive")}</button>` : ""}${paper ? `<button class="btn line" id="paper">${t("eq.paper")}</button><p class="muted">${t("eq.paper_hint")}</p>` : ""}`, bind(root) {
      const rb = root.querySelector("#recv"); if (rb) rb.onclick = () => { const res = {}, notes = {}; root.querySelectorAll("[data-res]").forEach((s) => { res[s.dataset.res] = s.value; }); root.querySelectorAll("[data-rnote]").forEach((n) => { if (n.value.trim()) notes[n.dataset.rnote] = n.value.trim(); });
        const sum = Object.keys(E.results).map((k) => [k, Object.values(res).filter((v) => v === k).length]).filter((x) => x[1]).map((x) => `${esc(E.results[x[0]])}: ${x[1]}`).join(" · ");
        UI.sheet(`<h3>${t("eq.receive")} — ${esc(D.doc_no)}</h3><p class="muted">${esc(D.person)}</p><p class="note">${sum}</p>${UI.totpField()}<p id="err" class="note red hidden"></p><button class="btn navy" id="ok">${t("eq.receive")}</button>`, (sh) => { const totp = UI.totpValue(sh); if (totp === false) return; act(sh, () => API.eqReceive(D.id, { res, notes, totp: totp || undefined }), () => drop("eq", "eqdoc:" + D.id)); }); };
      const pb = root.querySelector("#paper"); if (pb) pb.onclick = () => UI.sheet(`<h3>${t("eq.paper")} — ${esc(D.doc_no)}</h3><label>${t("eq.paper_name")} *</label><input id="pname" maxlength="160" value="${esc(D.person)}">${UI.totpField()}<p id="err" class="note red hidden"></p><button class="btn navy" id="ok">${t("x.save")}</button>`,
        (sh) => { const name = sh.querySelector("#pname").value.trim(); const er = sh.querySelector("#err"); if (!name) { er.textContent = t("eq.paper_name"); er.classList.remove("hidden"); return; } const totp = UI.totpValue(sh); if (totp === false) return; act(sh, () => API.eqPaper(D.id, { name, totp: totp || undefined }), () => drop("eq", "eqdoc:" + D.id)); });
    } };
  }
  return { today, recruiting, candidate, cv, jds, jd, year, expiring, equipment, eqdoc, wipe };
})();
