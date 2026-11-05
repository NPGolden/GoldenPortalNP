/* Golden HR — API client for the portal module /customer/api/v1 (JWT access + rotating refresh), app "hr" (GAS-CP-R74-001).
 * Same transport as Golden PM (customer/mobile-pm/js/api.js); routes under /hr/app/*. No mock mode: HR data is never faked. */
window.API = (() => {
  const VERSION = "1.0.2";
  const BASE = (document.querySelector('meta[name="gas-api"]') || {}).content || "../api/v1";
  const tok = { get access() { try { return sessionStorage.getItem("ghr.at"); } catch (e) { return null; } }, set access(v) { try { v ? sessionStorage.setItem("ghr.at", v) : sessionStorage.removeItem("ghr.at"); } catch (e) {} },
    get refresh() { try { return localStorage.getItem("ghr.rt"); } catch (e) { return null; } }, set refresh(v) { try { v ? localStorage.setItem("ghr.rt", v) : localStorage.removeItem("ghr.rt"); } catch (e) {} } };

  async function raw(method, path, body, isForm) {
    const h = { "X-App": "hr", "X-App-Version": VERSION, "X-Lang": I18N.get(), "Cache-Control": "no-store" };
    if (tok.access) h.Authorization = `Bearer ${tok.access}`;
    if (!isForm) h["Content-Type"] = "application/json";
    const res = await fetch(`${BASE}${path}`, { method, headers: h, body: isForm ? body : (body ? JSON.stringify(body) : undefined), cache: "no-store" });
    if (res.status === 204) return {};
    let data = {}; try { data = await res.json(); } catch (e) {}
    if (!res.ok) { const err = new Error(data.error || `HTTP ${res.status}`); err.status = res.status; err.data = data; throw err; }
    return data;
  }
  let refreshing = null;   // R77-004: one refresh at a time — parallel 401s share it (a second call with the same token would be "reuse")
  const refreshOnce = () => refreshing || (refreshing = raw("POST", "/auth/refresh", { refresh_token: tok.refresh }).then((r) => { tok.access = r.access_token; tok.refresh = r.refresh_token; return r; }).catch((e) => { if (e.status === 401 || e.status === 403) { tok.access = null; tok.refresh = null; } throw e; }).finally(() => { refreshing = null; }));
  async function call(method, path, body, isForm) {
    try { return await raw(method, path, body, isForm); }
    catch (e) {
      if (e.status === 401 && tok.refresh && !path.startsWith("/auth/")) {
        await refreshOnce(); return await raw(method, path, body, isForm);
      }
      throw e;
    }
  }
  /* passkeys — identical to Golden PM (R71-001 / R71-003) */
  const pkB64 = { enc: (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, ""), dec: (s) => Uint8Array.from(atob(String(s).replace(/-/g, "+").replace(/_/g, "/") + "===".slice((String(s).length + 3) % 4)), (c) => c.charCodeAt(0)) };
  function pkToJSON(cred) { const r = cred.response, out = { id: cred.id, rawId: pkB64.enc(cred.rawId), type: cred.type, response: { clientDataJSON: pkB64.enc(r.clientDataJSON) } }; if (r.attestationObject) out.response.attestationObject = pkB64.enc(r.attestationObject); if (r.authenticatorData) out.response.authenticatorData = pkB64.enc(r.authenticatorData); if (r.signature) out.response.signature = pkB64.enc(r.signature); if (r.userHandle) out.response.userHandle = pkB64.enc(r.userHandle); return out; }
  const pkNative = () => (window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform() && Capacitor.Plugins && Capacitor.Plugins.CapacitorPasskey) ? Capacitor.Plugins.CapacitorPasskey : null;
  const deviceInfo = () => ({ platform: (window.Capacitor && Capacitor.getPlatform && Capacitor.getPlatform()) || "web", ua: navigator.userAgent.slice(0, 200), name: "Golden HR", id: deviceId() });
  function deviceId() { try { let d = localStorage.getItem("ghr.device"); if (!d) { d = DB.uuid(); localStorage.setItem("ghr.device", d); } return d; } catch (e) { return "web"; } }

  return {
    VERSION, mock: false,
    get signedIn() { return !!tok.refresh; },
    async login(email, password, totp) { const r = await call("POST", "/auth/login", { email, password, totp: totp || undefined, device: deviceInfo() }); tok.access = r.access_token; tok.refresh = r.refresh_token; return r; },
    async logout() { try { await call("POST", "/auth/logout", {}); } catch (e) {} tok.access = null; tok.refresh = null; },
    get passkeyCapable() { return !!pkNative() || !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get); },
    async passkeyLogin() {
      const o = await call("POST", "/auth/passkey/options", {}); const n = pkNative(); let cred;
      if (n) { cred = await n.getCredential({ requestJson: JSON.stringify({ ...o.options, allowCredentials: [] }), origin: "https://goldensas.com" }); }
      else { const pk = o.options; pk.challenge = pkB64.dec(pk.challenge); pk.allowCredentials = []; cred = pkToJSON(await navigator.credentials.get({ publicKey: pk })); }
      const r = await call("POST", "/auth/passkey/login", { challenge_id: o.challenge_id, credential: cred, device: deviceInfo() }); tok.access = r.access_token; tok.refresh = r.refresh_token; return r; },
    async passkeyRegister(name, totp) {
      const o = await call("POST", "/me/passkeys/options", { totp: totp || undefined }); const n = pkNative(); let cred;
      if (n) { cred = await n.createCredential({ requestJson: JSON.stringify(o.options), origin: "https://goldensas.com" }); }
      else { const pk = o.options; pk.challenge = pkB64.dec(pk.challenge); pk.user.id = pkB64.dec(pk.user.id); pk.excludeCredentials = (pk.excludeCredentials || []).map((c) => ({ type: c.type, id: pkB64.dec(c.id) })); cred = pkToJSON(await navigator.credentials.create({ publicKey: pk })); }
      return call("POST", "/me/passkeys", { challenge_id: o.challenge_id, credential: cred, name }); },
    passkeys: () => call("GET", "/me/passkeys"), passkeyRemove: (id) => call("DELETE", `/me/passkeys/${id}`),

    me: () => call("GET", "/me"),
    inbox: () => call("GET", "/hr/app/inbox"), history: (year) => call("GET", `/hr/app/history?year=${year}`),
    people: () => call("GET", "/hr/app/people"), away: (from, to) => call("GET", `/hr/app/away?from=${from}&to=${to}`), balances: (year) => call("GET", `/hr/app/balances?year=${year}`),
    threads: () => call("GET", "/hr/app/threads"), thread: (pid) => call("GET", `/hr/app/thread/${pid}`), send: (pid, body) => call("POST", "/hr/app/send", { person_id: pid, body }),
    sync: (ops) => call("POST", "/sync", { ops }), pushToken: (token, service) => call("POST", "/devices/push-token", { token, service }),
  };
})();
