/* native.js — bridge to Capacitor plug-ins when running in the store app; browser fallbacks for the PWA.
   Store builds inject window.Capacitor; capacitor/native-bridge.js registers the plug-ins listed in capacitor/README-build.md. */
window.Native = (function () {
  const cap = () => window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform() ? window.Capacitor : null;
  const P = () => (window.Capacitor && window.Capacitor.Plugins) || {};
  const isNative = () => !!cap();
  async function deviceId() {
    if (isNative() && P().Device) { try { return (await P().Device.getId()).identifier; } catch (e) {} }
    try { let id = localStorage.getItem("gcu.device"); if (!id) { id = "web-" + crypto.randomUUID(); localStorage.setItem("gcu.device", id); } return id; } catch (e) { return "web-" + Math.random().toString(36).slice(2); }
  }
  function deviceInfo() {
    const ua = navigator.userAgent; const platform = isNative() ? window.Capacitor.getPlatform() : "web";
    const model = /iPhone/.test(ua) ? "iPhone" : /iPad/.test(ua) ? "iPad" : /Android/.test(ua) ? "Android" : "Browser";
    return { platform, model, ua: ua.slice(0, 120), app_version: "1.0.0" };
  }
  // Secure store: Keychain / Keystore in the app; localStorage in the browser PWA.
  async function secureGet(k) { if (isNative() && P().SecureStorage) { try { return (await P().SecureStorage.get({ key: k })).value || null; } catch (e) { return null; } } try { return localStorage.getItem("gcu.sec_" + k); } catch (e) { return null; } }
  async function secureSet(k, v) { if (isNative() && P().SecureStorage) { try { await P().SecureStorage.set({ key: k, value: v }); return; } catch (e) {} } try { localStorage.setItem("gcu.sec_" + k, v); } catch (e) {} }
  async function secureDel(k) { if (isNative() && P().SecureStorage) { try { await P().SecureStorage.remove({ key: k }); } catch (e) {} } try { localStorage.removeItem("gcu.sec_" + k); } catch (e) {} }
  // Biometrics: native plug-in in the app; WebAuthn platform authenticator (user-verifying) in the browser; demo stub in mock mode.
  async function biometricAvailable() {
    if (isNative() && P().NativeBiometric) { try { return (await P().NativeBiometric.isAvailable()).isAvailable; } catch (e) { return false; } }
    if (Api.cfg.mock) return true;
    try { return !!(window.PublicKeyCredential && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()); } catch (e) { return false; }
  }
  async function biometricVerify(reason) {
    if (isNative() && P().NativeBiometric) { try { await P().NativeBiometric.verifyIdentity({ reason, title: "Golden A/S", useFallback: true }); return true; } catch (e) { return false; } }
    if (Api.cfg.mock) { return confirm((reason || "Confirm") + "\n\n[Demo] Biometric prompt — OK to simulate Face ID / fingerprint success."); }
    try {
      const challenge = crypto.getRandomValues(new Uint8Array(32));
      let credId = null; try { credId = localStorage.getItem("gcu.webauthn"); } catch (e) {}
      if (!credId) {
        const cred = await navigator.credentials.create({ publicKey: { challenge, rp: { name: "Golden A/S Customer" }, user: { id: new TextEncoder().encode(await deviceId()), name: "customer", displayName: "Customer" }, pubKeyCredParams: [{ type: "public-key", alg: -7 }, { type: "public-key", alg: -257 }], authenticatorSelection: { authenticatorAttachment: "platform", userVerification: "required" }, timeout: 60000 } });
        credId = btoa(String.fromCharCode(...new Uint8Array(cred.rawId))); try { localStorage.setItem("gcu.webauthn", credId); } catch (e) {}
        return true;
      }
      await navigator.credentials.get({ publicKey: { challenge, allowCredentials: [{ type: "public-key", id: Uint8Array.from(atob(credId), c => c.charCodeAt(0)) }], userVerification: "required", timeout: 60000 } });
      return true;
    } catch (e) { return false; }
  }
  async function pushRegister() {
    if (!isNative() || !P().PushNotifications) return null;
    try { const perm = await P().PushNotifications.requestPermissions(); if (perm.receive !== "granted") return null; await P().PushNotifications.register(); } catch (e) {}
    return null; // token arrives through the 'registration' listener in native-bridge.js → Api.call('POST','/push/register')
  }
  /* R97-171: photo and file are picked with the system picker of the web view (camera · photo library · files), as in the Service app.
     The Camera plug-in is no longer used here: on iOS the app stopped when the photo was confirmed and the ticket was lost.
     The input stays in the page until it answers — iOS drops the answer of an input that is not in the document. */
  function pickWith(accept, capture) {
    return new Promise(res => { const i = document.createElement("input"); i.type = "file"; if (accept) i.accept = accept; if (capture) i.capture = capture;
      i.style.cssText = "position:fixed;left:-9999px;top:0;opacity:0"; document.body.appendChild(i); let done = false;
      const end = (f) => { if (done) return; done = true; setTimeout(() => i.remove(), 0); res(f || null); };
      i.onchange = () => end(i.files && i.files[0]); i.addEventListener("cancel", () => end(null)); i.click(); });
  }
  async function pickPhoto() { const f = await pickWith("image/*", ""); /* no capture attribute: iOS offers Take Photo · Photo Library · Choose File */ if (!f) return null; try { return await compress(f); } catch (e) { return { blob: f, name: f.name || "photo.jpg" }; } }
  async function pickFile() { const f = await pickWith("", ""); return f ? { blob: f, name: f.name } : null; }
  // Compress ≤1600 px long side, JPEG q0.8; re-encoding through canvas strips EXIF (location) on the device.
  // The picture is decoded already reduced where the browser can (createImageBitmap), so a 48-megapixel photo does not exhaust the memory of the web view.
  async function compress(file) {
    const MAX = 1600, out = (c) => new Promise(r => c.toBlob(b => r(b ? { blob: b, name: "photo-" + Date.now() + ".jpg" } : { blob: file, name: file.name || "photo.jpg" }), "image/jpeg", 0.8));
    if (window.createImageBitmap) { try {
      const bm = await createImageBitmap(file, { resizeWidth: MAX, resizeQuality: "medium" }); let w = bm.width, h = bm.height; if (h > MAX) { w = Math.round(w * MAX / h); h = MAX; }
      const c = document.createElement("canvas"); c.width = w; c.height = h; c.getContext("2d").drawImage(bm, 0, 0, w, h); if (bm.close) bm.close(); return await out(c);
    } catch (e) {} }
    return new Promise(res => { const img = new Image(); const url = URL.createObjectURL(file);
      img.onload = () => { const s = Math.min(1, MAX / Math.max(img.width, img.height)); const c = document.createElement("canvas"); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s); c.getContext("2d").drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url); out(c).then(res); };
      img.onerror = () => { URL.revokeObjectURL(url); res({ blob: file, name: file.name || "photo.jpg" }); }; img.src = url; });
  }
  function onResume(fn) { if (isNative() && P().App) { P().App.addListener("resume", fn); } document.addEventListener("visibilitychange", () => { if (!document.hidden) fn(); }); }
  return { isNative, deviceId, deviceInfo, secureGet, secureSet, secureDel, biometricAvailable, biometricVerify, pushRegister, pickPhoto, pickFile, onResume };
})();
