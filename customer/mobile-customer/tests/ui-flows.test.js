// End-to-end UI flows of the customer app (Playwright, Chromium).
//   node ui-flows.test.js mock   → in-page mock: serve the portal tree, open mobile-customer/index.html?mock=1
//   node ui-flows.test.js api    → against the sandbox portal (php -S 127.0.0.1:8079 router.php) with install/seed_demo.sql + the customer seed
// Env: BASE_URL (default http://127.0.0.1:8079/customer/mobile-customer/index.html), PW_CHROMIUM (chromium executable).
const { chromium } = require("@playwright/test"); const assert = require("node:assert/strict");
const target = process.argv[2] || "mock";
const base = (process.env.BASE_URL || "http://127.0.0.1:8079/customer/mobile-customer/index.html") + (target === "mock" ? "?mock=1" : "");
const crypto = require("node:crypto");
const b32 = (s) => { const A = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"; let bits = "", out = []; for (const c of s.replace(/=+$/, "").toUpperCase()) bits += A.indexOf(c).toString(2).padStart(5, "0"); for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2)); return Buffer.from(out); };
const totpCode = (secret) => { const t = Math.floor(Date.now() / 30000); const msg = Buffer.alloc(8); msg.writeUInt32BE(t, 4); const h = crypto.createHmac("sha1", b32(secret)).update(msg).digest(); const o = h[19] & 15; const v = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]; return String(v % 1e6).padStart(6, "0"); };
const SECRET = process.env.TEST_TOTP_SECRET || "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";
const code = () => target === "mock" ? "123456" : totpCode(SECRET); // both accounts have 2FA on (D-8)
(async () => {
  const b = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined });
  const ctx = await b.newContext({ viewport: { width: 390, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const p = await ctx.newPage(); const errors = [];
  p.on("pageerror", e => errors.push(String(e))); p.on("console", m => { if (m.type() === "error" && !/favicon|404|status of 401/.test(m.text())) errors.push(m.text()); }); p.on("dialog", d => d.accept());   // a 401 at sign-in = authenticator code requested (expected)
  const T = []; const test = (n, f) => T.push([n, f]); const wait = (ms) => p.waitForTimeout(ms); const toast = async () => (await p.textContent("#toast")) || "";
  async function stepUp() { await p.waitForSelector(".otp input", { timeout: 8000 }); const inputs = await p.$$(".otp input"); const c = code(); for (let i = 0; i < 6; i++) await inputs[i].type(c[i]); await wait(300); if (await p.$("#otp-ok")) await p.click("#otp-ok"); }
  async function draw(sel) { const c = await p.$(sel); await c.scrollIntoViewIfNeeded(); await wait(200); const box = await c.boundingBox(); const y0 = box.y + 70; await p.mouse.move(box.x + 20, y0); await p.mouse.down(); for (let i = 1; i <= 10; i++) await p.mouse.move(box.x + 20 + i * 25, y0 - 20 + (i % 2) * 40); await p.mouse.up(); await wait(200); }
  test("login (+ skip biometrics) → home counters", async () => {
    await p.goto(base); await wait(500); if (target === "mock") { await p.evaluate(() => { sessionStorage.clear(); localStorage.removeItem("gcu.bio_asked"); }); await p.reload(); await wait(500); }
    await p.fill("input[name=email]", "captain@demo-fleet.com"); await p.fill("input[name=password]", "GoldenDemo2026!"); await p.click("#btn-login"); await wait(1200); if (await p.$("#totp-field:not([hidden])")) { await p.fill("input[name=totp]", code()); await p.click("#btn-login"); await wait(1200); }
    if (await p.$("#bio-no")) { await p.click("#bio-no"); await wait(800); }
    await p.waitForSelector("#stats .stat"); const n = await p.$$eval("#stats .stat .n", e => e.map(x => x.textContent)); assert.equal(n[0], "2"); assert.equal(n[1], "2"); assert.equal(n[2], target === "mock" ? "1" : "2");
  });
  test("accept a service report: last page + signature + statement; step-up; acceptance shown", async () => {
    await p.goto(base + "#/sign"); await wait(800); const first = await p.$("#list a.card"); await first.click(); await p.waitForSelector("#btn-sign", { timeout: 10000 }); await wait(2500);
    assert.equal(await p.isDisabled("#btn-sign"), true); await p.$eval("#pdf", e => { e.scrollTop = e.scrollHeight; }); await wait(400);
    await draw("#pad"); await p.check("#s-consent"); await wait(200); assert.equal(await p.isDisabled("#btn-sign"), false);
    await p.fill("#s-remark", "Thank you."); await p.click("#btn-sign"); await stepUp(); await wait(2500);
    assert.match(await p.textContent("#rep"), /Demo Captain/); assert.equal(await p.$("#btn-sign"), null);
  });
  test("owner approval: B requires a note; decision recorded with code", async () => {
    await p.goto(base + "#/approvals"); await wait(800); await (await p.$("#list a.card")).click(); await p.waitForSelector("[data-code=B]"); await wait(1500);
    await p.click("[data-code=B]"); assert.equal(await p.isDisabled("#btn-dec"), true); await p.fill("#a-comments", "Add the 400 V label on the connector plate."); assert.equal(await p.isDisabled("#btn-dec"), false);
    await p.click("#btn-dec"); await stepUp(); await wait(2500); assert.match(await p.textContent("#ap"), /B — Approved with comments/); assert.equal(await p.$("#btn-dec"), null);
  });
  test("offers: expired cannot be accepted; reject needs a note; accept & sign on the open offer", async () => {
    await p.goto(base + "#/offers"); await wait(800); const cards = await p.$$("#list a.card"); assert.equal(cards.length, target === "mock" ? 2 : 3);
    const exp = (await Promise.all(cards.map(async c => [(await c.textContent()).includes("064"), c]))).find(x => x[0])[1]; await exp.click(); await p.waitForSelector("#o-dec"); await wait(800); assert.equal(await p.$("#o-accept"), null, "no accept on expired");
    await p.click("#o-dec"); await wait(300); assert.match(await toast(), /note/i);
    await p.goBack(); await wait(800); const open = (await Promise.all((await p.$$("#list a.card")).map(async c => [(await c.textContent()).includes("071"), c]))).find(x => x[0])[1]; await open.click(); await p.waitForSelector("#o-accept"); await wait(1500);
    await p.click("#o-accept"); await p.waitForSelector("#pad2 canvas"); await draw("#pad2"); await p.check("#s2-consent"); await p.click("#s2-ok"); await stepUp(); await wait(2500);
    assert.match(await p.textContent("#of"), /Accepted/); assert.equal(await p.$("#o-accept"), null);
  });
  test("tickets: reply re-opens a waiting ticket; resolved → close → reopen; new ticket", async () => {
    await p.goto(base + "#/tickets"); await wait(800); const t = (await Promise.all((await p.$$("#list a.card")).map(async c => [(await c.textContent()).includes("0088"), c]))).find(x => x[0])[1]; await t.click(); await p.waitForSelector("#tk-send");
    assert.ok(!(await p.textContent("#msgs")).includes("INTERNAL")); await p.fill("#tk-body", "Alongside Genoa from Thursday."); await p.click("#tk-send"); await wait(1500);
    assert.match(await p.textContent("#msgs"), /Alongside Genoa from Thursday/); assert.match(await p.textContent("#tk"), /In progress/);
    await p.goto(base + "#/tickets"); await wait(800); const r = (await Promise.all((await p.$$("#list a.card")).map(async c => [(await c.textContent()).includes("0081"), c]))).find(x => x[0])[1]; await r.click(); await p.waitForSelector("#tk-close"); await p.click("#tk-close"); await wait(1200); assert.ok(await p.$("#tk-reopen")); assert.equal(await p.$("#tk-send"), null); await p.click("#tk-reopen"); await wait(1200); assert.ok(await p.$("#tk-send"));
    await p.goto(base + "#/tickets/new"); await p.waitForSelector("#f-tk"); await p.fill("input[name=subject]", "Galley UPS beeping"); await p.fill("textarea[name=body]", "Beeps every 30 s since this morning."); await p.click("#tk-create"); await wait(1500);
    assert.match(await p.evaluate(() => location.hash), /#\/tickets\/\d+/); assert.match(await p.textContent("#tk"), /Galley UPS beeping/);
  });
  test("home counters updated; projects show the 4-stage line; account lists devices", async () => {
    await p.goto(base + "#/home"); await wait(1200); const n = await p.$$eval("#stats .stat .n", e => e.map(x => x.textContent)); assert.equal(n[1], "1"); assert.equal(n[2], target === "mock" ? "0" : "1");
    await p.goto(base + "#/projects"); await wait(1000); assert.ok((await p.$$(".stages")).length >= 1);
    await p.goto(base + "#/account"); await wait(1000); assert.match(await p.textContent("#l"), /Demo Captain/); assert.ok((await p.$$(".list-item")).length >= 1);
  });
  test("language switch to Greek re-renders the tabs; sign out", async () => { await p.click("[data-lang=el]"); await wait(900); assert.equal(await p.textContent('#tabbar a[data-tab=home] span:last-child'), "Αρχική"); await p.click("[data-lang=en]"); await wait(600); await p.click("#signout"); await wait(800); assert.ok(await p.$("#f-login")); });
  let fail = 0; for (const [n, f] of T) { try { await f(); console.log("✓", n); } catch (e) { fail++; console.log("✗", n, "\n   ", String(e.message).split("\n")[0]); await p.screenshot({ path: `${__dirname}/fail-${T.indexOf(T.find(t => t[0] === n))}.png` }).catch(() => {}); } }
  if (errors.length) { console.log("console errors:", errors); fail++; }
  console.log(fail ? `${fail} FAILED (${target})` : `all ${T.length} passed (${target})`); await b.close(); process.exit(fail ? 1 : 0);
})();
