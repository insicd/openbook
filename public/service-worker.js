self.addEventListener('install', function (event) {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch (error) {
            payload = {body: event.data.text()};
        }
    }

    var options = {
        body: typeof payload.body === 'string' ? payload.body : '',
        tag: typeof payload.tag === 'string' ? payload.tag : undefined,
        data: {
            url: typeof payload.url === 'string' ? payload.url : '/notifiche'
        }
    };

    if (typeof payload.icon === 'string' && payload.icon !== '') {
        options.icon = payload.icon;
    }

    event.waitUntil(
        self.registration.showNotification(
            typeof payload.title === 'string' && payload.title !== '' ? payload.title : 'OpenBook',
            options
        )
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var requestedUrl = event.notification.data && event.notification.data.url
        ? event.notification.data.url
        : '/notifiche';
    var targetUrl;

    try {
        targetUrl = new URL(requestedUrl, self.location.origin);
        if (targetUrl.origin !== self.location.origin) {
            targetUrl = new URL('/notifiche', self.location.origin);
        }
    } catch (error) {
        targetUrl = new URL('/notifiche', self.location.origin);
    }

    event.waitUntil(
        self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(function (windows) {
            var openbookWindow = windows.find(function (client) {
                return new URL(client.url).origin === self.location.origin;
            });

            if (openbookWindow) {
                return openbookWindow.navigate(targetUrl.href).then(function (client) {
                    return client ? client.focus() : openbookWindow.focus();
                }).catch(function () {
                    return self.clients.openWindow(targetUrl.href);
                });
            }

            return self.clients.openWindow(targetUrl.href);
        })
    );
});
