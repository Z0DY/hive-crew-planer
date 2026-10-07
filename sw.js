// HIVE Crew-Planer – Service Worker
// Bei App-Updates VERSION erhöhen, damit alle Geräte die neuen Dateien laden.
const VERSION = "hive-v4";
const SHELL = ["./", "index.html", "timetable.json", "manifest.webmanifest", "app-icons/icon-192.png", "app-icons/icon-512.png", "app-icons/apple-touch-icon.png"];
const FONT_CACHE = "hive-fonts";

self.addEventListener("install", e => {
  // Einzeln cachen: eine fehlende Datei darf die Installation nicht verhindern
  e.waitUntil(caches.open(VERSION).then(c => Promise.allSettled(SHELL.map(f => c.add(f)))).then(() => self.skipWaiting()));
});
self.addEventListener("activate", e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION && k !== FONT_CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener("fetch", e => {
  const req = e.request, url = new URL(req.url);
  if (req.method !== "GET") return;
  if (url.pathname.endsWith("/api.php")) return;                       // Daten verwaltet die Seite selbst
  if (url.pathname.endsWith("/sw.js")) return;                         // Versionsabfrage im Admin-Modus: immer vom Server
  if (url.host === "fonts.googleapis.com" || url.host === "fonts.gstatic.com") {
    e.respondWith(caches.open(FONT_CACHE).then(async c => {             // Schriften: einmal laden, dann aus dem Speicher
      const hit = await c.match(req); if (hit) return hit;
      try { const res = await fetch(req); if (res.ok || res.type === "opaque") c.put(req, res.clone()); return res; }
      catch { return new Response("", { status: 504 }); }
    }));
    return;
  }
  if (url.origin !== location.origin) return;
  // App-Dateien: sofort aus dem Speicher, im Hintergrund aktualisieren (schnell auch bei schlechtem Netz)
  e.respondWith(caches.open(VERSION).then(async c => {
    const key = req.mode === "navigate" ? "index.html" : req;
    const hit = await c.match(key, { ignoreSearch: req.mode === "navigate" });
    const net = fetch(req).then(res => { if (res.ok) c.put(key, res.clone()); return res; }).catch(() => null);
    if (hit) { e.waitUntil(net); return hit; }
    return (await net) || new Response("Offline", { status: 503 });
  }));
});

// ---------- Hintergrund-Abgleich (Android / Chrome) ----------
function idb() {
  return new Promise((res, rej) => {
    const r = indexedDB.open("hive", 1);
    r.onupgradeneeded = () => r.result.createObjectStore("kv");
    r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error);
  });
}
async function kv(mode, fn) {
  const db = await idb();
  return new Promise((res, rej) => {
    const tx = db.transaction("kv", mode), st = tx.objectStore("kv"); let out;
    Promise.resolve(fn(st, v => out = v)).catch(rej);
    tx.oncomplete = () => res(out); tx.onerror = () => rej(tx.error);
  });
}
const kvGet = k => kv("readonly", (st, set) => { const r = st.get(k); r.onsuccess = () => set(r.result); });
const kvPut = (k, v) => kv("readwrite", st => { st.put(v, k); });

async function flushOutbox() {
  const outbox = (await kvGet("outbox")) || [];
  if (!outbox.length) return;
  const r = await fetch("api.php", { method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "batch", ops: outbox }) });
  if (!r.ok) throw new Error("sync failed");
  const j = await r.json();
  const sent = new Set(outbox.map(o => o.id));
  const rest = ((await kvGet("outbox")) || []).filter(o => !sent.has(o.id));   // inzwischen neu hinzugekommene behalten
  await kvPut("outbox", rest);
  if (j.state) await kvPut("state", { state: j.state, at: Date.now() });
  const clients = await self.clients.matchAll();
  clients.forEach(c => c.postMessage({ type: "synced" }));
}
self.addEventListener("sync", e => { if (e.tag === "outbox") e.waitUntil(flushOutbox()); });
self.addEventListener("message", e => { if (e.data === "flush") e.waitUntil(flushOutbox().catch(() => {})); });

// ---------- Erinnerungen (Push) ----------
self.addEventListener("push", e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = { body: e.data ? e.data.text() : "" }; }
  e.waitUntil(self.registration.showNotification(d.title || "HIVE Crew-Planer", {
    body: d.body || "", tag: d.tag || undefined, renotify: !!d.tag,
    icon: "app-icons/icon-192.png", vibrate: [200, 100, 200],
    data: { url: d.url || "./#plan" }
  }));
});
self.addEventListener("notificationclick", e => {
  e.notification.close();
  const url = new URL(e.notification.data?.url || "./", self.registration.scope).href;
  const view = new URL(url).hash.slice(1) || "plan";
  e.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(list => {
    for (const c of list) if (c.url.startsWith(self.registration.scope)) { c.postMessage({ type: "goto", view }); return c.focus(); }
    return self.clients.openWindow(url);
  }));
});
