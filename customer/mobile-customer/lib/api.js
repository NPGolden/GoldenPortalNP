/* api.js — Golden A/S customer app: client of the portal module /customer/api/v1, application "customer" (GAS-CP-R68-001).
   Access JWT 15 min in sessionStorage; rotating refresh token in localStorage (Keychain / Keystore in the Capacitor build).
   Mock mode (?mock=1 or localStorage gcu.mock) serves mock/data.js in the shapes of the live contract — demo and UI tests only. */
window.Api = (function () {
  const APP_ID = "customer", APP_VERSION = "1.0.2";
  const BASE = (document.querySelector('meta[name="gas-api"]') || {}).content || "../api/v1";
  let MOCK = false; try { MOCK = localStorage.getItem("gcu.mock") === "1" || /[?&]mock=1/.test(location.search); if (/[?&]mock=1/.test(location.search)) localStorage.setItem("gcu.mock", "1"); } catch (e) {}
  const cfg = { base: BASE, get mock() { return MOCK; } };
  let access = null, me = null, deviceId = null, onAuthLost = () => {};
  try { access = sessionStorage.getItem("gcu.at"); } catch (e) {}
  const setAccess = (v) => { access = v; try { v ? sessionStorage.setItem("gcu.at", v) : sessionStorage.removeItem("gcu.at"); } catch (e) {} };
  let refresh = null;
  async function init() { deviceId = await Native.deviceId(); refresh = await Native.secureGet("refresh"); if (MOCK) Mock.load(); }
  function setRefresh(t) { refresh = t; return t ? Native.secureSet("refresh", t) : Native.secureDel("refresh"); }
  const hasSession = () => !!refresh || (MOCK && Mock.signedIn());
  const online = () => navigator.onLine !== false;
  const deviceInfo = () => ({ ...Native.deviceInfo(), device_id: deviceId, app_version: APP_VERSION });

  async function raw(method, path, body, opts = {}) {
    if (MOCK) return Mock.handle(method, path, body, opts);
    const headers = { "Accept": "application/json", "X-App": APP_ID, "X-App-Version": APP_VERSION, "X-Lang": I18N.get() };
    if (access && !opts.noAuth) headers["Authorization"] = "Bearer " + access;
    let payload = body;
    if (body && !(body instanceof FormData)) { headers["Content-Type"] = "application/json"; payload = JSON.stringify(body); }
    const r = await fetch(cfg.base + path, { method, headers, body: payload, cache: "no-store" });
    if (r.status === 204) return null;
    const ct = r.headers.get("content-type") || "";
    const data = ct.includes("json") ? await r.json().catch(() => ({})) : (opts.blob ? await r.blob() : await r.text());
    if (!r.ok) { const err = new Error((data && data.error) || r.statusText); err.status = r.status; err.data = data; err.code = r.status === 401 && data && data.totp ? "totp" : (r.status === 426 ? "upgrade" : (r.status === 401 ? "unauthorized" : (r.status === 429 ? "rate" : ""))); throw err; }
    return data;
  }
  async function refreshAccess() {
    if (!refresh) throw Object.assign(new Error("no session"), { status: 401 });
    const d = await raw("POST", "/auth/refresh", { refresh_token: refresh }, { noAuth: true });
    setAccess(d.access_token); await setRefresh(d.refresh_token); return d;
  }
  async function call(method, path, body, opts = {}) {
    if (!online() && method !== "GET") throw Object.assign(new Error(I18N.t("app.net")), { code: "offline" });
    if (!access && refresh && !opts.noAuth && !MOCK) { try { await refreshAccess(); } catch (e) { if (e.status === 401 || e.status === 403) { await logoutLocal(); onAuthLost(e); } throw e; } }
    try { return await raw(method, path, body, opts); }
    catch (e) {
      if (e.status === 401 && e.code !== "totp" && !opts.noAuth && !opts._retried && !MOCK) { try { await refreshAccess(); return await call(method, path, body, { ...opts, _retried: true }); } catch (e2) { await logoutLocal(); onAuthLost(e2); throw e2; } }
      if (e.status === 426) UI.toast(I18N.t("app.update"));
      throw e;
    }
  }
  async function login(email, password, totp) {
    const d = await raw("POST", "/auth/login", { email, password, totp: totp || undefined, device: deviceInfo() }, { noAuth: true });
    setAccess(d.access_token); await setRefresh(d.refresh_token); me = d.user; return d;
  }
  // R71-001 passkeys
  const pkB64 = { enc: (buf) => { let s = ""; const b = new Uint8Array(buf); for (let i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, ""); },
                  dec: (s) => { s = s.replace(/-/g, "+").replace(/_/g, "/"); while (s.length % 4) s += "="; const bin = atob(s), b = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i); return b.buffer; } };
  function pkToJSON(cred) { const r = cred.response, out = { id: cred.id, rawId: pkB64.enc(cred.rawId), type: cred.type, response: { clientDataJSON: pkB64.enc(r.clientDataJSON) } };
    if (r.attestationObject) { out.response.attestationObject = pkB64.enc(r.attestationObject); try { out.response.transports = r.getTransports ? r.getTransports() : []; } catch (e) { out.response.transports = []; } }
    if (r.authenticatorData) { out.response.authenticatorData = pkB64.enc(r.authenticatorData); out.response.signature = pkB64.enc(r.signature); out.response.userHandle = r.userHandle ? pkB64.enc(r.userHandle) : null; } return out; }
  const passkeyCapable = () => !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get && !MOCK);
  async function passkeyLogin() {
    const o = await raw("POST", "/auth/passkey/options", {}, { noAuth: true }); const pk = o.options; pk.challenge = pkB64.dec(pk.challenge); pk.allowCredentials = [];
    const cred = await navigator.credentials.get({ publicKey: pk });
    const d = await raw("POST", "/auth/passkey/login", { challenge_id: o.challenge_id, credential: pkToJSON(cred), device: deviceInfo() }, { noAuth: true });
    setAccess(d.access_token); await setRefresh(d.refresh_token); me = d.user; return d;
  }
  async function passkeyRegister(name, totp) {
    const o = await call("POST", "/me/passkeys/options", { totp: totp || undefined }); const pk = o.options; pk.challenge = pkB64.dec(pk.challenge); pk.user.id = pkB64.dec(pk.user.id); pk.excludeCredentials = (pk.excludeCredentials || []).map((c) => ({ type: c.type, id: pkB64.dec(c.id) }));
    const cred = await navigator.credentials.create({ publicKey: pk });
    return call("POST", "/me/passkeys", { challenge_id: o.challenge_id, credential: pkToJSON(cred), name });
  }
  async function logoutLocal() { setAccess(null); me = null; await setRefresh(null); await Store.clear(); if (MOCK) Mock.signOut(); }
  async function logout() { try { await call("POST", "/auth/logout", {}); } catch (e) {} await logoutLocal(); }
  async function cached(path) {
    const key = "GET " + path;
    if (!online()) { const c = await Store.get(key); if (c) return c.v; throw Object.assign(new Error(I18N.t("app.net")), { code: "offline" }); }
    try { const d = await call("GET", path); await Store.set(key, d); return d; }
    catch (e) { const c = await Store.get(key); if (c && e.status !== 401 && e.status !== 403) return c.v; throw e; }
  }
  async function pdf(path) {
    const key = "PDF " + path; const b = await Store.getBlob(key); if (b) return b;
    const blob = MOCK ? await Mock.pdf(path) : await call("GET", path, null, { blob: true }); await Store.setBlob(key, blob); return blob;
  }
  async function upload(path, fields, files) {
    const fd = new FormData(); for (const k in fields) fd.append(k, fields[k] == null ? "" : fields[k]);
    (files || []).forEach(f => fd.append("files[]", f.blob, f.name)); return call("POST", path, fd);
  }
  return { init, cfg, APP_ID, APP_VERSION, hasSession, online, login, logout, logoutLocal, call, cached, pdf, upload, deviceId: () => deviceId, passkeyCapable, passkeyLogin, passkeyRegister,
    get me() { return me; }, set me(v) { me = v; }, set onAuthLost(fn) { onAuthLost = fn; } };
})();

/* ---------------- Mock portal: same shapes as PortalCustomer (demo / tests). */
window.Mock = (function () {
  let data = null, user = null, pdfCache = {};
  function load() { try { const s = sessionStorage.getItem("gcu.mockdb"); if (s) { data = JSON.parse(s); user = data._user || null; return; } } catch (e) {} data = JSON.parse(JSON.stringify(window.MOCK_DATA)); }
  const persist = () => { data._user = user; try { sessionStorage.setItem("gcu.mockdb", JSON.stringify(data)); } catch (e) {} };
  const delay = (ms = 120) => new Promise(r => setTimeout(r, ms));
  const err = (status, message, extra) => Object.assign(new Error(message), { status, data: { error: message, ...(extra || {}) }, code: status === 401 && extra && extra.totp ? "totp" : "" });
  const now = () => new Date().toISOString().slice(0, 19).replace("T", " ");
  const nid = () => Math.floor(Math.random() * 1e6) + 1000;
  const stepUp = (b) => { if (data.user.totp_enabled && (b.totp || "") !== "123456") throw err(401, "Authenticator code required", { totp: true }); };
  function meShape() { return { user: data.user, vessels: data.vessels, devices: data.devices, branch: data.branch, languages: ["en", "el", "it", "bg", "uk"], min_app_version: "1.0.2", features: data.features || { tickets: true, offers: true, approvals: true, reports: true, offer_2fa: true } }; }
  function home() {
    const items = [];
    data.reports.filter(r => r.status === "sent_for_signature").forEach(r => items.push({ type: "sign", id: r.id, ref: r.doc_no + " " + r.revision, title: r.title, vessel: r.vessel, at: r.issued_at }));
    data.approvals.filter(a => a.status === "sent_for_approval").forEach(a => items.push({ type: "approve", id: a.id, ref: a.doc_no + " " + a.revision, title: a.title, vessel: a.vessel, at: a.issued_at }));
    data.offers.filter(o => o.status === "issued").forEach(o => items.push({ type: "offer", id: o.id, ref: o.offer_no + " " + o.revision, title: o.title, vessel: o.vessel, at: o.issued_at }));
    data.tickets.filter(t => t.status === "waiting_customer").forEach(t => items.push({ type: "ticket", id: t.id, ref: t.ticket_no, title: t.subject, vessel: t.vessel, at: t.updated_at }));
    items.sort((a, b) => String(b.at).localeCompare(String(a.at)));
    return { counters: { sign: data.reports.filter(r => r.status === "sent_for_signature").length, approve: data.approvals.filter(a => a.status === "sent_for_approval").length, offers: data.offers.filter(o => o.status === "issued").length, tickets: data.tickets.filter(t => !["closed", "resolved"].includes(t.status)).length, tickets_waiting: data.tickets.filter(t => t.status === "waiting_customer").length }, items };
  }
  const ticketOut = (t) => ({ ...t, can_close: t.status === "resolved", can_reopen: ["resolved", "closed"].includes(t.status), can_reply: ["open", "in_progress", "waiting_customer", "resolved"].includes(t.status) });
  async function handle(method, path, body) {
    await delay(); if (!data) load();
    const [p, qs] = path.split("?"); const params = new URLSearchParams(qs || ""); let x; const m = (re) => p.match(re);
    if (method === "POST" && p === "/auth/login") { if ((body.email || "").toLowerCase() !== data.user.email || body.password !== data.password) throw err(401, "Invalid credentials"); user = data.user.email; persist(); return { access_token: "mock", refresh_token: "mock", user: meShape() }; }
    if (method === "POST" && p === "/auth/refresh") return { access_token: "mock", refresh_token: "mock" };
    if (method === "POST" && p === "/auth/logout") { user = null; persist(); return null; }
    if (!user) throw err(401, "Unauthorized");
    if (method === "GET" && p === "/me") return meShape();
    if (method === "POST" && p === "/cu/locale") { data.user.locale = body.locale; persist(); return null; }
    if (method === "GET" && p === "/cu/devices") return { items: data.devices };
    if (method === "POST" && (x = m(/^\/cu\/devices\/(\d+)\/revoke$/))) { data.devices = data.devices.filter(d => d.id !== +x[1]); persist(); return null; }
    if (method === "POST" && p === "/devices/push-token") return null;
    if (method === "GET" && p === "/cu/home") return home();
    if (method === "GET" && p === "/cu/reports") { const st = params.get("status"); return { items: data.reports.filter(r => !st || st.split(",").includes(r.status)) }; }
    if (method === "GET" && (x = m(/^\/cu\/reports\/(\d+)$/))) { const r = data.reports.find(r => r.id === +x[1]); if (!r) throw err(404, "Report not found"); return r; }
    if (method === "POST" && (x = m(/^\/cu\/reports\/(\d+)\/(accept|dispute)$/))) {
      const r = data.reports.find(r => r.id === +x[1]); if (r.status !== "sent_for_signature") throw err(409, "Report not pending"); stepUp(body);
      if (x[2] === "accept") { if (!body.consent || !body.signature_png) throw err(422, "Signature required"); r.status = "signed"; r.signed_at = now(); r.remark = body.remark || ""; r.signature = { name: data.user.name, signed_at_utc: r.signed_at, ip: "127.0.0.1", report: r.doc_no }; }
      else { if (!body.remark) throw err(422, "Remark required"); r.status = "returned_to_gas"; r.remark = body.remark; }
      persist(); return r;
    }
    if (method === "GET" && p === "/cu/approvals") return { items: data.approvals };
    if (method === "GET" && (x = m(/^\/cu\/approvals\/(\d+)$/))) return data.approvals.find(a => a.id === +x[1]) || (() => { throw err(404, "Not found"); })();
    if (method === "POST" && (x = m(/^\/cu\/approvals\/(\d+)\/decide$/))) {
      const a = data.approvals.find(a => a.id === +x[1]); if (a.status !== "sent_for_approval") throw err(409, "Already decided"); stepUp(body);
      const code = (body.code || "").toUpperCase(); if (!["A", "B", "D"].includes(code)) throw err(422, "Decision must be A, B or D"); if (code !== "A" && !(body.comments || "").trim()) throw err(409, "A note is required");
      a.status = code === "D" ? "rejected" : "approved"; a.portal_status = { A: "approved", B: "approved_comments", D: "rejected" }[code]; a.code = code; a.comments = body.comments || ""; a.decided_at = now(); a.decided_by = data.user.name; (a.thread = a.thread || []).push({ id: nid(), kind: a.portal_status, who: data.user.name, side: "customer", at: now(), body: a.comments }); persist(); return a;
    }
    if (method === "POST" && (x = m(/^\/cu\/approvals\/(\d+)\/comment$/))) { const a = data.approvals.find(a => a.id === +x[1]); (a.thread = a.thread || []).push({ id: nid(), kind: "comment", who: data.user.name, side: "customer", at: now(), body: body.body }); persist(); return a; }
    if (method === "GET" && p === "/cu/offers") return { items: data.offers };
    if (method === "GET" && (x = m(/^\/cu\/offers\/(\d+)$/))) return data.offers.find(o => o.id === +x[1]);
    if (method === "POST" && (x = m(/^\/cu\/offers\/(\d+)\/decide$/))) {
      const o = data.offers.find(o => o.id === +x[1]); if (!["issued", "expired"].includes(o.status)) throw err(409, "Not pending"); stepUp(body);
      if (body.decision === "approved") { if (o.status === "expired") throw err(409, "This offer has expired — please ask Golden A/S for a new revision."); if (!body.consent || !body.signature_png) throw err(422, "Signature required"); o.status = "accepted"; o.portal_status = "approved"; }
      else { if (!(body.note || "").trim()) throw err(409, "A note is required when rejecting"); o.status = "declined"; o.portal_status = "rejected"; }
      o.comments = body.note || ""; o.decided_at = now(); o.decided_by = data.user.name; (o.events = o.events || []).push({ event: o.portal_status, who: data.user.name, at: now(), note: o.comments }); persist(); return o;
    }
    if (method === "GET" && p === "/cu/tickets") return { items: data.tickets.map(t => ({ ...t, messages: undefined })) };
    if (method === "GET" && (x = m(/^\/cu\/tickets\/(\d+)$/))) { const t = data.tickets.find(t => t.id === +x[1]); if (!t) throw err(404, "Ticket not found"); return ticketOut(t); }
    if (method === "POST" && p === "/cu/tickets") { const v = data.vessels.find(v => v.id === +fd(body, "vessel_id")); if (!v) throw err(422, "Vessel not found"); const t = { id: nid(), ticket_no: "TK-2026-" + String(100 + data.tickets.length).padStart(4, "0"), subject: fd(body, "subject"), vessel: v.name, status: "open", priority: fd(body, "priority") || "normal", category: fd(body, "category") || "fault", created_at: now(), updated_at: now(), n_comments: 0, messages: [{ id: 0, from: "customer", who: data.user.name, at: now(), body: fd(body, "body"), attachments: atts(body) }] }; data.tickets.unshift(t); persist(); return ticketOut(t); }
    if (method === "POST" && (x = m(/^\/cu\/tickets\/(\d+)\/comment$/))) { const t = data.tickets.find(t => t.id === +x[1]); if (!ticketOut(t).can_reply) throw err(409, "Ticket is closed — reopen it first."); t.messages.push({ id: nid(), from: "customer", who: data.user.name, at: now(), body: fd(body, "body") || "📎", attachments: atts(body) }); if (t.status === "waiting_customer") t.status = "in_progress"; t.updated_at = now(); persist(); return ticketOut(t); }
    if (method === "POST" && (x = m(/^\/cu\/tickets\/(\d+)\/status$/))) { const t = data.tickets.find(t => t.id === +x[1]); const s = body.status === "closed" ? "closed" : "open"; if (!((s === "closed" && t.status === "resolved") || (s === "open" && ["resolved", "closed"].includes(t.status)))) throw err(409, "Not allowed"); t.status = s; t.updated_at = now(); persist(); return ticketOut(t); }
    if (method === "GET" && p === "/cu/vessels") return { items: data.vessels };
    if (method === "GET" && p === "/cu/projects") return { items: data.projects };
    if (method === "GET" && p === "/cu/documents") return { items: data.documents };
    throw err(404, "Unknown " + method + " " + p);
  }
  function fd(b, k) { return b instanceof FormData ? b.get(k) : b[k]; }
  function atts(b) { if (!(b instanceof FormData)) return []; return b.getAll("files[]").map(f => ({ id: nid(), name: f.name, url: URL.createObjectURL(f), type: f.type })); }
  async function pdf(path) { if (pdfCache[path]) return pdfCache[path]; const blob = makePdf(path.replace(/^\/cu\//, "").toUpperCase()); pdfCache[path] = blob; return blob; }
  function makePdf(title) {
    const pages = [["Golden A/S - DEMO DOCUMENT", title, "Page 1 of 2", "This is a sample document generated for the demonstration of the", "Golden A/S customer app. In production the real PDF is served by", "the portal from the project files."], ["Golden A/S - DEMO DOCUMENT", title, "Page 2 of 2", "End of document. Scroll here to enable the Sign button."]];
    const objs = []; const add = (s) => { objs.push(s); return objs.length; }; const font = add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"); const pageIds = [];
    const contents = pages.map(lines => { let y = 760; const ops = lines.map((l, i) => { const o = `BT /F1 ${i < 2 ? 16 : 11} Tf 60 ${y} Td (${l.replace(/[()\\]/g, "")}) Tj ET`; y -= i < 2 ? 28 : 18; return o; }).join("\n"); return add(`<< /Length ${ops.length} >>\nstream\n${ops}\nendstream`); });
    const pagesIdx = objs.length + pages.length + 1; contents.forEach(c => pageIds.push(add(`<< /Type /Page /Parent ${pagesIdx} 0 R /MediaBox [0 0 595 842] /Contents ${c} 0 R /Resources << /Font << /F1 ${font} 0 R >> >> >>`)));
    const pagesId = add(`<< /Type /Pages /Kids [${pageIds.map(i => i + " 0 R").join(" ")}] /Count ${pageIds.length} >>`); const cat = add(`<< /Type /Catalog /Pages ${pagesId} 0 R >>`);
    let out = "%PDF-1.4\n"; const offs = []; objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; }); const xref = out.length;
    out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map(o => String(o).padStart(10, "0") + " 00000 n \n").join("") + `trailer\n<< /Size ${objs.length + 1} /Root ${cat} 0 R >>\nstartxref\n${xref}\n%%EOF`;
    return new Blob([out], { type: "application/pdf" });
  }
  return { load, handle, pdf, signedIn: () => { if (!data) load(); return !!user; }, signOut: () => { user = null; persist(); }, reset: () => { try { sessionStorage.removeItem("gcu.mockdb"); } catch (e) {} data = null; user = null; } };
})();
