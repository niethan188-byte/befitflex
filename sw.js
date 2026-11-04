const VERSION = 'bff-mobile-v4';
const STATIC_CACHE = VERSION + '-static';
const PRIVATE_CACHE = VERSION + '-private';
const MAX_PRIVATE_PAGES = 40;
const STATIC_ASSETS = [
  './manifest.webmanifest',
  './assets/css/glass.css',
  './assets/css/login.css?v=3',
  './assets/css/ui.css',
  './assets/js/app.js',
  './assets/js/ui.js',
  './assets/img/logo.png'
];

function offlineResponse(request) {
  if (request.mode === 'navigate') {
    return new Response(
      '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<style>body{margin:0;padding:2rem;background:#121212;color:#fff;font:16px system-ui}h1{color:#E53935}</style>' +
      '<h1>Be Fit Flex is offline</h1><p>This page has not been saved on this device. Reconnect, open it once, then it will be available offline.</p>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  }
  return new Response('', { status: 503, statusText: 'Service Unavailable' });
}

function isPrivatePage(url) {
  return /\/(?:admin|member)\/[A-Za-z0-9_-]+\.php$/.test(url.pathname);
}

function lastPageKey() {
  return new Request(new URL('__bff_last_private_page__', self.registration.scope).href);
}

async function savePrivatePage(request, response) {
  const contentType = response.headers.get('Content-Type') || '';
  if (!response.ok || response.type !== 'basic' || response.redirected || !contentType.includes('text/html')) {
    return;
  }

  const cache = await caches.open(PRIVATE_CACHE);
  await cache.delete(request);
  await cache.put(request, response.clone());
  const lastPage = lastPageKey();
  await cache.delete(lastPage);
  await cache.put(lastPage, new Response(request.url, {
    headers: { 'Content-Type': 'text/plain; charset=utf-8' }
  }));
  const requests = (await cache.keys()).filter((cachedRequest) => cachedRequest.url !== lastPage.url);
  await Promise.all(requests.slice(0, Math.max(0, requests.length - MAX_PRIVATE_PAGES))
    .map((oldRequest) => cache.delete(oldRequest)));
}

async function offlineHomeResponse(request) {
  const cache = await caches.open(PRIVATE_CACHE);
  const response = await cache.match(lastPageKey());
  if (response) {
    const url = new URL(await response.text());
    if (url.origin === self.location.origin && isPrivatePage(url) && await cache.match(url.href)) {
      return Response.redirect(url.href, 302);
    }
  }
  return offlineResponse(request);
}

async function cachedPrivatePage(request, clientId) {
  const cache = await caches.open(PRIVATE_CACHE);
  const response = await cache.match(request);
  if (response && clientId) {
    await self.clients.get(clientId)
      .then((client) => {
        if (client) client.postMessage({ type: 'BFF_OFFLINE_PAGE' });
      })
      .catch((error) => console.error('Offline page status could not be sent to the client.', error));
  }
  return response;
}

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
        keys.filter((key) => key !== STATIC_CACHE && key !== PRIVATE_CACHE).map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (url.pathname.endsWith('/logout.php')) {
    event.respondWith(
      caches.delete(PRIVATE_CACHE)
        .then(() => fetch(request))
        .catch((error) => {
          console.error('Offline page cache could not be cleared during sign out.', error);
          return fetch(request).catch(() => offlineResponse(request));
        })
    );
    return;
  }

  if (url.pathname.includes('/api/')) return;

  if (request.mode === 'navigate') {
    if (!isPrivatePage(url)) {
      event.respondWith(fetch(request).catch(() => (
        url.pathname.endsWith('/index.php') || url.pathname.endsWith('/')
          ? offlineHomeResponse(request)
          : offlineResponse(request)
      )));
      return;
    }

    event.respondWith(
      fetch(request)
        .then(async (response) => {
          if (response.redirected) {
            await caches.delete(PRIVATE_CACHE).catch((error) => {
              console.error('Offline page cache could not be cleared after an authentication redirect.', error);
            });
          } else {
            await savePrivatePage(request, response).catch((error) => {
              console.error('Private page could not be saved for offline access.', error);
            });
          }
          return response;
        })
        .catch(async () => (await cachedPrivatePage(request, event.resultingClientId || event.clientId))
          || offlineResponse(request))
    );
    return;
  }

  event.respondWith(
    caches.match(request, { ignoreSearch: true })
      .then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
          if (response.ok && response.type === 'basic') {
            const copy = response.clone();
            event.waitUntil(
              caches.open(STATIC_CACHE)
                .then((cache) => cache.put(request, copy))
                .catch((error) => console.warn('Service worker cache write failed.', error))
            );
          }
          return response;
        });
      })
      .catch(() => offlineResponse(request))
  );
});
