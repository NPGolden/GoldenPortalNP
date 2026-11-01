/* screens/auth.js — sign-in (with authenticator code when the account has 2FA), biometric offer / unlock gate, step-up sheet. */
window.Auth = (function () {
  const { t, esc, $, render } = UI;
  function login(needTotp) {
    document.body.classList.add("hidden-nav");
    const v = render(`<div class="auth">
      <img class="logo" src="img/logo.png" alt="Golden A/S">
      <div class="brand">${esc(t("login.brand"))}</div>
      <h1>${esc(t("login.title"))}</h1><p class="lede">${esc(t("login.lede"))}</p>
      <form id="f-login" autocomplete="on">
        <label class="field"><span data-i18n="login.email"></span><input name="email" type="email" inputmode="email" autocomplete="username" required></label>
        <label class="field"><span data-i18n="login.password"></span><input name="password" type="password" autocomplete="current-password" required></label>
        <label class="field" id="totp-field" ${needTotp ? "" : "hidden"}><span data-i18n="login.totp"></span><input name="totp" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"></label>
        <div id="login-err" class="notice err" hidden></div>
        <button class="btn gold" type="submit" id="btn-login">${esc(t("login.btn"))}</button>
      </form>
      ${Api.passkeyCapable() ? `<button class="btn line" id="btn-pk" style="margin-top:10px">🔑 ${esc(t("login.passkey"))}</button>` : ""}
      <p class="meta" style="text-align:center;margin-top:14px">${esc(t("login.help"))}</p>
      ${Api.cfg.mock ? `<div class="notice" style="margin-top:14px">Demo: <b>captain@demo-fleet.com</b> / <b>GoldenDemo2026!</b> — authenticator code <b>123456</b></div>` : ""}
      <div class="langs">${I18N.LANGS.map(([c, n]) => `<button type="button" data-lang="${c}" class="${I18N.get() === c ? "on" : ""}">${n}</button>`).join("")}</div>
    </div>`, "");
    I18N.apply(v);
    v.querySelectorAll("[data-lang]").forEach(b => b.onclick = () => { I18N.set(b.dataset.lang); login(needTotp); });
    const pkb = $("#btn-pk"); if (pkb) pkb.onclick = async () => { const err = $("#login-err"); err.hidden = true; UI.busy(pkb, true); try { await Api.passkeyLogin(); await App.afterLogin(); } catch (ex) { err.textContent = ex && ex.name === "NotAllowedError" ? t("pk.failed") : (ex.status === 403 ? t("login.notcustomer") : UI.errMsg(ex)); err.hidden = false; } finally { UI.busy(pkb, false); } };   // R71-001
    $("#f-login").onsubmit = async e => {
      e.preventDefault(); const f = e.target, btn = $("#btn-login"), err = $("#login-err"); err.hidden = true; UI.busy(btn, true);
      try { await Api.login(f.email.value.trim(), f.password.value, f.totp.value.trim()); await App.afterLogin(); }
      catch (ex) {
        if (ex.code === "totp") { $("#totp-field").hidden = false; f.totp.focus(); err.textContent = t("login.totp_req"); }
        else err.textContent = ex.status === 429 ? t("login.locked") : (ex.status === 401 ? t("login.bad") : (ex.status === 403 ? t("login.notcustomer") : UI.errMsg(ex)));
        err.hidden = false;
      } finally { UI.busy(btn, false); }
    };
  }
  function offerBiometrics() {
    return new Promise(async res => {
      if (!(await Native.biometricAvailable())) return res(false);
      let asked = null; try { asked = localStorage.getItem("gcu.bio_asked"); } catch (e) {}
      if (asked) return res(false);
      render(`<div class="auth"><h1>${esc(t("bio.title"))}</h1><p class="lede">${esc(t("bio.lede"))}</p><button class="btn gold" id="bio-yes">${esc(t("bio.enable"))}</button><button class="btn ghost" id="bio-no">${esc(t("bio.skip"))}</button></div>`, "");
      const done = (on) => { try { localStorage.setItem("gcu.bio_asked", "1"); localStorage.setItem("gcu.bio_on", on ? "1" : "0"); } catch (e) {} res(on); };
      $("#bio-yes").onclick = async () => { const ok = await Native.biometricVerify(t("bio.enable")); if (!ok) UI.toast(t("bio.failed")); done(ok); };
      $("#bio-no").onclick = () => done(false);
    });
  }
  const bioOn = () => { try { return localStorage.getItem("gcu.bio_on") === "1"; } catch (e) { return false; } };
  async function unlockGate() {
    if (!bioOn()) return true;
    document.body.classList.add("hidden-nav");
    return new Promise(res => {
      render(`<div class="auth"><img class="logo" src="img/logo.png" alt=""><h1 style="text-align:center">${esc(t("app.name"))}</h1><button class="btn gold" id="bio-unlock">${esc(t("bio.unlock"))}</button><button class="btn ghost" id="bio-out">${esc(t("acc.signout"))}</button></div>`, "");
      const tryIt = async () => { if (await Native.biometricVerify(t("bio.unlock"))) res(true); else UI.toast(t("bio.failed")); };
      $("#bio-unlock").onclick = tryIt; $("#bio-out").onclick = async () => { await Api.logout(); res(false); App.go("#/login"); };
      setTimeout(tryIt, 300);
    });
  }
  /** Second factor before a signature / decision: device biometric when enabled, then the authenticator code when the account has 2FA.
   *  Resolves { totp } (totp may be "" when 2FA is off) or null when cancelled. */
  async function secondFactor() {
    if (bioOn()) { const ok = await Native.biometricVerify(t("otp.title")); if (!ok) { UI.toast(t("bio.failed")); return null; } }
    const u = (Api.me && Api.me.user) || {}; if (!u.totp_enabled) return { totp: "" };
    return new Promise(res => {
      const s = UI.sheet(`<h1>${esc(t("otp.title"))}</h1><p class="lede">${esc(t("otp.lede"))}</p>
        <div class="otp">${[0, 1, 2, 3, 4, 5].map(i => `<input inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="digit ${i + 1}" autocomplete="one-time-code">`).join("")}</div>
        <button class="btn gold" id="otp-ok">${esc(t("otp.confirm"))}</button><button class="btn ghost" id="otp-cancel">${esc(t("app.cancel"))}</button>`);
      const inputs = Array.from(s.querySelectorAll(".otp input"));
      inputs.forEach((inp, i) => { inp.oninput = () => { inp.value = inp.value.replace(/\D/g, "").slice(-1); if (inp.value && inputs[i + 1]) inputs[i + 1].focus(); if (i === 5 && inp.value) s.querySelector("#otp-ok").click(); }; inp.onkeydown = e => { if (e.key === "Backspace" && !inp.value && inputs[i - 1]) inputs[i - 1].focus(); }; inp.onpaste = e => { const v = (e.clipboardData.getData("text") || "").replace(/\D/g, "").slice(0, 6); if (v.length === 6) { e.preventDefault(); inputs.forEach((x, k) => x.value = v[k]); s.querySelector("#otp-ok").click(); } }; });
      setTimeout(() => inputs[0].focus(), 100);
      s.querySelector("#otp-ok").onclick = () => { const code = inputs.map(i => i.value).join(""); if (code.length < 6) return; s.close(); res({ totp: code }); };
      s.querySelector("#otp-cancel").onclick = () => { s.close(); res(null); };
    });
  }
  return { login, offerBiometrics, unlockGate, secondFactor, bioOn };
})();
