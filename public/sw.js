/* Service worker SOTRAMA : affiche les notifications envoyées par le serveur (rappels des ramassages). */
self.addEventListener('push', function (event) {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { corps: event.data ? event.data.text() : '' }; }
    event.waitUntil(self.registration.showNotification(data.titre || 'SOTRAMA', {
        body: data.corps || '',
        icon: '/images/logo-sotrama.png',
        badge: '/images/logo-sotrama.png',
        tag: data.tag || undefined,
        renotify: !!data.tag,
        requireInteraction: true,
        data: { url: data.url || '/' }
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (fenetres) {
        for (const f of fenetres) {
            if (f.url === url && 'focus' in f) { return f.focus(); }
        }
        return clients.openWindow(url);
    }));
});
