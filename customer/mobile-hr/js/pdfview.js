/* Golden HR — in-app PDF reader (CV of a candidate). pdf.js is served from this origin (vendor/, CSP script-src 'self'); the file is
 * rendered to canvases from memory and is never written to the phone. GAS-CP-R97-156 */
window.PDFV = (() => {
  const HERE = (document.currentScript && document.currentScript.src) || location.href;
  const LIB = new URL("../vendor/pdf.min.js", HERE).href, WORKER = new URL("../vendor/pdf.worker.min.js", HERE).href, MAX_PAGES = 20;
  let lib = null;
  async function getLib() { if (lib) return lib; lib = await import(LIB); lib.GlobalWorkerOptions.workerSrc = WORKER; return lib; }
  async function mount(box, blob) {
    const L = await getLib(); const doc = await L.getDocument({ data: await blob.arrayBuffer(), isEvalSupported: false }).promise;
    const n = Math.min(doc.numPages, MAX_PAGES); box.innerHTML = ""; const width = Math.min(box.clientWidth || 340, 900); const dpr = Math.min(window.devicePixelRatio || 1, 2.5);
    for (let i = 1; i <= n; i++) {
      const page = await doc.getPage(i); const v0 = page.getViewport({ scale: 1 }); const vp = page.getViewport({ scale: (width / v0.width) * dpr });
      const c = document.createElement("canvas"); c.width = vp.width; c.height = vp.height; c.style.width = "100%"; box.appendChild(c);
      await page.render({ canvasContext: c.getContext("2d"), viewport: vp }).promise;
      if (!document.body.contains(box)) break;
    }
    if (doc.numPages > n) { const p = document.createElement("p"); p.className = "muted"; p.textContent = `${n} / ${doc.numPages}`; box.appendChild(p); }
    return { pages: n, destroy: () => { try { doc.destroy(); } catch (e) {} box.innerHTML = ""; } };
  }
  return { mount };
})();
