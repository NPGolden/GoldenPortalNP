/* pdfview.js — renders a PDF blob into a scroll box with pdf.js (cdnjs, pinned). Reports when the last page was reached.
   Fallback when pdf.js is unavailable (offline first start, CSP block): an <iframe> on iOS/desktop, a download link on Android. */
window.PdfView = (function () {
  const CDN = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.4.168/";
  let lib = null;
  async function getLib() {
    if (lib) return lib;
    try { lib = await import(CDN + "pdf.min.mjs"); lib.GlobalWorkerOptions.workerSrc = CDN + "pdf.worker.min.mjs"; return lib; } catch (e) { return null; }
  }
  /** mount(box, blob, {onProgress(p,n), onEnd()}) */
  async function mount(box, blob, { onProgress, onEnd } = {}) {
    box.innerHTML = `<div class="pdf-progress">${UI.esc(I18N.t("app.loading"))}</div>`;
    const L = await getLib();
    const url = URL.createObjectURL(blob);
    if (!L) {
      const isAndroid = /Android/i.test(navigator.userAgent);
      box.innerHTML = isAndroid ? `<div class="pdf-fallback"><a class="btn sm gold" href="${url}" download>${UI.esc(I18N.t("app.download"))}</a></div>` : `<iframe src="${url}" style="width:100%;height:55vh;border:0;background:#fff"></iframe>`;
      onEnd && onEnd(); return { destroy: () => URL.revokeObjectURL(url) };
    }
    const doc = await L.getDocument({ data: await blob.arrayBuffer() }).promise;
    const n = doc.numPages; box.innerHTML = "";
    const width = Math.min(box.clientWidth - 16, 800);
    const canvases = [];
    for (let i = 1; i <= n; i++) {
      const page = await doc.getPage(i);
      const vp0 = page.getViewport({ scale: 1 }); const scale = width / vp0.width; const vp = page.getViewport({ scale: scale * (window.devicePixelRatio || 1) });
      const c = document.createElement("canvas"); c.width = vp.width; c.height = vp.height; c.style.width = width + "px"; c.dataset.page = i;
      box.appendChild(c); canvases.push(c);
      await page.render({ canvasContext: c.getContext("2d"), viewport: vp }).promise;
    }
    let ended = false;
    const check = () => {
      const last = canvases[canvases.length - 1]; if (!last) return;
      const r = last.getBoundingClientRect(), b = box.getBoundingClientRect();
      let cur = 1; for (const c of canvases) { if (c.getBoundingClientRect().top < b.top + b.height / 2) cur = +c.dataset.page; }
      onProgress && onProgress(cur, n);
      if (!ended && (r.bottom <= b.bottom + 4 || n === 1 || box.scrollHeight <= box.clientHeight + 4)) { ended = true; onEnd && onEnd(); }
    };
    box.addEventListener("scroll", check, { passive: true }); setTimeout(check, 50);
    return { pages: n, destroy: () => { box.removeEventListener("scroll", check); URL.revokeObjectURL(url); doc.destroy(); } };
  }
  return { mount };
})();
