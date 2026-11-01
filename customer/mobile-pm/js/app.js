/* Golden PM — router and UI helpers (chrome, tab badges, sheet, photos, biometric unlock, step-up TOTP sheet, process line). */
window.UI = (() => {
  const $ = (s) => document.querySelector(s);
  const view = $("#view");
  let current = null, rendering = false, pending = false;

  function chrome(on, title, tab, back) {
    $("#hdr").classList.toggle("hidden", !on); $("#tabs").classList.toggle("hidden", !on); $("#sync").classList.toggle("hidden", !on);
    if (title) $("#title").textContent = title;
    $("#back").classList.toggle("show", !!back);
    document.querySelectorAll("#tabs a").forEach((a) => a.classList.toggle("on", a.dataset.tab === tab));
    const me = SCREENS.ctx.me || {}; $("#branch").textContent = me.home_branch || "GR";
    badges();
  }
  const badge = (s) => { $("#branch").textContent = s; };
  /** tab badges (CONTRACT §10): Team = hr_pending + ts_pending · Approvals = drawings_overdue + submittals_open */
  function badges() {
    const c = ((SCREENS.ctx.me || {}).counts) || {}; const set = (id, n) => { const b = $(id); if (!b) return; b.textContent = n > 99 ? "99+" : String(n); b.classList.toggle("hidden", !n); };
    set("#b-team", (c.hr_pending || 0) + (c.ts_pending || 0)); set("#b-appr", (c.drawings_overdue || 0) + (c.submittals_open || 0));
  }
  function toast(msg, ms) { const tst = $("#toast"); tst.textContent = msg; tst.classList.remove("hidden"); clearTimeout(tst._t); tst._t = setTimeout(() => tst.classList.add("hidden"), ms || 2200); }
  const goLinks = (root) => root.querySelectorAll("[data-go]").forEach((el) => el.onclick = (e) => { e.preventDefault(); e.stopPropagation(); location.hash = el.dataset.go; });
  const segs = (root, onChange) => root.querySelectorAll(".seg[data-k]").forEach((seg) => seg.querySelectorAll("button").forEach((b) => b.onclick = () => { seg.querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b)); if (onChange) onChange(seg.dataset.k, b.dataset.v); }));

  // bottom sheet
  function sheet(html, onOk, onOpen) {
    closeSheet(); const el = document.createElement("div"); el.className = "sheet"; el.id = "sheet"; el.innerHTML = `<div class="in">${html}<button class="btn line sm" id="cancel">${I18N.t("common.cancel")}</button></div>`;
    document.body.appendChild(el); el.onclick = (e) => { if (e.target === el) closeSheet(); }; el.querySelector("#cancel").onclick = closeSheet;
    const ok = el.querySelector("#ok"); if (ok && onOk) ok.onclick = () => onOk(el); if (onOpen) onOpen(el);
    return el;
  }
  const closeSheet = () => { const s = $("#sheet"); if (s) s.remove(); };

  /** step-up field (6-digit authenticator code) for decision sheets — present only when the account has 2FA */
  const totpField = (me) => (me || SCREENS.ctx.me || {}).totp_enabled ? `<div class="totp"><label>${I18N.t("team.code")}</label><input id="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" placeholder="000000"><p class="muted">${I18N.t("team.code_hint")}</p></div>` : "";
  /** read the code from a sheet; returns false (and shows the message) when required and not 6 digits */
  function totpValue(sh, me) {
    const need = (me || SCREENS.ctx.me || {}).totp_enabled; const el = sh.querySelector("#totp"); const v = el ? el.value.trim() : "";
    if (need && !/^\d{6}$/.test(v)) { const er = sh.querySelector("#err"); if (er) { er.textContent = I18N.t("team.code_req"); er.classList.remove("hidden"); } if (el) el.focus(); return false; }
    return v || undefined;
  }
  /** sheet that only asks for a code — used to retry an operation waiting as needs_totp */
  function totpSheet(onCode, wrong) {
    sheet(`<h3>${I18N.t("team.enter_code")}</h3>${wrong ? `<p class="note red">${I18N.t("team.code_bad")}</p>` : ""}${totpField({ totp_enabled: true })}<p id="err" class="note red hidden"></p><button class="btn gold" id="ok">${I18N.t("common.ok")}</button>`, (sh) => { const v = totpValue(sh, { totp_enabled: true }); if (v === false) return; closeSheet(); onCode(v); }, (sh) => sh.querySelector("#totp").focus());
  }

  // process line — the portal's process_line(): 7 segments, tones done / active / todo / na (hatched), fed = read-only marker
  function processLine(sub, compact, tappable) {
    const st = sub.stages || {}; const fed = sub.fed || [];
    return `<div class="stepper${compact ? " stepper--compact" : ""}" role="list">${SYNC.STAGES.map((k) => { const raw = st[k] == null ? 0 : +st[k]; const na = raw === -1; const pct = na ? 0 : Math.max(0, Math.min(100, raw)); const tone = na ? "na" : pct >= 100 ? "done" : pct > 0 ? "active" : "todo"; const isFed = fed.includes(k);
      return `<div class="stp stp--${tone}${isFed ? " stp--fed" : ""}${sub.queued ? " stp--queued" : ""}${sub.conflict ? " stp--conflict" : ""}${tappable && !isFed ? " tap" : ""}" role="listitem" data-stage="${k}" title="${I18N.t("stage." + k)} · ${na ? I18N.t("sub.na_long") : pct + "%"}${isFed ? " · " + I18N.t("sub.fed") : ""}"><i class="stp-fill" style="width:${pct}%"></i><span class="stp-txt"><b>${I18N.t("stab." + k)}</b><em>${na ? I18N.t("sub.na") : pct + "%"}</em></span></div>`; }).join("")}</div>`;
  }
  const opill = (pct) => { pct = Math.max(0, Math.min(100, Math.round(+pct || 0))); const tone = pct >= 100 ? "done" : pct > 0 ? "active" : "todo"; return `<span class="opill opill--${tone}" title="${I18N.t("proj.overall")} ${pct}%"><i style="--w:${pct}%"></i><span>${pct}%</span></span>`; };

  // photos: picked → compressed on device (≤1600 px, JPEG q0.8) → canvas re-encode strips all EXIF (CS-06)
  const photos = {
    init(box, record, max) {
      box.innerHTML = `<label class="add">+<input type="file" accept="image/*" capture="environment" multiple></label>`; box._max = max || 9;
      box.querySelector("input").onchange = async (e) => { for (const f of e.target.files) { if (box.querySelectorAll(".ph").length >= box._max) { toast(`Max ${box._max} photos`); break; } const blob = await compress(f); addThumb(box, blob, f.name.replace(/\.[^.]+$/, "") + ".jpg"); } e.target.value = ""; };
      if (record) DB.all("photos").then((all) => all.filter((p) => p.record === record).forEach((p) => addThumb(box, p.blob, p.name, p.id)));
    },
    count: (box) => box.querySelectorAll(".ph").length,
    async store(box, record) { for (const ph of box.querySelectorAll(".ph")) { if (ph._stored) continue; await DB.put("photos", { id: ph._id || DB.uuid(), record, blob: ph._blob, name: ph._name, captured_at: new Date().toISOString() }); ph._stored = true; } },
  };
  function addThumb(box, blob, name, id) {
    const ph = document.createElement("div"); ph.className = "ph"; ph._blob = blob; ph._name = name; ph._id = id; ph._stored = !!id;
    ph.innerHTML = `<img src="${URL.createObjectURL(blob)}"><button>×</button>`; ph.querySelector("button").onclick = async () => { if (id) await DB.del("photos", id); ph.remove(); };
    box.insertBefore(ph, box.querySelector(".add"));
  }
  function compress(file) {
    return new Promise((res) => { const img = new Image(); img.onload = () => { const max = 1600; const sc = Math.min(1, max / Math.max(img.width, img.height)); const c = document.createElement("canvas"); c.width = Math.round(img.width * sc); c.height = Math.round(img.height * sc); c.getContext("2d").drawImage(img, 0, 0, c.width, c.height); c.toBlob((b) => res(b || file), "image/jpeg", 0.8); URL.revokeObjectURL(img.src); }; img.onerror = () => res(file); img.src = URL.createObjectURL(file); });
  }

  // biometric / platform unlock: Capacitor plug-in when present, WebAuthn otherwise (web demo: mock passes)
  // R70-005: lock state — set by "Lock app", or on every start when the setting "Face ID on open" is on; cleared by a successful unlock
  function isLocked() { try { return localStorage.getItem("gpm.locked") === "1"; } catch (e) { return false; } }
  async function biometricAvailable() { if (API.mock) return true; try { if (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.BiometricAuth) return true; return !!(window.PublicKeyCredential && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()); } catch (e) { return false; } }
  async function biometric() {
    try {
      if (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.BiometricAuth) { await Capacitor.Plugins.BiometricAuth.authenticate({ reason: "Unlock Golden PM" }); return true; }
      if (window.PublicKeyCredential && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()) {
        const id = localStorage.getItem("gpm.cred");
        if (!id) { const cred = await navigator.credentials.create({ publicKey: { challenge: crypto.getRandomValues(new Uint8Array(32)), rp: { name: "Golden PM" }, user: { id: new TextEncoder().encode(API.VERSION + Date.now()), name: "pm", displayName: "Project Manager" }, pubKeyCredParams: [{ type: "public-key", alg: -7 }, { type: "public-key", alg: -257 }], authenticatorSelection: { authenticatorAttachment: "platform", userVerification: "required" }, timeout: 60000 } }); localStorage.setItem("gpm.cred", btoa(String.fromCharCode(...new Uint8Array(cred.rawId)))); return true; }
        await navigator.credentials.get({ publicKey: { challenge: crypto.getRandomValues(new Uint8Array(32)), allowCredentials: [{ type: "public-key", id: Uint8Array.from(atob(id), (c) => c.charCodeAt(0)) }], userVerification: "required", timeout: 60000 } }); return true;
      }
    } catch (e) { toast("Unlock cancelled"); return false; }
    return API.mock; // web demo without platform authenticator
  }

  // documents: streamed by the portal (Bearer token) → opened from a blob URL; cached in the Cache API for offline reading
  async function openDocument(id, kind) {
    if (API.mock) return toast("Demo: the portal streams the PDF to the document viewer");
    toast("Opening…"); const url = kind === "hr" ? API.hrDocUrl(id) : API.documentUrl(id);
    try { let res = window.caches && kind !== "hr" ? await (await caches.open("gas-pm-docs")).match(url) : null;
      if (!res) { res = await API.fetchDocument(id, kind); if (window.caches && res.ok && kind !== "hr") (await caches.open("gas-pm-docs")).put(url, res.clone()); }
      const blob = await res.blob(); const u = URL.createObjectURL(blob); window.open(u, "_blank"); setTimeout(() => URL.revokeObjectURL(u), 60000); } catch (e) { toast(e.message || "Document not available"); }
  }
  // sync indicator
  function syncUI(s) {
    const el = $("#sync"); el.classList.toggle("offline", !s.online || !!s.queued); el.classList.toggle("error", !!s.error);
    const ago = s.last ? Math.round((Date.now() - s.last) / 60000) : null;
    let txt = !s.online ? I18N.t("sync.off") : s.error === "update-required" ? "Update required — open the store" : s.error === "not-pm" ? I18N.t("login.pmonly") : s.error ? I18N.t("sync.err") : `${I18N.t("sync.ok")} ${ago === null ? "" : ago < 1 ? I18N.t("sync.now") : `${ago} min ${I18N.t("sync.ago")}`}`;
    if (s.queued) txt += ` — ${s.queued} ${I18N.t("sync.queued")}`;
    if (s.needsTotp) txt += ` — ${s.needsTotp} ${I18N.t("synclog.needs_totp")}`;
    $("#synctext").textContent = txt;
  }
  SYNC.on(syncUI);
  // after a completed sync, refresh list screens (never while a sheet is open or the PM is typing a note)
  let lastSync = SYNC.state.last;
  SYNC.on((s) => { if (s.last && s.last !== lastSync) { lastSync = s.last; if (!/^#\/notes\?new/.test(location.hash) && !document.querySelector("#sheet") && !document.activeElement.matches("textarea, input:not([readonly])")) render(); } });

  // router
  const routes = [
    [/^#\/login$/, () => SCREENS.login()], [/^#\/lock$/, () => SCREENS.lock()], [/^#\/home$/, () => SCREENS.home()],
    [/^#\/projects(\?.*)?$/, (m, q) => SCREENS.projects(q)], [/^#\/project\/(\d+)$/, (m) => SCREENS.project(m[1])], [/^#\/sub\/(\d+)(\?.*)?$/, (m, q) => SCREENS.sub(m[1], q)],
    [/^#\/team(\?.*)?$/, (m, q) => HR.team(q)], [/^#\/approvals(\?.*)?$/, (m, q) => SCREENS.approvals(q)],
    [/^#\/notes(\?.*)?$/, (m, q) => SCREENS.notes(q)], [/^#\/docs$/, () => SCREENS.docs()], [/^#\/inbox$/, () => SCREENS.inbox()], [/^#\/synclog$/, () => SCREENS.synclog()],
    [/^#\/more$/, () => SCREENS.more()], [/^#\/hr(\?.*)?$/, (m, q) => HR.page(q)],
  ];
  const query = (h) => { const q = {}; const i = h.indexOf("?"); if (i < 0) return q; new URLSearchParams(h.slice(i + 1)).forEach((v, k) => { q[k] = v; }); return q; };
  async function render() {
    if (rendering) { pending = true; return; } rendering = true; assistCtx = null;
    try {
      let h = location.hash || "#/home";
      if (!API.signedIn && h !== "#/login") { location.hash = "#/login"; return; }
      if (API.signedIn && h !== "#/lock" && h !== "#/login" && isLocked()) { location.hash = "#/lock"; return; }   // R70-005
      await SCREENS.load();
      const r = routes.find(([re]) => re.test(h)); const m = h.match(r ? r[0] : /.*/);
      const scr = r ? await r[1](m, query(h)) : { html: "<p class='list-empty'>Not found</p>" };
      closeSheet(); view.innerHTML = scr.html; I18N.apply(); if (current !== h) window.scrollTo(0, 0); if (scr.bind) scr.bind(view); current = h;
    } finally { rendering = false; if (pending) { pending = false; render(); } }
  }
  window.addEventListener("hashchange", render);
  $("#back").onclick = () => history.length > 1 ? history.back() : (location.hash = "#/home");
  // ✦ Claude assistance — the current screen may register a richer context via UI.assistContext(...)
  let assistCtx = null; const assistContext = (c) => { assistCtx = c; };
  $("#hdr-ai").onclick = () => ASSIST.open(assistCtx || { page: "fsr_edit", title: "Golden PM", headline: location.hash, fields: () => [], noFill: true });
  // app start
  (async () => {
    if ("serviceWorker" in navigator && location.protocol !== "file:") { try { await navigator.serviceWorker.register("sw.js"); } catch (e) {} }
    await DB.open(); I18N.apply(); await SYNC.refreshCount();
    try { if (API.signedIn && localStorage.getItem("gpm.bio") === "1" && !API.mock) localStorage.setItem("gpm.locked", "1"); } catch (e) {}   // R70-005: the gate is the lock screen — a cancelled Face ID no longer signs you out
    if (API.signedIn) { const me = await DB.get("meta", "me"); if (!me) await SYNC.run(); else SYNC.run(); }
    if (!location.hash || isLocked()) location.hash = API.signedIn ? (isLocked() ? "#/lock" : "#/home") : "#/login"; else render();
    document.addEventListener("visibilitychange", () => { if (!document.hidden) SYNC.run(); });
    setInterval(() => SYNC.run(false), 5 * 60 * 1000);
  })();
  return { chrome, badge, badges, toast, goLinks, segs, sheet, closeSheet, totpField, totpValue, totpSheet, processLine, opill, photos, biometric, biometricAvailable, render, assistContext, openDocument };
})();
