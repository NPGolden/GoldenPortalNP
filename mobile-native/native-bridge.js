/* Loaded by the store builds only (injected into www/index.html by prepare.js). The portal clients run unchanged in the
 * browser; on a device this file adds what a web page cannot do: push-token registration, resume → sync, status bar
 * colour, splash hide. Plug-ins are reached through Capacitor.Plugins (registered by the Capacitor runtime). */
(function () {
  if (!window.Capacitor || !Capacitor.isNativePlatform || !Capacitor.isNativePlatform()) return;
  var P = Capacitor.Plugins || {};
  function pushToken(t) { try { if (window.API && API.pushToken) API.pushToken(t, Capacitor.getPlatform() === "ios" ? "apns" : "fcm"); } catch (e) {} }
  try { if (P.PushNotifications) { P.PushNotifications.requestPermissions().then(function (r) { if (r.receive === "granted") P.PushNotifications.register(); });
    P.PushNotifications.addListener("registration", function (t) { pushToken(t.value); });
    P.PushNotifications.addListener("pushNotificationActionPerformed", function (n) { var r = n.notification && n.notification.data && n.notification.data.route; if (r) location.hash = r; }); } } catch (e) {}
  try { if (P.App) P.App.addListener("appStateChange", function (s) { if (s.isActive && window.SYNC && SYNC.run) SYNC.run(); }); } catch (e) {}
  try { if (P.StatusBar) { P.StatusBar.setStyle({ style: "DARK" }); if (Capacitor.getPlatform() === "android") P.StatusBar.setBackgroundColor({ color: "#1F3A5F" }); } } catch (e) {}
  try { if (P.SplashScreen) setTimeout(function () { P.SplashScreen.hide(); }, 600); } catch (e) {}
})();
