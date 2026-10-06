const VERSION = 'bff-mobile-v1';
const STATIC_CACHE = VERSION + '-static';
const STATIC_ASSETS = [
  './',
  './kiosk.php',
  './manifest.webmanifest',
  './assets/css/glass.css',
  './assets/css/ui.css',
  './assets/js/app.js',
  './assets/js/ui.js',
  './assets/img/logo.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key !== STATIC_CACHE).map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.includes('/api/')) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => new Response(
        '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<style>body{margin:0;padding:2rem;background:#121212;color:#fff;font:16px system-ui}h1{color:#E53935}</style>' +
        '<h1>Be Fit Flex is offline</h1><p>Reconnect to load your latest dashboard data.</p>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
      ))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (response.ok && response.type === 'basic') {
        const copy = response.clone();
        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
      }
      return response;
    }))
  );
});
