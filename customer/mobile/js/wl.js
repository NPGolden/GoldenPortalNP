/* Golden A/S — Wellness (GAS-CP-R97-293): the same file in the Service, PM and Employee apps.
 * The anonymous check-in of the day, a breathing minute, stretches for manual work, a thank-you to a colleague, the company information with questions
 * and answers, an anonymous question to HR, and my settings for the field (weather, rest, travel, leave, quiet hours).
 * GET /wellness (kept on the phone, meta "wl") · POST /wellness/mood {mood} · /wellness/prefs {…} · /wellness/question {body} · /wellness/thanks {to, reason}. */
window.WL = (() => {
  const L = {
    en: { title: "Wellness", hint: "Check-in, stretches, company information, my settings", loading: "Loading…", offline: "No connection — try again when you are online.",
      mood: "How was today?", mood_anon: "Anonymous: only the answer and the day are stored.", mood_thanks: "Thank you.", m1: "Very hard", m2: "Hard", m3: "All right", m4: "Good", m5: "Very good",
      breathe: "A breathing minute", br_start: "Start", br_in: "Breathe in", br_hold: "Hold", br_out: "Breathe out", br_done: "Well done.",
      stretch: "Stretches for manual work", s1: "Back: hands on your hips, lean back gently five times.", s2: "Shoulders: roll them slowly backwards ten times.", s3: "Wrists: palm up, pull the fingers back for 15 seconds on each hand.", s4: "Neck: ear towards the shoulder, 10 seconds each side — no circles.", s5: "Knees and hips: stand up and walk for two minutes after kneeling or crouching.", s6: "Lifting: close to the body, bend the knees, never twist while carrying.",
      thanks: "Thank a colleague", th_to: "colleague", th_why: "For what?", th_send: "Send thanks", th_ok: "Thanks sent.", th_e: "Choose a colleague and write a few words.",
      info: "Company information", doctor: "Occupational doctor", insurance: "Insurance", incident: "After an accident or incident", other: "Other support",
      qa: "Questions and answers", ask: "Ask HR anonymously", ask_ph: "Your question or remark…", ask_anon: "Your name is not stored. The answer appears here for everybody — do not write anything that identifies you.", ask_send: "Send anonymously", ask_ok: "Your question is with HR.", ask_e: "Write at least ten characters.",
      set: "My settings", o_mood: "Ask me «How was today?»", o_weather: "Weather of the place I am planned at", o_rest: "Rest between shifts (only I see it)", o_travel: "Travel checklist the evening before", o_leave_nudge: "Remind me to take leave", o_quiet: "Quiet hours — the phone waits", from: "From", to: "Until", quiet_d: "Greek time. Urgent news is not held: a change of my planning, a new device, In / Out.", save: "Save", saved: "Saved." },
    el: { title: "Ευεξία", hint: "Έλεγχος ημέρας, διατάσεις, πληροφορίες εταιρείας, ρυθμίσεις", loading: "Φόρτωση…", offline: "Χωρίς σύνδεση — δοκιμάστε ξανά όταν συνδεθείτε.",
      mood: "Πώς ήταν η σημερινή ημέρα;", mood_anon: "Ανώνυμα: αποθηκεύονται μόνο η απάντηση και η ημέρα.", mood_thanks: "Ευχαριστούμε.", m1: "Πολύ δύσκολη", m2: "Δύσκολη", m3: "Εντάξει", m4: "Καλή", m5: "Πολύ καλή",
      breathe: "Ένα λεπτό αναπνοής", br_start: "Έναρξη", br_in: "Εισπνοή", br_hold: "Κράτημα", br_out: "Εκπνοή", br_done: "Μπράβο.",
      stretch: "Διατάσεις για χειρωνακτική εργασία", s1: "Μέση: χέρια στους γοφούς, γείρετε απαλά προς τα πίσω πέντε φορές.", s2: "Ώμοι: αργές περιστροφές προς τα πίσω δέκα φορές.", s3: "Καρποί: παλάμη προς τα πάνω, τραβήξτε τα δάχτυλα πίσω 15 δευτερόλεπτα σε κάθε χέρι.", s4: "Αυχένας: αυτί προς τον ώμο, 10 δευτερόλεπτα κάθε πλευρά — όχι κύκλους.", s5: "Γόνατα και ισχία: σηκωθείτε και περπατήστε δύο λεπτά μετά από γονάτισμα ή σκύψιμο.", s6: "Ανύψωση: κοντά στο σώμα, λυγίστε τα γόνατα, ποτέ στροφή ενώ κουβαλάτε.",
      thanks: "Ευχαριστήστε έναν συνάδελφο", th_to: "συνάδελφος", th_why: "Για ποιο λόγο;", th_send: "Αποστολή", th_ok: "Οι ευχαριστίες στάλθηκαν.", th_e: "Επιλέξτε συνάδελφο και γράψτε δυο λόγια.",
      info: "Πληροφορίες εταιρείας", doctor: "Ιατρός εργασίας", insurance: "Ασφάλιση", incident: "Μετά από ατύχημα ή συμβάν", other: "Άλλη υποστήριξη",
      qa: "Ερωτήσεις και απαντήσεις", ask: "Ρωτήστε το HR ανώνυμα", ask_ph: "Η ερώτηση ή η παρατήρησή σας…", ask_anon: "Το όνομά σας δεν αποθηκεύεται. Η απάντηση εμφανίζεται εδώ για όλους — μην γράφετε κάτι που σας ταυτοποιεί.", ask_send: "Ανώνυμη αποστολή", ask_ok: "Η ερώτησή σας έφτασε στο HR.", ask_e: "Γράψτε τουλάχιστον δέκα χαρακτήρες.",
      set: "Οι ρυθμίσεις μου", o_mood: "Ρώτα με «Πώς ήταν η σημερινή ημέρα;»", o_weather: "Καιρός στον τόπο όπου είμαι προγραμματισμένος", o_rest: "Ανάπαυση μεταξύ βαρδιών (το βλέπω μόνο εγώ)", o_travel: "Λίστα ταξιδιού το προηγούμενο βράδυ", o_leave_nudge: "Υπενθύμιση για άδεια", o_quiet: "Ώρες ησυχίας — το τηλέφωνο περιμένει", from: "Από", to: "Έως", quiet_d: "Ώρα Ελλάδας. Τα επείγοντα δεν κρατούνται: αλλαγή προγραμματισμού, νέα συσκευή, In / Out.", save: "Αποθήκευση", saved: "Αποθηκεύτηκε." },
    it: { title: "Benessere", hint: "Check-in, stretching, informazioni aziendali, impostazioni", loading: "Caricamento…", offline: "Nessuna connessione — riprova quando sei online.",
      mood: "Com’è andata oggi?", mood_anon: "Anonimo: si salvano solo la risposta e il giorno.", mood_thanks: "Grazie.", m1: "Molto dura", m2: "Dura", m3: "Così così", m4: "Bene", m5: "Molto bene",
      breathe: "Un minuto di respiro", br_start: "Avvia", br_in: "Inspira", br_hold: "Trattieni", br_out: "Espira", br_done: "Ben fatto.",
      stretch: "Stretching per il lavoro manuale", s1: "Schiena: mani sui fianchi, inclinati piano all’indietro cinque volte.", s2: "Spalle: ruotale lentamente all’indietro dieci volte.", s3: "Polsi: palmo in su, tira indietro le dita per 15 secondi per mano.", s4: "Collo: orecchio verso la spalla, 10 secondi per lato — niente cerchi.", s5: "Ginocchia e anche: alzati e cammina due minuti dopo essere stato in ginocchio o accovacciato.", s6: "Sollevamento: vicino al corpo, piega le ginocchia, mai ruotare mentre porti il carico.",
      thanks: "Ringrazia un collega", th_to: "collega", th_why: "Per cosa?", th_send: "Invia", th_ok: "Ringraziamento inviato.", th_e: "Scegli un collega e scrivi qualche parola.",
      info: "Informazioni aziendali", doctor: "Medico del lavoro", insurance: "Assicurazione", incident: "Dopo un infortunio o un evento", other: "Altro supporto",
      qa: "Domande e risposte", ask: "Chiedi a HR in forma anonima", ask_ph: "La tua domanda o osservazione…", ask_anon: "Il tuo nome non viene salvato. La risposta compare qui per tutti — non scrivere nulla che ti identifichi.", ask_send: "Invia in forma anonima", ask_ok: "La tua domanda è arrivata a HR.", ask_e: "Scrivi almeno dieci caratteri.",
      set: "Le mie impostazioni", o_mood: "Chiedimi «Com’è andata oggi?»", o_weather: "Meteo del luogo in cui sono pianificato", o_rest: "Riposo tra i turni (lo vedo solo io)", o_travel: "Promemoria di viaggio la sera prima", o_leave_nudge: "Ricordami di prendere ferie", o_quiet: "Ore di silenzio — il telefono attende", from: "Dalle", to: "Alle", quiet_d: "Ora greca. Le notizie urgenti non sono trattenute: modifica della pianificazione, nuovo dispositivo, In / Out.", save: "Salva", saved: "Salvato." },
    bg: { title: "Благосъстояние", hint: "Проверка на деня, разтягане, информация от фирмата, настройки", loading: "Зареждане…", offline: "Няма връзка — опитайте отново, когато сте онлайн.",
      mood: "Как мина днес?", mood_anon: "Анонимно: записват се само отговорът и денят.", mood_thanks: "Благодарим.", m1: "Много тежко", m2: "Тежко", m3: "Нормално", m4: "Добре", m5: "Много добре",
      breathe: "Минута за дишане", br_start: "Начало", br_in: "Вдишайте", br_hold: "Задръжте", br_out: "Издишайте", br_done: "Браво.",
      stretch: "Разтягане при физическа работа", s1: "Гръб: ръце на хълбоците, наклонете се леко назад пет пъти.", s2: "Рамене: бавни кръгове назад десет пъти.", s3: "Китки: дланта нагоре, изтеглете пръстите назад за 15 секунди на всяка ръка.", s4: "Врат: ухо към рамото, по 10 секунди на страна — без кръгове.", s5: "Колене и таз: станете и походете две минути след коленичене или клякане.", s6: "Вдигане: близо до тялото, свийте коленете, не се извъртайте, докато носите.",
      thanks: "Благодарете на колега", th_to: "колега", th_why: "За какво?", th_send: "Изпрати", th_ok: "Благодарността е изпратена.", th_e: "Изберете колега и напишете няколко думи.",
      info: "Информация от фирмата", doctor: "Служба по трудова медицина", insurance: "Застраховка", incident: "След злополука или инцидент", other: "Друга подкрепа",
      qa: "Въпроси и отговори", ask: "Попитайте HR анонимно", ask_ph: "Вашият въпрос или бележка…", ask_anon: "Името ви не се записва. Отговорът се показва тук за всички — не пишете нищо, което ви идентифицира.", ask_send: "Изпрати анонимно", ask_ok: "Въпросът ви е при HR.", ask_e: "Напишете поне десет знака.",
      set: "Моите настройки", o_mood: "Питай ме «Как мина днес?»", o_weather: "Времето на мястото, за което съм планиран", o_rest: "Почивка между смените (виждам го само аз)", o_travel: "Списък за пътуване предната вечер", o_leave_nudge: "Напомняй ми да ползвам отпуск", o_quiet: "Тихи часове — телефонът изчаква", from: "От", to: "До", quiet_d: "Гръцко време. Спешните новини не се задържат: промяна в планирането, ново устройство, In / Out.", save: "Запази", saved: "Запазено." },
    uk: { title: "Самопочуття", hint: "Опитування дня, розтяжка, інформація компанії, налаштування", loading: "Завантаження…", offline: "Немає з’єднання — спробуйте знову, коли будете онлайн.",
      mood: "Як минув сьогоднішній день?", mood_anon: "Анонімно: зберігаються лише відповідь і день.", mood_thanks: "Дякуємо.", m1: "Дуже важко", m2: "Важко", m3: "Нормально", m4: "Добре", m5: "Дуже добре",
      breathe: "Хвилина дихання", br_start: "Почати", br_in: "Вдих", br_hold: "Затримка", br_out: "Видих", br_done: "Чудово.",
      stretch: "Розтяжка для фізичної роботи", s1: "Спина: руки на стегна, м’яко прогніться назад п’ять разів.", s2: "Плечі: повільні оберти назад десять разів.", s3: "Зап’ястя: долоня вгору, відтягніть пальці назад на 15 секунд на кожній руці.", s4: "Шия: вухо до плеча, по 10 секунд на бік — без кіл.", s5: "Коліна і стегна: встаньте й походіть дві хвилини після роботи навколішки чи навпочіпки.", s6: "Піднімання: близько до тіла, згинайте коліна, не повертайтеся з вантажем.",
      thanks: "Подякуйте колезі", th_to: "колега", th_why: "За що?", th_send: "Надіслати", th_ok: "Подяку надіслано.", th_e: "Оберіть колегу й напишіть кілька слів.",
      info: "Інформація компанії", doctor: "Лікар з гігієни праці", insurance: "Страхування", incident: "Після нещасного випадку чи інциденту", other: "Інша підтримка",
      qa: "Запитання та відповіді", ask: "Запитайте HR анонімно", ask_ph: "Ваше запитання або зауваження…", ask_anon: "Ваше ім’я не зберігається. Відповідь з’являється тут для всіх — не пишіть нічого, що вас ідентифікує.", ask_send: "Надіслати анонімно", ask_ok: "Ваше запитання в HR.", ask_e: "Напишіть щонайменше десять символів.",
      set: "Мої налаштування", o_mood: "Запитувати «Як минув день?»", o_weather: "Погода місця, на яке мене заплановано", o_rest: "Відпочинок між змінами (бачу лише я)", o_travel: "Пам’ятка для поїздки напередодні ввечері", o_leave_nudge: "Нагадувати про відпустку", o_quiet: "Тихі години — телефон чекає", from: "З", to: "До", quiet_d: "Час за Грецією. Термінове не затримується: зміна планування, новий пристрій, In / Out.", save: "Зберегти", saved: "Збережено." },
    ka: { title: "კეთილდღეობა", hint: "დღის გამოკითხვა, გაჭიმვები, კომპანიის ინფორმაცია, პარამეტრები", loading: "იტვირთება…", offline: "კავშირი არ არის — სცადეთ ხელახლა, როცა ონლაინ იქნებით.",
      mood: "როგორი იყო დღევანდელი დღე?", mood_anon: "ანონიმურია: ინახება მხოლოდ პასუხი და დღე.", mood_thanks: "გმადლობთ.", m1: "ძალიან რთული", m2: "რთული", m3: "ნორმალური", m4: "კარგი", m5: "ძალიან კარგი",
      breathe: "სუნთქვის ერთი წუთი", br_start: "დაწყება", br_in: "ჩაისუნთქეთ", br_hold: "შეაჩერეთ", br_out: "ამოისუნთქეთ", br_done: "ყოჩაღ.",
      stretch: "გაჭიმვები ფიზიკური სამუშაოსთვის", s1: "წელი: ხელები თეძოებზე, ნაზად გადაიხარეთ უკან ხუთჯერ.", s2: "მხრები: ნელა დაატრიალეთ უკან ათჯერ.", s3: "მაჯები: ხელისგული ზემოთ, თითები უკან გასწიეთ 15 წამით თითო ხელზე.", s4: "კისერი: ყური მხრისკენ, თითო მხარეს 10 წამი — წრეების გარეშე.", s5: "მუხლები და თეძოები: მუხლებზე დგომის ან ჩაცუცქვის შემდეგ ადექით და ორი წუთი გაიარეთ.", s6: "აწევა: ტანთან ახლოს, მოხარეთ მუხლები, ტვირთით არასდროს შებრუნდეთ.",
      thanks: "მადლობა გადაუხადეთ კოლეგას", th_to: "კოლეგა", th_why: "რისთვის?", th_send: "გაგზავნა", th_ok: "მადლობა გაიგზავნა.", th_e: "აირჩიეთ კოლეგა და დაწერეთ რამდენიმე სიტყვა.",
      info: "კომპანიის ინფორმაცია", doctor: "შრომის ექიმი", insurance: "დაზღვევა", incident: "უბედური შემთხვევის ან ინციდენტის შემდეგ", other: "სხვა მხარდაჭერა",
      qa: "შეკითხვები და პასუხები", ask: "ჰკითხეთ HR-ს ანონიმურად", ask_ph: "თქვენი შეკითხვა ან შენიშვნა…", ask_anon: "თქვენი სახელი არ ინახება. პასუხი აქ ყველასთვის ჩნდება — არ დაწეროთ ისეთი რამ, რაც თქვენს ვინაობას ავლენს.", ask_send: "ანონიმურად გაგზავნა", ask_ok: "თქვენი შეკითხვა HR-თანაა.", ask_e: "დაწერეთ მინიმუმ ათი სიმბოლო.",
      set: "ჩემი პარამეტრები", o_mood: "მკითხე «როგორი იყო დღე?»", o_weather: "ამინდი იმ ადგილას, სადაც დაგეგმილი ვარ", o_rest: "დასვენება ცვლებს შორის (მხოლოდ მე ვხედავ)", o_travel: "მგზავრობის სია წინა საღამოს", o_leave_nudge: "შემახსენე შვებულების აღება", o_quiet: "მშვიდი საათები — ტელეფონი ელოდება", from: "დან", to: "მდე", quiet_d: "საბერძნეთის დრო. სასწრაფო არ ჩერდება: გეგმის ცვლილება, ახალი მოწყობილობა, In / Out.", save: "შენახვა", saved: "შენახულია." },
  };
  const lang = () => { try { const l = String(I18N.get() || "en").slice(0, 2); return L[l] ? l : "en"; } catch (e) { return "en"; } };
  const tt = (k) => (L[lang()] || L.en)[k] || L.en[k] || k;
  const esc = (x) => SCREENS.esc(x);
  const msg = (e) => (e && [400, 403, 404, 409, 422].includes(e.status)) ? String(e.message || "").replace(/\s*\((GET|POST) [^)]*\)$/, "") : tt("offline");
  const keep = async (p) => { await DB.put("meta", Object.assign({ id: "wl", fetched_at: new Date().toISOString() }, p)); return p; };
  async function refresh() { try { return await keep(await API.wellness()); } catch (e) { return null; } }
  let timer = null;
  const chk = (k, P) => `<label style="display:flex;gap:10px;align-items:flex-start;padding:6px 0"><input type="checkbox" data-pref="${k}" ${P[k] ? "checked" : ""} style="width:22px;height:22px;flex:none"><span>${tt("o_" + k)}</span></label>`;
  function html(p) {
    if (!p) return `<div class="card"><p class="muted">${tt("loading")}</p></div>`;
    if (p.enabled === false) return "";
    const P = p.prefs || {}; let h = "";
    if (!p.mood_done && P.mood) h += `<h2>🙂 ${tt("mood")}</h2><div class="card c-gold" data-mood><div style="display:flex;gap:6px;justify-content:space-between">${["😣", "🙁", "😐", "🙂", "😄"].map((f, i) => `<button class="btn" data-m="${i + 1}" title="${esc(tt("m" + (i + 1)))}" style="flex:1;font-size:26px;padding:10px 0">${f}</button>`).join("")}</div><p class="s muted" style="margin:8px 0 0">🔒 ${tt("mood_anon")}</p><p class="note red hidden" data-err></p></div>`;
    h += `<h2>🌬 ${tt("breathe")}</h2><div class="card c-grey" data-breathe><div class="t" data-br style="text-align:center;font-size:20px;min-height:30px"></div><button class="btn gold" data-brs>${tt("br_start")}</button></div>`;
    h += `<h2>🤸 ${tt("stretch")}</h2><div class="card c-grey">${[1, 2, 3, 4, 5, 6].map((i) => `<p style="margin:6px 0">${tt("s" + i)}</p>`).join("")}</div>`;
    if ((p.colleagues || []).length) h += `<h2>👏 ${tt("thanks")}</h2><div class="card c-grey" data-thanks><select data-to style="width:100%"><option value="0">— ${tt("th_to")} —</option>${p.colleagues.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join("")}</select><input data-why maxlength="300" placeholder="${esc(tt("th_why"))}" style="width:100%;margin:6px 0"><p class="note red hidden" data-err></p><button class="btn gold" data-ths>👏 ${tt("th_send")}</button></div>`;
    const inf = p.info || {}; const ik = ["doctor", "insurance", "incident", "other"].filter((k) => inf[k]);
    if (ik.length) h += `<h2>ℹ ${tt("info")}</h2><div class="card c-grey">${ik.map((k) => `<p style="margin:8px 0 2px"><b>${tt(k)}</b></p><p style="margin:0;white-space:pre-line">${esc(inf[k])}</p>`).join("")}</div>`;
    if ((p.qa || []).length) h += `<h2>❓ ${tt("qa")}</h2>` + p.qa.map((q) => `<div class="card c-grey"><p style="margin:0 0 4px;white-space:pre-line"><b>${esc(q.q)}</b></p><p style="margin:0;white-space:pre-line">${esc(q.a)}</p></div>`).join("");
    if (p.questions) h += `<h2>✉ ${tt("ask")}</h2><div class="card c-grey" data-ask><textarea data-body rows="3" maxlength="1000" placeholder="${esc(tt("ask_ph"))}" style="width:100%"></textarea><p class="s muted">🔒 ${tt("ask_anon")}</p><p class="note red hidden" data-err></p><button class="btn gold" data-asks>${tt("ask_send")}</button></div>`;
    h += `<h2>⚙ ${tt("set")}</h2><div class="card c-grey" data-set>${["mood", "weather", "rest", "travel", "leave_nudge", "quiet"].map((k) => chk(k, P)).join("")}<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:4px 0"><label>${tt("from")} <input type="time" data-qf value="${esc(P.quiet_from || "20:00")}"></label><label>${tt("to")} <input type="time" data-qt value="${esc(P.quiet_to || "07:00")}"></label></div><p class="s muted">${tt("quiet_d")}</p><p class="note red hidden" data-err></p><button class="btn gold" data-save>${tt("save")}</button></div>`;
    return h;
  }
  function breathe(card) {
    const el = card.querySelector("[data-br]"), b = card.querySelector("[data-brs]"); if (timer) { clearInterval(timer); timer = null; }
    const seq = [["br_in", 4], ["br_hold", 4], ["br_out", 6]]; let cyc = 0, st = 0, left = seq[0][1]; b.disabled = true; el.textContent = tt(seq[0][0]) + " " + left;
    timer = setInterval(() => { if (!document.body.contains(el)) { clearInterval(timer); timer = null; return; } left--; if (left <= 0) { st++; if (st >= seq.length) { st = 0; cyc++; } if (cyc >= 4) { clearInterval(timer); timer = null; el.textContent = tt("br_done"); b.disabled = false; return; } left = seq[st][1]; } el.textContent = tt(seq[st][0]) + " " + left; }, 1000);
  }
  function bind(root) {
    const box = root.querySelector("#wl-box") || root; const draw = (p) => { box.innerHTML = html(p); };
    box.onclick = async (ev) => {
      const b = ev.target.closest("button"); if (!b || b.disabled) return; const card = b.closest(".card"); if (!card) return;
      const err = card.querySelector("[data-err]"); const fail = (m) => { if (err) { err.textContent = m; err.classList.remove("hidden"); } else UI.toast(m); }; if (err) err.classList.add("hidden");
      const run = async (fn, okMsg, redraw) => { b.disabled = true; try { const r = await fn(); if (r && r.state) { await keep(r.state); if (redraw) draw(r.state); } UI.toast(okMsg, 2500); return true; } catch (e) { b.disabled = false; fail(msg(e)); return false; } };
      if (b.dataset.m) return run(() => API.wellnessPost("mood", { mood: +b.dataset.m }), tt("mood_thanks"), true);
      if (b.hasAttribute("data-brs")) return breathe(card);
      if (b.hasAttribute("data-ths")) { const to = +card.querySelector("[data-to]").value, why = card.querySelector("[data-why]").value.trim(); if (!to || why.length < 3) return fail(tt("th_e")); if (await run(() => API.wellnessPost("thanks", { to, reason: why }), tt("th_ok"))) { card.querySelector("[data-why]").value = ""; card.querySelector("[data-to]").value = "0"; b.disabled = false; } return; }
      if (b.hasAttribute("data-asks")) { const body = card.querySelector("[data-body]").value.trim(); if (body.length < 10) return fail(tt("ask_e")); if (await run(() => API.wellnessPost("question", { body }), tt("ask_ok"))) { card.querySelector("[data-body]").value = ""; b.disabled = false; } return; }
      if (b.hasAttribute("data-save")) { const o = { quiet_from: card.querySelector("[data-qf]").value, quiet_to: card.querySelector("[data-qt]").value }; card.querySelectorAll("[data-pref]").forEach((c) => { o[c.dataset.pref] = c.checked; }); if (await run(() => API.wellnessPost("prefs", o), tt("saved"))) b.disabled = false; return; }
    };
  }
  async function page() {
    UI.chrome(true, tt("title"), "more", false);
    const have = await DB.get("meta", "wl");
    refresh().then((p) => { if (p && /^#\/wellness/.test(location.hash)) { const box = document.getElementById("wl-box"); if (box && !box.querySelector("[data-br]:not(:empty)")) box.innerHTML = html(p); } });
    return { html: `<div id="wl-box">${html(have)}</div>`, bind };
  }
  function moreCard() { return `<div class="card tap c-gold" data-go="#/wellness"><div class="t">🌿 ${tt("title")}</div><div class="s muted">${tt("hint")}</div></div>`; }
  return { page, refresh, moreCard };
})();
