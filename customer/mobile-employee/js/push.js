/* Push notifications in the store builds (GAS-CP-R97-197). Loaded by every app client; does nothing in a browser and
 * nothing in a store build made before push was configured (those builds do not carry the "GASPush/1" mark).
 * After sign-in: asks the permission once, registers with Apple / Google and hands the device token to the portal.
 * The token is sent again after a new sign-in; a tap on a notification opens the screen the portal named. */
(function () {
  var C = window.Capacitor; if (!C || !C.isNativePlatform || !C.isNativePlatform()) return;
  if (!/GASPush\/1/.test(navigator.userAgent)) return;
  var PN = (C.Plugins || {}).PushNotifications; if (!PN) return;
  var tok = null, sent = null, asked = false, busy = false, svc = C.getPlatform() === "ios" ? "apns" : "fcm";
  function signed() { try { if (window.API && "signedIn" in API) return !!API.signedIn; if (window.Api && Api.hasSession) return !!Api.hasSession(); } catch (e) {} return false; }
  function post() { if (window.API && API.pushToken) return API.pushToken(tok, svc); if (window.Api && Api.call) return Api.call("POST", "/devices/push-token", { token: tok, service: svc }); return Promise.reject(new Error("no api")); }
  function send() { if (!tok || tok === sent || busy || !signed()) return; busy = true; var t = tok; Promise.resolve().then(post).then(function () { sent = t; }).catch(function () {}).then(function () { busy = false; }); }
  function ask() { if (asked || !signed()) return; asked = true; PN.requestPermissions().then(function (r) { if (r && r.receive === "granted") return PN.register(); }).catch(function () { asked = false; }); }
  function tick() { if (!signed()) { sent = null; return; } ask(); send(); }
  try {
    PN.addListener("registration", function (t) { if (t && t.value) { tok = String(t.value); send(); } });
    PN.addListener("pushNotificationActionPerformed", function (n) { try { var r = n && n.notification && n.notification.data && n.notification.data.route; if (typeof r === "string" && /^#\/[\w\/?=&-]*$/.test(r)) location.hash = r; } catch (e) {} });
  } catch (e) { return; }
  setTimeout(tick, 2500); setInterval(tick, 20000);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) tick(); });
})();
