/* Golden A/S Employee app — screens: sign-in, lock, More. My HR is js/hr.js. Built from the Service app shell (GAS-CP-R97-180). */
window.SCREENS = (() => {
  const t = I18N.t;
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const fmt = (iso) => { if (!iso) return ""; const [y, m, d] = String(iso).slice(0, 10).split("-"); return `${d}/${m}/${y}`; };
  const today = () => new Date().toISOString().slice(0, 10);
  const ctx = {};
  async function load() { ctx.me = (await DB.get("meta", "me")) || {}; }

  // ---------------------------------------------------------------- S-01 login
  async function login() {
    UI.chrome(false);
    return { html: `<div class="login">
      <img src="img/logo.png" alt="Golden A/S">
      <h2>${t("login.title")}</h2>
      <label>${t("login.portal")}</label><input readonly value="goldensas.com/customer${API.mock ? " (demo data)" : ""}">
      <label>${t("login.email")}</label><input id="email" type="email" autocomplete="username" value="">
      <label>${t("login.password")}</label><input id="pw" type="password" autocomplete="current-password" value="">
      <div id="npwrow" class="hidden"><label>${t("pwc.new")}</label><input id="npw" type="password" autocomplete="new-password" maxlength="200"><label>${t("pwc.again")}</label><input id="npw2" type="password" autocomplete="new-password" maxlength="200"><p class="muted">${t("pwc.rules")}</p></div>
      <div id="totprow" class="hidden"><label>${t("login.totp")}</label><input id="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
      <p id="err" class="note red hidden"></p>
      <button class="btn gold" id="go">${t("login.signin")}</button>
      <button class="btn line" id="bio" ${API.signedIn ? "" : "disabled"}>${t("login.bio")}</button>${API.signedIn ? "" : (API.passkeyCapable ? `<button class="btn line" id="pkin">🔑 ${t("login.passkey")}</button>` : `<p class="muted" style="text-align:center;margin-top:-6px">${t("login.bio_hint")}</p>`)}
      <h2>${t("login.lang")}</h2><div class="lang">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}" class="${I18N.get() === c ? "on" : ""}">${l}</button>`).join("")}</div>
      <p class="muted" style="text-align:center;margin-top:16px">${ctx.me.home_branch || "GR"} • Golden A/S ${ctx.me.home_branch === "GIT" ? "Italian Branch" : "head office"} • v${API.VERSION}</p>
    </div>`, bind(root) {
      root.querySelectorAll(".lang button").forEach((b) => b.onclick = () => { I18N.set(b.dataset.l); UI.render(); });
      root.querySelector("#go").onclick = async () => {
        const b = root.querySelector("#go"); b.disabled = true; const er = root.querySelector("#err");
        const nrow = root.querySelector("#npwrow"), npw = nrow.classList.contains("hidden") ? "" : root.querySelector("#npw").value; if (npw && npw !== root.querySelector("#npw2").value) { er.textContent = t("pwc.nomatch"); er.classList.remove("hidden"); b.disabled = false; return; }
        try { await API.login(root.querySelector("#email").value.trim(), root.querySelector("#pw").value, root.querySelector("#totp").value.trim(), npw); await SYNC.run(); location.hash = "#/hr"; UI.securityAsk(); }
        catch (e) { const d = e.data || {}; const totp = d.totp === true || d.code === "totp_required";
          if (d.password_change) { root.querySelector("#npwrow").classList.remove("hidden"); er.textContent = e.status === 422 && d.error ? d.error : t("pwc.hint"); er.classList.remove("hidden"); root.querySelector("#npw").focus(); b.disabled = false; return; }
          if (totp) { root.querySelector("#totprow").classList.remove("hidden"); er.textContent = root.querySelector("#totp").value ? t("login.totpbad") : t("login.totphint"); root.querySelector("#totp").focus(); }
          else er.textContent = e.status === 402 ? t("login.nolicence") : e.status === 403 ? (d.error || t("login.staffonly")) : e.status === 423 ? t("login.locked") : e.status === 426 ? t("login.update") : e.status === 429 ? t("login.ratelimit") : e.status === 401 ? t("login.failed") : `${t("login.failed")} (${e.status || "offline"})`; er.classList.remove("hidden"); b.disabled = false; }
      };
      root.querySelector("#bio").onclick = async () => { if (await UI.biometric()) { await SYNC.run(); location.hash = "#/hr"; } };
      const pkb = root.querySelector("#pkin"); if (pkb) pkb.onclick = async () => { pkb.disabled = true; try { await API.passkeyLogin(); await SYNC.run(); location.hash = "#/hr"; } catch (e) { root.querySelector("#err").textContent = e && e.name === "NotAllowedError" ? t("pk.failed") : (e.data && e.data.error) || e.message || t("pk.failed"); root.querySelector("#err").classList.remove("hidden"); pkb.disabled = false; } };
    } };
  }

  // ---------------------------------------------------------------- lock screen (R70-005): the session stays on the device, Face ID / fingerprint opens it again
  function lock() {
    UI.chrome(false);
    return { html: `<div class="login"><img class="logo" src="img/logo.png" alt=""><h1 style="text-align:center">${t("lock.title")}</h1><p class="muted" style="text-align:center">${t("lock.lede")}</p>
      <button class="btn gold" id="unlock">${t("lock.unlock")}</button><button class="btn line" id="out">${t("lock.signout")}</button></div>`, bind(root) {
      const go = async () => { if (await UI.biometric()) { try { localStorage.removeItem("gem.locked"); } catch (e) {} location.hash = "#/hr"; } };
      root.querySelector("#unlock").onclick = go; root.querySelector("#out").onclick = async () => { await API.logout(); await DB.wipe(); try { localStorage.removeItem("gem.locked"); } catch (e) {} location.hash = "#/login"; };
      if (!API.mock) setTimeout(go, 300);   // on a phone the Face ID prompt opens by itself
    } };
  }
  // ---------------------------------------------------------------- S-14 more / settings
  async function more() {
    let bio = false; try { bio = localStorage.getItem("gem.bio") === "1"; } catch (e) {}
    UI.chrome(true, t("tab.more"), "more");
    const ob = await DB.outbox();
    const hr = await DB.get("meta", "hr"); const hrUnread = hr ? hr.messages.filter((m) => m.from_hr && !m.seen).length : 0;
    const plSum = window.PLAN ? await PLAN.summary() : "";   // R97-221
    return { html: `${window.TOOLS ? TOOLS.moreCard() : "" /* R97-291 */}<div class="card tap c-gold" data-go="#/io"><div class="t">⏱ ${t("io.title")}</div><div class="s muted">${t("io.hint")}</div></div>
<div class="card tap c-gold" data-go="#/plan"><div class="t">📅 ${t("pl.title")}</div><div class="s muted">${plSum ? esc(plSum) : t("pl.hint")}</div></div>
<div class="card tap c-gold" data-go="#/hr"><div class="t">🏖 ${t("hr.title")}${hrUnread ? ` <small>${hrUnread} ${t("hr.new")}</small>` : ""}</div><div class="s muted">${hr ? `${t("hr.available")}: ${hr.balances.filter((b) => !b.by_law && b.available != null && !b.via_annual).slice(0, 3).map((b) => `${b.code} ${b.available}`).join(" • ")}` : t("hr.none")}</div></div>
      <h2>${t("more.settings")}</h2>
      <div class="kv"><b>${t("set.account")}</b><span class="v">${esc(ctx.me.name || "")}</span></div><div class="kv"><b>${t("set.branch")}</b><span class="v">${esc(ctx.me.home_branch)} ${ctx.me.home_branch === "GIT" ? "— GOLDEN AS ITALIAN" : "— Golden A/S Keratsini"}</span></div>
      <div class="kv"><b>${t("set.version")}</b><span class="v">${API.VERSION}${API.mock ? " (demo)" : ""}${ctx.me.min_app_version ? ` • portal min ${esc(ctx.me.min_app_version)}` : ""}${window.PUSH && PUSH.label() ? `<br><small>${esc(PUSH.label())}</small>` : ""}</span></div><div class="kv"><b>${t("more.queue")}</b><span class="v">${ob.length}${ob.filter((o) => o.status === "rejected").length ? ` (${ob.filter((o) => o.status === "rejected").length} ${t("st.rejected").toLowerCase()})` : ""}</span></div>
      <label>${t("set.lang")}</label><div class="lang">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}" class="${I18N.get() === c ? "on" : ""}">${l}</button>`).join("")}</div>
      <label>${t("more.bio")}</label><div class="seg" data-k="bio"><button data-v="1" class="${bio ? "on" : ""}">${t("more.bio_on")}</button><button data-v="0" class="${bio ? "" : "on"}">${t("more.bio_off")}</button></div>
      ${SYNC.state.error ? `<div class="note red">${t("sync.err")}: ${esc(SYNC.state.error)}</div>` : ""}
      ${ob.filter((o) => o.status === "rejected").map((o) => `<div class="note red">${esc(o.kind)} — ${esc(o.error)} <button class="btn red sm" data-drop="${o.id}">${t("more.discard")}</button></div>`).join("")}
      <button class="btn navy" id="sync">${t("more.sync")}</button><h2>🔑 ${t("pk.title")}</h2><p class="muted">${t("pk.hint")}</p><div id="pklist" class="muted">…</div>${API.passkeyCapable ? `<label>${t("pk.name")}</label><input id="pkname" maxlength="80" placeholder="iPhone"><div id="pkcoderow" class="hidden"><label>${t("pk.code")}</label><input id="pkcode" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></div><button class="btn line" id="pkadd">+ ${t("pk.add")}</button>` : `<p class="muted">${t("pk.unsupported")}</p>`}
      <button class="btn line" id="lock">🔒 ${t("more.lock")}</button><button class="btn line" id="out">${t("more.signout")}</button>`, bind(root) {
      UI.goLinks(root); UI.segs(root, (k, v) => { try { localStorage.setItem("gem.bio", v); } catch (e) {} }); root.querySelectorAll(".lang button").forEach((b) => b.onclick = () => { I18N.set(b.dataset.l); I18N.apply(); SYNC.refreshCount(); UI.render(); });
      root.querySelector("#sync").onclick = () => SYNC.run().then(() => UI.render());
      root.querySelectorAll("[data-drop]").forEach((b) => b.onclick = async () => { await DB.del("outbox", b.dataset.drop); UI.render(); });
      root.querySelector("#out").onclick = async () => { await API.logout(); await DB.wipe(); location.hash = "#/login"; };
      const pkRender = async () => { const el = root.querySelector("#pklist"); if (!el) return; try { const r = await API.passkeys(); el.innerHTML = r.items.length ? r.items.map((k) => `<div class="kv"><b>${esc(k.name)}</b><span class="v">${k.last_used_at ? esc(k.last_used_at.slice(0, 10)) : "—"} <button class="btn red sm" data-pkrm="${k.id}">${t("pk.remove")}</button></span></div>`).join("") : t("pk.none"); el.querySelectorAll("[data-pkrm]").forEach((b) => b.onclick = async () => { try { await API.passkeyRemove(b.dataset.pkrm); } catch (e) {} pkRender(); }); } catch (e) { el.textContent = (e.data && e.data.error) || "—"; } };
      if (!API.mock) pkRender(); else { const el = root.querySelector("#pklist"); if (el) el.textContent = t("pk.none"); }
      const pka = root.querySelector("#pkadd"); if (pka) pka.onclick = async () => { pka.disabled = true; try { await API.passkeyRegister(root.querySelector("#pkname").value.trim(), root.querySelector("#pkcode").value.trim()); UI.toast(t("pk.added")); pkRender(); } catch (e) { if (e.data && e.data.totp) root.querySelector("#pkcoderow").classList.remove("hidden"); UI.toast(e && e.name === "InvalidStateError" ? t("pk.added") : (e.data && e.data.error) || e.message || t("pk.failed")); } pka.disabled = false; };
      root.querySelector("#lock").onclick = async () => { if (!(await UI.biometricAvailable())) { UI.toast(t("lock.need_bio")); await API.logout(); await DB.wipe(); location.hash = "#/login"; return; } try { localStorage.setItem("gem.locked", "1"); } catch (e) {} location.hash = "#/lock"; };
    } };
  }

  return { load, ctx, esc, fmt, today, login, lock, more };
})();
