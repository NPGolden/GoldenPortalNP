/* Golden PM — service worker
 * Precache the shell; never cache the API (Cache-Control: no-store on the server as well).
 * Bump VERSION with every portal release: the old cache is dropped on activate.
 */
const VERSION = "1.0.2";
const SHELL = `gas-pm-v${VERSION}`;
const IMAGES = "gas-pm-images";
const ASSETS = [
  "./", "./index.html", "./manifest.webmanifest",
  `./css/app.css?v=${VERSION}`,
  `./js/i18n.js?v=${VERSION}`, `./js/db.js?v=${VERSION}`, `./js/api.js?v=${VERSION}`,
  `./js/sync.js?v=${VERSION}`, `./js/screens.js?v=${VERSION}`, `./js/hr.js?v=${VERSION}`, `./js/assist.js?v=${VERSION}`, `./js/app.js?v=${VERSION}`,
  "./img/logo.png", "./img/logo-white.png", "./icons/icon-192.png", "./icons/icon-512.png", "./icons/icon-180.png",
];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(SHELL).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith("gas-pm-v") && k !== SHELL).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET") return;                       // writes always go to the network (outbox handles offline)
  if (url.pathname.includes("/api/")) return;                   // API is never served from cache
  if (url.pathname.match(/\/files\/|\/documents\//)) {           // documents and photos: cache on first open
    e.respondWith(caches.open(IMAGES).then(async (c) => {
      const hit = await c.match(e.request); if (hit) return hit;
      const res = await fetch(e.request); if (res.ok) c.put(e.request, res.clone()); return res;
    }));
    return;
  }
  // shell: cache first, network fallback, navigation falls back to index
  e.respondWith(caches.match(e.request, { ignoreSearch: false }).then((hit) => hit || fetch(e.request).catch(() => {
    if (e.request.mode === "navigate") return caches.match("./index.html");
  })));
});

// background sync (Chrome / Android); iOS falls back to sync on app start / online event
self.addEventListener("sync", (e) => {
  if (e.tag === "gas-outbox") e.waitUntil(self.clients.matchAll().then((cs) => cs.forEach((c) => c.postMessage({ type: "flush" }))));
});

self.addEventListener("push", (e) => {
  const d = e.data ? e.data.json() : { title: "Golden PM", body: "" };
  e.waitUntil(self.registration.showNotification(d.title, { body: d.body, icon: "./icons/icon-192.png", data: d }));
});
self.addEventListener("notificationclick", (e) => {
  e.notification.close();
  const route = (e.notification.data && e.notification.data.route) || "#/home";
  e.waitUntil(self.clients.matchAll({ type: "window" }).then((cs) => {
    if (cs.length) { cs[0].navigate(`./index.html${route}`); return cs[0].focus(); }
    return self.clients.openWindow(`./index.html${route}`);
  }));
});
