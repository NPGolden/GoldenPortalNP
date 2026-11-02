/* Golden A/S apps — self-update (GAS-CP-R72-012). The service worker already installs the new shell and claims the page
   (skipWaiting + clients.claim); this makes the app notice it: it checks for a new sw.js on every foreground and every 30 min,
   and when the new worker takes control it reloads — at once when nothing is being edited, otherwise on the next screen change
   so a half-written report or message is never lost. */
window.UPD = (() => {
  let reg = null, pending = false, last = 0, reloading = false;
  const editing = () => { const a = document.activeElement; if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && a.type !== "search") return true; return !!document.querySelector("#save, #submit, #send, .sigpad canvas"); };
  const reload = () => { if (reloading) return; reloading = true; try { sessionStorage.setItem("gas.updated", "1"); } catch (e) {} location.reload(); };
  const maybe = () => { if (!pending) return; if (editing()) return; reload(); };
  const check = () => { const now = Date.now(); if (!reg || now - last < 60000) return; last = now; try { reg.update().catch(() => {}); } catch (e) {} };
  function init(r) {
    reg = r; if (!r) return;
    navigator.serviceWorker.addEventListener("controllerchange", () => { pending = true; maybe(); });
    window.addEventListener("hashchange", () => setTimeout(maybe, 300));
    document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible") { check(); setTimeout(maybe, 500); } });
    setInterval(check, 30 * 60 * 1000); setTimeout(check, 15000);
    try { if (sessionStorage.getItem("gas.updated") === "1") { sessionStorage.removeItem("gas.updated"); if (window.UI && UI.toast) setTimeout(() => UI.toast((window.I18N && I18N.t("upd.done") !== "upd.done") ? I18N.t("upd.done") : "App updated"), 800); } } catch (e) {}
  }
  return { init, check, get pending() { return pending; } };
})();
