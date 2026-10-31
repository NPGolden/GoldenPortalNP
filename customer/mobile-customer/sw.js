/* sw.js — precache the app shell; never cache the API (data is cached by the app in IndexedDB, under the user's control). */
const VERSION = "gas-customer-1.0.0";
const SHELL = ["./", "index.html", "styles.css", "app.js", "manifest.webmanifest", "i18n/i18n.js", "lib/store.js", "lib/api.js", "lib/ui.js", "lib/pdfview.js", "lib/sigpad.js", "lib/native.js",
  "screens/auth.js", "screens/home.js", "screens/sign.js", "screens/approvals.js", "screens/offers.js", "screens/tickets.js", "screens/more.js", "img/logo.png", "img/logo-white.png", "icons/icon-192.png", "icons/icon-512.png"];
self.addEventListener("install", e => { e.waitUntil(caches.open(VERSION).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener("activate", e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener("fetch", e => {
  const u = new URL(e.request.url);
  if (e.request.method !== "GET" || u.pathname.includes("/api/")) return; // API: network only
  if (u.origin !== location.origin) return; // pdf.js from cdnjs: browser HTTP cache
  e.respondWith(caches.match(e.request).then(r => r || fetch(e.request).then(res => { if (res.ok) { const copy = res.clone(); caches.open(VERSION).then(c => c.put(e.request, copy)); } return res; }).catch(() => caches.match("index.html"))));
});
self.addEventListener("push", e => { // FCM web push (PWA fallback); store apps use the native plug-in
  let d = {}; try { d = e.data.json(); } catch (x) {}
  e.waitUntil(self.registration.showNotification(d.title || "Golden A/S", { body: d.body || "", icon: "icons/icon-192.png", data: { url: d.url || "#/home" } }));
});
self.addEventListener("notificationclick", e => { e.notification.close(); e.waitUntil(clients.openWindow("./" + (e.notification.data.url || "#/home"))); });
