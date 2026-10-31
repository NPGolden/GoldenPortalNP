/* Golden A/S Service app — Claude assistance, exactly as in the portal (app/ai.php):
 * kinds  write (draft / improve the focused text in house style) · translate (to EN/EL/IT/UK/BG) · ask (question with the
 *        current form as context) · fill (read a photo of a nameplate / handwritten note and propose field values)
 * pages  fsr_edit · isr_edit · ticket · equipment_edit · timesheet_edit — enabled per page in portal Settings › Assistant.
 * The app never holds an API key: POST /assist → portal ai_run() (system prompt, logging in ai_log, monetary fields
 * stripped). Results are suggestions — the engineer confirms before anything is written. Online only. */
window.ASSIST = (() => {
  const t = I18N.t; const esc = (s) => SCREENS.esc(s);
  let history = [];   // free chat of this session (memory only, never stored)
  const KINDS = [["write", "ai.write"], ["translate", "ai.translate"], ["ask", "ai.askform"], ["fill", "ai.fill"]];
  const LANG_NAMES = { en: "English", el: "Greek", it: "Italian", uk: "Ukrainian", bg: "Bulgarian" };
  async function run(kind, body) {
    if (!navigator.onLine && !API.mock) throw new Error(t("ai.offline"));
    if (!(SCREENS.ctx.me || {}).ai && !API.mock) throw new Error(t("ai.disabled"));
    return API.assist({ kind, ...body });
  }
  /** opts: { page, title, headline, fields(), getText(), setText(text), noFill } */
  function open(opts) {
    const kinds = KINDS.filter(([k]) => (k !== "fill" || (opts.setText && !opts.noFill)) && (k !== "write" || opts.getText));
    UI.sheet(`<h3>✦ ${t("ai.title")}</h3><p class="muted">${t("ai.hint")}</p>
      <div class="ai-actions">${kinds.map(([k, l]) => `<button class="btn line sm" data-act="${k}">${t(l)}</button>`).join("")}</div>
      <input type="file" id="ai-img" accept="image/*" capture="environment" class="hidden">
      <div id="ai-out" class="ai-out hidden"></div>
      <div class="row2" style="margin-top:8px"><input id="ai-q" placeholder="${t("ai.ask")}"><button class="btn navy sm" id="ai-send" style="margin:0">${t("ai.send")}</button></div>
      <p class="muted" style="font-size:11px">${t("ai.disclaimer")}</p>`, null, (sh) => {
      const out = sh.querySelector("#ai-out"); let last = "";
      const show = (text, canInsert) => { last = text; out.classList.remove("hidden"); out.innerHTML = `<div class="ai-text">${esc(text).replace(/\n/g, "<br>")}</div><div class="row2" style="margin-top:8px">${canInsert && opts.setText ? `<button class="btn gold sm" id="ai-use">${t("ai.use")}</button>` : ""}<button class="btn line sm" id="ai-copy">${t("ai.copy")}</button></div>`;
        const use = out.querySelector("#ai-use"); if (use) use.onclick = () => { opts.setText(last); UI.closeSheet(); UI.toast(t("ai.inserted")); };
        out.querySelector("#ai-copy").onclick = () => { try { navigator.clipboard.writeText(last); UI.toast(t("ai.copied")); } catch (e) {} }; };
      const busy = (b) => { sh.querySelectorAll("button").forEach((x) => x.disabled = b); if (b) { out.classList.remove("hidden"); out.innerHTML = `<div class="ai-text muted">${t("ai.thinking")}</div>`; } };
      const base = () => ({ page: opts.page || "fsr_edit", title: opts.title || "", headline: opts.headline || "", fields: opts.fields ? opts.fields() : [] });
      const go = async (kind, body, insert) => { busy(true); try { const r = await run(kind, { ...base(), ...body }); busy(false); show(r.text || "", insert); return r; } catch (e) { busy(false); show(e.message || t("ai.error"), false); } };
      sh.querySelectorAll("[data-act]").forEach((b) => b.onclick = async () => {
        const act = b.dataset.act; const text = opts.getText ? opts.getText() : "";
        if (act === "write") { if (!text.trim()) { show(t("ai.needtext"), false); return; } return go("write", { prompt: `Improve this text in the house style of a Golden A/S ${opts.title || "report"}; keep every technical fact, fix grammar, be concise:\n\n${text}` }, true); }
        if (act === "translate") { const lang = await pickLang(sh); if (!lang) return; return go("translate", { prompt: `Translate to ${LANG_NAMES[lang] || lang}:\n\n${text || "(all form fields)"}` }, !!text); }
        if (act === "ask") { sh.querySelector("#ai-q").focus(); show(t("ai.askhint"), false); return; }
        if (act === "fill") { sh.querySelector("#ai-img").click(); }
      });
      sh.querySelector("#ai-img").onchange = async (e) => { const f = e.target.files[0]; if (!f) return; const b64 = await toB64(f);
        const r = await go("fill", { prompt: "Read the photo (nameplate, handwritten note or screen) and propose values for the fields of this form.", image_b64: b64.data, image_type: b64.type }, false);
        if (r && r.fields && typeof r.fields === "object") { const lines = Object.entries(r.fields).map(([k, v]) => `${k}: ${v}`).join("\n"); show(lines + (r.notes ? `\n\n${r.notes}` : ""), true); } };
      const send = async () => { const q = sh.querySelector("#ai-q").value.trim(); if (!q) return; history.push({ role: "user", text: q }); sh.querySelector("#ai-q").value = "";
        const r = await go("ask", { prompt: history.slice(-6).map((h) => `${h.role === "user" ? "Q" : "A"}: ${h.text}`).join("\n") }, !!opts.setText); if (r) history.push({ role: "assistant", text: r.text || "" }); };
      sh.querySelector("#ai-send").onclick = send; sh.querySelector("#ai-q").onkeydown = (e) => { if (e.key === "Enter") send(); };
    });
  }
  const toB64 = (file) => new Promise((res) => { const img = new Image(); img.onload = () => { const max = 1280; const sc = Math.min(1, max / Math.max(img.width, img.height)); const c = document.createElement("canvas"); c.width = Math.round(img.width * sc); c.height = Math.round(img.height * sc); c.getContext("2d").drawImage(img, 0, 0, c.width, c.height); const url = c.toDataURL("image/jpeg", 0.8); URL.revokeObjectURL(img.src); res({ data: url.split(",")[1], type: "image/jpeg" }); }; img.src = URL.createObjectURL(file); });
  function pickLang(sh) {
    return new Promise((res) => { const out = sh.querySelector("#ai-out"); out.classList.remove("hidden");
      out.innerHTML = `<div class="ai-text">${t("ai.translateto")}</div><div class="lang" style="margin-top:6px">${I18N.LANGS.map(([c, l]) => `<button data-l="${c}">${l}</button>`).join("")}</div>`;
      out.querySelectorAll("[data-l]").forEach((b) => b.onclick = () => res(b.dataset.l)); });
  }
  /** small ✦ button placed right after a textarea */
  const button = (label) => `<button type="button" class="ai-btn" data-ai>✦ ${esc(label || t("ai.claude"))}</button>`;
  return { open, run, button };
})();
