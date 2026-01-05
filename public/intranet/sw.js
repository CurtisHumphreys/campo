const CACHE_NAME = 'campo-intranet-v1';

// Precache essential intranet resources. Paths are relative to the domain root.
const PRECACHE = [
  '/intranet/',
  '/intranet/manifest.json',
  '/public/css/style.css?v=35',
  '/public/css/intranet.css?v=1',
  '/public/js/intranet.js?v=1',
  '/public/icons/android-chrome-192x192.png',
  '/public/icons/android-chrome-512x512.png',
  '/public/icons/apple-touch-icon.png',
  '/public/icons/favicon.ico'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.map((key) => {
        if (key !== CACHE_NAME) return caches.delete(key);
      })
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Always try network first for dynamic intranet content so users see fresh data
  if (url.pathname === '/api/public/intranet') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          return response;
        })
        .catch(() => caches.match(request))
    );
    return;
  }

  // Serve cached shell on navigation if offline
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match('/intranet/'))
    );
    return;
  }

  // Cache-first for other precached resources
  event.respondWith(
    caches.match(request).then((cached) => cached || fetch(request))
  );
});