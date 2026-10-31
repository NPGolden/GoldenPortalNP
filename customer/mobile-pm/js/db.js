/* Golden PM — IndexedDB: cache stores (read model) + outbox (write queue).
 * Every outgoing record carries a UUID and client timestamp (idempotent on the server, POST /sync).
 * Same layout as the Service app (customer/mobile/js/db.js) so one team maintains both. */
window.DB = (() => {
  const NAME = "gas-pm", VER = 1;
  // projects: GET /pm/projects items (with subprojects) · subs: GET /pm/subproject/{id} details per id · drawings / submittals: §4 / §5 rows
  // team: one record {id:"team"} (§6) · notes: sp_notes across projects · documents · inbox (§9) · meta: me, hr, stages, staff · outbox · photos
  const STORES = ["projects", "subs", "drawings", "submittals", "team", "notes", "documents", "inbox", "meta", "outbox", "photos"];
  let db;

  function open() {
    if (db) return Promise.resolve(db);
    return new Promise((res, rej) => {
      const r = indexedDB.open(NAME, VER);
      r.onupgradeneeded = () => {
        const d = r.result;
        STORES.forEach((s) => { if (!d.objectStoreNames.contains(s)) d.createObjectStore(s, { keyPath: "id" }); });
      };
      r.onsuccess = () => { db = r.result; res(db); };
      r.onerror = () => rej(r.error);
    });
  }
  const tx = (store, mode, fn) => open().then((d) => new Promise((res, rej) => {
    const t = d.transaction(store, mode); const s = t.objectStore(store); const out = fn(s);
    t.oncomplete = () => res(out && out.result !== undefined ? out.result : out); t.onerror = () => rej(t.error);
  }));
  const put = (store, obj) => tx(store, "readwrite", (s) => { s.put(obj); return obj; });
  const putAll = (store, arr) => tx(store, "readwrite", (s) => { arr.forEach((o) => s.put(o)); return arr.length; });
  const get = (store, id) => open().then((d) => new Promise((res, rej) => { const r = d.transaction(store).objectStore(store).get(id); r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); }));
  const all = (store) => open().then((d) => new Promise((res, rej) => { const r = d.transaction(store).objectStore(store).getAll(); r.onsuccess = () => res(r.result || []); r.onerror = () => rej(r.error); }));
  const del = (store, id) => tx(store, "readwrite", (s) => { s.delete(id); return true; });
  const clear = (store) => tx(store, "readwrite", (s) => { s.clear(); return true; });
  const wipe = () => Promise.all(STORES.map(clear));
  /** replace a whole store with the server list (local rows that pass `keep` survive) */
  const replace = async (store, items, keep) => { const kept = keep ? (await all(store)).filter(keep) : []; await clear(store); await putAll(store, items.concat(kept)); };

  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === "x" ? r : (r & 3 | 8)).toString(16); }));

  /** queue an operation: kind = sp_stages | sp_comment | issue_status | note | drawing_update | hr_decide | ts_decide | nf_read | hr_* (own) | photo
   *  status: queued → (rejected | needs_totp) ; accepted operations are deleted */
  const enqueue = (kind, payload, uuidOverride) => put("outbox", { id: uuidOverride || uuid(), kind, payload, client_ts: new Date().toISOString(), tries: 0, status: "queued" });
  const outbox = () => all("outbox").then((a) => a.sort((x, y) => x.client_ts.localeCompare(y.client_ts)));

  return { open, put, putAll, get, all, del, clear, wipe, replace, uuid, enqueue, outbox, STORES };
})();
