/* Only the public offline notice and icon are cached. Moodle pages, API, media and POST stay on the network. */
self.addEventListener('install', function (event) {
    event.waitUntil(caches.open(USTAR_APP_CACHE).then(async function (cache) {
        for (const url of USTAR_PUBLIC_ASSETS) {
            const response = await fetch(url, {credentials: 'omit', cache: 'reload'});
            if (!response.ok) throw new Error('Public app asset unavailable');
            await cache.put(url, response);
        }
    }));
});
self.addEventListener('activate', function (event) {
    event.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (key) { return key.startsWith('ustar-app-') && key !== USTAR_APP_CACHE; })
            .map(function (key) { return caches.delete(key); }));
    }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (event) {
    if (event.request.method !== 'GET' || new URL(event.request.url).origin !== self.location.origin) return;
    if (USTAR_PUBLIC_ASSETS.includes(event.request.url)) {
        event.respondWith(caches.open(USTAR_APP_CACHE).then(async function (cache) {
            return await cache.match(event.request.url) || fetch(event.request);
        }));
    } else if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(function () {
            return caches.open(USTAR_APP_CACHE).then(function (cache) { return cache.match(USTAR_PUBLIC_ASSETS[0]); });
        }));
    }
});
