/* Golden A/S portal — assistant panel (GAS-CP-R50-001). Loaded only when window.GASAI is set (staff with the assistant enabled on this page).
   Fill a form from pasted text / a photo, write or improve a field, translate a field to English, ask about the record.
   The assistant proposes; the person applies. Field inventory = what the page shows; nothing else leaves the browser. */
(function () {
  var A = window.GASAI; if (!A) return; var T = A.t;
  var SKIP = /^(_csrf|action|id|subproject_id|project_id|mode|_r)$/;
  function forms() { return Array.prototype.slice.call(document.querySelectorAll('main form[method="post"]')).filter(function (f) { return f.querySelector('textarea, input[type="text"], input:not([type]), input[type="number"], input[type="date"], select'); }); }
  function labelOf(el) {
    if (el.id) { var l = document.querySelector('label[for="' + el.id + '"]'); if (l) return l.textContent.trim(); }
    var f = el.closest('.field'); if (f) { var fl = f.querySelector('label'); if (fl) return fl.textContent.replace(/\*/g, '').trim(); }
    var td = el.closest('td'); if (td) { var tr = td.parentNode, i = Array.prototype.indexOf.call(tr.children, td), th = tr.closest('table') && tr.closest('table').querySelector('thead tr'); if (th && th.children[i]) return th.children[i].textContent.trim(); }
    return el.getAttribute('placeholder') || el.getAttribute('title') || el.name;
  }
  function inventory() {
    var out = [], seen = {};
    forms().forEach(function (f) {
      Array.prototype.forEach.call(f.elements, function (el) {
        if (!el.name || seen[el.name] || SKIP.test(el.name) || el.disabled || el.type === 'hidden' || el.type === 'password' || el.type === 'file' || el.type === 'submit' || el.type === 'button') return;
        if (el.closest('[data-ai-skip]')) return;
        var t = el.tagName === 'SELECT' ? 'select' : (el.tagName === 'TEXTAREA' ? 'textarea' : (el.type || 'text')); if (t === 'checkbox' || t === 'radio') return;
        var o = { name: el.name, label: labelOf(el), type: t, value: (el.value || '').slice(0, 1500) };
        if (t === 'select') o.options = Array.prototype.slice.call(el.options).slice(0, 60).map(function (op) { return { value: op.value, label: op.textContent.trim() }; });
        seen[el.name] = el; out.push(o);
      });
    });
    return { list: out, map: seen };
  }
  function ctx(inv) { var h = document.querySelector('main h1'), e = document.querySelector('main .eyebrow'); return { title: document.title, headline: ((e ? e.textContent.trim() + ' · ' : '') + (h ? h.textContent.replace(/\s+/g, ' ').trim() : '')).slice(0, 300), fields: inv.list }; }
  function textFields(inv) { return inv.list.filter(function (f) { return f.type === 'textarea' || f.type === 'text'; }); }
  function setVal(el, v) { if (el.tagName === 'SELECT') { var ok = false; Array.prototype.forEach.call(el.options, function (op) { if (op.value === String(v) || op.textContent.trim().toLowerCase() === String(v).toLowerCase()) { el.value = op.value; ok = true; } }); if (!ok) return false; } else el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); el.classList.add('ai-applied'); setTimeout(function () { el.classList.remove('ai-applied'); }, 2500); return true; }
  function h(tag, attrs, kids) { var el = document.createElement(tag); if (attrs) Object.keys(attrs).forEach(function (k) { if (k === 'text') el.textContent = attrs[k]; else if (k === 'html') el.innerHTML = attrs[k]; else el.setAttribute(k, attrs[k]); }); (kids || []).forEach(function (c) { if (c) el.appendChild(c); }); return el; }

  /* ---- panel */
  var panel, body, tab = 'fill', selField = null, busy = false;
  function open(which, field) { if (!panel) build(); panel.classList.add('is-open'); if (which) tab = which; if (field) selField = field; render(); }
  function build() {
    panel = h('aside', { 'class': 'ai-panel', 'aria-label': T.title });
    var head = h('div', { 'class': 'ai-panel__head' }, [h('b', { text: '✨ ' + T.title }), h('button', { 'class': 'linkbtn', type: 'button', text: '✕', title: T.close })]);
    head.lastChild.addEventListener('click', function () { panel.classList.remove('is-open'); });
    var tabs = h('div', { 'class': 'ai-tabs' }); ['fill', 'write', 'translate', 'ask'].forEach(function (k) { var b = h('button', { type: 'button', 'data-k': k, text: T[k] }); b.addEventListener('click', function () { tab = k; render(); }); tabs.appendChild(b); });
    body = h('div', { 'class': 'ai-body' }); panel.appendChild(head); panel.appendChild(tabs); panel.appendChild(body); document.body.appendChild(panel);
  }
  function render() {
    panel.querySelectorAll('.ai-tabs button').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-k') === tab); });
    body.innerHTML = ''; var inv = inventory();
    if (tab === 'fill') {
      var ta = h('textarea', { rows: '7', placeholder: T.ph_fill, 'data-plain': '1' }), file = h('input', { type: 'file', accept: 'image/*' }), go = h('button', { 'class': 'btn btn--gold btn--sm', type: 'button', text: T.go }), res = h('div', { 'class': 'ai-res' });
      body.appendChild(ta); body.appendChild(h('label', { 'class': 'ai-file' }, [h('span', { text: '📷 ' + T.photo }), file])); body.appendChild(h('div', { 'class': 'btn-row' }, [go])); body.appendChild(res);
      go.addEventListener('click', function () { call('fill', ta.value, file.files[0], inv, res, function (r) { showFill(r, inv, res); }); });
    } else if (tab === 'write' || tab === 'translate') {
      var sel = h('select', { 'class': 'ai-sel' }); textFields(inv).forEach(function (f) { var o = h('option', { value: f.name, text: f.label }); if (selField === f.name) o.selected = true; sel.appendChild(o); });
      var ins = tab === 'write' ? h('textarea', { rows: '4', placeholder: T.ph_write, 'data-plain': '1' }) : null, go2 = h('button', { 'class': 'btn btn--gold btn--sm', type: 'button', text: T.go }), res2 = h('div', { 'class': 'ai-res' });
      body.appendChild(h('label', { 'class': 'ai-lbl', text: T.field })); body.appendChild(sel); if (ins) body.appendChild(ins); body.appendChild(h('div', { 'class': 'btn-row' }, [go2])); body.appendChild(res2);
      go2.addEventListener('click', function () {
        var el = inv.map[sel.value]; if (!el) return; var cur = el.value || '';
        var prompt = tab === 'write' ? ('Field: ' + labelOf(el) + '\nInstruction: ' + (ins.value || T.improve) + '\nCurrent text:\n' + cur) : cur;
        call(tab, prompt, null, inv, res2, function (r) { showText(r.text, el, res2); });
      });
    } else {
      var q = h('textarea', { rows: '3', placeholder: T.ph_ask, 'data-plain': '1' }), go3 = h('button', { 'class': 'btn btn--gold btn--sm', type: 'button', text: T.go }), res3 = h('div', { 'class': 'ai-res' });
      body.appendChild(q); body.appendChild(h('div', { 'class': 'btn-row' }, [go3])); body.appendChild(res3);
      go3.addEventListener('click', function () { call('ask', q.value, null, inv, res3, function (r) { res3.innerHTML = ''; res3.appendChild(h('div', { 'class': 'ai-answer', text: r.text })); }); });
    }
  }
  function call(kind, prompt, file, inv, res, done) {
    if (busy) return; busy = true; res.innerHTML = ''; res.appendChild(h('p', { 'class': 'hint', text: T.busy }));
    var fd = new FormData(); fd.append('kind', kind); fd.append('page', A.page); fd.append('prompt', prompt || ''); fd.append('ctx', JSON.stringify(ctx(inv))); if (file) fd.append('image', file);
    fetch('index.php?p=ai', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF': A.csrf } }).then(function (r) { return r.json(); }).then(function (r) {
      busy = false; if (!r.ok) { res.innerHTML = ''; res.appendChild(h('p', { 'class': 'flash flash--bad', text: r.error || 'Error' })); return; } done(r);
    }).catch(function () { busy = false; res.innerHTML = ''; res.appendChild(h('p', { 'class': 'flash flash--bad', text: 'Network error' })); });
  }
  function showFill(r, inv, res) {
    res.innerHTML = ''; var keys = Object.keys(r.fields || {}).filter(function (k) { return inv.map[k]; });
    if (!keys.length) { res.appendChild(h('p', { 'class': 'hint', text: T.none + (r.notes ? ' — ' + r.notes : '') })); return; }
    res.appendChild(h('p', { 'class': 'hint', text: T.proposals + ' · ' + T.review }));
    var tbl = h('table', { 'class': 'tbl ai-tbl' }); keys.forEach(function (k) {
      var el = inv.map[k], tr = h('tr'); tr.appendChild(h('td', { text: labelOf(el) })); tr.appendChild(h('td', { 'class': 'ai-new', text: String(r.fields[k]) }));
      var b = h('button', { 'class': 'btn btn--ghost btn--sm', type: 'button', text: T.apply }); b.addEventListener('click', function () { if (setVal(el, r.fields[k])) { tr.classList.add('is-done'); b.disabled = true; } }); tr.appendChild(h('td', null, [b])); tbl.appendChild(tr);
    });
    res.appendChild(tbl); var all = h('button', { 'class': 'btn btn--gold btn--sm', type: 'button', text: T.apply_all }); all.addEventListener('click', function () { tbl.querySelectorAll('tr button').forEach(function (b) { if (!b.disabled) b.click(); }); }); res.appendChild(h('div', { 'class': 'btn-row' }, [all]));
    if (r.notes) res.appendChild(h('p', { 'class': 'hint', text: r.notes }));
  }
  function showText(text, el, res) {
    res.innerHTML = ''; var out = h('textarea', { rows: '8', 'data-plain': '1' }); out.value = text; res.appendChild(out);
    var rep = h('button', { 'class': 'btn btn--gold btn--sm', type: 'button', text: T.replace }), cp = h('button', { 'class': 'btn btn--ghost btn--sm', type: 'button', text: T.copy });
    rep.addEventListener('click', function () { setVal(el, out.value); }); cp.addEventListener('click', function () { try { navigator.clipboard.writeText(out.value); } catch (e) {} });
    res.appendChild(h('div', { 'class': 'btn-row' }, [rep, cp]));
  }

  /* ---- entry points: floating button, ✨ next to every text area, "translate to English" hint on non-Latin text */
  if (!forms().length) return;
  var fab = h('button', { 'class': 'ai-fab', type: 'button', title: T.title, text: '✨' }); fab.addEventListener('click', function () { open(); }); document.body.appendChild(fab);
  var NONLAT = /[Ͱ-ϿЀ-ӿ]/;
  function decorate(el) {
    if (el.closest('[data-ai-skip]') || el.dataset.aiDone) return; el.dataset.aiDone = '1';
    var wrap = h('span', { 'class': 'ai-fx' }), b = h('button', { 'class': 'linkbtn', type: 'button', text: '✨', title: T.improve }), tr = h('button', { 'class': 'linkbtn ai-tr', type: 'button', text: 'EN', title: T.to_en });
    b.addEventListener('click', function () { open('write', el.name); }); tr.addEventListener('click', function () { open('translate', el.name); });
    wrap.appendChild(b); wrap.appendChild(tr); el.insertAdjacentElement('afterend', wrap);
    function chk() { wrap.classList.toggle('is-nonlat', NONLAT.test(el.value || '')); } el.addEventListener('blur', chk); el.addEventListener('input', chk); chk();
  }
  forms().forEach(function (f) { f.querySelectorAll('textarea[name], input[type="text"][name][maxlength], input:not([type])[name][maxlength]').forEach(function (el) { if (el.tagName === 'TEXTAREA' || parseInt(el.getAttribute('maxlength') || '0', 10) >= 200) decorate(el); }); });
})();
