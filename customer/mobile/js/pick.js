/* Golden A/S apps — searchable picker for long <select> lists (GAS-CP-R72-009).
   Any <select> with 6+ options (or data-search) opens a bottom sheet with a search box instead of the native wheel;
   choosing an entry sets the select's value and fires its change event, so existing handlers keep working.
   Opt out per select with data-nosearch. No markup changes needed in the screens. */
window.PICK = (() => {
  const MIN = 6;
  const T = (k, d) => (window.I18N && I18N.t(k) !== k) ? I18N.t(k) : d;
  const fold = (s) => String(s || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  let open = null;
  function close() { if (open) { open.remove(); open = null; } }
  function show(sel) {
    close();
    const items = []; let g = null;
    Array.from(sel.children).forEach((c) => {
      if (c.tagName === "OPTGROUP") { g = c.label; Array.from(c.children).forEach((o) => items.push({ o, g })); }
      else items.push({ o: c, g: null });
    });
    const label = (sel.labels && sel.labels[0] && sel.labels[0].textContent) || (sel.previousElementSibling && sel.previousElementSibling.tagName === "LABEL" ? sel.previousElementSibling.textContent : "");
    const el = document.createElement("div"); el.className = "sheet pk"; el.id = "pksheet";
    el.innerHTML = `<div class="in"><h3>${esc(label)}</h3><input type="search" id="pk-q" placeholder="${esc(T("common.search", "Search…"))}" autocomplete="off" autocapitalize="off" spellcheck="false"><div id="pk-list" class="pk-list"></div><button class="btn line sm" id="pk-cancel">${esc(T("common.cancel", "Cancel"))}</button></div>`;
    document.body.appendChild(el); open = el;
    const list = el.querySelector("#pk-list"), q = el.querySelector("#pk-q");
    const draw = () => {
      const f = fold(q.value.trim()); let lastG = null, n = 0; const parts = [];
      items.forEach(({ o, g }) => {
        if (o.disabled && o.value === "") return;
        if (f && !fold(o.textContent + " " + (g || "")).includes(f)) return;
        if (g !== lastG && g) parts.push(`<div class="pk-g">${esc(g)}</div>`); lastG = g;
        parts.push(`<button type="button" class="pk-it ${o.selected ? "on" : ""}" data-i="${items.indexOf(items.find((x) => x.o === o))}">${esc(o.textContent)}</button>`); n++;
      });
      list.innerHTML = n ? parts.join("") : `<p class="muted">—</p>`;
    };
    draw(); q.oninput = draw;
    list.onclick = (e) => { const b = e.target.closest(".pk-it"); if (!b) return; const it = items[+b.dataset.i]; if (!it) return;
      sel.value = it.o.value; close(); sel.dispatchEvent(new Event("change", { bubbles: true })); };
    el.querySelector("#pk-cancel").onclick = close; el.onclick = (e) => { if (e.target === el) close(); };
    setTimeout(() => { try { q.focus({ preventScroll: true }); } catch (e) {} }, 50);
  }
  function wants(sel) { return !sel.disabled && !sel.hasAttribute("data-nosearch") && (sel.hasAttribute("data-search") || sel.options.length >= MIN); }
  function enhance(root) {
    (root || document).querySelectorAll("select:not([data-pk])").forEach((sel) => {
      sel.setAttribute("data-pk", "1");
      const guard = (e) => { if (!wants(sel)) return; e.preventDefault(); sel.blur(); show(sel); };
      sel.addEventListener("mousedown", guard); sel.addEventListener("touchstart", guard, { passive: false });
      sel.addEventListener("keydown", (e) => { if (wants(sel) && (e.key === "Enter" || e.key === " ")) { e.preventDefault(); show(sel); } });
    });
  }
  const mo = new MutationObserver(() => enhance(document)); mo.observe(document.body, { childList: true, subtree: true }); enhance(document);
  return { enhance, close };
})();
