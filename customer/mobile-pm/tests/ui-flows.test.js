/* Golden PM — UI flows (Playwright, chromium headless at /opt/pw-browsers/chromium) against a tiny node:http static server of mobile-pm/ in mock mode.
 * Run: cd tests && npm test     (env SHOTS=/dir writes the 6 screenshots at 390×844; CHROMIUM overrides the executable) */
const http = require("node:http"), fs = require("node:fs"), path = require("node:path");
let pw; try { pw = require("playwright-core"); } catch (e) { pw = require("/opt/npm-tools/node_modules/playwright-core"); }
const ROOT = path.resolve(__dirname, ".."); const MIME = { ".html": "text/html", ".js": "text/javascript", ".css": "text/css", ".png": "image/png", ".webmanifest": "application/manifest+json", ".json": "application/json" };
const EXE = process.env.CHROMIUM || "/opt/pw-browsers/chromium"; const SHOTS = process.env.SHOTS || "";
let passed = 0, failed = 0; const results = [];
const step = async (name, fn) => { try { await fn(); passed++; results.push(`PASS  ${name}`); console.log(`PASS  ${name}`); } catch (e) { failed++; results.push(`FAIL  ${name} — ${e.message}`); console.log(`FAIL  ${name}\n      ${e.message}`); throw e; } };
const shot = (page, name) => SHOTS ? page.screenshot({ path: path.join(SHOTS, name + ".png") }) : Promise.resolve();

(async () => {
  const server = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split("?")[0]); if (p === "/") p = "/index.html"; const f = path.join(ROOT, p); if (!f.startsWith(ROOT) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { "Content-Type": MIME[path.extname(f)] || "application/octet-stream", "Cache-Control": "no-store" }); fs.createReadStream(f).pipe(res); });
  await new Promise((r) => server.listen(0, "127.0.0.1", r)); const base = `http://127.0.0.1:${server.address().port}/index.html`;
  const browser = await pw.chromium.launch({ executablePath: EXE, headless: true, args: ["--no-sandbox"] });
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: "en-GB" });
  const page = await context.newPage(); const errors = []; page.on("pageerror", (e) => errors.push(e.message)); page.on("console", (m) => { if (m.type() === "error") errors.push(m.text()); });
  try {
    await step("login screen renders in mock mode", async () => { await page.goto(`${base}?mock=1#/login`); await page.waitForSelector("#go"); if (!(await page.inputValue("#email")).includes("@")) throw new Error("no demo e-mail"); await shot(page, "01-login"); });
    await step("sign in → home renders KPI tiles", async () => { await page.click("#go"); await page.waitForSelector(".kpi div", { timeout: 15000 }); const n = await page.locator(".kpi div").count(); if (n !== 4) throw new Error(`${n} tiles`); const greet = await page.textContent(".greet"); if (!/Nikos/.test(greet)) throw new Error("no greeting"); const bt = await page.textContent("#b-team"); if (bt !== "4") throw new Error(`team badge ${bt}`); await shot(page, "02-home"); });
    await step("projects list shows 3 projects with overall pill", async () => { await page.click('#tabs a[data-tab="projects"]'); await page.waitForSelector(".prow"); const n = await page.locator(".prow").count(); if (n !== 3) throw new Error(`${n} rows`); if (!(await page.locator(".prow .opill").count())) throw new Error("no pill"); });
    await step("project tree shows 7-segment process lines", async () => { await page.click('.prow:has-text("PRJ.000154")'); await page.waitForSelector(".tree .stepper"); const segs = await page.locator(".tree .stepper").first().locator(".stp").count(); if (segs !== 7) throw new Error(`${segs} segments`); if (!(await page.locator(".tree .stp--na").count())) throw new Error("no hatched N/A"); if (!(await page.locator(".tree .stp--fed").count())) throw new Error("no fed marker"); if (!(await page.locator(".tree .lvl2").count())) throw new Error("no child rows"); await shot(page, "03-project-tree"); });
    await step("sub-project Y.03: change installation stage to 50 → queued → synced 50 % and overall updated", async () => {
      await page.click('.tree .prow:has-text("Y.03")'); await page.waitForSelector(".stepper:not(.stepper--compact)"); const before = await page.textContent(".card .opill span");
      await page.click('.stepper:not(.stepper--compact) .stp[data-stage="installation"]'); await page.waitForSelector("#sheet .pct-opts"); await page.click('#sheet .pct-opts button[data-v="50"]'); await page.click("#sheet #ok");
      await page.waitForFunction(() => { const el = document.querySelector('.stepper:not(.stepper--compact) .stp[data-stage="installation"] em'); return el && el.textContent === "50%"; }, null, { timeout: 5000 });
      await page.waitForFunction(() => !document.querySelector(".stp--queued"), null, { timeout: 8000 });
      const after = await page.textContent(".card .opill span"); if (after === before) throw new Error(`overall unchanged ${before}`); if (after !== "25%") throw new Error(`overall ${after} (expected 25%: 75,25,0,N/A,50,0,0)`);
      const fedTap = await page.locator('.stepper:not(.stepper--compact) .stp[data-stage="fat"].tap').count(); if (fedTap !== 1) throw new Error("N/A segment should still be tappable"); await shot(page, "04-subproject"); });
    await step("sub-project Y.01: fed production segment is not tappable", async () => { await page.goto(`${base}#/sub/1541`); await page.waitForSelector(".stepper:not(.stepper--compact)"); const n = await page.locator('.stepper:not(.stepper--compact) .stp[data-stage="production"].tap').count(); if (n) throw new Error("fed segment tappable"); if (!(await page.locator('.stp--fed').count())) throw new Error("no fed marker"); });
    await step("team → approve request without code shows 'code required'; with 123456 the row disappears", async () => {
      await page.click('#tabs a[data-tab="team"]'); await page.waitForSelector("[data-req]"); const n0 = await page.locator("[data-req]").count(); if (n0 !== 3) throw new Error(`${n0} requests`); await shot(page, "05-team-requests");
      await page.click('[data-req="42"] [data-dec][data-ok="1"]'); await page.waitForSelector("#sheet #totp"); await page.fill("#sheet #note", "Enjoy"); await page.click("#sheet #ok");
      const err = await page.textContent("#sheet #err"); if (!/code required/i.test(err)) throw new Error(`err: ${err}`);
      await page.fill("#sheet #totp", "123456"); await page.click("#sheet #ok");
      await page.waitForFunction(() => !document.querySelector('[data-req="42"]'), null, { timeout: 8000 }); const bt = await page.textContent("#b-team"); if (bt !== "3") throw new Error(`team badge ${bt}`); });
    await step("team → timesheet reject without note is refused; approve with code removes the sheet", async () => {
      await page.click('.seg [data-t="timesheets"]'); await page.waitForSelector("[data-ts]"); await page.click('[data-ts="311"] [data-tsd][data-ok="0"]'); await page.waitForSelector("#sheet #ok"); await page.click("#sheet #ok"); const err = await page.textContent("#sheet #err"); if (!/note is required/i.test(err)) throw new Error(`err: ${err}`); await page.click("#sheet #cancel");
      await page.click('[data-ts="311"] [data-tsd][data-ok="1"]'); await page.waitForSelector("#sheet #totp"); await page.fill("#sheet #totp", "654321"); await page.click("#sheet #ok"); await page.waitForFunction(() => !document.querySelector('[data-ts="311"]'), null, { timeout: 8000 }); });
    await step("team → away matrix renders 29 day columns", async () => { await page.click('.seg [data-t="away"]'); await page.waitForSelector(".matrix table"); const th = await page.locator(".matrix tr").first().locator("th").count(); if (th !== 30) throw new Error(`${th} header cells`); if (!(await page.locator(".matrix td.approved").count())) throw new Error("no approved cell"); });
    await step("approvals → mark a drawing received → row moves to received", async () => {
      await page.click('#tabs a[data-tab="approvals"]'); await page.waitForSelector("[data-dr]"); await shot(page, "06-approvals"); await page.click('[data-f="overdue"]'); await page.waitForSelector('[data-dr="901"]');
      await page.click('[data-dr="901"]'); await page.waitForSelector("#sheet #recv"); await page.fill("#sheet #rem", "Received by e-mail"); await page.click("#sheet #recv");
      await page.waitForFunction(() => !document.querySelector('[data-dr="901"]'), null, { timeout: 5000 });   // overdue filter no longer lists it
      await page.click('[data-f="received"]'); await page.waitForSelector('[data-dr="901"]'); const chip = await page.textContent('[data-dr="901"] .chip'); if (!/received/i.test(chip)) throw new Error(`chip ${chip}`);
      await page.waitForFunction(() => { const r = document.querySelector('[data-dr="901"]'); return r && !r.classList.contains("c-amber"); }, null, { timeout: 8000 }); });
    await step("submittals tab: owner and class sections, read-only note", async () => { await page.click('.seg [data-tab="submittals"]'); await page.waitForSelector(".note"); const h = await page.locator("h2").allTextContents(); if (!h.some((x) => /Owner/.test(x)) || !h.some((x) => /Class/.test(x))) throw new Error(h.join("|")); });
    await step("offline → new comment shows queued 1 in the sync bar → online → queue 0", async () => {
      await page.goto(`${base}#/sub/1543`); await page.waitForSelector("#csend"); await context.setOffline(true); await page.evaluate(() => window.dispatchEvent(new Event("offline")));
      await page.fill("#ctx", "Offline comment from the test"); await page.click("#csend");
      await page.waitForFunction(() => /1 queued/.test(document.querySelector("#synctext").textContent), null, { timeout: 5000 });
      if (!(await page.locator(".cmt:has-text('Offline comment')").count())) throw new Error("comment not listed");
      await context.setOffline(false); await page.evaluate(() => window.dispatchEvent(new Event("online")));
      await page.waitForFunction(() => !/queued/.test(document.querySelector("#synctext").textContent), null, { timeout: 10000 }); });
    await step("language switch to EL changes the tab labels", async () => { await page.click('#tabs a[data-tab="more"]'); await page.waitForSelector('.lang button[data-l="el"]'); await page.click('.lang button[data-l="el"]'); await page.waitForFunction(() => document.querySelector('#tabs a[data-tab="projects"] span').textContent === "Έργα", null, { timeout: 5000 }); await page.click('.lang button[data-l="en"]'); await page.waitForFunction(() => document.querySelector('#tabs a[data-tab="projects"] span').textContent === "Projects", null, { timeout: 5000 }); });
    await step("My HR page renders balances", async () => { await page.goto(`${base}#/hr`); await page.waitForSelector("table.week"); if (!(await page.locator("td:has-text('ANN')").count())) throw new Error("no ANN"); });
    await step("no page errors", async () => { const real = errors.filter((e) => !/favicon|ServiceWorker|sw\.js/i.test(e)); if (real.length) throw new Error(real.join(" | ")); });
  } catch (e) { /* reported above */ }
  await browser.close(); server.close();
  console.log(`\n${passed} passed, ${failed} failed`); process.exit(failed ? 1 : 0);
})();
