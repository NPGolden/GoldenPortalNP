/* Passkeys (WebAuthn) for the Golden A/S portal — sign in on the login page, enrol / list on My Account.   GAS-CP-R71-001
   Talks to index.php?p=pk (JSON). Base64url helpers; the browser's PublicKeyCredential does the cryptography. */
(function () {
  'use strict';
  var b64u = { enc: function (buf) { var s = '', b = new Uint8Array(buf); for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); },
               dec: function (s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; var bin = atob(s), b = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i); return b.buffer; } };
  var csrf = (document.querySelector('input[name=_csrf]') || {}).value || '';
  function api(body) { return fetch('index.php?p=pk', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }); }
  function supported() { return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get); }
  function toJSON(cred) {
    var r = cred.response, out = { id: cred.id, rawId: b64u.enc(cred.rawId), type: cred.type, response: { clientDataJSON: b64u.enc(r.clientDataJSON) } };
    if (r.attestationObject) { out.response.attestationObject = b64u.enc(r.attestationObject); try { out.response.transports = r.getTransports ? r.getTransports() : []; } catch (e) { out.response.transports = []; } }
    if (r.authenticatorData) { out.response.authenticatorData = b64u.enc(r.authenticatorData); out.response.signature = b64u.enc(r.signature); out.response.userHandle = r.userHandle ? b64u.enc(r.userHandle) : null; }
    return out;
  }
  function say(el, msg, bad) { if (!el) return; el.textContent = msg || ''; el.hidden = !msg; el.className = 'flash flash--' + (bad ? 'bad' : 'good'); }

  /* ---- login page ---- */
  var lb = document.getElementById('pk-login');
  if (lb) {
    if (!supported()) { lb.hidden = true; }
    lb.addEventListener('click', function () {
      var msg = document.getElementById('pk-msg'); lb.disabled = true; say(msg, lb.getAttribute('data-wait'));
      api({ op: 'login_options' }).then(function (j) {
        if (!j.ok) throw new Error(j.error);
        var o = j.options; o.challenge = b64u.dec(o.challenge); o.allowCredentials = [];
        return navigator.credentials.get({ publicKey: o });
      }).then(function (cred) { return api({ op: 'login', credential: toJSON(cred) }); })
        .then(function (j) { if (!j.ok) throw new Error(j.error); say(msg, lb.getAttribute('data-ok')); location.href = j.redirect; })
        .catch(function (e) { say(msg, (e && e.name === 'NotAllowedError') ? lb.getAttribute('data-cancel') : (e.message || String(e)), true); lb.disabled = false; });
    });
    // conditional UI (autofill): the browser offers the passkey in the e-mail field when it has one
    var em = document.getElementById('email');
    if (supported() && em && window.PublicKeyCredential.isConditionalMediationAvailable) {
      PublicKeyCredential.isConditionalMediationAvailable().then(function (ok) {
        if (!ok) return; em.setAttribute('autocomplete', 'username webauthn');
        api({ op: 'login_options' }).then(function (j) { if (!j.ok) return; var o = j.options; o.challenge = b64u.dec(o.challenge); o.allowCredentials = [];
          return navigator.credentials.get({ publicKey: o, mediation: 'conditional' }).then(function (cred) { return api({ op: 'login', credential: toJSON(cred) }); }).then(function (r) { if (r && r.ok) location.href = r.redirect; }); }).catch(function () {});
      }).catch(function () {});
    }
  }

  /* ---- My Account: enrol ---- */
  var rb = document.getElementById('pk-add');
  if (rb) {
    var msg = document.getElementById('pk-reg-msg'), codeRow = document.getElementById('pk-code-row');
    if (!supported()) { rb.disabled = true; say(msg, rb.getAttribute('data-unsupported'), true); }
    rb.addEventListener('click', function () {
      var name = (document.getElementById('pk-name') || {}).value || '', code = (document.getElementById('pk-code') || {}).value || '';
      rb.disabled = true; say(msg, rb.getAttribute('data-wait'));
      api({ op: 'reg_options', code: code }).then(function (j) {
        if (!j.ok) { if (j.code && codeRow) codeRow.hidden = false; throw new Error(j.error); }
        var o = j.options; o.challenge = b64u.dec(o.challenge); o.user.id = b64u.dec(o.user.id); o.excludeCredentials = (o.excludeCredentials || []).map(function (c) { return { type: c.type, id: b64u.dec(c.id) }; });
        return navigator.credentials.create({ publicKey: o });
      }).then(function (cred) { return api({ op: 'register', credential: toJSON(cred), name: name }); })
        .then(function (j) { if (!j.ok) throw new Error(j.error); say(msg, rb.getAttribute('data-ok')); setTimeout(function () { location.href = location.pathname + '?p=account&_r=' + Date.now() + '#passkeys'; }, 600); })
        .catch(function (e) { say(msg, (e && e.name === 'InvalidStateError') ? rb.getAttribute('data-exists') : (e && e.name === 'NotAllowedError') ? rb.getAttribute('data-cancel') : (e.message || String(e)), true); rb.disabled = false; });
    });
  }
})();
