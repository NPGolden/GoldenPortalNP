/* Golden A/S — My tools (GAS-CP-R97-291): the same file in the Service, PM and Employee apps.
 * What I hold (with the calibration of instruments), issue lists answered line by line, tools handed to me on a project and — for a supervisor —
 * the tools on my projects: hand out, take back. Read model GET /my-tools (the last answer is kept on the phone, meta "tools");
 * POST /my-tools/lists/{id}/answer {agree, no:{line: reason}} · POST /my-tools/site/{unit}/give {person_id|name} · …/back {result, note}. The portal applies every rule. */
window.TOOLS = (() => {
  const L = {
    en: { title: "My tools", hint: "Equipment in my name, lists to accept, tools on my projects", loading: "Loading…", offline: "No connection — try again when you are online.", none: "Nothing is in your name.", noperson: "Your account is not linked to a personnel record — ask HR.",
      lists: "Lists to accept", list: "Issue list", accept: "I accept", why: "If not accepted — why", agree: "I received the equipment of the ticked lines and accept the declaration of the list.", send: "Send my answer", e_why: "Write why the unticked line is not accepted.", e_agree: "Tick the box to confirm.", done: "Answer sent: {a} accepted, {r} not accepted.",
      mine: "In my name", cal: "Calibration", until: "valid until", handed: "Handed to me on a project", since: "since", site: "Tools on my projects", sup: "Supervisor", give: "Hand out", back: "Take back", found: "Found", pick: "member of staff", name: "or another name", note: "Remark", e_person: "Choose a person or write a name.", e_note: "Write what happened to the tool.",
      f_site: "on site", f_out: "handed out", f_late: "not back", f_missing: "missing", r_ok: "in order", r_damaged: "damaged", r_missing: "missing", done_proj: "Project completed — return the tools to the warehouse.", n_wait: "{n} to accept", n_late: "{n} not back" },
    el: { title: "Τα εργαλεία μου", hint: "Εξοπλισμός στο όνομά μου, λίστες προς αποδοχή, εργαλεία στα έργα μου", loading: "Φόρτωση…", offline: "Χωρίς σύνδεση — δοκιμάστε ξανά όταν συνδεθείτε.", none: "Δεν υπάρχει τίποτα στο όνομά σας.", noperson: "Ο λογαριασμός σας δεν είναι συνδεδεμένος με καρτέλα προσωπικού — ρωτήστε το HR.",
      lists: "Λίστες προς αποδοχή", list: "Λίστα παράδοσης", accept: "Αποδέχομαι", why: "Αν δεν το αποδέχομαι — γιατί", agree: "Παρέλαβα τον εξοπλισμό των σημειωμένων γραμμών και αποδέχομαι τη δήλωση της λίστας.", send: "Αποστολή απάντησης", e_why: "Γράψτε γιατί δεν αποδέχεστε τη γραμμή χωρίς σημάδι.", e_agree: "Σημειώστε το πλαίσιο για επιβεβαίωση.", done: "Η απάντηση στάλθηκε: {a} αποδεκτές, {r} μη αποδεκτές.",
      mine: "Στο όνομά μου", cal: "Διακρίβωση", until: "ισχύει έως", handed: "Μου παραδόθηκαν σε έργο", since: "από", site: "Εργαλεία στα έργα μου", sup: "Επιβλέπων", give: "Παράδοση", back: "Παραλαβή", found: "Βρέθηκε", pick: "μέλος προσωπικού", name: "ή άλλο όνομα", note: "Παρατήρηση", e_person: "Επιλέξτε άτομο ή γράψτε όνομα.", e_note: "Γράψτε τι συνέβη με το εργαλείο.",
      f_site: "στο έργο", f_out: "παραδομένο", f_late: "δεν επέστρεψε", f_missing: "λείπει", r_ok: "εντάξει", r_damaged: "με ζημιά", r_missing: "λείπει", done_proj: "Το έργο ολοκληρώθηκε — επιστρέψτε τα εργαλεία στην αποθήκη.", n_wait: "{n} προς αποδοχή", n_late: "{n} δεν επέστρεψαν" },
    it: { title: "I miei attrezzi", hint: "Dotazioni a mio nome, elenchi da accettare, attrezzi nei miei progetti", loading: "Caricamento…", offline: "Nessuna connessione — riprova quando sei online.", none: "Non c’è nulla a tuo nome.", noperson: "Il tuo account non è collegato a una scheda del personale — chiedi a HR.",
      lists: "Elenchi da accettare", list: "Elenco di consegna", accept: "Accetto", why: "Se non accetto — perché", agree: "Ho ricevuto le dotazioni delle righe spuntate e accetto la dichiarazione dell’elenco.", send: "Invia la risposta", e_why: "Scrivi perché la riga senza spunta non è accettata.", e_agree: "Spunta la casella per confermare.", done: "Risposta inviata: {a} accettate, {r} non accettate.",
      mine: "A mio nome", cal: "Taratura", until: "valida fino al", handed: "Consegnati a me in un progetto", since: "dal", site: "Attrezzi nei miei progetti", sup: "Supervisore", give: "Consegna", back: "Ritira", found: "Ritrovato", pick: "membro del personale", name: "o un altro nome", note: "Osservazione", e_person: "Scegli una persona o scrivi un nome.", e_note: "Scrivi cosa è successo all’attrezzo.",
      f_site: "in cantiere", f_out: "consegnato", f_late: "non rientrato", f_missing: "mancante", r_ok: "in ordine", r_damaged: "danneggiato", r_missing: "mancante", done_proj: "Progetto concluso — restituire gli attrezzi al magazzino.", n_wait: "{n} da accettare", n_late: "{n} non rientrati" },
    bg: { title: "Моите инструменти", hint: "Оборудване на мое име, списъци за приемане, инструменти по моите проекти", loading: "Зареждане…", offline: "Няма връзка — опитайте отново, когато сте онлайн.", none: "Няма нищо на ваше име.", noperson: "Профилът ви не е свързан с личен картон — попитайте HR.",
      lists: "Списъци за приемане", list: "Списък за издаване", accept: "Приемам", why: "Ако не приемам — защо", agree: "Получих оборудването от отметнатите редове и приемам декларацията на списъка.", send: "Изпрати отговора", e_why: "Напишете защо редът без отметка не е приет.", e_agree: "Отметнете полето за потвърждение.", done: "Отговорът е изпратен: {a} приети, {r} неприети.",
      mine: "На мое име", cal: "Калибриране", until: "валидно до", handed: "Дадени ми по проект", since: "от", site: "Инструменти по моите проекти", sup: "Отговорник", give: "Дай", back: "Приеми обратно", found: "Намерен", pick: "служител", name: "или друго име", note: "Бележка", e_person: "Изберете човек или напишете име.", e_note: "Напишете какво се е случило с инструмента.",
      f_site: "на обекта", f_out: "даден", f_late: "не е върнат", f_missing: "липсва", r_ok: "в ред", r_damaged: "повреден", r_missing: "липсва", done_proj: "Проектът е приключен — върнете инструментите в склада.", n_wait: "{n} за приемане", n_late: "{n} не са върнати" },
    uk: { title: "Мої інструменти", hint: "Обладнання на моє ім’я, списки для прийняття, інструменти на моїх проєктах", loading: "Завантаження…", offline: "Немає з’єднання — спробуйте знову, коли будете онлайн.", none: "На ваше ім’я нічого немає.", noperson: "Ваш обліковий запис не пов’язаний з карткою персоналу — зверніться до HR.",
      lists: "Списки для прийняття", list: "Список видачі", accept: "Приймаю", why: "Якщо не приймаю — чому", agree: "Я отримав(-ла) обладнання з позначених рядків і приймаю заяву списку.", send: "Надіслати відповідь", e_why: "Напишіть, чому рядок без позначки не прийнято.", e_agree: "Поставте позначку для підтвердження.", done: "Відповідь надіслано: {a} прийнято, {r} не прийнято.",
      mine: "На моє ім’я", cal: "Калібрування", until: "чинне до", handed: "Видано мені на проєкті", since: "з", site: "Інструменти на моїх проєктах", sup: "Відповідальний", give: "Видати", back: "Прийняти назад", found: "Знайдено", pick: "працівник", name: "або інше ім’я", note: "Примітка", e_person: "Оберіть особу або напишіть ім’я.", e_note: "Напишіть, що сталося з інструментом.",
      f_site: "на об’єкті", f_out: "видано", f_late: "не повернуто", f_missing: "відсутній", r_ok: "у порядку", r_damaged: "пошкоджено", r_missing: "відсутній", done_proj: "Проєкт завершено — поверніть інструменти на склад.", n_wait: "{n} до прийняття", n_late: "{n} не повернуто" },
    ka: { title: "ჩემი ხელსაწყოები", hint: "აღჭურვილობა ჩემს სახელზე, მისაღები სიები, ხელსაწყოები ჩემს პროექტებზე", loading: "იტვირთება…", offline: "კავშირი არ არის — სცადეთ ხელახლა, როცა ონლაინ იქნებით.", none: "თქვენს სახელზე არაფერია.", noperson: "თქვენი ანგარიში პერსონალის ბარათთან დაკავშირებული არ არის — მიმართეთ HR-ს.",
      lists: "მისაღები სიები", list: "გაცემის სია", accept: "ვიღებ", why: "თუ არ ვიღებ — რატომ", agree: "მივიღე მონიშნული სტრიქონების აღჭურვილობა და ვეთანხმები სიის განცხადებას.", send: "პასუხის გაგზავნა", e_why: "დაწერეთ, რატომ არ იღებთ მოუნიშნავ სტრიქონს.", e_agree: "დასადასტურებლად მონიშნეთ ველი.", done: "პასუხი გაიგზავნა: მიღებულია {a}, არ არის მიღებული {r}.",
      mine: "ჩემს სახელზე", cal: "კალიბრაცია", until: "მოქმედებს", handed: "პროექტზე გადმომეცა", since: "დან", site: "ხელსაწყოები ჩემს პროექტებზე", sup: "ზედამხედველი", give: "გადაცემა", back: "უკან მიღება", found: "ნაპოვნია", pick: "თანამშრომელი", name: "ან სხვა სახელი", note: "შენიშვნა", e_person: "აირჩიეთ პირი ან დაწერეთ სახელი.", e_note: "დაწერეთ, რა დაემართა ხელსაწყოს.",
      f_site: "ობიექტზე", f_out: "გადაცემულია", f_late: "არ დაბრუნებულა", f_missing: "დაკარგულია", r_ok: "წესრიგშია", r_damaged: "დაზიანებულია", r_missing: "დაკარგულია", done_proj: "პროექტი დასრულებულია — დააბრუნეთ ხელსაწყოები საწყობში.", n_wait: "{n} მისაღები", n_late: "{n} არ დაბრუნებულა" },
  };
  const lang = () => { try { const l = String(I18N.get() || "en").slice(0, 2); return L[l] ? l : "en"; } catch (e) { return "en"; } };
  const tt = (k, v) => { let s = (L[lang()] || L.en)[k] || L.en[k] || k; if (v) Object.keys(v).forEach((x) => { s = s.replace("{" + x + "}", v[x]); }); return s; };
  const esc = (x) => SCREENS.esc(x);
  const day = (s) => { if (!s) return ""; try { return new Date(String(s).slice(0, 10) + "T12:00:00").toLocaleDateString(I18N.get(), { day: "numeric", month: "short", year: "numeric" }); } catch (e) { return s; } };
  const hm = (s) => { if (!s) return ""; const d = String(s).replace(" ", "T"); return day(d) + " " + d.slice(11, 16); };
  const TONE = { green: "green", gold: "amber", bad: "red", mute: "grey", site: "green", out: "amber", late: "red", missing: "red", held: "green", pending: "amber" };
  const chip = (tone, text) => `<span class="chip ${TONE[tone] || "grey"}">${esc(text)}</span>`;
  const msg = (e) => (e && [403, 404, 409, 422].includes(e.status)) ? String(e.message || "").replace(/\s*\((GET|POST) [^)]*\)$/, "") : tt("offline");
  let busy = false;
  const keep = async (p) => { await DB.put("meta", Object.assign({ id: "tools", fetched_at: new Date().toISOString() }, p)); return p; };
  async function refresh() { if (busy) return null; busy = true; try { return await keep(await API.toolsMine(lang())); } catch (e) { return null; } finally { busy = false; } }

  function html(p) {
    if (!p) return `<div class="card"><p class="muted">${tt("loading")}</p></div>`;
    if (p.enabled === false) return `<div class="card"><p class="muted">${tt("none")}</p></div>`;
    let h = "";
    if (p.person === false && !(p.site || []).length) h += `<div class="card"><p class="muted">${tt("noperson")}</p></div>`;
    (p.lists || []).forEach((d) => {
      h += `<h2>✉ ${tt("list")} ${esc(d.no)}</h2><div class="card c-gold" data-list="${d.id}"><div class="s muted">${esc(day(d.date))} • ${esc(d.by)}${d.note ? " • " + esc(d.note) : ""}</div>` +
        d.lines.map((l) => `<div class="kv" style="align-items:flex-start;flex-wrap:wrap"><label style="display:flex;gap:8px;align-items:flex-start;max-width:100%"><input type="checkbox" data-ok="${l.id}" checked style="width:22px;height:22px;flex:none"><span><b>${esc(l.description)}</b><br><small class="muted">${esc(l.category)}${l.serial ? " • S/N " + esc(l.serial) : ""}${l.tag ? " • " + esc(l.tag) : ""} • × ${l.qty} • ${esc(l.cond)}</small></span></label><input data-why="${l.id}" maxlength="300" placeholder="${esc(tt("why"))}" style="width:100%;margin-top:6px"></div>`).join("") +
        `<label style="display:flex;gap:8px;align-items:flex-start;margin:10px 0"><input type="checkbox" data-agree style="width:22px;height:22px;flex:none"><span>${tt("agree")}</span></label><p class="note red hidden" data-err></p><button class="btn gold" data-send="${d.id}">✔ ${tt("send")}</button></div>`;
    });
    if ((p.handed || []).length) h += `<h2>📍 ${tt("handed")} (${p.handed.length})</h2>` + p.handed.map((x) => `<div class="card c-gold"><div class="t">${esc(x.no)} <small>${esc(x.description)}</small></div><div class="s muted">${esc(x.project)} • ${tt("since")} ${esc(hm(x.since))}${x.by ? " • " + esc(x.by) : ""}</div></div>`).join("");
    const it = p.items || [];
    if (it.length || p.person !== false) h += `<h2>🧰 ${tt("mine")} (${it.length})</h2>` + (it.length ? it.map((x) => `<div class="card ${x.cal && (x.cal.tone === "bad") ? "c-red" : "c-grey"}"><div class="t">${esc(x.description)}</div><div class="s muted">${esc(x.category)}${x.serial ? " • S/N " + esc(x.serial) : ""}${x.tag ? " • " + esc(x.tag) : ""}${x.qty !== 1 ? " • × " + x.qty : ""}</div><div class="s">${chip(x.status, x.status_label)}${x.cal ? ` 📏 ${tt("cal")}: ${chip(x.cal.tone, x.cal.label)}${x.cal.until ? ` <small class="muted">${tt("until")} ${esc(day(x.cal.until))}</small>` : ""}` : ""}</div></div>`).join("") : `<p class="muted">${tt("none")}</p>`);
    (p.site || []).forEach((g, gi) => {
      h += `${gi ? "" : `<h2>📍 ${tt("site")}</h2>`}<div class="card ${g.n.late || g.n.missing || g.project.done ? "c-red" : "c-grey"}"><div class="t">${esc(g.project.code)} <small>${esc(g.project.name)}</small></div><div class="s muted">${g.n.all} • ${tt("f_out")}: ${g.n.out} • ${tt("f_late")}: ${g.n.late} • ${tt("f_missing")}: ${g.n.missing}</div>${g.project.done ? `<div class="s">${chip("bad", tt("done_proj"))}</div>` : ""}</div>` +
        g.units.map((u) => `<div class="card ${u.flag === "late" || u.flag === "missing" ? "c-red" : u.flag === "out" ? "c-gold" : "c-grey"}" data-unit="${u.id}"><div class="t">${esc(u.no)} <small>${esc(u.description)}</small></div><div class="s">${chip(u.flag, tt("f_" + u.flag))}${u.with ? ` <b>${esc(u.with)}</b> <small class="muted">${tt("since")} ${esc(hm(u.since))}</small>` : ""}</div>` +
          (!u.can ? "" : u.with ? `<div class="s" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px"><select data-res style="flex:1;min-width:110px">${["ok", "damaged", "missing"].map((r) => `<option value="${r}">${tt("r_" + r)}</option>`).join("")}</select><input data-note maxlength="255" placeholder="${esc(tt("note"))}" style="flex:2;min-width:120px"><button class="btn" data-back="${u.id}">↩ ${tt("back")}</button></div>`
            : u.flag === "missing" ? `<div class="s" style="display:flex;gap:6px;margin-top:6px"><input data-note maxlength="255" placeholder="${esc(tt("note"))}" style="flex:1"><button class="btn" data-found="${u.id}">✔ ${tt("found")}</button></div>`
            : `<div class="s" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px"><select data-person style="flex:2;min-width:140px"><option value="0">— ${tt("pick")} —</option>${(p.people || []).map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join("")}</select><input data-name maxlength="120" placeholder="${esc(tt("name"))}" style="flex:1;min-width:110px"><button class="btn gold" data-give="${u.id}">👤 ${tt("give")}</button></div>`) +
          `<p class="note red hidden" data-err></p></div>`).join("");
    });
    return h || `<div class="card"><p class="muted">${tt("none")}</p></div>`;
  }
  function bind(root) {
    const box = root.querySelector("#tools-box") || root; const draw = (p) => { box.innerHTML = html(p); };
    box.onclick = async (ev) => {
      const b = ev.target.closest("button"); if (!b || b.disabled) return; const card = b.closest(".card"); if (!card) return;
      const err = card.querySelector("[data-err]"); const fail = (m) => { if (err) { err.textContent = m; err.classList.remove("hidden"); } else UI.toast(m); }; if (err) err.classList.add("hidden");
      const run = async (fn, okMsg) => { b.disabled = true; try { const r = await fn(); if (r && r.mine) { await keep(r.mine); draw(r.mine); } if (okMsg) UI.toast(typeof okMsg === "function" ? okMsg(r) : okMsg, 3000); } catch (e) { b.disabled = false; fail(msg(e)); } };
      if (b.dataset.send) { const no = {}; let bad = false; card.querySelectorAll("[data-ok]").forEach((c) => { if (!c.checked) { const w = card.querySelector(`[data-why="${c.dataset.ok}"]`).value.trim(); if (!w) bad = true; no[c.dataset.ok] = w; } });
        if (bad) return fail(tt("e_why")); if (!card.querySelector("[data-agree]").checked) return fail(tt("e_agree"));
        return run(() => API.toolsAnswer(+b.dataset.send, { agree: true, no }, lang()), (r) => tt("done", { a: r.accepted, r: r.refused })); }
      if (b.dataset.give) { const pid = +card.querySelector("[data-person]").value, name = card.querySelector("[data-name]").value.trim(); if (!pid && !name) return fail(tt("e_person")); return run(() => API.toolsGive(+b.dataset.give, { person_id: pid, name }, lang())); }
      if (b.dataset.back) { const result = card.querySelector("[data-res]").value, note = card.querySelector("[data-note]").value.trim(); if (result !== "ok" && !note) return fail(tt("e_note")); return run(() => API.toolsBack(+b.dataset.back, { result, note }, lang())); }
      if (b.dataset.found) return run(() => API.toolsBack(+b.dataset.found, { result: "ok", note: card.querySelector("[data-note]").value.trim() }, lang()));
    };
  }
  async function page() {
    UI.chrome(true, tt("title"), "more", false);
    const have = await DB.get("meta", "tools");
    refresh().then((p) => { if (p && /^#\/tools/.test(location.hash)) { const box = document.getElementById("tools-box"); if (box) box.innerHTML = html(p); } });
    return { html: `<div class="card"><p class="muted" style="margin:0">${tt("hint")}</p></div><div id="tools-box">${html(have)}</div>`, bind };
  }
  /** Card for the «More» screen. */
  function moreCard() { return `<div class="card tap c-gold" data-go="#/tools"><div class="t">🧰 ${tt("title")}</div><div class="s muted">${tt("hint")}</div></div>`; }
  return { page, refresh, moreCard };
})();
