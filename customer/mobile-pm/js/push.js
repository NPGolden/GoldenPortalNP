/* Push notifications in the store builds (GAS-CP-R97-197). Loaded by every app client; does nothing in a browser and
 * nothing in a store build made before push was configured (those builds do not carry the "GASPush/1" mark).
 * After sign-in: asks the permission once, registers with Apple / Google and hands the device token to the portal.
 * The token is sent again after a new sign-in; a tap on a notification opens the screen the portal named.
 * R97-198: window.PUSH.label() names the installed store build and where the phone stands — shown beside the version on the settings screen. */
(function () {
  var st = "", shell = "", upd = "", C = window.Capacitor;
  window.PUSH = { label: function () { return [shell ? "store build " + shell : "", upd ? "version " + upd + " is in the store" : "", st ? "notifications: " + st : ""].filter(Boolean).join(" · "); }, get state() { return st; } };
  if (!C || !C.isNativePlatform || !C.isNativePlatform()) return;
  try { var A = (C.Plugins || {}).App; if (A && A.getInfo) A.getInfo().then(function (i) { shell = String(i.version || "") + " (" + String(i.build || "") + ")"; store(String(i.version || "")); }).catch(function () {}); } catch (e) {}
  /* R97-200: the portal knows which version is in the store; an older installed build offers the update (once a day at most after "Later"). */
  function older(a, b) { var x = a.split("."), y = b.split("."); for (var i = 0; i < Math.max(x.length, y.length); i++) { var p = parseInt(x[i] || "0", 10), q = parseInt(y[i] || "0", 10); if (p !== q) return p < q; } return false; }
  function store(mine) {
    var app = { "mobile": "engineer", "mobile-pm": "pm", "mobile-hr": "hr", "mobile-employee": "employee", "mobile-customer": "customer" }[(location.pathname.match(/\/(mobile(?:-[a-z]+)?)\//) || [])[1]];
    var meta = document.querySelector('meta[name="gas-api"]'), base = meta && meta.content, ver = (window.API && API.VERSION) || (window.Api && Api.APP_VERSION) || "";
    if (!app || !base || !/^\d+(\.\d+)+$/.test(mine) || location.protocol !== "https:") return;
    try { if (Date.now() - parseInt(localStorage.getItem("gas.upd.later") || "0", 10) < 86400000) return; } catch (e) {}
    fetch(base + "/store?platform=" + (C.getPlatform() === "ios" ? "ios" : "android"), { headers: { "X-App": app, "X-App-Version": ver }, cache: "no-store" }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      if (!d || typeof d.version !== "string" || !/^\d+(\.\d+)+$/.test(d.version) || !older(mine, d.version)) return;
      if (typeof d.url !== "string" || !/^https:\/\/(apps\.apple\.com|play\.google\.com)\//.test(d.url)) return;
      upd = d.version; banner(d.version, d.url);
    }).catch(function () {});
  }
  function banner(v, url) {
    if (document.getElementById("gas-upd")) return;
    var lang = "en"; try { lang = (window.I18N && I18N.get && I18N.get()) || document.documentElement.lang || "en"; } catch (e) {}
    var T = { en: ["A new version of the app is available", "Update", "Later"], el: ["Υπάρχει νέα έκδοση της εφαρμογής", "Ενημέρωση", "Αργότερα"], it: ["È disponibile una nuova versione dell'app", "Aggiorna", "Più tardi"], bg: ["Налична е нова версия на приложението", "Актуализиране", "По-късно"], uk: ["Доступна нова версія застосунку", "Оновити", "Пізніше"] }[lang] || null; if (!T) T = ["A new version of the app is available", "Update", "Later"];
    var b = document.createElement("div"); b.id = "gas-upd"; b.setAttribute("role", "status");
    b.style.cssText = "position:fixed;left:0;right:0;top:0;z-index:99999;display:flex;gap:10px;align-items:center;justify-content:space-between;padding:calc(env(safe-area-inset-top, 0px) + 8px) 12px 8px;background:#1F3A5F;color:#fff;font:14px/1.3 system-ui,-apple-system,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.25)";
    var s = document.createElement("span"); s.textContent = T[0] + " (" + v + ")"; s.style.cssText = "flex:1;min-width:0";
    var a = document.createElement("a"); a.href = url; a.target = "_blank"; a.rel = "noopener"; a.textContent = T[1]; a.style.cssText = "background:#C9A227;color:#1F3A5F;font-weight:700;text-decoration:none;padding:7px 12px;border-radius:8px;white-space:nowrap";
    var x = document.createElement("button"); x.type = "button"; x.textContent = T[2]; x.style.cssText = "background:none;border:0;color:#fff;opacity:.85;font:inherit;padding:7px 4px;white-space:nowrap";
    x.addEventListener("click", function () { try { localStorage.setItem("gas.upd.later", String(Date.now())); } catch (e) {} b.remove(); });
    b.appendChild(s); b.appendChild(a); b.appendChild(x); (document.body || document.documentElement).appendChild(b);
  }
  if (!/GASPush\/1/.test(navigator.userAgent)) { st = "not in this build — update the app from the store"; return; }
  var PN = (C.Plugins || {}).PushNotifications; if (!PN) { st = "plug-in missing"; return; }
  var tok = null, sent = null, asked = false, busy = false, svc = C.getPlatform() === "ios" ? "apns" : "fcm";
  function signed() { try { if (window.API && "signedIn" in API) return !!API.signedIn; if (window.Api && Api.hasSession) return !!Api.hasSession(); } catch (e) {} return false; }
  function post() { if (window.API && API.pushToken) return API.pushToken(tok, svc); if (window.Api && Api.call) return Api.call("POST", "/devices/push-token", { token: tok, service: svc }); return Promise.reject(new Error("no api")); }
  function send() { if (!tok || tok === sent || busy || !signed()) return; busy = true; var t = tok; Promise.resolve().then(post).then(function () { sent = t; st = "on"; }).catch(function (e) { st = "token not accepted by the portal (" + String((e && (e.status || e.message)) || "error").slice(0, 60) + ")"; }).then(function () { busy = false; }); }
  function ask() { if (asked || !signed()) return; asked = true; st = "asking permission";
    PN.requestPermissions().then(function (r) { if (r && r.receive === "granted") { st = "registering"; return PN.register(); } st = "not allowed — switch on in the settings of the phone"; })
      .catch(function (e) { asked = false; st = "error (" + String((e && e.message) || e).slice(0, 60) + ")"; }); }
  function tick() { if (!signed()) { sent = null; st = "after sign-in"; return; } ask(); send(); }
  try {
    PN.addListener("registration", function (t) { if (t && t.value) { tok = String(t.value); st = "sending token"; send(); } });
    PN.addListener("registrationError", function (e) { st = "registration refused (" + String((e && e.error) || "").slice(0, 80) + ")"; });
    PN.addListener("pushNotificationActionPerformed", function (n) { try { var r = n && n.notification && n.notification.data && n.notification.data.route; if (typeof r === "string" && /^#\/[\w\/?=&-]*$/.test(r)) location.hash = r; } catch (e) {} });
  } catch (e) { st = "error (" + String(e.message || e).slice(0, 60) + ")"; return; }
  st = "starting"; setTimeout(tick, 2500); setInterval(tick, 20000);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) tick(); });
})();
