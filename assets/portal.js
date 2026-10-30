/* GOLDEN portal — shared behaviour. No dependencies.               GAS-CP-R4-003 */
(function () {
  'use strict';

  /* mobile nav */
  var t = document.getElementById('navtoggle');
  if (t) {
    t.addEventListener('click', function () {
      document.body.classList.toggle('nav-open');
      t.setAttribute('aria-expanded', document.body.classList.contains('nav-open') ? 'true' : 'false');
    });
    var s = document.querySelector('.scrim');
    if (s) s.addEventListener('click', function () { document.body.classList.remove('nav-open'); });
  }

  /* data-lock: one submit only — buttons disable, the clicked one shows the
     busy text (data-busy), so a slow server never produces duplicates */
  document.querySelectorAll('form[data-lock]').forEach(function (f) {
    var clicked = null;
    f.addEventListener('click', function (e) {
      var b = e.target.closest('button[type=submit], input[type=submit]');
      if (b) clicked = b;
    });
    f.addEventListener('submit', function (e) {
      if (f.dataset.confirm !== undefined && !f.__confirmed) return;   // the dialog decides first
      if (f.__locked) { e.preventDefault(); return; }
      f.__locked = true;
      var busy = f.dataset.busy || '';
      var btns = f.querySelectorAll('button[type=submit], input[type=submit]');
      // a named submit button must still reach the server: clone its value
      if (clicked && clicked.name) {
        var h = document.createElement('input'); h.type = 'hidden'; h.name = clicked.name; h.value = clicked.value; f.appendChild(h);
      }
      setTimeout(function () {
        btns.forEach(function (b) {
          b.disabled = true; b.setAttribute('aria-busy', 'true');
          if (b === clicked && busy && b.tagName === 'BUTTON') b.textContent = busy;
        });
      }, 0);
      setTimeout(function () { f.__locked = false; btns.forEach(function (b) { b.disabled = false; }); }, 30000);
    });
  });

  /* data-confirm: a styled confirmation dialog in the house colours,
     replacing the browser's plain confirm(). Attributes on the form:
       data-confirm="message"  data-confirm-title="…"  data-confirm-ok="Delete"
       data-confirm-danger (red confirm button) */
  var dlg = null;
  function getDialog() {
    if (dlg) return dlg;
    dlg = document.createElement('dialog');
    dlg.className = 'gas-confirm';
    dlg.innerHTML = '<form method="dialog"><h2></h2><p></p><div class="gas-confirm-actions">'
      + '<button type="button" class="btn btn--ghost" value="cancel"></button>'
      + '<button type="button" class="btn" value="ok"></button></div></form>';
    document.body.appendChild(dlg);
    return dlg;
  }
  /* the same dialog for scripts: gasConfirm({title, message, ok, cancel, danger}, onOk) */
  window.gasConfirm = function (o, onOk) {
    if (typeof HTMLDialogElement !== 'function') { if (window.confirm(o.message)) onOk(); return; }
    var d = getDialog();
    d.querySelector('h2').textContent = o.title || 'Please confirm';
    d.querySelector('p').textContent = (o.message || '').replace(/\\n/g, '\n');
    var ok = d.querySelector('button[value=ok]'), cancel = d.querySelector('button[value=cancel]');
    ok.textContent = o.ok || 'OK'; cancel.textContent = o.cancel || 'Cancel';
    ok.className = 'btn' + (o.danger ? ' btn--danger' : '');
    ok.onclick = function () { d.close(); onOk(); };
    cancel.onclick = function () { d.close(); };
    d.showModal(); cancel.focus();
  };
  /* a submit button with its own data-confirm (several buttons, several questions) */
  document.querySelectorAll('button[type=submit][data-confirm]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      var f = b.form; if (!f || f.__confirmed) return;
      e.preventDefault();
      if (typeof HTMLDialogElement !== 'function') { if (window.confirm(b.dataset.confirm)) { f.__confirmed = true; f.requestSubmit ? f.requestSubmit(b) : f.submit(); } return; }
      var d = getDialog();
      d.querySelector('h2').textContent = b.dataset.confirmTitle || 'Please confirm';
      d.querySelector('p').textContent = b.dataset.confirm.replace(/\\n/g, '\n');
      var ok = d.querySelector('button[value=ok]'), cancel = d.querySelector('button[value=cancel]');
      ok.textContent = b.dataset.confirmOk || 'OK'; cancel.textContent = b.dataset.confirmCancel || 'Cancel';
      ok.className = 'btn' + (b.hasAttribute('data-confirm-danger') ? ' btn--danger' : '');
      ok.onclick = function () { d.close(); f.__confirmed = true; f.requestSubmit ? f.requestSubmit(b) : f.submit(); };
      cancel.onclick = function () { d.close(); };
      d.showModal(); cancel.focus();
    });
  });

  /* language drop-down: switch on change (the form's no-script button is hidden with JS) */
  document.querySelectorAll('.js-langsel').forEach(function (sel) { sel.addEventListener('change', function () { sel.form.submit(); }); });

  /* comment threads: "Reply" fills parent_id and shows whom you answer; "Cancel" clears */
  document.querySelectorAll('.js-reply').forEach(function (b) {
    b.addEventListener('click', function () {
      var pid = document.getElementById('parent_id'), box = document.getElementById('reply-to'); if (!pid || !box) return;
      pid.value = b.dataset.reply; box.querySelector('b').textContent = b.dataset.author || ''; box.hidden = false;
      var ta = pid.form.querySelector('textarea'); if (ta) { ta.focus(); ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
  });
  document.querySelectorAll('.js-reply-cancel').forEach(function (b) {
    b.addEventListener('click', function () { var pid = document.getElementById('parent_id'), box = document.getElementById('reply-to'); if (pid) pid.value = ''; if (box) box.hidden = true; });
  });

  /* signature pad: <div data-sigpad="field"> + hidden input[name=field]; draws with
     mouse, pen or finger and stores a PNG data URL on submit */
  document.querySelectorAll('[data-sigpad]').forEach(function (box) {
    var name = box.dataset.sigpad, form = box.closest('form'), input = form ? form.querySelector('input[name="' + name + '"]') : null;
    var c = document.createElement('canvas'); c.width = 600; c.height = 180; c.className = 'sigpad';
    var clear = document.createElement('button'); clear.type = 'button'; clear.className = 'btn btn--ghost btn--sm'; clear.textContent = box.dataset.clear || 'Clear';
    box.appendChild(c); box.appendChild(clear);
    var ctx = c.getContext('2d'), drawing = false, drawn = false, last = null;
    ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#14285A';
    function pos(e) { var r = c.getBoundingClientRect(), t = e.touches ? e.touches[0] : e; return { x: (t.clientX - r.left) * c.width / r.width, y: (t.clientY - r.top) * c.height / r.height }; }
    function start(e) { drawing = true; last = pos(e); e.preventDefault(); }
    function move(e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; drawn = true; e.preventDefault(); }
    function end() { drawing = false; }
    c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
    c.addEventListener('touchstart', start, { passive: false }); c.addEventListener('touchmove', move, { passive: false }); c.addEventListener('touchend', end);
    clear.addEventListener('click', function () { ctx.clearRect(0, 0, c.width, c.height); drawn = false; if (input) input.value = ''; });
    if (form) form.addEventListener('submit', function () { if (input) input.value = drawn ? c.toDataURL('image/png') : ''; });
  });

  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.__confirmed) return;
      e.preventDefault();
      if (typeof HTMLDialogElement !== 'function') {            // very old browsers
        if (window.confirm(f.dataset.confirm)) { f.__confirmed = true; f.requestSubmit ? f.requestSubmit() : f.submit(); }
        return;
      }
      var d = getDialog();
      d.querySelector('h2').textContent = f.dataset.confirmTitle || 'Please confirm';
      d.querySelector('p').textContent = f.dataset.confirm.replace(/\\n/g, '\n');
      var ok = d.querySelector('button[value=ok]'), cancel = d.querySelector('button[value=cancel]');
      ok.textContent = f.dataset.confirmOk || 'OK';
      cancel.textContent = f.dataset.confirmCancel || 'Cancel';
      ok.className = 'btn' + (f.hasAttribute('data-confirm-danger') ? ' btn--danger' : '');
      ok.onclick = function () { d.close(); f.__confirmed = true; f.requestSubmit ? f.requestSubmit() : f.submit(); };
      cancel.onclick = function () { d.close(); };
      d.showModal();
      cancel.focus();
    });
  });
})();

/* R15: grouped menu — fold state per group and the compact (icon) rail are remembered per browser */
(function () {
  'use strict';
  var KEY = 'gas.rail';
  var st = {}; try { st = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { st = {}; }
  var save = function () { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} };
  document.querySelectorAll('.rail-group').forEach(function (g) {
    var k = g.dataset.group, here = g.nextElementSibling && g.nextElementSibling.querySelector('.rail-link.is-here');
    if (st['g_' + k] !== undefined && !here) g.classList.toggle('is-folded', !!st['g_' + k]);
    g.setAttribute('aria-expanded', g.classList.contains('is-folded') ? 'false' : 'true');
    g.addEventListener('click', function () { g.classList.toggle('is-folded'); st['g_' + k] = g.classList.contains('is-folded'); g.setAttribute('aria-expanded', st['g_' + k] ? 'false' : 'true'); save(); });
  });
  var c = document.getElementById('railcollapse');
  if (c) {
    var H = document.documentElement; if (st.compact) H.classList.add('rail-compact');
    c.addEventListener('click', function () { st.compact = !H.classList.contains('rail-compact'); H.classList.toggle('rail-compact', st.compact); save(); });
  }
})();

/* ---- R21-003 world clock strip: 24 h per zone, ticking every second, day offset vs the home zone ---- */
(function () {
  var strip = document.querySelector('.clockstrip'); if (!strip || !window.Intl) return;
  var home = strip.getAttribute('data-home') || 'UTC';
  var fmt = {}, dfmt = {}, wk = {};
  function tf(tz) { return fmt[tz] || (fmt[tz] = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })); }
  function df(tz) { return dfmt[tz] || (dfmt[tz] = new Intl.DateTimeFormat('en-GB', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' })); }
  function wf(tz) { return wk[tz] || (wk[tz] = new Intl.DateTimeFormat('en-GB', { timeZone: tz, weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })); }
  function dayNum(tz, now) { var p = df(tz).formatToParts(now), o = {}; p.forEach(function (x) { o[x.type] = x.value; }); return Date.UTC(+o.year, +o.month - 1, +o.day) / 86400000; }
  function isoWeek(tz, now) { var p = df(tz).formatToParts(now), o = {}; p.forEach(function (x) { o[x.type] = x.value; }); var d = new Date(Date.UTC(+o.year, +o.month - 1, +o.day)); var dn = d.getUTCDay() || 7; d.setUTCDate(d.getUTCDate() + 4 - dn); var y0 = new Date(Date.UTC(d.getUTCFullYear(), 0, 1)); return Math.ceil(((d - y0) / 86400000 + 1) / 7); }
  var items = [].slice.call(strip.querySelectorAll('.clk')), dateEl = strip.querySelector('.clk-date');
  function tick() {
    var now = new Date(), hd = dayNum(home, now);
    items.forEach(function (el) {
      var tz = el.getAttribute('data-tz'); try {
        el.querySelector('.t').textContent = tf(tz).format(now).replace(/^24/, '00');
        var diff = dayNum(tz, now) - hd; el.querySelector('.d').textContent = diff ? (diff > 0 ? '+1d' : '\u22121d') : '';
      } catch (e) { el.querySelector('.t').textContent = '--:--'; }
    });
    if (dateEl) { try { dateEl.textContent = wf(home).format(now) + ' \u00b7 W' + String(isoWeek(home, now)).padStart(2, '0'); } catch (e) {} }
  }
  tick(); setInterval(tick, 1000);
})();


/* ---- R22-003 sunrise / sunset and today's weather per zone: badge on the home zone, pop-over on click (Open-Meteo, no key, cached 30 min) ---- */
(function () {
  var strip = document.querySelector('.clockstrip'), pop = document.getElementById('clkpop'); if (!strip || !pop || !window.fetch) return;
  var L = {}; try { L = JSON.parse(strip.getAttribute('data-wx') || '{}'); } catch (e) {}
  var ICON = { 0: '☀️', 1: '🌤', 2: '⛅', 3: '☁️', 45: '🌫', 48: '🌫', 51: '🌦', 53: '🌦', 55: '🌦', 56: '🌦', 57: '🌦', 61: '🌧', 63: '🌧', 65: '🌧', 66: '🌧', 67: '🌧', 71: '🌨', 73: '🌨', 75: '🌨', 77: '🌨', 80: '🌦', 81: '🌧', 82: '🌧', 85: '🌨', 86: '🌨', 95: '⛈', 96: '⛈', 99: '⛈' };
  function label(c) { if (c === 0) return L.clear; if (c <= 2) return L.partly; if (c === 3) return L.cloudy; if (c < 50) return L.fog; if (c < 60) return L.drizzle; if (c < 70) return L.rain; if (c < 80) return L.snow; if (c < 85) return L.showers; if (c < 90) return L.snow; return L.storm; }
  function icon(c, night) { if (night && c <= 2) return c === 0 ? '🌙' : '☁️'; return ICON[c] || '☁️'; }
  function hm(s) { return s ? s.slice(11, 16) : '--:--'; }
  function dur(a, b) { var m = Math.round((new Date(b) - new Date(a)) / 60000); return Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0'); }
  function dirTxt(d) { return ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'][Math.round(d / 45) % 8]; }
  function load(el) {
    var tz = el.getAttribute('data-tz'), key = 'gas.wx.' + tz, now = Date.now(), c = null;
    try { c = JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) {}
    if (c && c.at && now - c.at < 30 * 60000 && c.d) return Promise.resolve(c.d);
    var u = 'https://api.open-meteo.com/v1/forecast?latitude=' + el.getAttribute('data-lat') + '&longitude=' + el.getAttribute('data-lon') + '&timezone=' + encodeURIComponent(tz)
          + '&current=temperature_2m,weather_code,wind_speed_10m,wind_direction_10m,is_day&hourly=temperature_2m,weather_code,is_day&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset&forecast_days=1';
    return fetch(u).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }).then(function (d) { try { localStorage.setItem(key, JSON.stringify({ at: now, d: d })); } catch (e) {} return d; });
  }
  function badge(el, d) { var w = el.querySelector('.w'); if (!w) return; w.textContent = icon(d.current.weather_code, !d.current.is_day) + ' ' + Math.round(d.current.temperature_2m) + '°'; w.hidden = false; }
  function render(el, d) {
    var cu = d.current, da = d.daily, hr = d.hourly, city = el.getAttribute('data-city'), tz = el.getAttribute('data-tz');
    var date = ''; try { date = new Intl.DateTimeFormat(document.documentElement.lang || 'en', { timeZone: tz, weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date()); } catch (e) {}
    var h = '<div class="cp-h"><b>' + city + '</b> · ' + date + '<button type="button" class="cp-x" aria-label="' + L.close + '">×</button></div>';
    h += '<div class="cp-r"><span>' + L.sunrise + ' / ' + L.sunset + '</span><b>☀️↑ ' + hm(da.sunrise[0]) + ' → ☀️↓ ' + hm(da.sunset[0]) + ' · ' + L.daylight + ' ' + dur(da.sunrise[0], da.sunset[0]) + '</b></div>';
    h += '<div class="cp-r"><span>' + L.now + '</span><b>' + icon(cu.weather_code, !cu.is_day) + ' ' + label(cu.weather_code) + ' · ' + Math.round(cu.temperature_2m) + '°C</b></div>';
    h += '<div class="cp-r"><span>' + L.today + '</span><b>' + Math.round(da.temperature_2m_min[0]) + '° → ' + Math.round(da.temperature_2m_max[0]) + '° · ' + L.rain_p + ' ' + (da.precipitation_probability_max[0] == null ? '–' : da.precipitation_probability_max[0] + '%') + ' · ' + L.wind + ' ' + Math.round(cu.wind_speed_10m) + ' km/h ' + dirTxt(cu.wind_direction_10m) + '</b></div>';
    h += '<div class="cp-hrs">';
    for (var i = 6; i <= 21; i += 3) if (hr.time[i]) h += '<div><span>' + hr.time[i].slice(11, 16) + '</span><em>' + icon(hr.weather_code[i], !hr.is_day[i]) + '</em><b>' + Math.round(hr.temperature_2m[i]) + '°</b></div>';
    h += '</div><div class="cp-src">' + L.source + '</div>';
    pop.innerHTML = h;
  }
  function place(el) { var r = el.getBoundingClientRect(), s = strip.getBoundingClientRect(); pop.style.top = (s.bottom + 6) + 'px'; pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - pop.offsetWidth - 8)) + 'px'; }
  var openFor = null;
  function close() { pop.hidden = true; openFor = null; strip.querySelectorAll('.clk.is-open').forEach(function (x) { x.classList.remove('is-open'); }); }
  function open(el) {
    if (openFor === el) return close();
    close(); openFor = el; el.classList.add('is-open');
    pop.innerHTML = '<div class="cp-h"><b>' + el.getAttribute('data-city') + '</b><button type="button" class="cp-x" aria-label="' + L.close + '">×</button></div><div class="cp-src">' + L.loading + '</div>';
    pop.hidden = false; place(el);
    load(el).then(function (d) { if (openFor !== el) return; render(el, d); badge(el, d); place(el); }).catch(function () { if (openFor === el) pop.innerHTML = '<div class="cp-h"><b>' + el.getAttribute('data-city') + '</b><button type="button" class="cp-x">×</button></div><div class="cp-src">' + L.error + '</div>'; });
  }
  strip.querySelectorAll('.clk[data-lat]').forEach(function (el) { el.addEventListener('click', function (e) { e.preventDefault(); open(el); }); });
  pop.addEventListener('click', function (e) { if (e.target.closest('.cp-x')) close(); });
  document.addEventListener('click', function (e) { if (!pop.hidden && !pop.contains(e.target) && !e.target.closest('.clk[data-lat]')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  window.addEventListener('resize', function () { if (openFor) place(openFor); });
  var home = strip.querySelector('.clk.is-home[data-lat]');
  if (home) { load(home).then(function (d) { badge(home, d); }).catch(function () {}); setInterval(function () { load(home).then(function (d) { badge(home, d); }).catch(function () {}); }, 30 * 60000); }
})();

/* ---- Presence heartbeat: a signed-in portal page reports every minute, so "online" means signed in and
        connected (page open); it stops when the server answers 401 (signed out / idle time-out).   GAS-CP-R24-002 */
(function () {
  if (!document.querySelector('.who')) return;                       // not signed in
  var stop = false;
  function beat() {
    if (stop || document.hidden && Math.random() < 0.5) return;        // hidden tabs still beat, half as often
    fetch('index.php?p=ping', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { if (r.status === 401) stop = true; }).catch(function () {});
  }
  setInterval(beat, 60000);
})();

/* ---- R49-001: sign out when the browser is closed. Every signed-in tab refreshes a window marker in localStorage every
        10 s. A page that loads with no fresh marker (browser closed and reopened, tabs restored, URL typed hours later)
        asks the server to drop the session; a new tab opened while another one is live inherits the marker. */
(function () {
  var b = document.body, key = b.getAttribute('data-wchk'); if (!key) return;
  var LS = 'gascp_w', now = function () { return Date.now(); }, ok = true;
  function read() { try { return JSON.parse(localStorage.getItem(LS) || 'null'); } catch (e) { return null; } }
  function write() { if (!ok) return; try { localStorage.setItem(LS, JSON.stringify({ k: key, t: now() })); } catch (e) {} }
  var m = read();
  if (b.getAttribute('data-wnew') === '1') write();
  else if (!m || m.k !== key || now() - m.t > 45000) {
    ok = false; try { localStorage.removeItem(LS); } catch (e) {}
    fetch('index.php?p=relock', { method: 'POST', credentials: 'same-origin', cache: 'no-store' }).catch(function () {}).then(function () { location.replace('index.php?p=login'); });
    return;
  }
  write(); setInterval(write, 10000);
  ['focus', 'pageshow', 'visibilitychange'].forEach(function (ev) { window.addEventListener(ev, write); });
})();

/* R29-006 — every table with a header sorts on click (ascending → descending), unless it is sorted by the server (data-sorted)
   or marked data-nosort. Numbers, dates (dd Mon yyyy, yyyy-mm-dd, dd/mm/yyyy) and text are recognised; paged tables sort the page shown. */
(function () {
  var MON = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12, ιαν: 1, φεβ: 2, μαρ: 3, απρ: 4, μαϊ: 5, μαι: 5, ιουν: 6, ιουλ: 7, αυγ: 8, σεπ: 9, οκτ: 10, νοε: 11, δεκ: 12 };
  function key(td) {
    var t = (td.getAttribute('data-sort') || td.textContent || '').trim(); if (t === '' || t === '—') return { n: null, s: '' };
    var m = t.match(/^(\d{4})-(\d{2})-(\d{2})/); if (m) return { n: +m[1] * 10000 + +m[2] * 100 + +m[3], s: t };
    m = t.match(/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})/); if (m) return { n: +m[3] * 10000 + +m[2] * 100 + +m[1], s: t };
    m = t.match(/^(\d{1,2})\s+([A-Za-zΑ-Ωα-ωίϊΐόάέύϋΰήώ]{3})[A-Za-zα-ω]*\.?\s+(\d{4})/); if (m && MON[m[2].toLowerCase()]) return { n: +m[3] * 10000 + MON[m[2].toLowerCase()] * 100 + +m[1], s: t };
    m = t.replace(/\s/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.').match(/^-?\d+(\.\d+)?/); if (m && /^-?[\d.,\s]+(\s*(%|d|h|€|EUR|MB|KB))?$/.test(t)) return { n: parseFloat(m[0]), s: t };
    return { n: null, s: t.toLowerCase() };
  }
  function sortTable(table, idx, dir) {
    var tbody = table.tBodies[0]; if (!tbody) return;
    var rows = Array.prototype.slice.call(tbody.rows).filter(function (r) { return !r.classList.contains('pager-row'); });
    if (rows.some(function (r) { return r.cells.length <= idx || Array.prototype.some.call(r.cells, function (c) { return c.rowSpan > 1 || c.colSpan > 1; }); })) return;
    rows.forEach(function (r, i) { r._k = key(r.cells[idx]); r._i = i; });
    rows.sort(function (a, b) { var x = a._k, y = b._k, c;
      if (x.n !== null && y.n !== null) c = x.n - y.n; else if (x.n !== null) c = -1; else if (y.n !== null) c = 1; else c = x.s.localeCompare(y.s, undefined, { numeric: true, sensitivity: 'base' });
      if (c === 0) c = a._i - b._i; return dir === 'desc' ? -c : c; });
    rows.forEach(function (r) { tbody.appendChild(r); });
  }
  document.querySelectorAll('table').forEach(function (table) {
    if (table.hasAttribute('data-sorted') || table.hasAttribute('data-nosort') || !table.tHead || !table.tBodies[0] || table.tBodies[0].rows.length < 2) return;
    var ths = table.tHead.rows[table.tHead.rows.length - 1].cells;
    Array.prototype.forEach.call(ths, function (th, idx) {
      if (!th.textContent.trim() || th.querySelector('input,select,a')) return;
      th.classList.add('th-sort'); th.tabIndex = 0; th.setAttribute('role', 'button');
      var ind = document.createElement('span'); ind.className = 'sort-ind'; ind.textContent = ' ⇅'; th.appendChild(ind);
      function go() { var dir = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
        Array.prototype.forEach.call(ths, function (o) { o.removeAttribute('data-dir'); o.classList.remove('is-on'); var s = o.querySelector('.sort-ind'); if (s) s.textContent = ' ⇅'; });
        th.setAttribute('data-dir', dir); th.classList.add('is-on'); ind.textContent = dir === 'asc' ? ' ▲' : ' ▼'; sortTable(table, idx, dir); }
      th.addEventListener('click', go); th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
    });
  });
})();

/* R29-008 — remembered list filters: when the server restored the previous selection, say so next to "Clear". */
(function () {
  if (!document.body || document.body.getAttribute('data-flt') !== '1') return;
  var bars = document.querySelectorAll('.fltbar');
  var txt = (document.documentElement.getAttribute('data-flt-restored') || 'Showing your previous selection');
  for (var i = 0; i < bars.length; i++) {
    var host = bars[i].querySelector('.fltbar-actions');
    if (!host) { var clr = bars[i].querySelector('a.btn--ghost[href*="reset=1"]'); host = clr ? clr.parentNode : null; }
    if (!host) continue;
    var s = document.createElement('span'); s.className = 'flt-restored'; s.textContent = txt; host.appendChild(s);
    var clr2 = bars[i].querySelector('a.btn--ghost[href*="reset=1"]');
    var b = document.createElement('div'); b.className = 'flt-banner'; b.setAttribute('role', 'status');
    b.innerHTML = '<span>↺ ' + txt.replace(/</g, '&lt;') + ' — ' + (document.documentElement.getAttribute('data-flt-hidden') || 'items outside these filters are not shown') + '</span>';
    if (clr2) { var a = document.createElement('a'); a.className = 'btn btn--sm'; a.href = clr2.getAttribute('href'); a.textContent = clr2.textContent; b.appendChild(a); }
    bars[i].parentNode.insertBefore(b, bars[i]);
  }
})();

/* R30-007 — searchable drop-downs: every <select> with more than 8 entries gets a type-to-filter list.
   The native select stays in the form (value, name, change events, validation); a button shows the choice and opens the panel. */
(function () {
  var MIN = 8, open = null, txt = document.documentElement.getAttribute('data-ss-search') || 'Type to search…', none = document.documentElement.getAttribute('data-ss-none') || 'No match';
  function optLabel(o) { return (o.textContent || '').replace(/\s+/g, ' ').trim(); }
  function enhance(sel) {
    if (sel.multiple || sel.dataset.ss || sel.hasAttribute('data-nosearch') || sel.closest('.pager, .pager-per, table.fsr-lines, table.acct')) return;
    if (sel.querySelectorAll('option').length <= MIN) return;
    sel.dataset.ss = '1'; sel.classList.add('ss-native'); sel.tabIndex = -1;
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'ss-btn'; btn.setAttribute('aria-haspopup', 'listbox');
    if (sel.style.minWidth) btn.style.minWidth = sel.style.minWidth; if (sel.style.width) btn.style.width = sel.style.width;
    var lab = document.createElement('span'); lab.className = 'ss-lab'; var car = document.createElement('span'); car.className = 'ss-car'; car.textContent = '▾';
    btn.appendChild(lab); btn.appendChild(car); sel.parentNode.insertBefore(btn, sel.nextSibling);
    function sync() { var o = sel.options[sel.selectedIndex]; lab.textContent = o ? optLabel(o) : ''; lab.classList.toggle('is-empty', !o || o.value === ''); btn.disabled = sel.disabled; btn.classList.toggle('is-invalid', false); }
    sync();
    new MutationObserver(function () { sync(); if (open && open.sel === sel) render(''); }).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden'] });
    sel.addEventListener('change', sync); sel.addEventListener('focus', function () { btn.focus(); });
    sel.addEventListener('invalid', function (e) { e.preventDefault(); btn.classList.add('is-invalid'); setTimeout(function () { btn.focus(); if (!open) show(); }, 0); });
    btn.addEventListener('click', function () { if (open && open.sel === sel) close(); else show(); });
    btn.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(); } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { show(); open.inp.value = e.key; render(e.key); e.preventDefault(); } });
    var panel, inp, list, items = [], cur = -1;
    function show() {
      close();
      panel = document.createElement('div'); panel.className = 'ss-panel'; panel.setAttribute('role', 'listbox');
      var sw = document.createElement('div'); sw.className = 'ss-search'; inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = txt; inp.setAttribute('aria-label', txt); sw.appendChild(inp); panel.appendChild(sw);
      list = document.createElement('div'); list.className = 'ss-list'; panel.appendChild(list);
      document.body.appendChild(panel); open = { sel: sel, panel: panel, btn: btn, inp: inp, place: place }; place(); render(''); inp.focus();
      inp.addEventListener('input', function () { render(inp.value); });
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); move(1); } else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Enter') { e.preventDefault(); if (items[cur]) pick(items[cur]); } else if (e.key === 'Escape') { e.preventDefault(); close(); btn.focus(); }
        else if (e.key === 'Tab') { close(); }
      });
    }
    function place() { var r = btn.getBoundingClientRect(); var w = Math.max(r.width, 260); panel.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px'; panel.style.width = w + 'px'; var below = window.innerHeight - r.bottom; panel.style.maxHeight = Math.max(160, Math.min(360, (below > 220 ? below : r.top) - 12)) + 'px'; if (below > 220) { panel.style.top = (r.bottom + 4) + 'px'; panel.style.bottom = ''; } else { panel.style.bottom = (window.innerHeight - r.top + 4) + 'px'; panel.style.top = ''; } }
    function render(q) {
      list.innerHTML = ''; items = []; cur = -1; var k = q.trim().toLowerCase(); var n = 0;
      Array.prototype.forEach.call(sel.options, function (o, i) {
        var l = optLabel(o); if (o.hidden) return; if (k && l.toLowerCase().indexOf(k) < 0) return; if (o.disabled && o.value === '') return;
        var d = document.createElement('div'); d.className = 'ss-item' + (o.selected ? ' is-sel' : '') + (o.value === '' ? ' is-empty' : ''); d.setAttribute('role', 'option'); d.dataset.i = i;
        if (k) { var p = l.toLowerCase().indexOf(k); d.appendChild(document.createTextNode(l.slice(0, p))); var m = document.createElement('mark'); m.textContent = l.slice(p, p + k.length); d.appendChild(m); d.appendChild(document.createTextNode(l.slice(p + k.length))); } else d.textContent = l;
        d.addEventListener('mousedown', function (e) { e.preventDefault(); pick(d); }); list.appendChild(d); items.push(d); n++;
        if (o.selected) cur = items.length - 1;
      });
      if (!n) { var e0 = document.createElement('div'); e0.className = 'ss-none'; e0.textContent = none; list.appendChild(e0); }
      if (cur < 0 && items.length) cur = k ? 0 : Math.max(0, items.findIndex(function (x) { return !x.classList.contains('is-empty'); }));
      hi();
    }
    function hi() { items.forEach(function (x, i) { x.classList.toggle('is-cur', i === cur); }); if (items[cur]) items[cur].scrollIntoView({ block: 'nearest' }); }
    function move(d) { if (!items.length) return; cur = (cur + d + items.length) % items.length; hi(); }
    function pick(d) { var i = +d.dataset.i; if (sel.selectedIndex !== i) { sel.selectedIndex = i; sel.dispatchEvent(new Event('change', { bubbles: true })); } sync(); close(); btn.focus(); }
    function close() { if (open && open.panel) { open.panel.remove(); } open = null; }
    sel._ssClose = close;
  }
  function closeOpen() { if (open) { open.panel.remove(); open = null; } }
  document.addEventListener('mousedown', function (e) { if (open && !open.panel.contains(e.target) && e.target !== open.btn && !open.btn.contains(e.target)) closeOpen(); });
  window.addEventListener('resize', closeOpen); window.addEventListener('scroll', function (e) { if (open && !(open.panel.contains(e.target))) open.place(); }, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) { var b = open.btn; closeOpen(); b.focus(); } });
  function scan(root) { (root.querySelectorAll ? root.querySelectorAll('select') : []).forEach(enhance); }
  scan(document);
  new MutationObserver(function (ms) { ms.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.tagName === 'SELECT') enhance(n); else scan(n); } }); }); }).observe(document.body, { childList: true, subtree: true });
})();

/* ---- @mentions in notes and comments (R31-007): type @ in a textarea[data-mt] and pick a colleague ---- */
(function () {
  var raw = document.documentElement.getAttribute('data-mt-users'); if (!raw) return;
  var users; try { users = JSON.parse(raw); } catch (e) { return; }
  if (!users || !users.length) return;
  var hint = document.documentElement.getAttribute('data-mt-hint') || '';
  function enhance(ta) {
    if (ta.dataset.mtOn) return; ta.dataset.mtOn = '1';
    var box = document.createElement('div'); box.className = 'mt-panel'; box.hidden = true; document.body.appendChild(box);
    var items = [], sel = 0, start = -1;
    if (hint && !ta.placeholder) ta.placeholder = hint;
    ta.setAttribute('autocomplete', 'off');
    function close() { box.hidden = true; items = []; start = -1; }
    function place() {
      var r = ta.getBoundingClientRect();
      box.style.left = (r.left + window.scrollX) + 'px'; box.style.minWidth = Math.min(r.width, 360) + 'px';
      var h = box.offsetHeight || 160, below = r.bottom + 2 + h <= window.innerHeight || r.top - h - 2 < 0;
      box.style.top = (below ? r.bottom + window.scrollY + 2 : r.top + window.scrollY - h - 2) + 'px';
    }
    function pick(i) {
      var u = items[i]; if (!u) return;
      var v = ta.value, end = ta.selectionStart;
      ta.value = v.slice(0, start) + '@' + u.h + ' ' + v.slice(end);
      var pos = start + u.h.length + 2; ta.setSelectionRange(pos, pos); ta.focus(); close();
    }
    function render() {
      box.innerHTML = '';
      items.forEach(function (u, i) {
        var d = document.createElement('div'); d.className = 'mt-item' + (i === sel ? ' is-sel' : '');
        d.innerHTML = '<b>@' + u.h + '</b> <span>' + u.n.replace(/[&<>]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c]; }) + '</span>';
        d.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
        box.appendChild(d);
      });
      box.hidden = !items.length; if (!box.hidden) place();
    }
    function update() {
      var pos = ta.selectionStart, v = ta.value.slice(0, pos), m = /(^|[^\w@.])@([a-z0-9._-]*)$/i.exec(v);
      if (!m) { close(); return; }
      start = pos - m[2].length - 1; var q = m[2].toLowerCase();
      var a = [], c = [];
      users.forEach(function (u) { if (!q || u.h.indexOf(q) === 0) a.push(u); else if (u.n.toLowerCase().split(/\s+/).some(function (w) { return w.indexOf(q) === 0; })) c.push(u); });
      items = a.concat(c).slice(0, 8);
      sel = 0; render();
    }
    ta.addEventListener('input', update);
    ta.addEventListener('click', update);
    ta.addEventListener('blur', function () { setTimeout(close, 150); });
    ta.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'ArrowDown') { sel = (sel + 1) % items.length; render(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { sel = (sel - 1 + items.length) % items.length; render(); e.preventDefault(); }
      else if (e.key === 'Enter' || e.key === 'Tab') { pick(sel); e.preventDefault(); }
      else if (e.key === 'Escape') { close(); e.preventDefault(); }
    });
    window.addEventListener('scroll', function () { if (!box.hidden) place(); }, true);
  }
  document.querySelectorAll('textarea[data-mt]').forEach(enhance);
  new MutationObserver(function () { document.querySelectorAll('textarea[data-mt]').forEach(enhance); }).observe(document.body, { childList: true, subtree: true });
})();

/* ---- E-mail card (R31-008): the "Mail app" button builds a mailto: from the form (no attachments) ---- */
(function () {
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.js-em-mailto'); if (!b) return;
    var f = b.closest('form'); if (!f) return;
    var to = Array.prototype.map.call(f.querySelectorAll('input[name="to[]"]:checked'), function (i) { return i.value; });
    var more = (f.querySelector('[name="to_more"]') || {}).value || ''; more.split(/[\s,;]+/).forEach(function (a) { if (a && a.indexOf('@') > 0) to.push(a); });
    var cc = ((f.querySelector('[name="cc"]') || {}).value || '').trim();
    var q = [];
    if (cc) q.push('cc=' + encodeURIComponent(cc));
    q.push('subject=' + encodeURIComponent((f.querySelector('[name="subject"]') || {}).value || ''));
    q.push('body=' + encodeURIComponent((f.querySelector('[name="body"]') || {}).value || ''));
    window.location.href = 'mailto:' + to.join(',') + '?' + q.join('&');
  });
})();

/* ---- Rich text toolbar (R37-001): bold / italic / underline / bullets / numbering above every textarea (not [data-plain]).
        Writes portable marks — **b**, _i_, __u__, "- " and "1. " lines — rendered by rt_html() on screen and cleaned for PDF / Word. ---- */
(function () {
  var L = {}; try { L = JSON.parse(document.documentElement.getAttribute('data-rt-labels') || '{}'); } catch (e) {}
  var defs = [['b', '<b>B</b>', L.b || 'Bold', '**', '**'], ['i', '<i>I</i>', L.i || 'Italic', '_', '_'], ['u', '<u>U</u>', L.u || 'Underline', '__', '__'], ['ul', '&bull;&#8201;&equiv;', L.ul || 'Bulleted list', null, null], ['ol', '1.&#8201;&equiv;', L.ol || 'Numbered list', null, null]];
  function lines(ta) {                                   // [start, end] of the whole lines covering the selection
    var v = ta.value, s = ta.selectionStart, e = ta.selectionEnd;
    var ls = v.lastIndexOf('\n', s - 1) + 1, le = v.indexOf('\n', e); if (le < 0) le = v.length;
    if (e > s && v.charAt(e - 1) === '\n') le = e - 1;
    return [ls, le];
  }
  function wrapSel(ta, a, b) {
    var v = ta.value, s = ta.selectionStart, e = ta.selectionEnd, sel = v.slice(s, e);
    if (sel.slice(0, a.length) === a && sel.slice(-b.length) === b && sel.length >= a.length + b.length) { ta.value = v.slice(0, s) + sel.slice(a.length, sel.length - b.length) + v.slice(e); ta.setSelectionRange(s, e - a.length - b.length); }
    else { ta.value = v.slice(0, s) + a + sel + b + v.slice(e); ta.setSelectionRange(s + a.length, e + a.length); }
    ta.focus(); ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function listSel(ta, kind) {
    var r = lines(ta), v = ta.value, block = v.slice(r[0], r[1]), arr = block.split('\n');
    var re = /^(\s*)(?:[-*•]|\d{1,2}[.)])\s+/, all = arr.every(function (l) { return l.trim() === '' || re.test(l); });
    var sameKind = all && arr.every(function (l) { return l.trim() === '' || (kind === 'ul' ? /^\s*[-*•]\s/.test(l) : /^\s*\d{1,2}[.)]\s/.test(l)); });
    var n = 0, out = arr.map(function (l) {
      if (l.trim() === '') return l;
      var bare = l.replace(re, '$1');
      if (sameKind) return bare;
      n++; return kind === 'ul' ? bare.replace(/^(\s*)/, '$1- ') : bare.replace(/^(\s*)/, '$1' + n + '. ');
    }).join('\n');
    ta.value = v.slice(0, r[0]) + out + v.slice(r[1]); ta.setSelectionRange(r[0], r[0] + out.length); ta.focus(); ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function onEnter(ta, e) {                               // continue a list on Enter; an empty item ends it
    if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;
    var v = ta.value, s = ta.selectionStart; if (s !== ta.selectionEnd) return;
    var ls = v.lastIndexOf('\n', s - 1) + 1, line = v.slice(ls, s), m = /^(\s*)(?:([-*•])|(\d{1,2})([.)]))\s+(.*)$/.exec(line);
    if (!m) return;
    e.preventDefault();
    if (m[5].trim() === '') { ta.value = v.slice(0, ls) + m[1] + v.slice(s); ta.setSelectionRange(ls + m[1].length, ls + m[1].length); }
    else { var pre = m[2] ? m[1] + m[2] + ' ' : m[1] + (parseInt(m[3], 10) + 1) + m[4] + ' '; ta.value = v.slice(0, s) + '\n' + pre + v.slice(s); ta.setSelectionRange(s + 1 + pre.length, s + 1 + pre.length); }
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function enhance(ta) {
    if (ta.dataset.rtOn || ta.hasAttribute('data-plain') || ta.closest('.et-form, .em-form') || ta.rows === 1) return; ta.dataset.rtOn = '1';
    var bar = document.createElement('div'); bar.className = 'rt-bar'; bar.setAttribute('role', 'toolbar');
    defs.forEach(function (d) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'rt-b rt-b--' + d[0]; b.innerHTML = d[1]; b.title = d[2]; b.tabIndex = -1;
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () { d[3] !== null ? wrapSel(ta, d[3], d[4]) : listSel(ta, d[0]); });
      bar.appendChild(b);
    });
    var hint = document.createElement('span'); hint.className = 'rt-hint'; hint.textContent = L.hint || ''; bar.appendChild(hint);
    ta.parentNode.insertBefore(bar, ta);
    ta.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey) { var k = e.key.toLowerCase(); if (k === 'b') { e.preventDefault(); wrapSel(ta, '**', '**'); } else if (k === 'i') { e.preventDefault(); wrapSel(ta, '_', '_'); } else if (k === 'u') { e.preventDefault(); wrapSel(ta, '__', '__'); } }
      onEnter(ta, e);
    });
  }
  function scan() { document.querySelectorAll('textarea').forEach(enhance); }
  scan(); new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
})();

/* R43-003: "✉ Send" buttons open the e-mail card of the page and scroll to it. */
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-open-email]'); if (!b) return;
  var d = document.querySelector('details.em-card'); if (!d) return;
  d.open = true; d.scrollIntoView({ behavior: 'smooth', block: 'start' });
  var f = d.querySelector('input[name="to_more"], input, textarea'); if (f) setTimeout(function () { try { f.focus(); } catch (e) {} }, 300);
});

/* ---- R53-001: inline event handlers replaced by data-js-* hooks (Content-Security-Policy without 'unsafe-inline'). */
(function () {
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-js-confirm], [data-js-print]'); if (!el) return;
    if (el.hasAttribute('data-js-print')) { e.preventDefault(); window.print(); return; }
    if (!confirm(el.getAttribute('data-js-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);
  document.addEventListener('submit', function (e) { var f = e.target; if (f.hasAttribute && f.hasAttribute('data-js-confirm') && !confirm(f.getAttribute('data-js-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); } }, true);
  document.addEventListener('change', function (e) {
    var el = e.target; if (!el || !el.getAttribute) return;
    if (el.hasAttribute('data-js-href')) { location.href = el.value; return; }
    if (el.hasAttribute('data-js-toggle')) { var t = document.getElementById(el.getAttribute('data-js-toggle')); if (t) t.hidden = el.value !== el.getAttribute('data-js-toggle-value'); }
    if (el.hasAttribute('data-js-submit') && el.form) { var r = el.getAttribute('data-js-reset'); if (r && el.form[r]) el.form[r].value = ''; el.form.submit(); }
  });
  document.addEventListener('input', function (e) {
    var el = e.target; if (!el || !el.hasAttribute || !el.hasAttribute('data-js-opacity-demo')) return;
    var v = document.getElementById('wf-op-val'), d = document.getElementById('wf-op-demo'); if (v) v.textContent = el.value + ' %'; if (d) d.style.opacity = el.value / 100;
  });
})();
