/* Golden A/S Service app — router and UI helpers (sheet, photos, signature, chrome, biometric unlock). */
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
  }
  const badge = (s) => { $("#branch").textContent = s; };
  function toast(msg, ms) { const tst = $("#toast"); tst.textContent = msg; tst.classList.remove("hidden"); clearTimeout(tst._t); tst._t = setTimeout(() => tst.classList.add("hidden"), ms || 2200); }
  const goLinks = (root) => root.querySelectorAll("[data-go]").forEach((el) => el.onclick = (e) => { e.preventDefault(); location.hash = el.dataset.go; });
  const segs = (root, onChange) => root.querySelectorAll(".seg[data-k]").forEach((seg) => seg.querySelectorAll("button").forEach((b) => b.onclick = () => { seg.querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b)); if (onChange) onChange(seg.dataset.k, b.dataset.v); }));

  // bottom sheet
  function sheet(html, onOk, onOpen) {
    closeSheet(); const el = document.createElement("div"); el.className = "sheet"; el.id = "sheet"; el.innerHTML = `<div class="in">${html}<button class="btn line sm" id="cancel">${I18N.t("common.cancel")}</button></div>`;
    document.body.appendChild(el); el.onclick = (e) => { if (e.target === el) closeSheet(); }; el.querySelector("#cancel").onclick = closeSheet;
    const ok = el.querySelector("#ok"); if (ok) ok.onclick = () => onOk(el); if (onOpen) onOpen(el);
  }
  const closeSheet = () => { const s = $("#sheet"); if (s) s.remove(); };

  // photos: picked → compressed on device (≤1600 px, JPEG q0.8 ≈ ≤400 kB) → canvas re-encode strips all EXIF (CS-06)
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

  // signature pad
  const sig = {
    init(root, existing) {
      const c = root.querySelector("#sig"); if (!c) return; const ctx = c.getContext("2d"); const fit = () => { const r = c.getBoundingClientRect(); c.width = r.width * 2; c.height = r.height * 2; ctx.scale(2, 2); ctx.lineWidth = 2; ctx.lineCap = "round"; ctx.strokeStyle = "#1F3A5F"; if (existing) { const im = new Image(); im.onload = () => ctx.drawImage(im, 0, 0, r.width, r.height); im.src = existing; c._dirty = true; } };
      fit(); c._fit = () => { if (!c.width) fit(); }; let drawing = false; const pos = (e) => { const r = c.getBoundingClientRect(); const p = e.touches ? e.touches[0] : e; return [p.clientX - r.left, p.clientY - r.top]; };
      const start = (e) => { c._fit(); drawing = true; c._dirty = true; const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); e.preventDefault(); };
      const move = (e) => { if (!drawing) return; const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); e.preventDefault(); };
      const end = () => { drawing = false; };
      c.addEventListener("pointerdown", start); c.addEventListener("pointermove", move); window.addEventListener("pointerup", end);
      root.querySelector("#clr").onclick = () => { ctx.clearRect(0, 0, c.width, c.height); c._dirty = false; existing = null; };
    },
    fit(root) { const c = root.querySelector("#sig"); if (c && c._fit) c._fit(); },   // the pad is sized when its block becomes visible (hidden blocks measure 0 × 0)
    data(root) { const c = root.querySelector("#sig"); return c && c._dirty && c.width ? c.toDataURL("image/png") : null; },
  };

  // biometric / platform unlock: Capacitor plug-in when present, WebAuthn otherwise, PIN prompt as last resort (web demo)
  async function biometric() {
    try {
      if (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.BiometricAuth) { await Capacitor.Plugins.BiometricAuth.authenticate({ reason: "Unlock Golden A/S Service" }); return true; }
      if (window.PublicKeyCredential && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()) {
        const id = localStorage.getItem("gas.cred");
        if (!id) { const cred = await navigator.credentials.create({ publicKey: { challenge: crypto.getRandomValues(new Uint8Array(32)), rp: { name: "Golden A/S Service" }, user: { id: new TextEncoder().encode(API.VERSION + Date.now()), name: "engineer", displayName: "Service Engineer" }, pubKeyCredParams: [{ type: "public-key", alg: -7 }, { type: "public-key", alg: -257 }], authenticatorSelection: { authenticatorAttachment: "platform", userVerification: "required" }, timeout: 60000 } }); localStorage.setItem("gas.cred", btoa(String.fromCharCode(...new Uint8Array(cred.rawId)))); return true; }
        await navigator.credentials.get({ publicKey: { challenge: crypto.getRandomValues(new Uint8Array(32)), allowCredentials: [{ type: "public-key", id: Uint8Array.from(atob(id), (c) => c.charCodeAt(0)) }], userVerification: "required", timeout: 60000 } }); return true;
      }
    } catch (e) { toast("Unlock cancelled"); return false; }
    return API.mock; // web demo without platform authenticator
  }

  // documents: streamed by the portal (Bearer token) → opened from a blob URL; cached per viewer in the Cache API for offline reading
  async function openDocument(id) {
    if (API.mock) return toast("Demo: the portal streams the PDF to the document viewer");
    toast("Opening…"); const url = API.documentUrl(id);
    try { let res = window.caches ? await (await caches.open("gas-service-docs")).match(url) : null;
      if (!res) { res = await API.fetchDocument(id); if (window.caches && res.ok) (await caches.open("gas-service-docs")).put(url, res.clone()); }
      const blob = await res.blob(); const u = URL.createObjectURL(blob); window.open(u, "_blank"); setTimeout(() => URL.revokeObjectURL(u), 60000); } catch (e) { toast(e.message || "Document not available"); }
  }
  // sync indicator
  function syncUI(s) {
    const el = $("#sync"); el.classList.toggle("offline", !s.online || !!s.queued); el.classList.toggle("error", !!s.error);
    const ago = s.last ? Math.round((Date.now() - s.last) / 60000) : null;
    let txt = !s.online ? I18N.t("sync.off") : s.error === "update-required" ? "Update required — open the store" : s.error ? I18N.t("sync.err") : `${I18N.t("sync.ok")} ${ago === null ? "" : ago < 1 ? I18N.t("sync.now") : `${ago} min ${I18N.t("sync.ago")}`}`;
    if (s.queued) txt += ` — ${s.queued} ${I18N.t("sync.queued")}`;
    $("#synctext").textContent = txt;
  }
  SYNC.on(syncUI);
  // after a completed sync, refresh list screens (never an editor the engineer may be typing in)
  let lastSync = SYNC.state.last;
  SYNC.on((s) => { if (s.last && s.last !== lastSync) { lastSync = s.last; const h = location.hash; if (!/^#\/(fsr\/|hours(\?|$)|notes\?new)/.test(h) && !document.querySelector("#sheet")) render(); } });

  // router
  const routes = [
    [/^#\/login$/, () => SCREENS.login()], [/^#\/home$/, () => SCREENS.home()], [/^#\/assign\/(.+)$/, (m) => SCREENS.assign(m[1])],
    [/^#\/tickets$/, () => SCREENS.tickets()], [/^#\/ticket\/(.+)$/, (m) => SCREENS.ticket(m[1])],
    [/^#\/fsr$/, () => SCREENS.fsrList()], [/^#\/fsr\/([^?]+)(\?.*)?$/, (m, q) => SCREENS.fsrEdit(m[1], q)],
    [/^#\/hours(\?.*)?$/, (m, q) => SCREENS.hours(q)], [/^#\/notes(\?.*)?$/, (m, q) => SCREENS.notes(q)], [/^#\/docs$/, () => SCREENS.docs()],
    [/^#\/equipment(\?.*)?$/, (m, q) => SCREENS.equipment(q)], [/^#\/more$/, () => SCREENS.more()],
  ];
  const query = (h) => { const q = {}; const i = h.indexOf("?"); if (i < 0) return q; new URLSearchParams(h.slice(i + 1)).forEach((v, k) => { q[k] = v; }); return q; };
  async function render() {
    if (rendering) { pending = true; return; } rendering = true; assistCtx = null;
    try {
      let h = location.hash || "#/home";
      if (!API.signedIn && h !== "#/login") { location.hash = "#/login"; return; }
      await SCREENS.load();
      const r = routes.find(([re]) => re.test(h)); const m = h.match(r ? r[0] : /.*/);
      const scr = r ? await r[1](m, query(h)) : { html: "<p class='list-empty'>Not found</p>" };
      closeSheet(); view.innerHTML = scr.html; I18N.apply(); window.scrollTo(0, 0); if (scr.bind) scr.bind(view); current = h;
    } finally { rendering = false; if (pending) { pending = false; render(); } }
  }
  window.addEventListener("hashchange", render);
  $("#back").onclick = () => history.length > 1 ? history.back() : (location.hash = "#/home");
  // ✦ Claude assistance — the current screen may register a richer context via UI.assistContext(...)
  let assistCtx = null; const assistContext = (c) => { assistCtx = c; };
  $("#hdr-ai").onclick = () => ASSIST.open(assistCtx || { screen: (location.hash.match(/^#\/(\w+)/) || [])[1] || "generic", context: () => ({ screen: location.hash }) });
  // app start
  (async () => {
    if ("serviceWorker" in navigator && !API.mock) { try { await navigator.serviceWorker.register("sw.js"); } catch (e) {} }
    else if ("serviceWorker" in navigator) { try { await navigator.serviceWorker.register("sw.js"); } catch (e) {} }
    await DB.open(); I18N.apply(); await SYNC.refreshCount();
    if (API.signedIn) { const me = await DB.get("meta", "me"); if (!me) await SYNC.run(); else SYNC.run(); }
    if (!location.hash) location.hash = API.signedIn ? "#/home" : "#/login"; else render();
    document.addEventListener("visibilitychange", () => { if (!document.hidden) SYNC.run(); });
    setInterval(() => SYNC.run(false), 5 * 60 * 1000);
  })();
  return { chrome, badge, toast, goLinks, segs, sheet, closeSheet, photos, sig, biometric, render, assistContext, openDocument };
})();
