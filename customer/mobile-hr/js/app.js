/* Golden HR — router and UI helpers (chrome, badges, sheet, step-up code, mandatory biometric lock with 15-minute idle). GAS-CP-R74-001
 * Lock policy (stricter than the other apps): the app is locked on every cold start and whenever it has been in the background
 * or idle for 15 minutes; it unlocks only with Face ID / Touch ID / device passcode. No "Face ID on open" switch — it is always on. */
window.UI = (() => {
  const $ = (s) => document.querySelector(s);
  const view = $("#view"); const IDLE_MS = 15 * 60 * 1000;
  let current = null, rendering = false, pending = false, lastActive = Date.now();

  function chrome(on, title, tab, back) {
    $("#hdr").classList.toggle("hidden", !on); $("#tabs").classList.toggle("hidden", !on); $("#sync").classList.toggle("hidden", !on);
    if (title) $("#title").textContent = title;
    $("#back").classList.toggle("show", !!back);
    document.querySelectorAll("#tabs a").forEach((a) => a.classList.toggle("on", a.dataset.tab === tab));
    const me = SCREENS.ctx.me || {}; $("#branch").textContent = me.home_branch || "HR"; badges();
  }
  function badges() {
    const c = ((SCREENS.ctx.me || {}).counts) || {}; const set = (id, n) => { const b = $(id); if (!b) return; b.textContent = n > 99 ? "99+" : String(n); b.classList.toggle("hidden", !n); };
    set("#b-inbox", c.inbox || 0); set("#b-msg", c.unread || 0);
  }
  function toast(msg, ms) { const tst = $("#toast"); tst.textContent = msg; tst.classList.remove("hidden"); clearTimeout(tst._t); tst._t = setTimeout(() => tst.classList.add("hidden"), ms || 2200); }
  const goLinks = (root) => root.querySelectorAll("[data-go]").forEach((el) => el.onclick = (e) => { e.preventDefault(); e.stopPropagation(); location.hash = el.dataset.go; });
  const segs = (root, onChange) => root.querySelectorAll(".seg[data-k]").forEach((seg) => seg.querySelectorAll("button").forEach((b) => b.onclick = () => { seg.querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b)); if (onChange) onChange(seg.dataset.k, b.dataset.v); }));
  function sheet(html, onOk, onOpen) {
    closeSheet(); const el = document.createElement("div"); el.className = "sheet"; el.id = "sheet"; el.innerHTML = `<div class="in">${html}<button class="btn line sm" id="cancel">${I18N.t("common.cancel")}</button></div>`;
    document.body.appendChild(el); el.onclick = (e) => { if (e.target === el) closeSheet(); }; el.querySelector("#cancel").onclick = closeSheet;
    const ok = el.querySelector("#ok"); if (ok && onOk) ok.onclick = () => onOk(el); if (onOpen) onOpen(el); return el;
  }
  const closeSheet = () => { const s = $("#sheet"); if (s) s.remove(); };
  const totpField = (me) => (me || SCREENS.ctx.me || {}).totp_enabled ? `<div class="totp"><label>${I18N.t("dec.code")}</label><input id="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" placeholder="000000"><p class="muted">${I18N.t("dec.code_hint")}</p></div>` : "";
  function totpValue(sh, me) {
    const need = (me || SCREENS.ctx.me || {}).totp_enabled; const el = sh.querySelector("#totp"); const v = el ? el.value.trim() : "";
    if (need && !/^\d{6}$/.test(v)) { const er = sh.querySelector("#err"); if (er) { er.textContent = I18N.t("dec.code_req"); er.classList.remove("hidden"); } if (el) el.focus(); return false; }
    return v || undefined;
  }
  function totpSheet(onCode, wrong) {
    sheet(`<h3>${I18N.t("dec.enter_code")}</h3>${wrong ? `<p class="note red">${I18N.t("dec.code_bad")}</p>` : ""}${totpField({ totp_enabled: true })}<p id="err" class="note red hidden"></p><button class="btn gold" id="ok">${I18N.t("common.ok")}</button>`, (sh) => { const v = totpValue(sh, { totp_enabled: true }); if (v === false) return; closeSheet(); onCode(v); }, (sh) => sh.querySelector("#totp").focus());
  }

  // ---- lock (mandatory)
  const isLocked = () => { try { return localStorage.getItem("ghr.locked") !== "0"; } catch (e) { return true; } };
  const lockNow = () => { try { localStorage.setItem("ghr.locked", "1"); } catch (e) {} };
  const unlock = () => { try { localStorage.setItem("ghr.locked", "0"); } catch (e) {} lastActive = Date.now(); };
  async function biometricAvailable() { try { if (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.BiometricAuth) return true; return !!(window.PublicKeyCredential && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()); } catch (e) { return false; } }
  async function biometric() {
    try {
      if (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.BiometricAuth) { await Capacitor.Plugins.BiometricAuth.authenticate({ reason: "Unlock Golden HR" }); return true; }
      if (window.PublicKeyCredential && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()) {
        const id = localStorage.getItem("ghr.cred");
        if (!id) { const cred = await navigator.credentials.create({ publicKey: { challenge: crypto.getRandomValues(new Uint8Array(32)), rp: { name: "Golden HR" }, user: { id: new TextEncoder().encode(API.VERSION + Date.now()), name: "hr", displayName: "HR" }, pubKeyCredParams: [{ type: "public-key", alg: -7 }, { type: "public-key", alg: -257 }], authenticatorSelection: { authenticatorAttachment: "platform", userVerification: "required" }, timeout: 60000 } }); localStorage.setItem("ghr.cred", btoa(String.fromCharCode(...new Uint8Array(cred.rawId)))); return true; }
        await navigator.credentials.get({ publicKey: { challenge: crypto.getRandomValues(new Uint8Array(32)), allowCredentials: [{ type: "public-key", id: Uint8Array.from(atob(id), (c) => c.charCodeAt(0)) }], userVerification: "required", timeout: 60000 } }); return true;
      }
    } catch (e) { toast(I18N.t("lock.cancelled")); return false; }
    toast(I18N.t("lock.need_bio"), 4000); return false;   // no platform authenticator at all: the app cannot be used (HR data)
  }
  async function afterSignIn() { await SYNC.run(); unlock(); location.hash = "#/inbox"; }
  async function signOut() { await API.logout(); await DB.wipe(); lockNow(); location.hash = "#/login"; }
  const touch = () => { lastActive = Date.now(); };
  ["click", "keydown", "touchstart", "scroll"].forEach((ev) => document.addEventListener(ev, touch, { passive: true }));
  function idleCheck() { if (API.signedIn && !isLocked() && Date.now() - lastActive > IDLE_MS) { lockNow(); if (location.hash !== "#/lock") location.hash = "#/lock"; } }
  document.addEventListener("visibilitychange", () => { if (document.hidden) { try { sessionStorage.setItem("ghr.hidden_at", String(Date.now())); } catch (e) {} } else { let h = 0; try { h = +sessionStorage.getItem("ghr.hidden_at") || 0; } catch (e) {} if (h && Date.now() - h > IDLE_MS) { lockNow(); location.hash = "#/lock"; } else { idleCheck(); SYNC.run(); } } });
  setInterval(idleCheck, 30000);

  function syncUI(s) {
    const el = $("#sync"); el.classList.toggle("offline", !s.online || !!s.queued); el.classList.toggle("error", !!s.error);
    const ago = s.last ? Math.round((Date.now() - s.last) / 60000) : null;
    let txt = !s.online ? I18N.t("sync.off") : s.error === "update-required" ? I18N.t("login.update") : s.error === "not-hr" ? I18N.t("login.hronly") : s.error ? I18N.t("sync.err") : `${I18N.t("sync.ok")} ${ago === null ? "" : ago < 1 ? I18N.t("sync.now") : `${ago} min ${I18N.t("sync.ago")}`}`;
    if (s.queued) txt += ` — ${s.queued} ${I18N.t("sync.queued")}`; if (s.needsTotp) txt += ` — ${s.needsTotp} ${I18N.t("dec.needs_code")}`;
    $("#synctext").textContent = txt;
  }
  SYNC.on(syncUI);
  let lastSync = SYNC.state.last;
  SYNC.on((s) => { if (s.last && s.last !== lastSync) { lastSync = s.last; if (!document.querySelector("#sheet") && !document.activeElement.matches("textarea, input:not([readonly])")) render(); } });

  const routes = [
    [/^#\/login$/, () => SCREENS.login()], [/^#\/lock$/, () => SCREENS.lock()],
    [/^#\/inbox(\?.*)?$/, (m, q) => SCREENS.inbox(q)], [/^#\/people$/, () => SCREENS.people()], [/^#\/person\/(\d+)$/, (m) => SCREENS.person(m[1])],
    [/^#\/messages$/, () => SCREENS.messages()], [/^#\/thread\/(\d+)$/, (m) => SCREENS.thread(m[1])],
    [/^#\/calendar(\?.*)?$/, (m, q) => SCREENS.calendar(q)], [/^#\/balances$/, () => SCREENS.balances()], [/^#\/more$/, () => SCREENS.more()],
  ];
  const query = (h) => { const q = {}; const i = h.indexOf("?"); if (i < 0) return q; new URLSearchParams(h.slice(i + 1)).forEach((v, k) => { q[k] = v; }); return q; };
  async function render() {
    if (rendering) { pending = true; return; } rendering = true;
    try {
      let h = location.hash || "#/inbox";
      if (!API.signedIn && h !== "#/login") { location.hash = "#/login"; return; }
      if (API.signedIn && h !== "#/lock" && h !== "#/login" && isLocked()) { location.hash = "#/lock"; return; }
      await SCREENS.load();
      const r = routes.find(([re]) => re.test(h)); const m = h.match(r ? r[0] : /.*/);
      const scr = r ? await r[1](m, query(h)) : { html: "<p class='list-empty'>Not found</p>" };
      closeSheet(); view.innerHTML = scr.html; I18N.apply(); if (current !== h) window.scrollTo(0, 0); if (scr.bind) scr.bind(view); current = h;
    } finally { rendering = false; if (pending) { pending = false; render(); } }
  }
  window.addEventListener("hashchange", render);
  $("#back").onclick = () => history.length > 1 ? history.back() : (location.hash = "#/inbox");
  (async () => {
    if ("serviceWorker" in navigator && location.protocol !== "file:") { try { UPD.init(await navigator.serviceWorker.register("sw.js")); } catch (e) {} }
    await DB.open(); I18N.apply(); await SYNC.refreshCount();
    if (API.signedIn) { lockNow(); const me = await DB.get("meta", "me"); if (!me) await SYNC.run(); else SYNC.run(); }   // every cold start is locked
    location.hash = API.signedIn ? "#/lock" : "#/login"; render();
    setInterval(() => SYNC.run(false), 5 * 60 * 1000);
  })();
  return { chrome, badges, toast, goLinks, segs, sheet, closeSheet, totpField, totpValue, totpSheet, biometric, biometricAvailable, render, afterSignIn, signOut, lockNow, unlock };
})();
