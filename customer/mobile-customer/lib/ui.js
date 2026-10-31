/* ui.js — tiny DOM helpers, toast, bottom sheet, formatting. */
window.UI = (function () {
  const t = (k, v) => I18N.t(k, v);
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  function el(html) { const d = document.createElement("div"); d.innerHTML = html.trim(); return d.firstElementChild; }
  let toastT;
  function toast(msg, ms = 2600) { const e = $("#toast"); e.textContent = msg; e.classList.add("show"); clearTimeout(toastT); toastT = setTimeout(() => e.classList.remove("show"), ms); }
  function render(html, title) { const v = $("#view"); v.innerHTML = html; v.scrollTop = 0; window.scrollTo(0, 0); $("#topbar-title").textContent = title || ""; I18N.apply(v); return v; }
  function sheet(html) {
    const s = el(`<div class="sheet" role="dialog" aria-modal="true"><div class="panel">${html}</div></div>`);
    s.addEventListener("click", e => { if (e.target === s) close(); });
    function close() { s.remove(); }
    document.body.appendChild(s); I18N.apply(s); s.close = close; return s;
  }
  function date(iso) { if (!iso) return ""; const d = new Date(iso); if (isNaN(d)) return iso; return `${String(d.getDate()).padStart(2, "0")}/${String(d.getMonth() + 1).padStart(2, "0")}/${d.getFullYear()}`; }
  function datetime(iso) { if (!iso) return ""; const d = new Date(iso); return date(iso) + " " + String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0"); }
  function money(cents, cur = "EUR") { return (cents / 100).toLocaleString("en-GB", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function pill(text, cls) { return `<span class="pill ${cls || ""}">${esc(text)}</span>`; }
  const statusCls = { sent_for_signature: "gold", signed: "green", returned_to_gas: "red", sent_for_approval: "navy", approved: "green", resubmit: "gold", rejected: "red", issued: "gold", viewed: "gold", accepted: "green", revision_requested: "gold", declined: "red", order_confirmed: "green", superseded: "grey", expired: "grey", open: "blue", in_progress: "navy", waiting_customer: "gold", resolved: "green", closed: "grey", draft: "grey" };
  function back(href, label) { return `<a class="back" href="${href}">&#x2039; ${esc(label || t("app.back"))}</a>`; }
  function empty(msg) { return `<div class="empty">${esc(msg)}</div>`; }
  function busy(btn, on) { if (!btn) return; btn.disabled = on; if (on) { btn.dataset.label = btn.innerHTML; btn.innerHTML = "…"; } else if (btn.dataset.label) btn.innerHTML = btn.dataset.label; }
  function errMsg(e) { if (e && e.code === "offline") return t("app.net"); return (e && e.message) || t("app.error"); }
  function stages(cur) { const S = ["engineering", "production", "fat", "sathat"]; const i = S.indexOf(cur); return `<div class="stages">${S.map((s, k) => `<div class="${k < i ? "done" : k === i ? "cur" : ""}"></div>`).join("")}</div><div class="stage-labels">${S.map(s => `<span>${esc(t("stage." + s))}</span>`).join("")}</div>`; }
  return { t, esc, $, $$, el, toast, render, sheet, date, datetime, money, pill, statusCls, back, empty, busy, errMsg, stages };
})();
