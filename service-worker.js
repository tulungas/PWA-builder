const CACHE_NAME = "pwa-cache-v1";
const ASSETS = ["./manifest.json","./index.php","./icon-192.png","./icon-512.png","./Dockerfile"];

self.addEventListener("install", e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(c => c.addAll(ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener("fetch", e => {
  e.respondWith(
    caches.match(e.request).then(r => r || fetch(e.request))
  );
});
