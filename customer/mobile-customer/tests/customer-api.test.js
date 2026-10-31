// Contract tests of the customer app routes against a portal sandbox (schema + all migrations + install/seed_demo.sql + the customer seed).
// Run:  API_BASE=http://127.0.0.1:8079/customer/api/v1 node customer-api.test.js
const assert = require("node:assert/strict"); const fs = require("node:fs"); const path = require("node:path");
const crypto = require("node:crypto");
// RFC 6238 TOTP (SHA1, 6 digits, 30 s) with the portal's base32 secret — the sandbox seed enables 2FA for the demo customer with TEST_TOTP_SECRET
const b32 = (s) => { const A = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"; let bits = "", out = []; for (const c of s.replace(/=+$/, "").toUpperCase()) bits += A.indexOf(c).toString(2).padStart(5, "0"); for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2)); return Buffer.from(out); };
const totpCode = (secret) => { const t = Math.floor(Date.now() / 30000); const msg = Buffer.alloc(8); msg.writeUInt32BE(t, 4); const h = crypto.createHmac("sha1", b32(secret)).update(msg).digest(); const o = h[19] & 15; const v = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]; return String(v % 1e6).padStart(6, "0"); };
const SECRET = process.env.TEST_TOTP_SECRET || "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";
const B = process.env.API_BASE || "http://127.0.0.1:8079/customer/api/v1";
const H = { "Content-Type": "application/json", "X-App": "customer", "X-App-Version": "1.0.0", "X-Lang": "en" };
let at = "", rt = "";
async function api(method, p, body, o = {}) { const h = { ...H, ...(o.headers || {}) }; if (at && !o.noAuth) h.Authorization = "Bearer " + at; const r = await fetch(B + p, { method, headers: h, body: body ? JSON.stringify(body) : undefined }); const ct = r.headers.get("content-type") || ""; const buf = Buffer.from(await r.arrayBuffer()); return { status: r.status, data: ct.includes("json") && buf.length ? JSON.parse(buf.toString()) : buf }; }
const png = "data:image/png;base64," + fs.readFileSync(path.join(__dirname, "sig.png")).toString("base64");
const T = []; const test = (n, f) => T.push([n, f]);
test("staff account refused on the customer app", async () => { const r = await api("POST", "/auth/login", { email: "admin@goldenas.com.gr", password: "GoldenDemo2026!", device: { platform: "web", device_id: "t-x" } }, { noAuth: true }); assert.equal(r.status, 403); });
test("customer login: 2FA account needs the code (401 totp), then tokens + /me shape", async () => { let r0 = await api("POST", "/auth/login", { email: "captain@demo-fleet.com", password: "GoldenDemo2026!", device: { platform: "ios", device_id: "t-cu-1" } }, { noAuth: true }); assert.equal(r0.status, 401); assert.equal(r0.data.totp, true); const r = await api("POST", "/auth/login", { email: "captain@demo-fleet.com", password: "GoldenDemo2026!", totp: totpCode(SECRET), device: { platform: "ios", device_id: "t-cu-1", ua: "iPhone", app_version: "1.0.0" } }, { noAuth: true }); assert.equal(r.status, 200); at = r.data.access_token; rt = r.data.refresh_token; assert.equal(r.data.user.user.email, "captain@demo-fleet.com"); assert.ok(r.data.user.vessels.length >= 1); assert.ok(r.data.user.devices.some(d => d.this)); });
test("wrong X-App with a customer token → 403", async () => { const r = await api("GET", "/me", null, { headers: { "X-App": "engineer" } }); assert.equal(r.status, 403); });
test("staff routes blocked for the customer app (CS-03)", async () => { for (const p of ["/fsr", "/tickets", "/pm/projects", "/hr", "/documents"]) { const r = await api("GET", p); assert.equal(r.status, 403, p); } });
test("old app version → 426", async () => { const r = await api("GET", "/me", null, { headers: { "X-App-Version": "0.0.1" } }); assert.equal(r.status, 426); });
test("home counters and items", async () => { const r = await api("GET", "/cu/home"); assert.equal(r.status, 200); assert.equal(r.data.counters.sign, 2); assert.equal(r.data.counters.approve, 2); assert.equal(r.data.counters.offers, 2); assert.ok(r.data.items.length >= 6); });
let rid;
test("reports: list + pdf stream", async () => { const r = await api("GET", "/cu/reports?status=sent_for_signature"); assert.equal(r.data.items.length, 2); rid = r.data.items[0].id; assert.equal(r.data.items[0].status, "sent_for_signature"); const p = await api("GET", `/cu/reports/${rid}/pdf`); assert.equal(p.status, 200); assert.equal(p.data.subarray(0, 4).toString(), "%PDF"); });
test("report accept: consent + signature required; then accepted with acceptance record", async () => {
  let r = await api("POST", `/cu/reports/${rid}/accept`, { remark: "" }); assert.equal(r.status, 401); assert.equal(r.data.totp, true); r = await api("POST", `/cu/reports/${rid}/accept`, { remark: "", totp: totpCode(SECRET) }); assert.equal(r.status, 422);
  r = await api("POST", `/cu/reports/${rid}/accept`, { consent: true, signature_png: png, remark: "Thank you.", name: "Demo Captain", totp: totpCode(SECRET) }); assert.equal(r.status, 200, JSON.stringify(r.data)); assert.equal(r.data.status, "signed"); assert.equal(r.data.signature.name, "Demo Captain"); assert.ok(r.data.signature.signed_at_utc);
  r = await api("POST", `/cu/reports/${rid}/accept`, { consent: true, signature_png: png, totp: totpCode(SECRET) }); assert.equal(r.status, 409);
});
test("approvals: B needs comments; decision recorded via oa_decide; file streams", async () => {
  let r = await api("GET", "/cu/approvals"); const a = r.data.items.find(x => x.doc_no === "GA-2026-033"); assert.equal(a.status, "sent_for_approval");
  r = await api("GET", `/cu/approvals/${a.id}/file`); assert.equal(r.status, 200);
  r = await api("POST", `/cu/approvals/${a.id}/decide`, { code: "B", comments: "", totp: totpCode(SECRET) }); assert.equal(r.status, 409);
  r = await api("POST", `/cu/approvals/${a.id}/decide`, { code: "B", comments: "Add the 400 V label.", totp: totpCode(SECRET) }); assert.equal(r.status, 200, JSON.stringify(r.data)); assert.equal(r.data.status, "approved"); assert.equal(r.data.code, "B"); assert.equal(r.data.portal_status, "approved_comments"); assert.ok(r.data.thread.some(t => t.kind === "approved_comments"));
  r = await api("POST", `/cu/approvals/${a.id}/decide`, { code: "A", totp: totpCode(SECRET) }); assert.equal(r.status, 409);
  const b = (await api("GET", "/cu/approvals")).data.items.find(x => x.doc_no === "GAS-2026-ALPHA-AMS-002"); r = await api("POST", `/cu/approvals/${b.id}/decide`, { code: "D", comments: "Not accepted — alarm priorities missing.", totp: totpCode(SECRET) }); assert.equal(r.data.code, "D"); assert.equal(r.data.status, "rejected");
});
test("offers: expired cannot be accepted; reject needs note; accept with signature → approved", async () => {
  let r = await api("GET", "/cu/offers"); const exp = r.data.items.find(o => o.offer_no === "GAS-OF-2026-064"), open = r.data.items.find(o => o.offer_no === "GAS-OF-2026-071"); assert.equal(exp.status, "expired"); assert.equal(open.status, "issued"); assert.equal(open.total_cents, 2181000); assert.equal(open.cost, undefined);
  r = await api("POST", `/cu/offers/${exp.id}/decide`, { decision: "approved", consent: true, signature_png: png, totp: totpCode(SECRET) }); assert.equal(r.status, 409);
  r = await api("POST", `/cu/offers/${open.id}/decide`, { decision: "rejected", note: "", totp: totpCode(SECRET) }); assert.equal(r.status, 409);
  r = await api("GET", `/cu/offers/${open.id}/file`); assert.equal(r.status, 200);
  r = await api("POST", `/cu/offers/${open.id}/decide`, { decision: "approved", consent: true, signature_png: png, note: "", totp: "000000" }); assert.equal(r.status, 401); assert.equal(r.data.totp, true, "wrong authenticator code refused");
  r = await api("POST", `/cu/offers/${open.id}/decide`, { decision: "approved", consent: true, signature_png: png, note: "", totp: totpCode(SECRET) }); assert.equal(r.status, 200, JSON.stringify(r.data)); assert.equal(r.data.status, "accepted"); assert.equal(r.data.portal_status, "approved"); assert.ok(r.data.events.some(e => e.event === "approved"));
  r = await api("POST", `/cu/offers/${exp.id}/decide`, { decision: "rejected", note: "Prices outdated.", totp: totpCode(SECRET) }); assert.equal(r.data.status, "declined");
});
test("tickets: internal notes hidden; reply re-opens waiting_customer; close only when resolved; reopen; create", async () => {
  let r = await api("GET", "/cu/tickets"); const t = r.data.items.find(x => x.ticket_no === "TK-2026-0088"), res = r.data.items.find(x => x.ticket_no === "TK-2026-0081");
  r = await api("GET", `/cu/tickets/${t.id}`); assert.ok(!JSON.stringify(r.data).includes("INTERNAL")); assert.equal(r.data.messages.length, 2);
  r = await api("POST", `/cu/tickets/${t.id}/status`, { status: "closed" }); assert.equal(r.status, 409);
  r = await api("POST", `/cu/tickets/${t.id}/comment`, { body: "Alongside Genoa from Thursday." }); assert.equal(r.status, 200); assert.equal(r.data.status, "in_progress"); assert.equal(r.data.messages.at(-1).body, "Alongside Genoa from Thursday.");
  r = await api("POST", `/cu/tickets/${res.id}/status`, { status: "closed" }); assert.equal(r.data.status, "closed"); r = await api("POST", `/cu/tickets/${res.id}/status`, { status: "open" }); assert.equal(r.data.status, "open");
  const v = (await api("GET", "/cu/vessels")).data.items[0]; r = await api("POST", "/cu/tickets", { vessel_id: v.id, subject: "Galley UPS beeping", body: "Beeps every 30 s since this morning.", category: "fault", priority: "normal" }); assert.equal(r.status, 200, JSON.stringify(r.data)); assert.match(r.data.ticket_no, /^TK-/); assert.equal(r.data.messages[0].body, "Beeps every 30 s since this morning.");
  r = await api("POST", "/cu/tickets", { vessel_id: 999999, subject: "x", body: "y" }); assert.equal(r.status, 422);
});
test("customer without 2FA: offers refused (D-8), reports still accepted; signature embedded in the FSR PDF (D-9)", async () => {
  const keep = at; let r = await api("POST", "/auth/login", { email: "owner@demo-fleet.com", password: "GoldenDemo2026!", device: { platform: "android", device_id: "t-cu-2" } }, { noAuth: true }); assert.equal(r.status, 200); at = r.data.access_token; assert.equal(r.data.user.features.offer_2fa, true); assert.equal(r.data.user.user.totp_enabled, false);
  const o = (await api("GET", "/cu/offers")).data.items.find(x => x.offer_no === "GAS-OF-2026-072"); r = await api("POST", `/cu/offers/${o.id}/decide`, { decision: "approved", consent: true, signature_png: png }); assert.equal(r.status, 403); assert.equal(r.data.needs_2fa, true);
  const rep = (await api("GET", "/cu/reports?status=sent_for_signature")).data.items[0]; r = await api("POST", `/cu/reports/${rep.id}/accept`, { consent: true, signature_png: png, remark: "Short note." }); assert.equal(r.status, 200, JSON.stringify(r.data)); assert.equal(r.data.status, "signed");
  const pdf = (await api("GET", `/cu/reports/${rep.id}/pdf`)).data.toString("latin1"); assert.match(pdf, /\/Subtype \/Image/, "signature image embedded"); assert.match(pdf, /DCTDecode/);
  at = keep;
});
test("read-only: vessels, projects (4-stage), documents + stream (customer scope)", async () => {
  let r = await api("GET", "/cu/projects"); assert.ok(r.data.items.length >= 1); assert.ok(["engineering", "production", "fat", "sathat"].includes(r.data.items[0].subprojects[0].stage));
  r = await api("GET", "/cu/documents"); assert.ok(r.data.items.length >= 1); assert.ok(!r.data.items.some(d => d.category.startsWith("offer"))); const d = r.data.items[0]; r = await api("GET", `/cu/documents/${d.id}`); assert.equal(r.status, 200);
});
test("refresh rotates; reuse revokes; logout", async () => {
  let r = await api("POST", "/auth/refresh", { refresh_token: rt }, { noAuth: true }); assert.equal(r.status, 200); const rt2 = r.data.refresh_token; at = r.data.access_token;
  r = await api("POST", "/auth/refresh", { refresh_token: rt }, { noAuth: true }); assert.equal(r.status, 401);
  r = await api("POST", "/auth/refresh", { refresh_token: rt2 }, { noAuth: true }); assert.equal(r.status, 401);
  r = await api("POST", "/auth/login", { email: "captain@demo-fleet.com", password: "GoldenDemo2026!", totp: totpCode(SECRET), device: { platform: "ios", device_id: "t-cu-1" } }, { noAuth: true }); at = r.data.access_token;
  const devs = (await api("GET", "/cu/devices")).data.items; assert.ok(devs.length >= 1);
  r = await api("POST", "/auth/logout", {}); assert.equal(r.status, 204);
});
(async () => { let fail = 0; for (const [n, f] of T) { try { await f(); console.log("✓", n); } catch (e) { fail++; console.log("✗", n, "\n   ", String(e.message).split("\n")[0]); } } console.log(fail ? `${fail} FAILED` : `all ${T.length} passed`); process.exit(fail ? 1 : 0); })();
