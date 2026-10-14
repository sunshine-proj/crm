// Compiled separately (WebWorker lib) so it doesn't clash with DOM types.
const sw = self as unknown as ServiceWorkerGlobalScope;
const CACHE = 'scicalc-v1';
const FILES: string[] = ['./', './index.html', './app.js', './manifest.json', './icon-192.png', './icon-512.png'];

sw.addEventListener('install', (e: ExtendableEvent) => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(FILES)));
  sw.skipWaiting();
});

sw.addEventListener('activate', (e: ExtendableEvent) => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
  );
  sw.clients.claim();
});

// Cache-first: serve from cache, fall back to network
sw.addEventListener('fetch', (e: FetchEvent) => {
  e.respondWith(caches.match(e.request).then(r => r ?? fetch(e.request)));
});
