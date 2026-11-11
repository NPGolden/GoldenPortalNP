/* Entry page (GAS-CP-R97-013). The portal signs a person out when its last tab has been gone for 45 s ("the browser was closed").
   Someone who steps from the portal to this page and back has not closed the browser: while this page is open and the portal's
   window marker was still fresh on arrival, keep it fresh. A marker that was already stale is left alone — the portal then asks to sign in. */
(function () {
  var LS = 'gascp_w';
  function read() { try { return JSON.parse(localStorage.getItem(LS) || 'null'); } catch (e) { return null; } }
  var m = read(); if (!m || !m.k || Date.now() - m.t > 45000) return;
  function write() { var c = read(); if (!c || !c.k) return; try { localStorage.setItem(LS, JSON.stringify({ k: c.k, t: Date.now() })); } catch (e) {} }
  write(); setInterval(write, 10000);
  ['focus', 'pageshow', 'visibilitychange'].forEach(function (ev) { window.addEventListener(ev, write); });
})();
