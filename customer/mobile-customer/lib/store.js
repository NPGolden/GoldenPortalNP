/* store.js — IndexedDB read-cache (lists, details, PDF blobs) with 200 MB soft cap, oldest first.
   Nothing that changes state is queued offline: signing, approvals, offer decisions and replies need a live connection (fresh server time + OTP). */
window.Store = (function () {
  const DB = "gas-customer-app", VER = 1, CAP = 200 * 1024 * 1024;
  let db = null;
  function open() {
    if (db) return Promise.resolve(db);
    return new Promise((res, rej) => {
      const r = indexedDB.open(DB, VER);
      r.onupgradeneeded = () => {
        const d = r.result;
        if (!d.objectStoreNames.contains("kv")) d.createObjectStore("kv");
        if (!d.objectStoreNames.contains("blobs")) { const s = d.createObjectStore("blobs"); s.createIndex("at", "at"); }
      };
      r.onsuccess = () => { db = r.result; res(db); };
      r.onerror = () => rej(r.error);
    });
  }
  const tx = (name, mode, fn) => open().then(d => new Promise((res, rej) => { const t = d.transaction(name, mode); const req = fn(t.objectStore(name)); t.oncomplete = () => res(req && req.result); t.onerror = () => rej(t.error); }));
  async function get(k) { try { return await tx("kv", "readonly", s => s.get(k)); } catch (e) { return undefined; } }
  async function set(k, v) { try { await tx("kv", "readwrite", s => s.put({ v, at: Date.now() }, k)); } catch (e) {} }
  async function getBlob(k) { try { const r = await tx("blobs", "readonly", s => s.get(k)); return r ? r.blob : null; } catch (e) { return null; } }
  async function setBlob(k, blob) { try { await tx("blobs", "readwrite", s => s.put({ blob, at: Date.now(), size: blob.size }, k)); await trim(); } catch (e) {} }
  async function trim() {
    const d = await open();
    return new Promise(res => {
      const t = d.transaction("blobs", "readwrite"); const s = t.objectStore("blobs");
      let total = 0; const items = [];
      s.openCursor().onsuccess = e => { const c = e.target.result; if (c) { total += c.value.size || 0; items.push({ k: c.key, at: c.value.at, size: c.value.size || 0 }); c.continue(); } else {
        if (total > CAP) { items.sort((a, b) => a.at - b.at); for (const it of items) { if (total <= CAP) break; s.delete(it.k); total -= it.size; } }
        res();
      } };
    });
  }
  async function clear() { try { const d = await open(); d.close(); db = null; await new Promise(r => { const x = indexedDB.deleteDatabase(DB); x.onsuccess = x.onerror = x.onblocked = () => r(); }); } catch (e) {} }
  return { get, set, getBlob, setBlob, clear };
})();
