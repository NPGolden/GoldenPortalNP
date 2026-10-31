/* Golden A/S Service app — Reports (portal contract v0.5.0).
 * Field Service Report = portal form SF-GLD-014 (fsr_save_from_post): header (Report No, Date, Customer, Vessel, Location |
 * PIC, Position, Mobile, Email) · Tasks 1…20, each with Description, Spare Parts (≤3, Type W/G/I), Open Issues (one per line)
 * and ≤3 time lines (Engineer, Specialty, Start, End, Type WT/TT/NC) · Hours summary per engineer · Submitted by Golden A/S ·
 * Customer acceptance (electronically in the portal — name, time, IP — or signed on the phone → accepted at once).
 * Inspection report = portal form SF-GLD-020 (isr_save_from_post): subject, class/flag, builder/year, hull no, objective,
 * findings, risks; one item per inspected system (condition found, checklist Q/A from the question bank, class regs, risk
 * level none…critical, risk analysis, actions). Submitted from the app → status "checking"; the PM issues it in the portal.
 * The portal assigns the numbers (YYYYMMDD+initials+HHMM / ISR-YYYYMMDD-INI-NN), builds the PDF, creates the accounting lines. */
window.REPORTS = (() => {
  const t = I18N.t; const S = () => SCREENS; const esc = (x) => S().esc(x);
  const PART_TYPES = [["I", "part.invoice"], ["W", "part.warranty"], ["G", "part.goodwill"]];
  const TIME_TYPES = [["WT", "rep.wt"], ["TT", "rep.tt"], ["NC", "rep.nc"]];
  const RISK = ["none", "low", "medium", "high", "critical"];
  const M = () => S().ctx.master || {};
  const lim = () => ({ lines: M().max_lines || 3, parts: M().max_parts || 3, photos: M().max_photos || 9 });
  const mins = (hhmm) => { const [h, m] = (hhmm || "0:0").split(":").map(Number); return (h || 0) * 60 + (m || 0); };
  const hm = (m) => `${Math.floor(m / 60)}:${String(Math.round(m % 60)).padStart(2, "0")}`;
  const dur = (l) => l.hours != null && !l.start ? Math.round(l.hours * 60) : Math.max(0, mins(l.end) - mins(l.start));
  const me = () => S().ctx.me;
  const engineers = () => M().engineers && M().engineers.length ? M().engineers : [{ id: me().uid, name: me().name, discipline: me().discipline }];
  const engName = (eid, fallback) => ((engineers().find((e) => +e.id === +eid) || {}).name) || fallback || (+eid === me().uid ? me().name : "");
  const specialties = () => M().specialties || ["Service Engineer"];
  const newLine = (eid, start, end, type) => ({ engineer_id: eid, engineer: engName(eid), specialty: specialties()[0], start: start || "08:00", end: end || "17:00", type: type || "WT" });
  const newTask = () => ({ description: "", parts: [], issues: [], times: [newLine(me().uid)], equipment_id: null });
  const sysBank = (name) => { const b = M().isr_bank || {}; const k = String(name || "").toLowerCase(); return (b[k] || []).map((q) => ({ q, a: "" })); };
  const newItem = (sys) => ({ system_id: sys ? sys.id || null : null, system_name: sys ? sys.name : "", condition_found: "", checklist: sysBank(sys && sys.name), class_regs: "", risk_level: "none", risk_analysis: "", actions: "", equipment_id: null });
  const blank = (kind, tk, q) => { const vs = S().ctx.vessels; const v = vs.find((x) => x.id === (tk ? tk.vessel_id : (q.vessel ? +q.vessel : (vs[0] || {}).id))) || {};
    const eq = q.equipment ? S().ctx.equipment.find((e) => e.id === +q.equipment) : null;
    const f = { id: DB.uuid(), kind, status: "draft", type: "internal", mode: tk && !tk.project_id ? "service" : (q.project || (tk && tk.project_id) ? "project" : (v.id && S().ctx.projects.some((p) => p.vessel_id === v.id) ? "project" : "service")), date: S().today(),
      vessel_id: v.id, vessel: v.name || "", customer: v.customer || "", location: v.yard || "", pic: "", pic_position: "", pic_mobile: "", pic_email: "",
      project_id: tk ? tk.project_id : (q.project ? +q.project : (S().ctx.projects.find((p) => p.vessel_id === v.id) || {}).id || null), subproject_id: tk ? tk.subproject_id : (q.sub ? +q.sub : null), ticket_id: tk ? tk.id : null,
      tasks: [{ ...newTask(), description: tk ? tk.title + (tk.description ? "\n" + tk.description : "") : "", issues: tk && tk.open_issues ? [...tk.open_issues] : [], equipment_id: eq ? eq.id : null }],
      subject: tk ? tk.title : "", class_flag: "", builder_year: "", hull_no: "", objective: "", findings: "", risks: "", items: [],
      acceptance: "portal", signer: "", signer_role: "", signature: null };
    if (kind === "inspection") { const name = tk && tk.system ? tk.system : eq ? eq.system : ""; const sys = name ? ((M().isr_systems || []).find((s) => s.name.toLowerCase() === name.toLowerCase()) || { id: tk && tk.system_id || 0, name }) : null; f.items = sys ? [newItem(sys)] : []; if (eq && f.items[0]) f.items[0].equipment_id = eq.id; }
    return f; };

  /** hours summary per engineer (name + specialty) from all task time lines — mirrors the portal's fsr summary block */
  function summary(f) {
    if (f.summary && f.summary.rows && f.status !== "draft") return { rows: f.summary.rows.map((r) => ({ ...r, wt: r.wt * 60, tt: r.tt * 60, nc: (r.nc || 0) * 60 })), tot: { wt: f.summary.wt * 60, tt: f.summary.tt * 60, nc: (f.summary.nc || 0) * 60 } };
    const m = {}; (f.tasks || []).forEach((tk) => (tk.times || []).forEach((l) => { const name = l.engineer || engName(l.engineer_id); const k = name + "|" + (l.specialty || ""); m[k] = m[k] || { engineer: name, specialty: l.specialty, wt: 0, tt: 0, nc: 0 }; m[k][l.type === "TT" ? "tt" : l.type === "NC" ? "nc" : "wt"] += dur(l); }));
    const rows = Object.values(m); const tot = rows.reduce((a, r) => ({ wt: a.wt + r.wt, tt: a.tt + r.tt, nc: a.nc + r.nc }), { wt: 0, tt: 0, nc: 0 }); return { rows, tot };
  }

  // ---------------------------------------------------------------- list
  let filter = "all";
  async function list() {
    UI.chrome(true, t("rep.title"), "fsr");
    const all = (await DB.all("fsr")).filter((f) => filter === "all" || (f.kind || "service") === filter).sort((a, b) => (b.date || "").localeCompare(a.date || "") || String(b.number || "").localeCompare(String(a.number || "")));
    const drafts = all.filter((f) => f.status === "draft" || f.status === "queued"), sent = all.filter((f) => f.status !== "draft" && f.status !== "queued");
    const stl = (s) => t("rst." + s) === "rst." + s ? s : t("rst." + s);
    const card = (f) => { const kind = f.kind || "service"; const sm = summary(f); const tk = f.ticket_id ? S().ticketNo(f.ticket_id) : "";
      const cls = f.status === "draft" ? "c-gold" : f.status === "accepted" || f.status === "issued" ? "c-green" : f.status === "rejected" ? "c-red" : kind === "inspection" ? "c-grey" : "c-amber";
      return `<div class="card tap ${cls}" data-go="#/fsr/${esc(f.id)}"><div class="t">${esc(f.number || t("rep.draft"))}${f.rev && f.status !== "draft" ? ` <span class="muted">${esc(f.rev)}</span>` : ""} <small>${kind === "inspection" ? "SF-GLD-020" : f.type === "external" ? "EXT" : "SF-GLD-014"} • ${esc(stl(f.status))}</small></div><div class="s">${esc(f.vessel || S().vesselName(f.vessel_id))} • ${S().fmt(f.date)}${kind === "inspection" && f.subject ? ` • ${esc(f.subject)}` : ""}</div>
        <div class="s muted">${kind === "inspection" ? `${(f.items || []).length} ${t("ins.systems").toLowerCase()} • ${esc(S().subName(f.subproject_id))}` : `${(f.tasks || []).length} ${t("rep.tasks").toLowerCase()} • ${sm.rows.length} ${t("rep.engineers").toLowerCase()} • ${hm(sm.tot.wt + sm.tot.tt + sm.tot.nc)} h${tk ? ` • ${esc(tk)}` : ""} ${f.invoicing && f.invoicing !== "none" ? S().stChip(f.invoicing) : ""}`}</div></div>`; };
    return { html: `<div class="row2"><button class="btn gold" data-go="#/fsr/new">${t("rep.newsr")}</button><button class="btn navy" data-go="#/fsr/new?kind=inspection">${t("rep.newins")}</button></div>
      <div class="seg">${["all", "service", "inspection"].map((k) => `<button data-f="${k}" class="${filter === k ? "on" : ""}">${t("rep.f_" + k)}</button>`).join("")}</div>
      <h2>${t("fsr.drafts")} (${drafts.length})</h2>${drafts.map(card).join("") || `<p class="muted">—</p>`}<h2>${t("fsr.submitted")}</h2>${sent.map(card).join("") || `<p class="muted">—</p>`}`,
      bind(root) { UI.goLinks(root); root.querySelectorAll(".seg button").forEach((b) => b.onclick = () => { filter = b.dataset.f; UI.render(); }); } };
  }

  // ---------------------------------------------------------------- read-only views (mirror the portal PDFs)
  const kv = (k, v) => `<div class="kv"><b>${k}</b><span class="v">${v || "—"}</span></div>`;
  const pdfLink = (f) => f.pdf_file_id ? `<a class="btn line sm" href="${API.documentUrl(f.pdf_file_id)}" target="_blank" rel="noopener">${t("rep.openpdf")}</a>` : "";
  function view(f) {
    const sm = summary(f);
    return `${kv(t("rep.reportno"), esc(f.number) + (f.rev ? ` <span class="muted">${esc(f.rev)}</span>` : ""))}${kv(t("fsr.date"), S().fmt(f.date))}${kv(t("rep.customer"), esc(f.customer))}${kv(t("fsr.vessel"), esc(f.vessel || S().vesselName(f.vessel_id)))}${kv(t("rep.location"), esc(f.location))}
      ${kv(t("rep.pic"), esc(f.pic))}${kv(t("rep.position"), esc(f.pic_position))}${kv(t("rep.mobile"), esc(f.pic_mobile))}${kv("E-mail", esc(f.pic_email))}${kv(t("fsr.project"), esc(S().projName(f.project_id)) + (f.subproject_id ? " • " + esc(S().subName(f.subproject_id)) : ""))}${f.ticket_id ? kv(t("fsr.ticket"), esc(S().ticketNo(f.ticket_id))) : ""}
      <h2>${t("rep.hours")}</h2><div class="overflow"><table class="week"><tr><th style="text-align:left">${t("rep.engineer")}</th><th style="text-align:left">${t("rep.specialty")}</th><th>WT</th><th>TT</th><th>NC</th><th>${t("rep.total")}</th></tr>
      ${sm.rows.map((r) => `<tr><td>${esc(r.engineer)}</td><td>${esc(r.specialty)}</td><td>${hm(r.wt)}</td><td>${hm(r.tt)}</td><td>${hm(r.nc)}</td><td><b>${hm(r.wt + r.tt + r.nc)}</b></td></tr>`).join("")}<tr><td><b>${t("rep.total")}</b></td><td></td><td><b>${hm(sm.tot.wt)}</b></td><td><b>${hm(sm.tot.tt)}</b></td><td><b>${hm(sm.tot.nc)}</b></td><td><b>${hm(sm.tot.wt + sm.tot.tt + sm.tot.nc)}</b></td></tr></table></div>
      ${(f.tasks || []).map((tk, i) => `<h2>${t("rep.task")} ${tk.seq || i + 1}</h2><div class="card c-grey"><div class="s" style="white-space:pre-wrap">${esc(tk.description)}</div>
        ${(tk.parts || []).length ? `<div class="s muted" style="margin-top:6px"><b>${t("rep.parts")}:</b> ${tk.parts.map((p) => `${esc(p.name)} <span class="chip">${esc(p.type)}</span>`).join(", ")}</div>` : ""}
        ${(tk.issues || []).length ? `<div class="s" style="margin-top:6px;color:var(--amber)"><b>${t("rep.issues")}:</b> ${tk.issues.map(esc).join("; ")}</div>` : ""}
        ${(tk.times || []).map((l) => `<div class="kv"><span>${esc(l.engineer || engName(l.engineer_id))} • ${esc(l.specialty)}</span><span class="v">${esc(l.start)}–${esc(l.end)} ${esc(l.type)} • ${hm(dur(l))}</span></div>`).join("")}</div>`).join("")}
      <h2>${t("rep.submitted")}</h2><p class="muted">${esc(f.submitted_by || me().name)} · ${f.submitted_at ? esc(String(f.submitted_at).replace("T", " ").slice(0, 16)) : "—"}</p>
      <h2>${t("fsr.sign")}</h2>${f.acceptance === "onsite" && f.signature ? `<img src="${f.signature}" style="max-width:260px;display:block;margin:8px auto">` : ""}${f.accepted_by ? `<p class="muted">${esc(f.accepted_by)} · ${esc(String(f.accepted_at || "").slice(0, 16))}${f.accepted_ip ? " · IP " + esc(f.accepted_ip) : ""}</p>` : f.acceptance === "onsite" && f.signer ? `<p class="muted" style="text-align:center">${esc(f.signer)}${f.signer_role ? ", " + esc(f.signer_role) : ""} · ${t("rep.signedphone")}</p>` : `<p class="muted">${t("rep.awaiting")}</p>`}
      ${f.invoicing && f.invoicing !== "none" ? kv(t("rep.invoicing"), S().stChip(f.invoicing)) : ""}
      <p class="muted">${f.status === "queued" ? t("rep.queued") : `PDF ${esc(f.number)} ${esc(f.rev || "")} — ${t("rep.archived")}`}</p>${pdfLink(f)}<p class="muted">WT: Working Time | TT: Travelling Time | NC: Not Chargeable · W: Warranty | G: Goodwill | I: Invoice</p>`;
  }
  function viewIsr(f) {
    const lv = (l) => `<span class="chip ${l === "critical" || l === "high" ? "red" : l === "medium" ? "amber" : l === "low" ? "grey" : "green"}">${esc(t("risk." + l))}</span>`;
    return `${kv(t("rep.reportno"), esc(f.number) + (f.rev ? ` <span class="muted">${esc(f.rev)}</span>` : ""))}${kv(t("fsr.date"), S().fmt(f.date))}${kv(t("ins.subject"), esc(f.subject))}${kv(t("rep.customer"), esc(f.customer))}${kv(t("fsr.vessel"), esc(f.vessel))}${kv(t("rep.location"), esc(f.location))}${kv(t("rep.pic"), esc(f.pic))}
      ${kv(t("ins.classflag"), esc(f.class_flag))}${kv(t("ins.builderyear"), esc(f.builder_year))}${kv(t("ins.hullno"), esc(f.hull_no))}${kv(t("fsr.project"), esc(S().projName(f.project_id)) + (f.subproject_id ? " • " + esc(S().subName(f.subproject_id)) : ""))}
      <h2>${t("ins.objective")}</h2><p class="txt">${esc(f.objective) || "—"}</p><h2>${t("ins.findings")}</h2><p class="txt">${esc(f.findings) || "—"}</p><h2>${t("ins.risks")}</h2><p class="txt">${esc(f.risks) || "—"}</p>
      ${(f.items || []).map((it, i) => `<h2>${i + 1}. ${esc(it.system_name)} ${lv(it.risk_level || "none")}</h2><div class="card c-grey">${kv(t("ins.condition"), esc(it.condition_found))}
        ${(it.checklist || []).length ? `<div class="s" style="margin-top:6px"><b>${t("ins.checklist")}</b>${it.checklist.map((c) => `<div class="kv"><span>${esc(c.q)}</span><span class="v">${esc(c.a) || "—"}</span></div>`).join("")}</div>` : ""}
        ${kv(t("ins.classregs"), esc(it.class_regs))}${kv(t("ins.riskanalysis"), esc(it.risk_analysis))}${kv(t("ins.actions"), esc(it.actions))}</div>`).join("")}
      <h2>${t("ins.performed")}</h2><p class="muted">${esc(f.performed_name || me().name)} · ${f.submitted_at ? esc(String(f.submitted_at).slice(0, 16)) : "—"}</p>
      ${f.checked_by ? kv(t("ins.checked"), esc(f.checked_by)) : ""}${f.issued_at ? kv(t("ins.issued"), esc(String(f.issued_at).slice(0, 16))) : `<p class="muted">${f.status === "queued" ? t("rep.queued") : t("ins.awaitingpm")}</p>`}${pdfLink(f)}`;
  }

  // ---------------------------------------------------------------- editor
  async function edit(id, q) {
    const ctx = S().ctx; let f = id === "new" ? null : await DB.get("fsr", id);
    if (!f) { const tk = q.ticket ? await DB.get("tickets", +q.ticket) : null; f = blank(q.kind === "inspection" ? "inspection" : "service", tk, q); }
    const kind = f.kind || "service"; const L = lim();
    UI.chrome(true, f.number || (kind === "inspection" ? t("rep.newins") : t("rep.newsr")), "fsr", true); UI.badge(kind === "inspection" ? "SF-GLD-020" : f.type === "external" ? "EXT" : "SF-GLD-014");
    if (f.status !== "draft") return { html: kind === "inspection" ? viewIsr(f) : view(f) };
    const myTickets = (await DB.all("tickets")).filter((x) => x.status !== "closed" && x.status !== "resolved");
    const engs = engineers(); const vEq = ctx.equipment.filter((e) => e.vessel_id === f.vessel_id);
    const eqOptions = (sel) => `<option value="">—</option>` + vEq.map((e) => `<option value="${e.id}" ${e.id === sel ? "selected" : ""}>${esc(e.tag)} — ${esc(e.system)}</option>`).join("");
    const header = `
      <div class="kv"><b>${t("rep.reportno")}</b><span class="v muted">${t("rep.assigned")}</span></div>
      <div class="row2"><div><label>${t("fsr.date")}</label><input id="date" type="date" value="${esc(f.date)}"></div>${kind === "service" ? `<div><label>${t("fsr.type")}</label><select id="type"><option value="internal" ${f.type === "internal" ? "selected" : ""}>${t("fsr.internal")}</option><option value="external" ${f.type === "external" ? "selected" : ""}>${t("fsr.external")}</option></select></div>` : `<div><label>${t("ins.subject")}</label><input id="subject" value="${esc(f.subject)}"></div>`}</div>
      <label>${t("fsr.vessel")}</label><select id="vessel">${ctx.vessels.map((v) => `<option value="${v.id}" data-customer="${esc(v.customer || "")}" data-yard="${esc(v.yard || "")}" ${v.id === f.vessel_id ? "selected" : ""}>${esc(v.name)} — ${esc(v.yard)}</option>`).join("")}</select>
      <div class="row2"><div><label>${t("rep.customer")}</label><input id="customer" value="${esc(f.customer)}"></div><div><label>${t("rep.location")}</label><input id="loc" value="${esc(f.location)}"></div></div>
      <div class="row2"><div><label>${t("rep.pic")}</label><input id="pic" value="${esc(f.pic)}"></div><div><label>${t("rep.position")}</label><input id="pos" value="${esc(f.pic_position)}" ${kind === "inspection" ? "disabled placeholder='—'" : ""}></div></div>
      ${kind === "service" ? `<div class="row2"><div><label>${t("rep.mobile")}</label><input id="mob" type="tel" value="${esc(f.pic_mobile)}"></div><div><label>E-mail</label><input id="mail" type="email" value="${esc(f.pic_email)}"></div></div>` : `<div class="row2"><div><label>${t("ins.classflag")}</label><input id="classflag" value="${esc(f.class_flag)}"></div><div><label>${t("ins.builderyear")}</label><input id="builderyear" value="${esc(f.builder_year)}"></div></div><label>${t("ins.hullno")}</label><input id="hullno" value="${esc(f.hull_no)}">`}
      <div class="seg" data-k="mode" style="margin-top:12px"><button data-v="project" class="${f.mode === "project" ? "on" : ""}">${t("fsr.project")}</button><button data-v="service" class="${f.mode === "service" ? "on" : ""}">${t("fsr.service")}</button></div>
      <div id="projrow" class="${f.mode === "service" ? "hidden" : ""}"><label>${t("fsr.project")}</label><select id="project">${S().projOptions(f.project_id)}</select><label>${t("fsr.sub")}</label><select id="sub">${S().subOptions(f.project_id, f.subproject_id)}</select></div>
      <p class="muted ${f.mode === "service" ? "" : "hidden"}" id="svchint">${t("fsr.svchint")}</p>
      <label>${t("fsr.ticket")}</label><select id="ticket"><option value="">—</option>${myTickets.map((x) => `<option value="${x.id}" ${x.id === f.ticket_id ? "selected" : ""}>${esc(x.ticket_no)} — ${esc(x.title)}</option>`).join("")}</select>
      ${kind === "service" ? `<p class="muted ${f.type === "external" ? "" : "hidden"}" id="exthint">${t("fsr.exthint")}</p>` : ""}`;
    const html = kind === "service" ? `${header}
      <h2>${t("rep.tasks")} <span class="muted">(${t("rep.limits", { l: L.lines, p: L.parts })})</span></h2><div id="tasks">${f.tasks.map((tk, i) => taskBlock(tk, i, engs, eqOptions, L)).join("")}</div>
      <button class="btn line sm" id="addtask">${t("rep.addtask")}</button>
      <h2>${t("rep.hours")}</h2><div id="summary"></div>
      <h2>${t("fsr.photos")} <span class="muted">(≤ ${L.photos})</span></h2><div class="photos" id="ph"></div>
      <h2>${t("fsr.sign")}</h2>
      <div class="seg" data-k="acc"><button data-v="portal" class="${f.acceptance !== "onsite" ? "on" : ""}">${t("rep.accportal")}</button><button data-v="onsite" class="${f.acceptance === "onsite" ? "on" : ""}">${t("rep.acconsite")}</button></div>
      <p class="muted" id="acchint">${t(f.acceptance === "onsite" ? "rep.acconsitehint" : "rep.accportalhint")}</p>
      <div id="sigblock" class="${f.acceptance === "onsite" ? "" : "hidden"}"><p class="muted">${t("fsr.consent")}</p><div class="sigbox"><canvas id="sig"></canvas><button class="clr" id="clr">${t("fsr.clear")}</button><span class="lbl">${t("fsr.signlbl")}</span></div>
      <div class="row2"><div><label>${t("fsr.signer")}</label><input id="signer" value="${esc(f.signer)}"></div><div><label>${t("fsr.role")}</label><input id="role" value="${esc(f.signer_role)}"></div></div></div>
      <button class="btn gold" id="submit">${t("fsr.submit")}</button><button class="btn line" id="save">${t("fsr.save")}</button>`
    : `${header}
      <h2>${t("ins.objective")}</h2><textarea id="objective">${esc(f.objective)}</textarea>${ASSIST.button()}
      <h2>${t("ins.systems")}</h2><div id="items">${f.items.map((it, i) => itemBlock(it, i, eqOptions)).join("")}</div>
      <div class="row2"><select id="addsys"><option value="">${t("ins.addsystem")}</option>${(M().isr_systems || []).map((s) => `<option value="${esc(s.name)}">${esc(s.name)}${s.abbr ? ` (${esc(s.abbr)})` : ""}</option>`).join("")}</select><button class="btn line sm" id="addsysok" style="margin:0">+</button></div>
      <h2>${t("ins.findings")}</h2><textarea id="findings">${esc(f.findings)}</textarea>${ASSIST.button()}
      <h2>${t("ins.risks")}</h2><textarea id="risks">${esc(f.risks)}</textarea>${ASSIST.button()}
      <h2>${t("fsr.photos")} <span class="muted">(≤ ${L.photos})</span></h2><div class="photos" id="ph"></div>
      <p class="muted">${t("ins.flow")}</p>
      <button class="btn gold" id="submit">${t("ins.submit")}</button><button class="btn line" id="save">${t("fsr.save")}</button>`;

    const read = (root) => {
      const $ = (s) => root.querySelector(s); const val = (s) => ($(s) ? $(s).value.trim() : ""); const segv = (k) => ((root.querySelector(`.seg[data-k=${k}] .on`) || {}).dataset || {}).v;
      const mode = segv("mode") || "service"; const vid = +$("#vessel").value;
      const base = { mode, date: $("#date").value, vessel_id: vid, vessel: ($("#vessel").selectedOptions[0] || {}).textContent.split(" — ")[0] || "", customer: val("#customer"), location: val("#loc"), pic: val("#pic"), pic_position: val("#pos"),
        project_id: mode === "project" ? (+$("#project").value || null) : null, subproject_id: mode === "project" ? (+$("#sub").value || null) : null, ticket_id: +$("#ticket").value || null };
      if (kind === "inspection") return { ...base, subject: val("#subject"), class_flag: val("#classflag"), builder_year: val("#builderyear"), hull_no: val("#hullno"), objective: val("#objective"), findings: val("#findings"), risks: val("#risks"),
        items: [...root.querySelectorAll(".item")].map((it) => ({ system_id: +it.dataset.sid || null, system_name: it.querySelector(".isn").value.trim(), condition_found: it.querySelector(".icf").value.trim(), checklist: [...it.querySelectorAll(".qa")].map((r) => ({ q: r.querySelector(".qq").value.trim(), a: r.querySelector(".qa-a").value.trim() })).filter((x) => x.q),
          class_regs: it.querySelector(".icr").value.trim(), risk_level: it.querySelector(".ilv").value, risk_analysis: it.querySelector(".ira").value.trim(), actions: it.querySelector(".iac").value.trim(), equipment_id: +it.querySelector(".ieq").value || null })).filter((it) => it.system_name) };
      return { ...base, type: $("#type").value, pic_mobile: val("#mob"), pic_email: val("#mail"),
        tasks: [...root.querySelectorAll(".task")].map((tk) => ({ description: tk.querySelector(".td").value.trim(), equipment_id: +tk.querySelector(".te").value || null,
          parts: [...tk.querySelectorAll(".part")].map((p) => ({ name: p.querySelector(".pn").value.trim(), type: p.querySelector(".pt").value })).filter((p) => p.name).slice(0, L.parts),
          issues: [...tk.querySelectorAll(".issue .od")].map((o) => o.value.trim()).filter(Boolean),
          times: [...tk.querySelectorAll(".tl")].map((l) => ({ engineer_id: +l.querySelector(".le").value, engineer: l.querySelector(".le").selectedOptions[0].textContent, specialty: l.querySelector(".ld").value, start: l.querySelector(".ls").value, end: l.querySelector(".ln").value, type: l.querySelector(".lt").value })).filter((l) => l.start && l.end).slice(0, L.lines) })),
        acceptance: segv("acc") || "portal", signer: val("#signer"), signer_role: val("#role"), signature: UI.sig.data(root) || f.signature };
    };
    return { html, bind(root) {
      const $ = (s) => root.querySelector(s);
      const renderSummary = () => { if (!$("#summary")) return; const sm = summary(read(root)); $("#summary").innerHTML = `<div class="overflow"><table class="week"><tr><th style="text-align:left">${t("rep.engineer")}</th><th>WT</th><th>TT</th><th>NC</th><th>${t("rep.total")}</th></tr>${sm.rows.map((r) => `<tr><td>${esc(r.engineer)} <span class="muted">${esc(r.specialty)}</span></td><td>${hm(r.wt)}</td><td>${hm(r.tt)}</td><td>${hm(r.nc)}</td><td><b>${hm(r.wt + r.tt + r.nc)}</b></td></tr>`).join("")}<tr><td><b>${t("rep.total")}</b></td><td><b>${hm(sm.tot.wt)}</b></td><td><b>${hm(sm.tot.tt)}</b></td><td><b>${hm(sm.tot.nc)}</b></td><td><b>${hm(sm.tot.wt + sm.tot.tt + sm.tot.nc)}</b></td></tr></table></div>`; };
      UI.segs(root, (k, v) => { if (k === "mode") { $("#projrow").classList.toggle("hidden", v === "service"); $("#svchint").classList.toggle("hidden", v !== "service"); } if (k === "acc") { $("#sigblock").classList.toggle("hidden", v !== "onsite"); if (v === "onsite") UI.sig.fit(root); $("#acchint").textContent = t(v === "onsite" ? "rep.acconsitehint" : "rep.accportalhint"); } });
      if ($("#type")) $("#type").onchange = (e) => $("#exthint").classList.toggle("hidden", e.target.value !== "external");
      $("#vessel").onchange = (e) => { const o = e.target.selectedOptions[0]; $("#customer").value = o.dataset.customer || ""; if (!$("#loc").value) $("#loc").value = o.dataset.yard || ""; const p = ctx.projects.find((x) => x.vessel_id === +e.target.value); if (p) { $("#project").value = p.id; $("#sub").innerHTML = S().subOptions(p.id, null); } };
      $("#project").onchange = (e) => { $("#sub").innerHTML = S().subOptions(+e.target.value, null); };
      const limits = (tk) => { tk.querySelector(".addpart").classList.toggle("hidden", tk.querySelectorAll(".part").length >= L.parts); tk.querySelector(".addline").classList.toggle("hidden", tk.querySelectorAll(".tl").length >= L.lines); tk.querySelector(".addtravel").classList.toggle("hidden", tk.querySelectorAll(".tl").length >= L.lines); };
      const wire = () => {
        root.querySelectorAll("[data-rm]").forEach((b) => b.onclick = () => { const row = b.closest("[data-row]"); const tk = row.closest(".task"); row.remove(); renumber(); if (tk && tk.isConnected) limits(tk); renderSummary(); });
        root.querySelectorAll(".task").forEach((tk) => { limits(tk);
          tk.querySelector(".addpart").onclick = () => { tk.querySelector(".parts").insertAdjacentHTML("beforeend", partRow({ name: "", type: "I" })); wire(); };
          tk.querySelector(".addissue").onclick = () => { tk.querySelector(".issues").insertAdjacentHTML("beforeend", issueRow("")); wire(); };
          tk.querySelector(".addline").onclick = () => { const last = [...tk.querySelectorAll(".tl")].slice(-1)[0]; const eid = last ? +last.querySelector(".le").value : ctx.me.uid; tk.querySelector(".lines").insertAdjacentHTML("beforeend", lineRow(newLine(eid, last ? last.querySelector(".ln").value : "08:00", last ? hm(Math.min(mins(last.querySelector(".ln").value) + 120, 1439)) : "17:00", "WT"), engs)); wire(); renderSummary(); };
          tk.querySelector(".addtravel").onclick = () => { tk.querySelector(".lines").insertAdjacentHTML("beforeend", lineRow(newLine(ctx.me.uid, "07:00", "08:00", "TT"), engs)); wire(); renderSummary(); };
          tk.querySelectorAll(".le").forEach((sel) => sel.onchange = renderSummary);
        });
        root.querySelectorAll(".item").forEach((it) => { it.querySelector(".addqa").onclick = () => { it.querySelector(".qas").insertAdjacentHTML("beforeend", qaRow({ q: "", a: "" })); wire(); }; it.querySelector(".isn").oninput = () => { it.dataset.sid = (M().isr_systems || []).find((s) => s.name.toLowerCase() === it.querySelector(".isn").value.trim().toLowerCase()) ? (M().isr_systems.find((s) => s.name.toLowerCase() === it.querySelector(".isn").value.trim().toLowerCase()).id || "") : ""; }; });
      };
      const renumber = () => { root.querySelectorAll(".task").forEach((tk, i) => { tk.querySelector(".tn").textContent = `${t("rep.task")} ${i + 1}`; }); root.querySelectorAll(".item").forEach((it, i) => { it.querySelector(".in").textContent = `${i + 1}.`; }); };
      wire(); renderSummary(); root.addEventListener("input", (e) => { if (e.target.closest(".tl")) renderSummary(); });
      // ✦ Claude assistance — portal kinds (write / translate / ask / fill) with the form fields as context, page fsr_edit / isr_edit
      const page = kind === "inspection" ? "isr_edit" : "fsr_edit";
      const fields = (focus) => { const v = read(root); const F = [{ name: "vessel", label: "Vessel", value: v.vessel }, { name: "customer", label: "Customer", value: v.customer }, { name: "location", label: "Location", value: v.location }, { name: "report_date", label: "Date", value: v.date }];
        if (kind === "inspection") { F.push({ name: "subject", label: "Subject", value: v.subject }, { name: "objective", label: "Objective", value: v.objective }, { name: "findings", label: "Findings", value: v.findings }, { name: "risks", label: "Risks", value: v.risks }); v.items.forEach((it, i) => F.push({ name: `it_cond_${i}`, label: `${it.system_name} — condition found`, value: it.condition_found }, { name: `it_risk_${i}`, label: `${it.system_name} — risk analysis`, value: it.risk_analysis }, { name: `it_act_${i}`, label: `${it.system_name} — actions`, value: it.actions })); }
        else v.tasks.forEach((tk, i) => { F.push({ name: `wk_desc_${i}`, label: `Task ${i + 1} description`, value: tk.description }); if (tk.issues.length) F.push({ name: `wk_issues_${i}`, label: `Task ${i + 1} open issues`, value: tk.issues.join("\n") }); });
        if (v.ticket_id) { const tk = myTickets.find((x) => x.id === v.ticket_id); if (tk) F.push({ name: "ticket", label: "Ticket", value: `${tk.ticket_no} — ${tk.title}: ${tk.description || ""}` }); }
        return F.map((x) => ({ ...x, type: "text", focus: x.name === focus })); };
      root.addEventListener("click", (e) => { const b = e.target.closest("[data-ai]"); if (!b) return; const ta = b.previousElementSibling && b.previousElementSibling.tagName === "TEXTAREA" ? b.previousElementSibling : null; const tk = b.closest(".task"); const focus = tk ? `wk_desc_${[...root.querySelectorAll(".task")].indexOf(tk)}` : (ta ? ta.id : "");
        ASSIST.open({ page, title: kind === "inspection" ? "Inspection report SF-GLD-020" : "Field Service Report SF-GLD-014", headline: read(root).vessel, fields: () => fields(focus), getText: () => (ta ? ta.value : ""), setText: (txt) => { if (ta) { ta.value = txt; ta.dispatchEvent(new Event("input", { bubbles: true })); } } }); });
      UI.assistContext({ page, title: kind === "inspection" ? "Inspection report SF-GLD-020" : "Field Service Report SF-GLD-014", headline: read(root).vessel, fields: () => fields(""), getText: () => kind === "inspection" ? read(root).findings : read(root).tasks.map((x) => x.description).join("\n") });
      if ($("#addtask")) $("#addtask").onclick = () => { if (root.querySelectorAll(".task").length >= 20) return UI.toast(t("rep.maxtasks")); $("#tasks").insertAdjacentHTML("beforeend", taskBlock(newTask(), root.querySelectorAll(".task").length, engs, eqOptions, L)); wire(); renderSummary(); };
      if ($("#addsysok")) $("#addsysok").onclick = () => { const name = $("#addsys").value; if (!name) return; const sys = (M().isr_systems || []).find((s) => s.name === name); $("#items").insertAdjacentHTML("beforeend", itemBlock(newItem(sys || { id: 0, name }), root.querySelectorAll(".item").length, eqOptions)); $("#addsys").value = ""; wire(); };
      UI.photos.init($("#ph"), f.id, L.photos); UI.sig.init(root, f.signature);
      const save = async () => { f = { ...f, ...read(root) }; await DB.put("fsr", f); await UI.photos.store($("#ph"), f.id); };
      $("#save").onclick = async () => { await save(); UI.toast(t("fsr.save")); location.hash = "#/fsr"; };
      $("#submit").onclick = async () => {
        const v = read(root);
        if (!v.vessel_id || !v.customer) return UI.toast(t("rep.needheader"));
        if (v.mode === "project" && !v.project_id) return UI.toast(t("rep.needproject"));
        if (kind === "service") {
          if (!v.tasks.some((tk) => tk.description)) return UI.toast(t("rep.needtask"));
          if (!v.tasks.some((tk) => tk.times.length)) return UI.toast(t("rep.needtime"));
          if (v.tasks.some((tk) => tk.times.some((l) => dur(l) <= 0))) return UI.toast(t("rep.badtime"));
          if (v.acceptance === "onsite" && (!v.signature || !v.signer)) return UI.toast(t("fsr.needsig"));
        } else { if (!v.subject) return UI.toast(t("ins.needsubject")); if (!v.items.length) return UI.toast(t("ins.needitem")); }
        await save(); f = { ...f, ...v, status: "queued", submitted_by: ctx.me.name, performed_name: ctx.me.name, submitted_at: new Date().toISOString(), engineer_id: ctx.me.uid };
        await DB.put("fsr", f); const { signature, ...payload } = f; await DB.enqueue(kind === "inspection" ? "inspection" : "fsr", { ...payload, signature_png: signature }, f.id);
        // no local timesheet mirror: the portal creates the accounting lines (acc_sync) for every engineer on the report
        SYNC.run(false); UI.toast(t("fsr.sent")); location.hash = "#/fsr";
      };
    } };
  }
  const rm = `<button class="btn red sm" style="width:auto;padding:4px 10px;margin:0" data-rm>×</button>`;
  const taskBlock = (tk, i, engs, eqOptions, L) => `<div class="card task c-grey" data-row><div class="t"><span class="tn">${t("rep.task")} ${i + 1}</span>${i > 0 ? rm : ""}</div>
    <label>${t("rep.description")}</label><textarea class="td">${esc(tk.description)}</textarea>${ASSIST.button()}
    <label>${t("rep.equipment")}</label><select class="te">${eqOptions(tk.equipment_id)}</select>
    <label>${t("rep.parts")} <span class="muted">(≤ ${L.parts} · W ${t("part.warranty")} · G ${t("part.goodwill")} · I ${t("part.invoice")})</span></label><div class="parts">${(tk.parts || []).map(partRow).join("")}</div><button class="btn line sm addpart">${t("fsr.addpart")}</button>
    <label>${t("rep.issues")}</label><div class="issues">${(tk.issues || []).map(issueRow).join("")}</div><button class="btn line sm addissue">${t("rep.addissue")}</button>
    <label>${t("rep.timelines")} <span class="muted">(≤ ${L.lines} · WT ${t("rep.wt")} · TT ${t("rep.tt")} · NC ${t("rep.nc")})</span></label><div class="lines">${(tk.times || []).map((l) => lineRow(l, engs)).join("")}</div>
    <div class="row2"><button class="btn line sm addline">${t("rep.addline")}</button><button class="btn line sm addtravel">${t("rep.addtravel")}</button></div></div>`;
  const partRow = (p) => `<div class="row2 part" data-row style="margin-top:6px"><input class="pn" placeholder="${t("rep.partname")}" value="${esc(p.name)}"><select class="pt" style="max-width:130px">${PART_TYPES.map(([v, k]) => `<option value="${v}" ${p.type === v ? "selected" : ""}>${v} — ${t(k)}</option>`).join("")}</select>${rm}</div>`;
  const issueRow = (txt) => `<div class="row2 issue" data-row style="margin-top:6px"><input class="od" placeholder="${t("rep.issuedesc")}" value="${esc(txt)}">${rm}</div>`;
  const lineRow = (l, engs) => `<div class="tl" data-row style="margin-top:6px;border-top:1px solid #E5E8EE;padding-top:6px"><div class="row2"><select class="le">${engs.map((e) => `<option value="${e.id}" ${+e.id === +l.engineer_id || (!l.engineer_id && e.name === l.engineer) ? "selected" : ""}>${esc(e.name)}</option>`).join("")}</select><select class="ld">${specialties().map((s) => `<option ${s === l.specialty ? "selected" : ""}>${esc(s)}</option>`).join("")}</select></div>
    <div class="row2" style="margin-top:6px;align-items:center"><input class="ls" type="time" value="${esc(l.start)}"><input class="ln" type="time" value="${esc(l.end)}"><select class="lt" style="max-width:76px">${TIME_TYPES.map(([v]) => `<option value="${v}" ${(l.type || "WT") === v ? "selected" : ""}>${v}</option>`).join("")}</select>${rm}</div></div>`;
  const qaRow = (c) => `<div class="qa" data-row style="margin-top:6px"><input class="qq" placeholder="${t("ins.question")}" value="${esc(c.q)}"><div class="row2" style="margin-top:4px"><input class="qa-a" placeholder="${t("ins.answer")}" value="${esc(c.a)}">${rm}</div></div>`;
  const itemBlock = (it, i, eqOptions) => `<div class="card item c-grey" data-row data-sid="${it.system_id || ""}"><div class="t"><span class="in">${i + 1}.</span> <input class="isn" style="display:inline-block;width:auto;flex:1;margin:0 6px" value="${esc(it.system_name)}" placeholder="${t("ins.system")}">${i > 0 ? rm : ""}</div>
    <label>${t("rep.equipment")}</label><select class="ieq">${eqOptions(it.equipment_id)}</select>
    <label>${t("ins.condition")}</label><textarea class="icf">${esc(it.condition_found)}</textarea>${ASSIST.button()}
    <label>${t("ins.checklist")}</label><div class="qas">${(it.checklist || []).map(qaRow).join("")}</div><button class="btn line sm addqa">${t("ins.addq")}</button>
    <label>${t("ins.classregs")}</label><input class="icr" value="${esc(it.class_regs)}">
    <label>${t("ins.risklevel")}</label><select class="ilv">${RISK.map((r) => `<option value="${r}" ${it.risk_level === r ? "selected" : ""}>${t("risk." + r)}</option>`).join("")}</select>
    <label>${t("ins.riskanalysis")}</label><textarea class="ira">${esc(it.risk_analysis)}</textarea>${ASSIST.button()}
    <label>${t("ins.actions")}</label><textarea class="iac">${esc(it.actions)}</textarea>${ASSIST.button()}</div>`;
  return { list, edit, summary, hm };
})();
