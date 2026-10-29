// Service Worker for School Absences Application
const CACHE_PREFIX = 'school-absences-';
const CACHE_NAME = CACHE_PREFIX + 'security-step9-v2';
const ASSETS_TO_CACHE = [
  './assets/js/absence-calendar.js?v=single-day-1',
  './assets/css/style.css',
  './assets/css/style.css?v=security-step9-v2',
  './assets/js/admin-ui.js?v=import-1',
  './assets/js/theme.js'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(ASSETS_TO_CACHE).catch(() => {});
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME).map(key => caches.delete(key))
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  // Only cache GET requests for static css/js
  if (event.request.method === 'GET' && new URL(event.request.url).origin === self.location.origin && new URL(event.request.url).pathname.startsWith(new URL('./assets/', self.location.href).pathname) && (event.request.url.includes('/assets/css/') || event.request.url.includes('/assets/js/'))) {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache => cache.match(event.request)).then(cachedResponse => {
        return cachedResponse || fetch(event.request).then(networkResponse => {
          if (networkResponse && networkResponse.status === 200) {
            const responseClone = networkResponse.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(event.request, responseClone));
          }
          return networkResponse;
        });
      }).catch(() => fetch(event.request))
    );
  }
});
