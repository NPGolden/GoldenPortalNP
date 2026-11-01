/* Golden PM — API client for the portal module /customer/api/v1 (JWT access + rotating refresh), app "pm" (CONTRACT §0)
 * and a MOCK mode (built-in sample data in the shapes of the contract) for the demo and the UI tests.
 * Mock mode: localStorage gpm.mock = "1", or ?mock=1 on the URL. */
window.API = (() => {
  const VERSION = "1.0.5";
  const BASE = (document.querySelector('meta[name="gas-api"]') || {}).content || "../api/v1";
  let MOCK = false;
  try { MOCK = localStorage.getItem("gpm.mock") === "1" || /[?&]mock=1/.test(location.search); if (/[?&]mock=1/.test(location.search)) localStorage.setItem("gpm.mock", "1"); } catch (e) {}
  const tok = { get access() { try { return sessionStorage.getItem("gpm.at"); } catch (e) { return null; } }, set access(v) { try { v ? sessionStorage.setItem("gpm.at", v) : sessionStorage.removeItem("gpm.at"); } catch (e) {} },
    get refresh() { try { return localStorage.getItem("gpm.rt"); } catch (e) { return null; } }, set refresh(v) { try { v ? localStorage.setItem("gpm.rt", v) : localStorage.removeItem("gpm.rt"); } catch (e) {} } };
  // Capacitor builds move the refresh token to Keychain / Keystore (capacitor/native-bridge.js)

  async function raw(method, path, body, isForm) {
    const h = { "X-App": "pm", "X-App-Version": VERSION, "X-Lang": I18N.get(), "Cache-Control": "no-store" };
    if (tok.access) h.Authorization = `Bearer ${tok.access}`;
    if (!isForm) h["Content-Type"] = "application/json";
    const res = await fetch(`${BASE}${path}`, { method, headers: h, body: isForm ? body : (body ? JSON.stringify(body) : undefined), cache: "no-store" });
    if (res.status === 204) return {};
    let data = {}; try { data = await res.json(); } catch (e) {}
    if (!res.ok) { const err = new Error(data.error || `HTTP ${res.status}`); err.status = res.status; err.data = data; throw err; }
    return data;
  }
  async function call(method, path, body, isForm) {
    if (MOCK) return mock(method, path, body);
    try { return await raw(method, path, body, isForm); }
    catch (e) {
      if (e.status === 401 && tok.refresh && !path.startsWith("/auth/")) { const r = await raw("POST", "/auth/refresh", { refresh_token: tok.refresh }); tok.access = r.access_token; tok.refresh = r.refresh_token; return raw(method, path, body, isForm); }
      throw e;
    }
  }
  // R71-001 base64url + credential → JSON (what the API verifies)
  const pkB64 = { enc: (buf) => { let s = ""; const b = new Uint8Array(buf); for (let i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, ""); },
                  dec: (s) => { s = s.replace(/-/g, "+").replace(/_/g, "/"); while (s.length % 4) s += "="; const bin = atob(s), b = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i); return b.buffer; } };
  function pkToJSON(cred) { const r = cred.response, out = { id: cred.id, rawId: pkB64.enc(cred.rawId), type: cred.type, response: { clientDataJSON: pkB64.enc(r.clientDataJSON) } };
    if (r.attestationObject) { out.response.attestationObject = pkB64.enc(r.attestationObject); try { out.response.transports = r.getTransports ? r.getTransports() : []; } catch (e) { out.response.transports = []; } }
    if (r.authenticatorData) { out.response.authenticatorData = pkB64.enc(r.authenticatorData); out.response.signature = pkB64.enc(r.signature); out.response.userHandle = r.userHandle ? pkB64.enc(r.userHandle) : null; } return out; }
  // R71-003: in the store builds the WebView has no WebAuthn — the native plug-in (@capgo/capacitor-passkey) takes the same JSON options and returns the same JSON credential
  const pkNative = () => (window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform() && Capacitor.Plugins && Capacitor.Plugins.CapacitorPasskey) ? Capacitor.Plugins.CapacitorPasskey : null;
  const deviceInfo = () => ({ platform: (window.Capacitor && Capacitor.getPlatform && Capacitor.getPlatform()) || "web", ua: navigator.userAgent.slice(0, 120), app_version: VERSION, device_id: deviceId() });
  function deviceId() { try { let d = localStorage.getItem("gpm.device"); if (!d) { d = DB.uuid(); localStorage.setItem("gpm.device", d); } return d; } catch (e) { return "web"; } }

  const api = {
    VERSION, get mock() { return MOCK; }, setMock(v) { MOCK = !!v; try { localStorage.setItem("gpm.mock", v ? "1" : "0"); } catch (e) {} },
    get signedIn() { return !!(tok.refresh || (MOCK && mockState.user)); },
    async login(email, password, totp) { const r = await call("POST", "/auth/login", { email, password, totp: totp || undefined, device: deviceInfo() }); tok.access = r.access_token; tok.refresh = r.refresh_token; return r; },
    async logout() { try { await call("POST", "/auth/logout", {}); } catch (e) {} tok.access = null; tok.refresh = null; },
    /* R71-001 passkeys — the browser / platform authenticator signs a server challenge; a passkey replaces password + authenticator code */
    get passkeyCapable() { return !MOCK && (!!pkNative() || !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get)); },
    async passkeyLogin() {
      const o = await call("POST", "/auth/passkey/options", {}); const n = pkNative(); let cred;
      if (n) { cred = await n.getCredential({ requestJson: JSON.stringify({ ...o.options, allowCredentials: [] }), origin: "https://goldensas.com" }); }   // native plug-in contract: requestJson string (the JS wrapper that builds it is not loaded in a server.url shell) }
      else { const pk = o.options; pk.challenge = pkB64.dec(pk.challenge); pk.allowCredentials = []; cred = pkToJSON(await navigator.credentials.get({ publicKey: pk })); }
      const r = await call("POST", "/auth/passkey/login", { challenge_id: o.challenge_id, credential: cred, device: deviceInfo() }); tok.access = r.access_token; tok.refresh = r.refresh_token; return r; },
    async passkeyRegister(name, totp) {
      const o = await call("POST", "/me/passkeys/options", { totp: totp || undefined }); const n = pkNative(); let cred;
      if (n) { cred = await n.createCredential({ requestJson: JSON.stringify(o.options), origin: "https://goldensas.com" }); }
      else { const pk = o.options; pk.challenge = pkB64.dec(pk.challenge); pk.user.id = pkB64.dec(pk.user.id); pk.excludeCredentials = (pk.excludeCredentials || []).map((c) => ({ type: c.type, id: pkB64.dec(c.id) })); cred = pkToJSON(await navigator.credentials.create({ publicKey: pk })); }
      return call("POST", "/me/passkeys", { challenge_id: o.challenge_id, credential: cred, name }); },
    passkeys: () => call("GET", "/me/passkeys"), passkeyRemove: (id) => call("DELETE", `/me/passkeys/${id}`),

    me: () => call("GET", "/me"), master: () => call("GET", "/master"), documents: () => call("GET", "/documents"), notes: () => call("GET", "/notes"),
    projects: () => call("GET", "/pm/projects"), subproject: (id) => call("GET", `/pm/subproject/${id}`),
    drawings: (project) => call("GET", `/pm/drawings${project ? `?project=${project}` : ""}`), submittals: (project) => call("GET", `/pm/submittals${project ? `?project=${project}` : ""}`),
    team: (year) => call("GET", `/pm/hr/team?year=${year || new Date().getFullYear()}`),
    absences: (project, from, to) => call("GET", `/pm/absences?project=${project}&from=${from}&to=${to}`),
    inbox: (since) => call("GET", `/pm/inbox${since ? `?since=${Math.floor(+since)}` : ""}`),   // since = unix seconds
    sync: (ops) => call("POST", "/sync", { ops }),
    photo: (recordId, blob, name, task, caption) => { const f = new FormData(); f.append("record_id", recordId); f.append("task", String(task || 1)); if (caption) f.append("caption", caption); f.append("photo", blob, name || "photo.jpg"); return call("POST", "/files/photo", f, true); },
    pushToken: (token, service) => call("POST", "/devices/push-token", { token, service }),
    assist: (body) => call("POST", "/assist", body),
    hr: (year) => call("GET", `/hr?year=${year || new Date().getFullYear()}`),
    hrRequest: (p, file) => { const f = new FormData(); Object.keys(p).forEach((k) => f.append(k, p[k] == null ? "" : p[k])); if (file) f.append("doc", file, file.name); return call("POST", "/hr/request", f, true); },
    hrDocUrl: (id) => `${BASE}/hr/doc/${id}`,
    documentUrl: (id) => `${BASE}/documents/${id}`,
    async fetchDocument(id, kind) { const go = () => fetch(kind === "hr" ? `${BASE}/hr/doc/${id}` : `${BASE}/documents/${id}`, { headers: { Authorization: `Bearer ${tok.access}`, "X-App": "pm", "X-App-Version": VERSION }, cache: "no-store" }); let r = await go(); if (r.status === 401 && tok.refresh) { const x = await raw("POST", "/auth/refresh", { refresh_token: tok.refresh }); tok.access = x.access_token; tok.refresh = x.refresh_token; r = await go(); } if (!r.ok) { const e = new Error(`HTTP ${r.status}`); e.status = r.status; throw e; } return r; },
  };

  // ------------------------------------------------------------------ MOCK portal (same shapes as CONTRACT.md §1–§9)
  const today = new Date().toISOString().slice(0, 10);
  const d = (n) => { const x = new Date(); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
  const STAGES = ["engineering", "procurement", "production", "fat", "installation", "sathat", "commissioning"];
  const overall = (st) => { const v = STAGES.map((k) => st[k]).filter((x) => x !== -1 && x != null); return v.length ? Math.round(v.reduce((a, b) => a + b, 0) / v.length) : 0; };
  const mockState = { user: null, n: 0 };
  const ME = { id: 2, name: "Nikos Polykandriotis", email: "np@goldenas.com.gr", role: "staff", discipline: "Management", discipline_id: 9, section_id: null, home_branch: "GR", branch_scope: ["GR", "GIT"], languages: ["en", "el", "it", "uk", "bg"], min_app_version: "1.0.5", ai: true, calendar: "GR", hr: true,
    pm: true, hr_manager: true, ts_approver: true, totp_enabled: true, counts: { hr_pending: 2, ts_pending: 2, drawings_overdue: 2, submittals_open: 5, hr_unread: 1 } };
  const STAFF = [
    { id: 2, name: "Nikos Polykandriotis", discipline: "Management", discipline_id: 9, person_id: 1 },
    { id: 7, name: "Dimitris Papadakis", discipline: "Electrical", discipline_id: 1, person_id: 3 },
    { id: 8, name: "Giorgos Antoniou", discipline: "Electrical", discipline_id: 1, person_id: 4 },
    { id: 9, name: "Marco Rossi", discipline: "Automation", discipline_id: 2, person_id: 5 },
    { id: 10, name: "Elena Markou", discipline: "Navigation", discipline_id: 3, person_id: 6 },
    { id: 11, name: "Oleksandr Bondarenko", discipline: "Automation", discipline_id: 2, person_id: 7 },
    { id: 12, name: "Konstantinos Nikolaou", discipline: "Management", discipline_id: 9, person_id: 2 },
  ];
  const sp = (id, code, name, system, st, extra) => { const stages = Object.fromEntries(STAGES.map((k, i) => [k, st[i]])); return { id, code, parent_code: /^(.+)\.\d+$/.test(code) && code.split(".").length > 2 ? code.replace(/\.\d+$/, "") : null, name, system, status: "active", stages, fed: [], overall: overall(stages), team: [7, 8], pm_id: 2, issues_open: 0, deadline: null, description: `${name} — scope as per offer, Golden A/S supply and commissioning.`, ...extra }; };
  const M = {
    me: ME, staff: STAFF, stages: STAGES,
    projects: [
      { id: 154, code: "PRJ.000154", name: "M/Y OCEANIA 80 m — electrical & automation", vessel: "M/Y OCEANIA", vessel_id: 41, yard: "Heesen Yachts, Oss", customer: "OCEANIA YACHTING LTD", branch: "GR", status: "active", pm: "Nikos Polykandriotis", pm_id: 2, mine: true,
        subprojects: [
          sp(1541, "Y.01", "Alarm & monitoring system", "ALARM AND MONITORING SYSTEM", [100, 100, 60, 0, 0, 0, 0], { fed: ["production"], team: [7, 9, 11], issues_open: 2, deadline: d(45) }),
          sp(1542, "Y.01.1", "AMS — bridge consoles", "ALARM AND MONITORING SYSTEM", [100, 75, 25, 0, 0, 0, 0], { team: [9, 11], deadline: d(60) }),
          sp(1543, "Y.02", "Main switchboard", "MAIN SWITCHBOARD", [100, 100, 100, 100, 50, 0, 0], { team: [7, 8], issues_open: 1, deadline: d(20) }),
          sp(1544, "Y.02.1", "MSB — generator control", "MAIN SWITCHBOARD", [100, 100, 100, 75, 0, 0, 0], { team: [8, 11] }),
          sp(1545, "Y.03", "Shore connection", "SHORE CONNECTION", [75, 25, 0, -1, 0, 0, 0], { team: [7], deadline: d(90) }),
          sp(1546, "Y.04", "Navigation lights", "NAVIGATION LIGHTS", [100, 100, -1, -1, 100, 100, 100], { team: [10], status: "completed" }),
        ] },
      { id: 161, code: "PRJ.000161", name: "M/Y ALTAIR refit — navigation & bridge", vessel: "M/Y ALTAIR", vessel_id: 42, yard: "Perini Navi, La Spezia", customer: "ALTAIR MARINE SRL", branch: "GIT", status: "active", pm: "Nikos Polykandriotis", pm_id: 2, mine: true,
        subprojects: [
          sp(1611, "Y.01", "NAVCOM renewal", "RADAR", [100, 50, -1, -1, 0, 0, 0], { team: [9, 10], issues_open: 1, deadline: d(30) }),
          sp(1612, "Y.02", "Bridge dimmers", "LIGHTING DISTRIBUTION SYSTEM", [100, 100, 100, -1, 100, 50, 0], { team: [9] }),
          sp(1613, "Y.03", "ECDIS integration", "ECDIS", [50, 0, -1, -1, 0, 0, 0], { team: [10], deadline: d(75) }),
        ] },
      { id: 170, code: "PRJ.000170", name: "RoPax door control retrofit", vessel: "F/B NISOS", vessel_id: 43, yard: "Perama", customer: "AEGEAN FERRIES SA", branch: "GR", status: "quoted", pm: "Konstantinos Nikolaou", pm_id: 12, mine: false,
        subprojects: [
          sp(1701, "Y.01", "Door control PLC", "WATERTIGHT DOORS", [0, 0, 0, 0, 0, 0, 0], { team: [11], status: "planned" }),
          sp(1702, "Y.01.1", "Door control — HMI", "WATERTIGHT DOORS", [0, 0, 0, -1, 0, 0, 0], { team: [11], status: "planned" }),
          sp(1703, "Y.02", "Cabling & junction boxes", "CABLING", [0, 0, -1, -1, 0, 0, 0], { team: [8], status: "planned" }),
        ] },
    ],
    drawings: [
      { id: 901, project_id: 154, subproject_id: 1541, kind: "equipment", seq_no: 1, title: "AMS cabinet GA — ER control room", system: "AMS", location: "ER control room", location_status: "confirmed", responsible: "Heesen — J. de Vries", request_sent: d(-30), expected_at: d(-9), received_at: null, status: "pending", overdue_days: 9, remarks: "", file_id: null },
      { id: 902, project_id: 154, subproject_id: 1543, kind: "principal", seq_no: 2, title: "MSB single line diagram R2", system: "MSB", location: "Main switchboard room", location_status: "confirmed", responsible: "Heesen — J. de Vries", request_sent: d(-21), expected_at: d(-3), received_at: null, status: "pending", overdue_days: 3, remarks: "Reminder sent", file_id: null },
      { id: 903, project_id: 154, subproject_id: 1545, kind: "equipment", seq_no: 3, title: "Shore converter foundation", system: "SHC", location: "Aft technical space", location_status: "tbc", responsible: "Heesen — P. Smit", request_sent: d(-10), expected_at: d(12), received_at: null, status: "pending", overdue_days: 0, remarks: "", file_id: null },
      { id: 904, project_id: 154, subproject_id: 1542, kind: "equipment", seq_no: 4, title: "Bridge console cut-outs", system: "AMS", location: "Wheelhouse", location_status: "confirmed", responsible: "Heesen — J. de Vries", request_sent: d(-40), expected_at: d(-20), received_at: d(-18), status: "received", overdue_days: 0, remarks: "", file_id: 777 },
      { id: 905, project_id: 154, subproject_id: 1546, kind: "equipment", seq_no: 5, title: "Navigation light positions", system: "NAVL", location: "Mast", location_status: "confirmed", responsible: "Heesen — P. Smit", request_sent: d(-90), expected_at: d(-70), received_at: d(-72), status: "received", overdue_days: 0, remarks: "", file_id: 778 },
      { id: 906, project_id: 161, subproject_id: 1611, kind: "equipment", seq_no: 1, title: "Radar pedestal arrangement", system: "RADAR", location: "Mast platform", location_status: "confirmed", responsible: "Perini — L. Bianchi", request_sent: d(-14), expected_at: d(7), received_at: null, status: "pending", overdue_days: 0, remarks: "", file_id: null },
      { id: 907, project_id: 161, subproject_id: 1612, kind: "principal", seq_no: 2, title: "Bridge lighting circuit list", system: "LDS", location: "Wheelhouse", location_status: "confirmed", responsible: "Perini — L. Bianchi", request_sent: d(-50), expected_at: d(-30), received_at: null, status: "not_required", overdue_days: 0, remarks: "Existing drawing reused", file_id: null },
      { id: 908, project_id: 161, subproject_id: 1613, kind: "equipment", seq_no: 3, title: "ECDIS console interface list", system: "ECDIS", location: "Wheelhouse", location_status: "tbc", responsible: "Perini — L. Bianchi", request_sent: d(-5), expected_at: d(20), received_at: null, status: "pending", overdue_days: 0, remarks: "", file_id: null },
    ],
    submittals: [
      { id: 501, kind: "owner", project_id: 154, subproject_id: 1541, doc_no: "OA-154-007", title: "AMS alarm list R1", revision: "R1", society: null, class_ref: null, submitted_at: d(-12), due_at: d(-2), decided_at: null, status: "submitted", open: true, overdue_days: 2, comments: "", file_id: 801 },
      { id: 502, kind: "owner", project_id: 154, subproject_id: 1543, doc_no: "OA-154-009", title: "MSB front view & mimic", revision: "R0", society: null, class_ref: null, submitted_at: d(-6), due_at: d(8), decided_at: null, status: "submitted", open: true, overdue_days: 0, comments: "", file_id: 802 },
      { id: 503, kind: "owner", project_id: 154, subproject_id: 1542, doc_no: "OA-154-004", title: "Bridge console layout", revision: "R2", society: null, class_ref: null, submitted_at: d(-40), due_at: d(-26), decided_at: d(-28), status: "approved_comments", open: false, overdue_days: 0, comments: "Move the AMS keyboard 50 mm to port", file_id: 803 },
      { id: 504, kind: "owner", project_id: 161, subproject_id: 1611, doc_no: "OA-161-002", title: "Radar display positions", revision: "R1", society: null, class_ref: null, submitted_at: d(-20), due_at: d(-6), decided_at: null, status: "resubmitted", open: true, overdue_days: 6, comments: "Resubmitted after owner comment", file_id: 804 },
      { id: 601, kind: "class", project_id: 154, subproject_id: 1543, doc_no: "CS-154-003", title: "MSB short-circuit calculation", revision: "R1", society: "Lloyd's Register", class_ref: "LR-2026-0871", submitted_at: d(-25), due_at: d(-4), decided_at: null, status: "submitted", open: true, overdue_days: 4, comments: "", file_id: 805 },
      { id: 602, kind: "class", project_id: 154, subproject_id: 1541, doc_no: "CS-154-005", title: "AMS FMEA", revision: "R0", society: "Lloyd's Register", class_ref: "LR-2026-0902", submitted_at: d(-8), due_at: d(14), decided_at: null, status: "submitted", open: true, overdue_days: 0, comments: "", file_id: 806 },
      { id: 603, kind: "class", project_id: 161, subproject_id: 1611, doc_no: "CS-161-001", title: "Radar installation drawing", revision: "R1", society: "RINA", class_ref: "RINA-26-4411", submitted_at: d(-60), due_at: d(-40), decided_at: d(-42), status: "approved", open: false, overdue_days: 0, comments: "", file_id: 807 },
    ],
    documents: [
      { id: 101, project_id: 154, subproject_id: 1543, name: "DRW-000154-E-012_R2 — MSB general arrangement", category: "drawing", size: "2.1 MB", mime: "application/pdf" },
      { id: 102, project_id: 154, subproject_id: 1541, name: "SPEC-000154-A-001_R1 — AMS functional description", category: "specification", size: "3.8 MB", mime: "application/pdf" },
      { id: 103, project_id: 161, subproject_id: 1611, name: "DRW-000161-N-003_R1 — Radar pedestal", category: "drawing", size: "0.9 MB", mime: "application/pdf" },
    ],
    inbox: [
      { id: 9001, kind: "hrq", title: "Leave request — Dimitris Papadakis", body: `ANN ${I18N.date(d(20))} – ${I18N.date(d(21))}, 2 days — awaiting your decision`, url: "/customer/?p=hr&t=requests", created_at: d(-1) + " 09:12", read: false },
      { id: 9002, kind: "timesheet", title: "Timesheet TS-2026-GA-011 submitted", body: "Giorgos Antoniou — 42 h on PRJ.000154", url: "/customer/?p=timesheets", created_at: d(-1) + " 17:40", read: false },
      { id: 9003, kind: "drawing", title: "Drawing overdue — AMS cabinet GA", body: "PRJ.000154 Y.01 — expected " + I18N.date(d(-9)), url: "/customer/?p=drawings&id=901", created_at: d(-2) + " 08:00", read: false },
      { id: 9004, kind: "submittal", title: "Class comment — MSB short-circuit calculation", body: "Lloyd's Register asks for the generator decrement curves", url: "/customer/?p=class&id=601", created_at: d(-3) + " 14:22", read: true },
      { id: 9005, kind: "note", title: "Weekly note — Y.02 Main switchboard", body: "Dimitris Papadakis: busbar torque check completed", url: "/customer/?p=sub&id=1543", created_at: d(-4) + " 16:05", read: true },
      { id: 9006, kind: "hrq", title: "Leave approved — Elena Markou", body: "HR approved ANN " + I18N.date(d(10)), url: "/customer/?p=hr", created_at: d(-5) + " 11:30", read: true },
    ],
    notes: [
      { id: "N201", date: d(-2), iso_week: "", project_id: 154, subproject_id: 1543, author: "Dimitris Papadakis", visibility: "external", text: "Busbar torque check completed on all sections; mimic panel wiring 80 %.", photos: 3 },
      { id: "N202", date: d(-6), iso_week: "", project_id: 154, subproject_id: 1541, author: "Marco Rossi", visibility: "internal", text: "AMS I/O list frozen with the yard; waiting for the bilge sensor type confirmation.", photos: 0 },
      { id: "N203", date: d(-9), iso_week: "", project_id: 161, subproject_id: 1611, author: "Elena Markou", visibility: "external", text: "Radar pedestal foundation inspected; cable route via mast agreed with Perini.", photos: 5 },
      { id: "N204", date: d(-16), iso_week: "", project_id: 154, subproject_id: 1545, author: "Nikos Polykandriotis", visibility: "internal", text: "Shore converter supplier confirms FAT at factory not applicable — tested at installation.", photos: 0 },
      { id: "N205", date: d(-30), iso_week: "", project_id: 154, subproject_id: 1542, author: "Oleksandr Bondarenko", visibility: "external", text: "Bridge console mock-up approved by the captain on board.", photos: 2 },
    ],
    issues: {
      1541: [{ id: 71, title: "Bilge sensor type not confirmed", body: "Yard has not confirmed the float switch model for the bilge wells.", raised_on: d(-12), raised_by: "Marco Rossi", owner_side: "yard", assignee: "Nikos Polykandriotis", due_on: d(-2), priority: "high", status: "open" },
        { id: 72, title: "AMS server rack ventilation", body: "Rack ventilation 2 × 80 mm fans — yard to confirm duct.", raised_on: d(-5), raised_by: "Dimitris Papadakis", owner_side: "gas", assignee: "Marco Rossi", due_on: d(10), priority: "normal", status: "in_progress" }],
      1543: [{ id: 73, title: "Generator breaker interlock logic", body: "Owner asks for manual override of the shore/generator interlock.", raised_on: d(-8), raised_by: "Owner — ETO", owner_side: "owner", assignee: "Giorgos Antoniou", due_on: d(5), priority: "normal", status: "open" }],
      1611: [{ id: 74, title: "Radar cable length exceeds 60 m", body: "Pedestal to display route is 68 m — check repeater.", raised_on: d(-3), raised_by: "Elena Markou", owner_side: "gas", assignee: "Elena Markou", due_on: d(7), priority: "high", status: "open" }],
    },
    comments: {
      1541: [{ id: 301, author: "Marco Rossi", author_id: 9, visibility: "internal", body: "I/O list frozen — rev 3 uploaded to documents.", created_at: d(-6) + " 10:12", parent_id: null }, { id: 302, author: "Owner — Captain", author_id: 99, visibility: "external", body: "Please confirm the alarm sound levels in the crew mess.", created_at: d(-4) + " 08:40", parent_id: null }],
      1543: [{ id: 303, author: "Dimitris Papadakis", author_id: 7, visibility: "internal", body: "Torque values recorded in the FAT protocol annex.", created_at: d(-2) + " 16:00", parent_id: null }],
    },
    budgets: { 1541: [{ section_id: 2, code: "AUT", name: "Automation", budget: 320, booked: 298, extra: 0 }, { section_id: 1, code: "ELE", name: "Electrical", budget: 120, booked: 84, extra: 0 }], 1543: [{ section_id: 1, code: "ELE", name: "Electrical", budget: 200, booked: 184, extra: 0 }, { section_id: 4, code: "UIX", name: "UI/UX", budget: 40, booked: 42, extra: 2 }], 1611: [{ section_id: 3, code: "NAV", name: "Navigation", budget: 80, booked: 31, extra: 0 }] },
    people: [
      { person_id: 3, user_id: 7, name: "Dimitris Papadakis", discipline: "Electrical", annual: { entitled: 22, taken: 9, pending: 2, available: 11 }, away_today: null },
      { person_id: 4, user_id: 8, name: "Giorgos Antoniou", discipline: "Electrical", annual: { entitled: 22, taken: 15, pending: 0, available: 7 }, away_today: null },
      { person_id: 5, user_id: 9, name: "Marco Rossi", discipline: "Automation", annual: { entitled: 20, taken: 6, pending: 3, available: 11 }, away_today: null },
      { person_id: 6, user_id: 10, name: "Elena Markou", discipline: "Navigation", annual: { entitled: 22, taken: 18, pending: 0, available: 4 }, away_today: "ANN" },
      { person_id: 7, user_id: 11, name: "Oleksandr Bondarenko", discipline: "Automation", annual: { entitled: 21, taken: 4, pending: 1, available: 16 }, away_today: null },
    ],
    requests: [
      { id: 42, person_id: 3, person: "Dimitris Papadakis", type_id: 1, code: "ANN", type: "Annual leave", unit: "day", date_from: d(20), date_to: d(21), time_from: null, time_to: null, hours: null, days: 2, reason: "Family", status: "pending_mgr", doc: null, created_at: d(-1) + " 09:10", manager: null, hr: null, decidable: true },
      { id: 43, person_id: 5, person: "Marco Rossi", type_id: 1, code: "ANN", type: "Annual leave", unit: "day", date_from: d(14), date_to: d(16), time_from: null, time_to: null, hours: null, days: 3, reason: "", status: "pending_mgr", doc: null, created_at: d(-2) + " 15:30", manager: null, hr: null, decidable: true },
      { id: 44, person_id: 7, person: "Oleksandr Bondarenko", type_id: 2, code: "SICK", type: "Sick leave", unit: "day", date_from: d(-1), date_to: d(-1), time_from: null, time_to: null, hours: null, days: 1, reason: "", status: "pending_hr", doc: { name: "medical-note.pdf" }, created_at: d(-1) + " 07:50", manager: null, hr: null, decidable: false },
    ],
    timesheets: [
      { id: 311, ts_no: "TS-2026-GA-011", user_id: 8, user: "Giorgos Antoniou", project_id: 154, project_code: "PRJ.000154", period_start: d(-7), period_end: d(-1), hours_normal: 40, hours_overtime: 2, hours_holiday: 0, hours_total: 42, submitted_at: d(-1) + " 17:40", status: "submitted",
        lines: [{ date: d(-7), subproject_code: "Y.02", hour_type: "normal", hours: 8, activity: "site", description: "MSB installation — busbar torque" }, { date: d(-6), subproject_code: "Y.02", hour_type: "normal", hours: 8, activity: "site", description: "Mimic panel wiring" }, { date: d(-5), subproject_code: "Y.02", hour_type: "normal", hours: 8, activity: "site", description: "Mimic panel wiring" }, { date: d(-4), subproject_code: "Y.02.1", hour_type: "normal", hours: 8, activity: "site", description: "Generator control cabling" }, { date: d(-3), subproject_code: "Y.02.1", hour_type: "normal", hours: 8, activity: "site", description: "Generator control cabling" }, { date: d(-3), subproject_code: "Y.02.1", hour_type: "overtime", hours: 2, activity: "site", description: "Cable test" }] },
      { id: 312, ts_no: "TS-2026-DP-009", user_id: 7, user: "Dimitris Papadakis", project_id: 154, project_code: "PRJ.000154", period_start: d(-7), period_end: d(-1), hours_normal: 32, hours_overtime: 0, hours_holiday: 8, hours_total: 40, submitted_at: d(-1) + " 18:02", status: "submitted",
        lines: [{ date: d(-7), subproject_code: "Y.01", hour_type: "normal", hours: 8, activity: "engineering", description: "AMS cabinet termination" }, { date: d(-6), subproject_code: "Y.01", hour_type: "normal", hours: 8, activity: "engineering", description: "AMS cabinet termination" }, { date: d(-5), subproject_code: "Y.03", hour_type: "normal", hours: 8, activity: "site", description: "Shore connection survey" }, { date: d(-4), subproject_code: "Y.03", hour_type: "normal", hours: 8, activity: "site", description: "Shore connection survey" }, { date: d(-3), subproject_code: "Y.01", hour_type: "holiday", hours: 8, activity: "site", description: "Public holiday — FAT preparation" }] },
    ],
  };
  // absence matrix today → +28 days for the team (codes / status only, never reasons — CS-24)
  function matrix(people) {
    const days = []; for (let i = 0; i < 29; i++) days.push(d(i));
    const plan = { 3: [[20, 21, "ANN", "pending_mgr"]], 4: [[7, 7, "SICK", "approved"]], 5: [[14, 16, "ANN", "pending_mgr"], [2, 2, "TRAIN", "board"]], 6: [[0, 3, "ANN", "approved"], [10, 10, "ANN", "approved"]], 7: [[25, 28, "ANN", "approved"]], 1: [[22, 22, "TRAVEL", "board"]] };
    return { from: d(0), to: d(28), days, rows: people.map((p) => { const cells = {}; (plan[p.person_id] || []).forEach(([a, b, code, status]) => { for (let i = a; i <= b; i++) cells[d(i)] = { code, status }; }); return { person_id: p.person_id, user_id: p.user_id, name: p.name, cells }; }) };
  }
  const peopleByUsers = (ids) => STAFF.filter((s) => ids.includes(s.id)).map((s) => ({ person_id: s.person_id, user_id: s.id, name: s.name }));
  M.hr = { year: new Date().getFullYear(), years: [new Date().getFullYear() - 1, new Date().getFullYear()], person: { id: 1, name: "Nikos Polykandriotis", department: "Management", hire_date: "2005-01-10", service: "21 y 9 m", contractor: false, jurisdiction: "GR" }, manager: null, hours_per_day: 8,
    balances: [{ type_id: 1, code: "ANN", name: "Annual leave", unit: "day", consumes_annual: false, entitled: 26, taken: 12, pending: 0, available: 14, via_annual: false }, { type_id: 2, code: "SICK", name: "Sick leave", unit: "day", consumes_annual: false, entitled: 26, taken: 0, pending: 0, available: 26, via_annual: false }, { type_id: 12, code: "EARLY", name: "Early leave / late arrival", unit: "hour", consumes_annual: true, entitled: null, taken: 0, pending: 0, available: 112, via_annual: true }],
    types: [{ id: 1, code: "ANN", name: "Annual leave", unit: "day", needs_reason: false, needs_doc: false, hr_only: false }, { id: 2, code: "SICK", name: "Sick leave", unit: "day", needs_reason: false, needs_doc: true, hr_only: true }, { id: 5, code: "UNP", name: "Unpaid leave", unit: "day", needs_reason: true, needs_doc: false, hr_only: false }, { id: 12, code: "EARLY", name: "Early leave / late arrival", unit: "hour", needs_reason: true, needs_doc: false, hr_only: false }],
    periods: [{ id: 1, name: "Christmas 2026", type_id: 1, code: "ANN", type: "Annual leave", date_from: "2026-12-24", date_to: "2027-01-04", note: "Company closure — join with your dates", mandatory: false, n_approved: 3, max_people: null, full: false, joined: null }],
    requests: [{ id: 31, type_id: 1, code: "ANN", type: "Annual leave", unit: "day", date_from: d(-50), date_to: d(-41), time_from: null, time_to: null, hours: null, days: 8, reason: "", status: "approved", counted: true, period_id: null, doc: null, manager: null, hr: { at: d(-55), note: "" }, cancellable: false }],
    trainings: { open: [{ id: 1, kind: "external", title: "Lloyd's Register — electrical rules update", provider: "LR Piraeus", location: "Piraeus", date_from: "2026-11-27", date_to: "2026-11-27", hours: 8, description: "Rules for electrical installations 2026", deadline: "2026-11-15", certificate: true, n_in: 4, capacity: 12, mine: null, full: false, late: false }], mine: [] },
    evaluations: [], messages: [{ id: 1, from_hr: true, from: "HR — Margarita Polykandrioti", body: "Please confirm the Christmas closure dates for your team by 15 November.", at: d(-2) + " 09:22", seen: false }], unread: 1 };

  function mock(method, path, body) {
    if (!navigator.onLine) { const e = new Error("offline"); e.offline = true; throw e; }   // the demo behaves like the network: nothing reaches the mock portal while offline
    const p = path.split("?")[0]; const q = Object.fromEntries(new URLSearchParams(path.split("?")[1] || "")); const delay = (v) => new Promise((r) => setTimeout(() => r(JSON.parse(JSON.stringify(v))), 120));
    if (p === "/auth/login") { if (!body.email || !body.password) { const e = new Error("Invalid credentials"); e.status = 401; throw e; } if (/^notpm@/.test(body.email)) { const e = new Error("This account is not a project manager"); e.status = 403; e.data = { error: "This account is not a project manager" }; throw e; }
      if (/^totp@/.test(body.email) && !body.totp) { const e = new Error("Authenticator code required"); e.status = 401; e.data = { totp: true, code: "totp_required" }; throw e; }
      mockState.user = M.me; return delay({ access_token: "mock", refresh_token: "mock-refresh", user: M.me }); }
    if (p === "/auth/logout") { mockState.user = null; return delay({}); }
    if (p === "/auth/refresh") return delay({ access_token: "mock", refresh_token: "mock-refresh" });
    const counts = () => ({ hr_pending: M.requests.filter((r) => r.status === "pending_mgr" && r.decidable).length, ts_pending: M.timesheets.length, drawings_overdue: M.drawings.filter((x) => x.status === "pending" && x.overdue_days > 0).length, submittals_open: M.submittals.filter((x) => x.open).length, hr_unread: M.hr.unread });
    const projItems = () => M.projects.map((pr) => ({ ...pr, overall: Math.round(pr.subprojects.reduce((a, s) => a + s.overall, 0) / pr.subprojects.length), counts: { subprojects: pr.subprojects.length, drawings_overdue: M.drawings.filter((x) => x.project_id === pr.id && x.status === "pending" && x.overdue_days > 0).length, submittals_open: M.submittals.filter((x) => x.project_id === pr.id && x.open).length, issues_open: pr.subprojects.reduce((a, s) => a + s.issues_open, 0) } }));
    if (p === "/me") return delay({ ...M.me, counts: counts() });
    if (p === "/master") return delay({ stages: STAGES, engineers: STAFF });
    if (p === "/documents") return delay({ items: M.documents });
    if (p === "/notes") return delay({ items: M.notes });
    if (p === "/pm/projects") return delay({ items: projItems(), stages: STAGES, staff: STAFF });
    if (p === "/pm/drawings") return delay({ items: M.drawings.filter((x) => !q.project || x.project_id === +q.project) });
    if (p === "/pm/submittals") return delay({ items: M.submittals.filter((x) => !q.project || x.project_id === +q.project) });
    if (p === "/pm/inbox") { const since = +q.since || 0; return delay({ items: M.inbox.filter((n) => !since || new Date(n.created_at.replace(" ", "T")).getTime() / 1000 > since).slice(0, 50) }); }
    if (p === "/pm/hr/team") return delay({ year: +q.year || new Date().getFullYear(), people: M.people, requests: M.requests, timesheets: M.timesheets, absences: matrix(M.people) });
    if (p === "/pm/absences") { const pr = M.projects.find((x) => x.id === +q.project); const ids = pr ? [...new Set(pr.subprojects.flatMap((s) => s.team).concat([pr.pm_id]))] : []; return delay(matrix(peopleByUsers(ids))); }
    const sm = p.match(/^\/pm\/subproject\/(\d+)$/);
    if (sm) { const id = +sm[1]; let pr = null, s = null; for (const x of M.projects) { s = x.subprojects.find((y) => y.id === id); if (s) { pr = x; break; } } if (!s) { const e = new Error("Not found"); e.status = 404; throw e; }
      return delay({ subproject: s, project: { id: pr.id, code: pr.code, name: pr.name, vessel: pr.vessel, customer: pr.customer, branch: pr.branch }, issues: M.issues[id] || [], comments: (M.comments[id] || []).slice().sort((a, b) => b.created_at.localeCompare(a.created_at)).slice(0, 100),
        notes: M.notes.filter((n) => n.subproject_id === id), drawings: M.drawings.filter((x) => x.subproject_id === id), submittals: M.submittals.filter((x) => x.subproject_id === id), budgets: M.budgets[id] || [],
        team: STAFF.filter((x) => s.team.includes(x.id)).map((x) => ({ id: x.id, name: x.name, discipline: x.discipline, person_id: x.person_id })), absences: matrix(peopleByUsers(s.team)) }); }
    if (p === "/hr") return delay(M.hr);
    if (p === "/hr/request") { const id = 80 + (++mockState.n); M.hr.requests.unshift({ id, type_id: 2, code: "SICK", type: "Sick leave", unit: "day", date_from: today, date_to: today, days: 1, status: "pending_hr", counted: true, doc: { name: "doc" }, cancellable: true, reason: "" }); return delay({ id: "HR" + id, request_id: id, status: "accepted", record_status: "pending_hr" }); }
    if (p === "/files/photo") return delay({ file_id: Math.floor(Math.random() * 9000) + 1000 });
    if (p === "/devices/push-token") return delay({});
    if (p === "/assist") { const txt = (body.prompt || "").trim(); const f = (body.fields || []).map((x) => `${x.label || x.name}: ${x.value || "—"}`).join("; ");
      const map = { write: `(house style) ${txt.replace(/^improve[^:]*:\s*/i, "").replace(/^./, (c) => c.toUpperCase())}`, translate: `(translation) ${txt}`, ask: `From ${body.page}: ${f.slice(0, 160) || "no fields"}. ${txt.slice(0, 80)} — in the live portal this comes from Claude.` };
      return delay({ text: map[body.kind] || map.ask, tokens: 120 }); }
    if (p === "/sync") {
      const results = body.ops.map((op) => { const pl = op.payload || {};
        const findSub = (id) => { for (const x of M.projects) { const s = x.subprojects.find((y) => y.id === +id); if (s) return s; } return null; };
        switch (op.kind) {
          case "sp_stages": { const s = findSub(pl.subproject_id); if (!s) return { uuid: op.id, status: "rejected", code: 404, error: "Sub-project not found" }; const ignored = [];
            Object.keys(pl.stages || {}).forEach((k) => { if (!STAGES.includes(k)) return; if (s.fed.includes(k)) { ignored.push(k); return; } const v = +pl.stages[k]; s.stages[k] = v === -1 ? -1 : Math.max(0, Math.min(100, Math.round(v))); });
            s.overall = overall(s.stages); return { uuid: op.id, status: "accepted", stages: s.stages, overall: s.overall, ignored }; }
          case "sp_comment": { const s = findSub(pl.subproject_id); if (!s || !(pl.body || "").trim()) return { uuid: op.id, status: "rejected", code: 422, error: "Empty comment" }; const id = 400 + (++mockState.n);
            (M.comments[s.id] = M.comments[s.id] || []).unshift({ id, author: M.me.name, author_id: M.me.id, visibility: pl.visibility === "external" ? "external" : "internal", body: pl.body, created_at: new Date().toISOString().slice(0, 16).replace("T", " "), parent_id: pl.parent_id || null }); return { uuid: op.id, status: "accepted", id: "C" + id }; }
          case "issue_status": { let found = null; Object.values(M.issues).forEach((l) => l.forEach((i) => { if (i.id === +pl.issue_id) found = i; })); if (!found) return { uuid: op.id, status: "rejected", code: 404, error: "Issue not found" };
            if (!["open", "in_progress", "closed"].includes(pl.status)) return { uuid: op.id, status: "rejected", code: 422, error: "Bad status" }; found.status = pl.status; const s = findSub(Object.keys(M.issues).find((k) => M.issues[k].includes(found))); if (s) s.issues_open = M.issues[s.id].filter((i) => i.status !== "closed").length; return { uuid: op.id, status: "accepted", record_status: pl.status, id: pl.issue_id }; }
          case "note": { const s = findSub(pl.subproject_id); if (!s) return { uuid: op.id, status: "rejected", code: 422, error: "Select the sub-project" }; const id = "N" + (300 + (++mockState.n)); const pr = M.projects.find((x) => x.subprojects.includes(s));
            M.notes.unshift({ id, date: pl.date || today, iso_week: "", project_id: pr.id, subproject_id: s.id, author: M.me.name, visibility: pl.visibility || "internal", text: pl.text || "", photos: 0 }); return { uuid: op.id, status: "accepted", id }; }
          case "drawing_update": { const dr = M.drawings.find((x) => x.id === +pl.drawing_id); if (!dr) return { uuid: op.id, status: "rejected", code: 404, error: "Drawing not found" }; if (!["pending", "received", "not_required"].includes(pl.status)) return { uuid: op.id, status: "rejected", code: 422, error: "Bad status" };
            dr.status = pl.status; dr.received_at = pl.status === "received" ? (pl.received_at || today) : null; if (pl.remarks != null) dr.remarks = pl.remarks; dr.overdue_days = dr.status === "pending" && dr.expected_at < today ? Math.round((new Date(today) - new Date(dr.expected_at)) / 86400000) : 0; return { uuid: op.id, status: "accepted", record_status: dr.status, received_at: dr.received_at }; }
          case "hr_decide": { if (M.me.totp_enabled && !/^\d{6}$/.test(String(pl.totp || ""))) return { uuid: op.id, status: "rejected", code: 401, totp: true, error: "Authenticator code required" };
            const r = M.requests.find((x) => x.id === +pl.request_id); if (!r) return { uuid: op.id, status: "conflict", code: 409, error: "Request no longer pending" }; if (!r.decidable) return { uuid: op.id, status: "rejected", code: 403, error: "Not your decision" };
            r.status = pl.approve ? "pending_hr" : "rejected"; r.decidable = false; r.manager = { name: M.me.name, at: today, note: pl.note || "" }; const i = M.requests.indexOf(r); M.requests.splice(i, 1); return { uuid: op.id, status: "accepted", record_status: r.status, record_status: r.status }; }
          case "ts_decide": { if (M.me.totp_enabled && !/^\d{6}$/.test(String(pl.totp || ""))) return { uuid: op.id, status: "rejected", code: 401, totp: true, error: "Authenticator code required" };
            const i = M.timesheets.findIndex((x) => x.id === +pl.ts_id); if (i < 0) return { uuid: op.id, status: "conflict", code: 409, error: "Timesheet already decided" }; if (!pl.approve && !(pl.note || "").trim()) return { uuid: op.id, status: "rejected", code: 422, error: "A note is required to reject" };
            M.timesheets.splice(i, 1); return { uuid: op.id, status: "accepted", record_status: pl.approve ? "approved" : "rejected", record_status: pl.approve ? "approved" : "rejected" }; }
          case "nf_read": { (pl.ids || []).forEach((id) => { const n = M.inbox.find((x) => x.id === +id); if (n) n.read = true; }); return { uuid: op.id, status: "accepted" }; }
          case "hr_request": { const ty = M.hr.types.find((x) => x.id === pl.type_id) || M.hr.types[0]; const id = 80 + (++mockState.n); M.hr.requests.unshift({ id, type_id: ty.id, code: ty.code, type: ty.name, unit: ty.unit, date_from: pl.date_from, date_to: pl.date_to || pl.date_from, time_from: pl.time_from || null, time_to: pl.time_to || null, hours: +pl.hours || null, days: 1, reason: pl.reason || "", status: ty.hr_only ? "pending_hr" : "pending_mgr", counted: true, period_id: null, doc: null, manager: null, hr: null, cancellable: true }); return { uuid: op.id, status: "accepted", id: "HR" + id, request_id: id, record_status: ty.hr_only ? "pending_hr" : "pending_mgr" }; }
          case "hr_cancel": { const r = M.hr.requests.find((x) => x.id === pl.request_id); if (r) { r.status = "cancelled"; r.cancellable = false; } return { uuid: op.id, status: "accepted", id: "HR" + pl.request_id }; }
          case "hr_period_join": { const lp = M.hr.periods.find((x) => x.id === pl.period_id); if (!lp) return { uuid: op.id, status: "rejected", error: "Period not open" }; lp.joined = { id: 60, status: "pending_hr", date_from: pl.date_from, date_to: pl.date_to }; return { uuid: op.id, status: "accepted", id: "HR60", request_id: 60 }; }
          case "hr_training_apply": { const tr = M.hr.trainings.open.find((x) => x.id === pl.training_id); if (tr) { tr.mine = "applied"; tr.n_in++; } return { uuid: op.id, status: "accepted", id: "TR" + pl.training_id }; }
          case "hr_eval_ack": return { uuid: op.id, status: "accepted", id: "EV" + pl.year };
          case "hr_message": { M.hr.messages.push({ id: 90 + (++mockState.n), from_hr: false, from: M.me.name, body: pl.text, at: today + " 10:00", seen: true }); return { uuid: op.id, status: "accepted", id: "HM" + mockState.n }; }
          case "hr_seen": { M.hr.messages.forEach((m) => { m.seen = true; }); M.hr.unread = 0; return { uuid: op.id, status: "accepted", id: "HM0" }; }
          case "approve": case "reject": case "issue": case "eng_decide": return { uuid: op.id, status: "rejected", code: 403, error: "Decided in the portal" };
          default: return { uuid: op.id, status: "rejected", error: "unknown kind" };
        } });
      return delay({ results });
    }
    const e = new Error("Not found"); e.status = 404; throw e;
  }
  return api;
})();
