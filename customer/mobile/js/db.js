/* Golden A/S Service app — IndexedDB: cache stores (read model) + outbox (write queue).
 * Every outgoing record carries a UUID and client timestamp (idempotent on the server).
 */
window.DB = (() => {
  const NAME = "gas-service", VER = 1;
  const STORES = ["tickets", "assignments", "projects", "vessels", "equipment", "fsr", "timesheets", "notes", "documents", "budgets", "master", "status", "meta", "outbox", "photos"];
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

  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === "x" ? r : (r & 3 | 8)).toString(16); }));

  /** queue an operation: kind = fsr | timesheet | note | ticket_note | ticket_accept | assignment_accept | extra_hours | equipment_correction | photo */
  const enqueue = (kind, payload, uuidOverride) => put("outbox", { id: uuidOverride || uuid(), kind, payload, client_ts: new Date().toISOString(), tries: 0, status: "queued" });
  const outbox = () => all("outbox").then((a) => a.sort((x, y) => x.client_ts.localeCompare(y.client_ts)));

  return { open, put, putAll, get, all, del, clear, wipe, uuid, enqueue, outbox, STORES };
})();
