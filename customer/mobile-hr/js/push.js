/* Push notifications in the store builds (GAS-CP-R97-197). Loaded by every app client; does nothing in a browser and
 * nothing in a store build made before push was configured (those builds do not carry the "GASPush/1" mark).
 * After sign-in: asks the permission once, registers with Apple / Google and hands the device token to the portal.
 * The token is sent again after a new sign-in; a tap on a notification opens the screen the portal named.
 * R97-198: window.PUSH.label() names the installed store build and where the phone stands — shown beside the version on the settings screen. */
(function () {
  var st = "", shell = "", C = window.Capacitor;
  window.PUSH = { label: function () { return [shell ? "store build " + shell : "", st ? "notifications: " + st : ""].filter(Boolean).join(" · "); }, get state() { return st; } };
  if (!C || !C.isNativePlatform || !C.isNativePlatform()) return;
  try { var A = (C.Plugins || {}).App; if (A && A.getInfo) A.getInfo().then(function (i) { shell = String(i.version || "") + " (" + String(i.build || "") + ")"; }).catch(function () {}); } catch (e) {}
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
