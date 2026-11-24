/* Wellness (GAS-CP-R97-194). No library, no network except the state refresh and the two small posts.
   Sounds are generated in the browser (noise through filters) — there are no audio files and nothing plays by itself. */
(function () {
  'use strict';
  var el = document.getElementById('wl-data'), btn = document.getElementById('wl-btn'); if (!el || !btn) return;
  var D; try { D = JSON.parse(el.textContent); } catch (e) { return; }
  var S = D.s, T = D.t, P = S.prefs, KEY = 'gas.wl.' + D.u, loadedAt = Date.now();
  function $(tag, cls, txt) { var n = document.createElement(tag); if (cls) n.className = cls; if (txt != null) n.textContent = txt; return n; }
  function nowMin() { return (S.now + Math.floor((Date.now() - loadedAt) / 60000)) % 1440; }
  function hm(s) { if (!s) return null; var p = s.split(':'); return (+p[0]) * 60 + (+p[1]); }
  function store(patch) { var o = {}; try { o = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) {} if (o.day !== S.today.day) o = { day: S.today.day, act: 0, brk: 0, eyes: 0, water: 0, post: 0 };
    if (patch) { for (var k in patch) o[k] = patch[k]; try { localStorage.setItem(KEY, JSON.stringify(o)); } catch (e) {} } return o; }
  function post(action, data, cb) { var b = new URLSearchParams(); b.set('_csrf', D.csrf); b.set('action', action); for (var k in data) b.set(k, data[k]);
    fetch(D.post, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }, body: b.toString() })
      .then(function (r) { return r.json(); }).then(function (j) { if (cb) cb(j); }).catch(function () { if (cb) cb({ ok: false }); }); }
  function setPref(k, v) { P[k] = v; var d = {}; d[k] = v; post('wl_set', d); }

  /* ---------- reminders ---------- */
  var box = $('div', 'wl-toasts'); box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); document.body.appendChild(box);
  function toast(o) {   // {t, p, green, life, buttons:[{label, fn, primary}], node}
    var c = $('div', 'wl-toast' + (o.green ? ' g' : '')); c.appendChild($('b', '', o.t)); if (o.p) c.appendChild($('p', '', o.p)); if (o.node) c.appendChild(o.node);
    var row = $('div', 'wl-row'); (o.buttons || [{ label: T.ok }]).forEach(function (b) { var x = $('button', 'wl-chip' + (b.primary ? ' on' : ''), b.label); x.type = 'button'; x.addEventListener('click', function () { c.remove(); if (b.fn) b.fn(); }); row.appendChild(x); });
    c.appendChild(row); box.appendChild(c); if (o.life) setTimeout(function () { c.remove(); }, o.life * 1000);
    if (P.desktop && document.hidden && 'Notification' in window && Notification.permission === 'granted') { try { new Notification(o.t, { body: o.p || '', tag: 'gas-wl', icon: '../assets/apps/icon-employee.png' }); } catch (e) {} }
    return c;
  }
  var lastAct = Date.now();
  ['mousemove', 'keydown', 'pointerdown', 'scroll'].forEach(function (ev) { window.addEventListener(ev, function () { lastAct = Date.now(); }, { passive: true }); });
  function tick() {
    var st = store(), now = Date.now();
    if (st.tick && now - st.tick < 25000) return;                         // another tab of the portal is counting
    var idle = now - lastAct, patch = { tick: now };
    if (idle < 120000 && !document.hidden) patch.act = (st.act || 0) + 30;   // seconds of work in the portal today
    if (idle > 300000) { patch.brk = st.act || 0; patch.eyes = st.act || 0; } // five quiet minutes are a break
    st = store(patch); var a = st.act || 0, m = nowMin(), t = S.today;
    if (P.breaks && a - (st.brk || 0) >= P.break_min * 60) { store({ brk: a, eyes: a });
      toast({ t: T.n_break_t, p: T.n_break.replace('{n}', P.break_min), buttons: [{ label: T.snooze, fn: function () { var s = store(); store({ brk: (s.act || 0) - P.break_min * 60 + 600 }); } }, { label: T.ok, primary: true }] }); }
    else if (P.eyes && a - (st.eyes || 0) >= 1200) { store({ eyes: a }); toast({ t: T.n_eyes_t, p: T.n_eyes, life: 25 }); }
    if (P.hydrate && a - (st.water || 0) >= 5400) { store({ water: a }); toast({ t: T.n_water_t, p: T.n_water, green: true, life: 25 }); }
    if (P.posture && a - (st.post || 0) >= 3600 && a - (st.water || 0) > 600) { store({ post: a }); toast({ t: T.n_posture_t, p: T.n_posture, green: true, life: 25 }); }
    if (!t.workday || t.leave) return;                                   // a day off or on leave: nothing about working hours, In / Out or the day
    var s0 = hm(t.start), e0 = hm(t.end), go = function () { location.href = D.urlIo; };
    if (P.io_remind && t.io && s0 != null) {
      if (!t.in && !st.ioIn && m >= s0 - 5 && m <= s0 + 90) { store({ ioIn: 1 }); toast({ t: T.n_in_t, p: T.n_in.replace('{t}', t.start), buttons: [{ label: T.later }, { label: T.n_open, fn: go, primary: true }] }); }
      if (t.open && !st.ioOut && e0 != null && m >= e0) { store({ ioOut: 1, eod: 1 }); toast({ t: T.n_out_t, p: T.n_out.replace('{t}', t.end), buttons: [{ label: T.later }, { label: T.n_open, fn: go, primary: true }] }); }
    }
    if (P.eod && e0 != null && !st.eod && m >= e0 + 20) { store({ eod: 1 }); toast({ t: T.n_eod_t, p: T.n_eod.replace('{t}', t.end) }); }
    if (P.mood && !S.mood_done && !st.mood && e0 != null && m >= e0 - 90) { store({ mood: 1 }); mood(); }
  }
  function mood() {
    var f = $('div', 'wl-faces'), c; ['😞', '🙁', '😐', '🙂', '😀'].forEach(function (face, i) { var b = $('button', '', face); b.type = 'button'; b.title = T['mood_' + (i + 1)]; b.setAttribute('aria-label', T['mood_' + (i + 1)]);
      b.addEventListener('click', function () { c.remove(); post('wl_mood', { mood: i + 1 }, function (j) { S.mood_done = true; if (j && j.ok) toast({ t: T.mood_thanks, green: true, life: 6 }); }); }); f.appendChild(b); });
    var w = $('div'); w.appendChild(f); w.appendChild($('p', 'wl-anon', T.mood_anon)); c = toast({ t: T.mood_t, p: T.mood_q, node: w, buttons: [{ label: T.later }] });
  }
  function refresh() { fetch(D.urlState, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.prefs) { S = j; P = S.prefs; loadedAt = Date.now(); } }).catch(function () {}); }
  setInterval(tick, 30000); setTimeout(tick, 4000); setInterval(refresh, 300000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - loadedAt > 120000) refresh(); });

  /* ---------- focus sounds (generated) ---------- */
  var ctx = null, chain = null, playing = null;
  /* R97-222: every menu opens a new page, which ends the sound. What the person started is remembered for this tab and picked up again on the next page. */
  var SK = 'gas.wl.snd.' + D.u, TK = 'gas.wl.tm.' + D.u;
  function keep(k, v) { try { if (v == null) sessionStorage.removeItem(k); else sessionStorage.setItem(k, v); } catch (e) {} }
  function kept(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function noise(kind) { var n = ctx.sampleRate * 4, buf = ctx.createBuffer(1, n, ctx.sampleRate), d = buf.getChannelData(0), i, w, b0 = 0, b1 = 0, b2 = 0, last = 0;
    for (i = 0; i < n; i++) { w = Math.random() * 2 - 1;
      if (kind === 'brown') { last = (last + 0.02 * w) / 1.02; d[i] = last * 3.5; }
      else if (kind === 'pink') { b0 = 0.99765 * b0 + w * 0.0990460; b1 = 0.96300 * b1 + w * 0.2965164; b2 = 0.57000 * b2 + w * 1.0526913; d[i] = (b0 + b1 + b2 + w * 0.1848) * 0.2; }
      else d[i] = w * 0.5; }
    var s = ctx.createBufferSource(); s.buffer = buf; s.loop = true; return s; }
  function lfo(freq, depth, param, base) { var o = ctx.createOscillator(), g = ctx.createGain(); o.frequency.value = freq; g.gain.value = depth; o.connect(g); g.connect(param); param.value = base; o.start(); return o; }
  function stop() { if (chain) { try { chain.out.gain.setTargetAtTime(0, ctx.currentTime, 0.2); } catch (e) {} var c = chain; setTimeout(function () { c.nodes.forEach(function (n) { try { n.stop && n.stop(); n.disconnect(); } catch (e) {} }); }, 700); } chain = null; playing = null; paint(); }
  function play(kind) {
    if (playing === kind) { keep(SK, null); return stop(); } if (chain) stop();
    try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); if (ctx.state === 'suspended') ctx.resume(); } catch (e) { return; }
    var out = ctx.createGain(), nodes = [out], src, f1, f2, g; out.gain.value = 0; out.connect(ctx.destination);
    if (kind === 'rain') { src = noise('white'); f1 = ctx.createBiquadFilter(); f1.type = 'highpass'; f1.frequency.value = 900; f2 = ctx.createBiquadFilter(); f2.type = 'lowpass'; f2.frequency.value = 7000; g = ctx.createGain(); nodes.push(lfo(0.13, 0.12, g.gain, 0.8)); src.connect(f1); f1.connect(f2); f2.connect(g); g.connect(out); nodes.push(f1, f2, g); }
    else if (kind === 'sea') { src = noise('brown'); f1 = ctx.createBiquadFilter(); f1.type = 'lowpass'; f1.frequency.value = 700; g = ctx.createGain(); nodes.push(lfo(0.09, 0.45, g.gain, 0.55), lfo(0.05, 250, f1.frequency, 600)); src.connect(f1); f1.connect(g); g.connect(out); nodes.push(f1, g); }
    else if (kind === 'wind') { src = noise('pink'); f1 = ctx.createBiquadFilter(); f1.type = 'bandpass'; f1.Q.value = 0.8; g = ctx.createGain(); nodes.push(lfo(0.07, 260, f1.frequency, 520), lfo(0.11, 0.25, g.gain, 0.7)); src.connect(f1); f1.connect(g); g.connect(out); nodes.push(f1, g); }
    else { src = noise(kind); f1 = ctx.createBiquadFilter(); f1.type = 'lowpass'; f1.frequency.value = kind === 'white' ? 9000 : 16000; src.connect(f1); f1.connect(out); nodes.push(f1); }
    src.start(); nodes.push(src); chain = { out: out, nodes: nodes }; playing = kind; keep(SK, kind); vol(P.volume); if (P.sound !== kind) setPref('sound', kind); paint();
  }
  function vol(v) { if (chain) chain.out.gain.setTargetAtTime(Math.pow(v / 100, 2) * 0.9, ctx.currentTime, 0.15); }

  /* ---------- focus timer ---------- */
  var tm = { on: false, phase: 'focus', left: P.timer_focus * 60, h: null }, badge = document.getElementById('wl-badge');
  function fmt(s) { var m = Math.floor(s / 60), x = s % 60; return (m < 10 ? '0' : '') + m + ':' + (x < 10 ? '0' : '') + x; }
  function tmPaint() { if (ui.time) { ui.time.textContent = fmt(tm.left); ui.phase.textContent = tm.phase === 'focus' ? T.tm_focus : T.tm_break; ui.start.textContent = tm.on ? T.tm_pause : T.tm_start; } badge.hidden = !tm.on; badge.textContent = fmt(tm.left); }
  function tmStep() { if (--tm.left > 0) return tmPaint(); var was = tm.phase; tm.phase = was === 'focus' ? 'break' : 'focus'; tm.left = (tm.phase === 'focus' ? P.timer_focus : P.timer_break) * 60;
    if (was === 'focus') { var s = store(); store({ brk: s.act || 0, eyes: s.act || 0 }); } toast({ t: was === 'focus' ? T.tm_done_focus : T.tm_done_break, green: was === 'focus', life: 20 }); tmKeep(); tmPaint(); }
  function tmKeep() { keep(TK, tm.on ? JSON.stringify({ phase: tm.phase, end: Date.now() + tm.left * 1000 }) : null); }
  function tmToggle() { tm.on = !tm.on; clearInterval(tm.h); if (tm.on) tm.h = setInterval(tmStep, 1000); tmKeep(); tmPaint(); }
  function tmReset() { tm.on = false; clearInterval(tm.h); tm.phase = 'focus'; tm.left = P.timer_focus * 60; tmKeep(); tmPaint(); }

  /* ---------- breathing minute and stretches ---------- */
  function overlay() { var o = $('div', 'wl-over'); o.setAttribute('role', 'dialog'); o.setAttribute('aria-modal', 'true'); var x = $('button', '', T.close); x.type = 'button'; var kill = function () { o.remove(); document.removeEventListener('keydown', esc); };
    function esc(e) { if (e.key === 'Escape') kill(); } document.addEventListener('keydown', esc); x.addEventListener('click', kill); o.kill = kill; o.closeBtn = x; document.body.appendChild(o); return o; }
  function breathe() { var o = overlay(), ball = $('div', 'wl-ball'), say = $('div', 'wl-say'); o.appendChild(ball); o.appendChild(say); o.appendChild(o.closeBtn); o.closeBtn.focus();
    var seq = [[T.br_in, 4, 1.25], [T.br_hold, 4, 1.25], [T.br_out, 6, 0.7]], i = 0, rounds = 0;
    (function next() { if (!o.isConnected) return; if (rounds >= 4) { say.textContent = T.br_done; setTimeout(o.kill, 2500); return; } var s = seq[i]; say.textContent = s[0]; ball.style.transitionDuration = s[1] + 's'; ball.style.transform = 'scale(' + s[2] + ')';
      i = (i + 1) % 3; if (i === 0) rounds++; setTimeout(next, s[1] * 1000); })(); }
  function stretch() { var o = overlay(), l = $('ol'); o.appendChild($('div', 'wl-say', T.stretch)); [T.st_1, T.st_2, T.st_3, T.st_4].forEach(function (s) { l.appendChild($('li', '', s)); }); o.appendChild(l); o.appendChild(o.closeBtn); o.closeBtn.focus(); var s = store(); store({ brk: s.act || 0, post: s.act || 0 }); }

  /* ---------- panel ---------- */
  var panel = null, ui = {};
  function paint() { if (!ui.snd) return; ui.snd.forEach(function (b) { b.classList.toggle('on', b.dataset.k === playing); b.setAttribute('aria-pressed', b.dataset.k === playing ? 'true' : 'false'); }); }
  function build() {
    panel = $('div', 'wl-panel'); panel.hidden = true; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', T.title);
    var s1 = $('div', 'wl-sec'), r1 = $('div', 'wl-row'); s1.appendChild($('h3', '', T.sounds)); ui.snd = [];
    ['rain', 'sea', 'wind', 'brown', 'pink', 'white'].forEach(function (k) { var b = $('button', 'wl-chip', T['snd_' + k]); b.type = 'button'; b.dataset.k = k; b.addEventListener('click', function () { play(k); }); ui.snd.push(b); r1.appendChild(b); });
    var v = $('input', 'wl-vol'); v.type = 'range'; v.min = 0; v.max = 100; v.value = P.volume; v.setAttribute('aria-label', T.volume); v.addEventListener('input', function () { P.volume = +v.value; vol(P.volume); }); v.addEventListener('change', function () { setPref('volume', +v.value); });
    s1.appendChild(r1); s1.appendChild(v);
    var s2 = $('div', 'wl-sec'), r2 = $('div', 'wl-row'); s2.appendChild($('h3', '', T.timer)); ui.time = $('span', 'wl-time'); ui.start = $('button', 'wl-chip on'); ui.start.type = 'button'; ui.start.addEventListener('click', tmToggle);
    var rs = $('button', 'wl-chip', T.tm_reset); rs.type = 'button'; rs.addEventListener('click', tmReset); ui.phase = $('span', 'wl-phase'); r2.appendChild(ui.time); r2.appendChild(ui.start); r2.appendChild(rs); r2.appendChild(ui.phase); s2.appendChild(r2);
    var s3 = $('div', 'wl-sec'), r3 = $('div', 'wl-row'), b1 = $('button', 'wl-chip', '🫁 ' + T.breathe), b2 = $('button', 'wl-chip', '🧘 ' + T.stretch); b1.type = b2.type = 'button';
    b1.addEventListener('click', function () { toggle(false); breathe(); }); b2.addEventListener('click', function () { toggle(false); stretch(); }); r3.appendChild(b1); r3.appendChild(b2); s3.appendChild(r3);
    var ft = $('div', 'wl-foot'), a = $('a', '', '⚙ ' + T.settings); a.href = D.urlSet; var c = $('button', 'wl-chip', T.close); c.type = 'button'; c.addEventListener('click', function () { toggle(false); }); ft.appendChild(a); ft.appendChild(c);
    panel.appendChild(s1); panel.appendChild(s2); panel.appendChild(s3); panel.appendChild(ft); document.body.appendChild(panel); tmPaint(); paint();
  }
  function toggle(open) { if (!panel) build(); panel.hidden = open === undefined ? !panel.hidden : !open; btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true'); }
  btn.addEventListener('click', function (e) { e.stopPropagation(); toggle(); });
  document.addEventListener('click', function (e) { if (panel && !panel.hidden && !panel.contains(e.target) && e.target !== btn) toggle(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel && !panel.hidden) { toggle(false); btn.focus(); } });

  /* the settings page: the button that asks the browser for notifications */
  var ask = document.getElementById('wl-ask'); if (ask) { var say = function () { ask.nextElementSibling.textContent = !('Notification' in window) ? ask.dataset.none : (Notification.permission === 'granted' ? ask.dataset.on : (Notification.permission === 'denied' ? ask.dataset.off : '')); };
    ask.addEventListener('click', function () { if ('Notification' in window) Notification.requestPermission().then(say); }); say(); }
  /* R97-222: pick up the sound and the timer of the previous page */
  (function () {
    var t0 = kept(TK); if (t0) { try { var o = JSON.parse(t0), left = Math.round((o.end - Date.now()) / 1000); if (left > 0 && left < 4 * 3600) { tm.phase = o.phase === 'break' ? 'break' : 'focus'; tm.left = left; tm.on = true; tm.h = setInterval(tmStep, 1000); tmPaint(); } else keep(TK, null); } catch (e) { keep(TK, null); } }
    var k0 = kept(SK); if (!k0 || ['rain', 'sea', 'wind', 'brown', 'pink', 'white'].indexOf(k0) < 0) return;
    var done = false, evs = ['pointerdown', 'keydown', 'touchstart'];
    function go() { if (done || playing) return; if (!ctx || ctx.state !== 'running') return; done = true; evs.forEach(function (ev) { window.removeEventListener(ev, wake, true); }); play(k0); }
    function wake() { try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); var p = ctx.resume(); if (p && p.then) p.then(go, function () {}); else go(); } catch (e) {} }
    evs.forEach(function (ev) { window.addEventListener(ev, wake, true); });   // a browser that wants a touch first: the next click or key starts it again
    wake();                                                                    // most browsers let a page of the same site continue at once
  })();
})();
