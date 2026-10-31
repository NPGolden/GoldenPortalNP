/* sigpad.js — canvas signature pad. Keeps raw strokes (vector evidence) and exports a 600×200 PNG. */
window.SigPad = (function () {
  function create(container, { onChange } = {}) {
    container.classList.add("sigpad", "empty"); container.setAttribute("data-hint", I18N.t("sign.hint"));
    container.innerHTML = `<canvas></canvas><div class="sigline"></div><button type="button" class="sigclear">${UI.esc(I18N.t("sign.clear"))}</button>`;
    const canvas = container.querySelector("canvas"), ctx = canvas.getContext("2d");
    let strokes = [], cur = null, drawing = false;
    function resize() { const r = container.getBoundingClientRect(), dpr = window.devicePixelRatio || 1; canvas.width = r.width * dpr; canvas.height = r.height * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); redraw(); }
    function pos(e) { const r = canvas.getBoundingClientRect(); const p = e.touches ? e.touches[0] : e; return { x: p.clientX - r.left, y: p.clientY - r.top, t: Date.now() }; }
    function redraw() { ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.lineWidth = 2.2; ctx.lineCap = "round"; ctx.lineJoin = "round"; ctx.strokeStyle = "#1F3A5F"; for (const s of strokes) { ctx.beginPath(); s.forEach((p, i) => i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)); ctx.stroke(); } }
    function start(e) { e.preventDefault(); drawing = true; cur = [pos(e)]; strokes.push(cur); container.classList.remove("empty"); }
    function move(e) { if (!drawing) return; e.preventDefault(); cur.push(pos(e)); redraw(); }
    function end() { if (!drawing) return; drawing = false; if (cur && cur.length === 1) cur.push({ ...cur[0], x: cur[0].x + 0.5 }); redraw(); onChange && onChange(!isEmpty()); }
    canvas.addEventListener("pointerdown", start); canvas.addEventListener("pointermove", move); window.addEventListener("pointerup", end); canvas.addEventListener("pointerleave", end);
    container.querySelector(".sigclear").addEventListener("click", () => { strokes = []; redraw(); container.classList.add("empty"); onChange && onChange(false); });
    const isEmpty = () => strokes.length === 0 || strokes.every(s => s.length < 2);
    function png() {
      const out = document.createElement("canvas"); out.width = 600; out.height = 200; const o = out.getContext("2d");
      const r = container.getBoundingClientRect(); const sx = 600 / r.width, sy = 200 / r.height, s = Math.min(sx, sy);
      o.lineWidth = 2.2 * s; o.lineCap = "round"; o.lineJoin = "round"; o.strokeStyle = "#1F3A5F";
      for (const st of strokes) { o.beginPath(); st.forEach((p, i) => i ? o.lineTo(p.x * s, p.y * s) : o.moveTo(p.x * s, p.y * s)); o.stroke(); }
      return out.toDataURL("image/png");
    }
    resize(); window.addEventListener("resize", resize);
    return { isEmpty, png, strokes: () => strokes.map(s => s.map(p => [Math.round(p.x), Math.round(p.y), p.t])), destroy: () => { window.removeEventListener("pointerup", end); window.removeEventListener("resize", resize); } };
  }
  return { create };
})();
