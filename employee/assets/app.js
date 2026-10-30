/* GAS-POMS — order form behaviour. No dependencies.
   Interface strings come from window.GAS_I18N, printed by the view in the
   active language. Everything falls back to English if the block is absent. */

(function () {
  'use strict';

  var S = window.GAS_I18N || {};
  function s(key, fallback, vars) {
    var out = S[key] || fallback;
    if (vars) Object.keys(vars).forEach(function (k) {
      out = out.replace('{' + k + '}', vars[k]);
    });
    return out;
  }

  /* ---------------------------------------------- line items (max 20) */

  var list = document.getElementById('lines');
  if (list) {
    var MAX     = parseInt(list.dataset.max || '20', 10);
    var TEMPLATE = list.dataset.lineOf || '{n} of ' + MAX;   // e.g. "{n} από 20"
    var addBtn  = document.getElementById('add-line');
    var counter = document.getElementById('line-count');

    function renumber() {
      var rows = list.querySelectorAll('.line');
      rows.forEach(function (row, i) {
        row.querySelector('.line-no').textContent = String(i + 1).padStart(2, '0');
      });
      counter.textContent = TEMPLATE.replace('{n}', rows.length);

      var full = rows.length >= MAX;
      addBtn.disabled = full;
      addBtn.textContent = full ? addBtn.dataset.labelFull : addBtn.dataset.labelAdd;
      list.querySelectorAll('.remove-line').forEach(function (b) { b.disabled = rows.length === 1; });
    }

    addBtn.addEventListener('click', function () {
      if (list.querySelectorAll('.line').length >= MAX) return;
      var tpl = document.getElementById('line-template').content.cloneNode(true);
      var idx = Date.now() + Math.floor(Math.random() * 1000);
      tpl.querySelectorAll('[name]').forEach(function (el) {
        el.name = el.name.replace('__i__', idx);
      });
      list.appendChild(tpl);
      renumber();
      wireRecorders();
      list.lastElementChild.querySelector('input,textarea').focus();
    });

    list.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.remove-line');
      if (!btn) return;
      if (list.querySelectorAll('.line').length === 1) return;
      btn.closest('.line').remove();
      renumber();
    });

    renumber();
  }

  /* ------------------------------------------------- in-browser audio */
  /* Records a voice note straight into the file input, so an engineer
     standing in an engine room can describe the part instead of typing. */

  function wireRecorders() {
    document.querySelectorAll('[data-recorder]:not([data-wired])').forEach(function (box) {
      box.dataset.wired = '1';

      var btn    = box.querySelector('.rec-btn');
      var status = box.querySelector('.rec-status');
      var input  = box.querySelector('input[type=file]');

      if (!navigator.mediaDevices || !window.MediaRecorder) {
        btn.disabled = true;
        status.textContent = s('noMic', 'Recording is not available in this browser. Attach an audio file instead.');
        return;
      }

      var rec = null, chunks = [], stream = null;
      var idle = btn.textContent;

      btn.addEventListener('click', function () {
        if (rec && rec.state === 'recording') { rec.stop(); return; }

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (st) {
          stream = st;
          chunks = [];
          rec = new MediaRecorder(stream);
          rec.ondataavailable = function (e) { chunks.push(e.data); };
          rec.onstop = function () {
            stream.getTracks().forEach(function (tr) { tr.stop(); });
            var blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
            var ext  = (blob.type.indexOf('mp4') > -1) ? 'm4a' : 'webm';
            var file = new File([blob], 'voice-note-' + Date.now() + '.' + ext, { type: blob.type });
            var dt   = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;

            btn.textContent = idle;
            btn.classList.remove('btn--danger');
            status.textContent = s('voiceOk', 'Voice note attached ({kb} KB).', { kb: Math.round(blob.size / 1024) });
          };
          rec.start();
          btn.textContent = s('stop', 'Stop recording');
          btn.classList.add('btn--danger');
          status.textContent = s('recording', 'Recording…');
        }).catch(function () {
          status.textContent = s('micRefused', 'Microphone access was refused. Attach an audio file instead.');
        });
      });
    });
  }
  wireRecorders();

  /* --------------------------------------------- destructive actions */

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.dataset.confirm)) ev.preventDefault();
    });
  });

  /* ---------------------------------- 2FA code: submit on six digits */

  var code = document.querySelector('.code-input');
  if (code) {
    code.addEventListener('input', function () {
      code.value = code.value.replace(/\D/g, '').slice(0, 6);
      if (code.value.length === 6) code.form.requestSubmit();
    });
  }

  /* ---------------------------------- photo preview before upload */

  document.addEventListener('change', function (ev) {
    var input = ev.target;
    if (!input.matches('input[type=file][accept*="image"]')) return;
    var box = input.closest('.field').querySelector('.thumbs');
    if (!box) return;
    box.innerHTML = '';
    Array.from(input.files).slice(0, 6).forEach(function (f) {
      var img = document.createElement('img');
      img.src = URL.createObjectURL(f);
      img.onload = function () { URL.revokeObjectURL(img.src); };
      box.appendChild(img);
    });
  });

})();

/* ---- R2 additions: language dropdown + presence badge -------------- */
(function () {
  'use strict';

  document.querySelectorAll('[data-langsel]').forEach(function (sel) {
    sel.addEventListener('change', function () { window.location.href = sel.value; });
  });

  var badge = document.getElementById('online-badge');
  if (!badge) return;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  badge.addEventListener('dblclick', function () {
    var open = document.querySelector('.online-pop');
    if (open) { open.remove(); return; }

    fetch(badge.dataset.onlineUrl, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var pop = document.createElement('div');
        pop.className = 'online-pop';
        pop.innerHTML = '<h4>' + esc(badge.dataset.onlineTitle) + ' (' + d.count + ')</h4>'
                      + '<ul>' + d.names.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ul>';
        document.body.appendChild(pop);
        setTimeout(function () {
          document.addEventListener('click', function close(ev) {
            if (!pop.contains(ev.target) && ev.target !== badge) {
              pop.remove();
              document.removeEventListener('click', close);
            }
          });
        }, 0);
      })
      .catch(function () {});
  });
})();

/* ---- R4: lockable forms (double-submit guard + progress) ------------ */
(function () {
  'use strict';
  document.querySelectorAll('form[data-lock]').forEach(function (form) {
    // Remember which submit button was pressed. A disabled button's
    // name/value is NOT posted, so on a multi-button form (e.g. Submit vs
    // Save draft) we must preserve the clicked value in a hidden field
    // before disabling anything.
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
      btn.addEventListener('click', function () { form.__clicked = btn; });
    });
    form.addEventListener('submit', function (ev) {
      if (form.dataset.locked === '1') { ev.preventDefault(); return; }
      form.dataset.locked = '1';
      var clicked = form.__clicked;
      if (clicked && clicked.name) {
        var keep = document.createElement('input');
        keep.type = 'hidden';
        keep.name = clicked.name;
        keep.value = clicked.value;
        form.appendChild(keep);
      }
      form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
        btn.disabled = true;
        btn.classList.add('is-busy');
        if (form.dataset.busy && btn === (clicked || form.querySelector('button[type="submit"]'))) {
          btn.textContent = form.dataset.busy;
        }
      });
    });
  });
})();

/* ---- R4: Q&A threaded replies --------------------------------------- */
(function () {
  'use strict';
  var replyTo  = document.getElementById('qa-reply-to');
  var bar      = document.getElementById('qa-replying');
  var barName  = document.getElementById('qa-replying-name');
  var cancel   = document.getElementById('qa-cancel');
  var body     = document.getElementById('qa-body');
  var form     = document.getElementById('qa-form');
  if (!replyTo || !form) return;

  document.querySelectorAll('.qa-reply').forEach(function (btn) {
    btn.addEventListener('click', function () {
      replyTo.value = btn.dataset.id;
      barName.textContent = btn.dataset.name + ' — “' + btn.dataset.excerpt + '”';
      bar.hidden = false;
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      body.focus();
    });
  });
  if (cancel) cancel.addEventListener('click', function () {
    replyTo.value = '';
    bar.hidden = true;
  });
})();
