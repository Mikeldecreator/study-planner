'use strict';

/* =========================================================
   STUDY PLANNER SERVICE WORKER
   File:
   public/service-worker.js
   ========================================================= */


/* =========================================================
   HELPERS FOR BACKGROUND SUBSCRIPTION RENEWAL
   ========================================================= */

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function getPushApiUrl(action) {
    const loc = self.location.pathname;
    let base = '/api/push.php';
    if (loc.includes('/public/')) {
        base = loc.substring(0, loc.indexOf('/public/')) + '/api/push.php';
    }
    const url = new URL(base, self.location.origin);
    if (action) {
        url.searchParams.set('action', action);
    }
    return url.toString();
}


/* =========================================================
   INSTALL
   ========================================================= */

self.addEventListener('install', function (event) {

    event.waitUntil(
        self.skipWaiting()
    );

});


/* =========================================================
   ACTIVATE
   ========================================================= */

self.addEventListener('activate', function (event) {

    event.waitUntil(
        self.clients.claim()
    );

});


/* =========================================================
   RECEIVE PUSH
   ========================================================= */

self.addEventListener('push', function (event) {

    let data = {};

    try {
        if (event.data) {
            data = event.data.json();
        }
    } catch (error) {
        data = {
            title: 'Study Planner',
            body: event.data
                ? event.data.text()
                : 'You have a new study reminder.'
        };
    }

    const title = data.title || 'Study Planner';
    const tag = String(data.tag || 'study-planner-reminder');

    // Resolve absolute URLs for icon and badge to avoid Android WebView / PWA path resolution failures
    const origin = self.location.origin;
    const iconUrl = data.icon && data.icon.startsWith('http')
        ? data.icon
        : new URL(data.icon || '/assets/images/icon-192x192.png', origin).href;
    const badgeUrl = data.badge && data.badge.startsWith('http')
        ? data.badge
        : new URL(data.badge || '/assets/images/favicon-32x32.png', origin).href;

    const options = {
        body: data.body || 'You have a new study reminder.',
        icon: iconUrl,
        badge: badgeUrl,
        tag: tag,
        vibrate: Array.isArray(data.vibrate) ? data.vibrate : [200, 100, 200],
        renotify: data.renotify !== false,
        requireInteraction: data.requireInteraction === true,
        timestamp: data.timestamp || Date.now(),
        data: data.data || {
            url: '/notifications.php'
        }
    };

    // Forward to any open client windows so in-app state updates immediately
    const broadcastPromise = self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(function (clients) {
            clients.forEach(function (client) {
                client.postMessage({
                    type: 'PUSH_NOTIFICATION_RECEIVED',
                    notification: data
                });
            });
        })
        .catch(function () {});

    // Guaranteed showNotification with graceful fallback if rich options fail on specific Android versions
    const showPromise = self.registration.showNotification(title, options)
        .catch(function (err) {
            console.error('Service Worker showNotification error:', err);
            return self.registration.showNotification(title, {
                body: options.body,
                icon: iconUrl,
                tag: tag
            });
        });

    event.waitUntil(Promise.all([showPromise, broadcastPromise]));

});


/* =========================================================
   PUSH SUBSCRIPTION CHANGE (LIFECYCLE ROTATION / SELF-HEAL)
   ========================================================= */

self.addEventListener('pushsubscriptionchange', function (event) {

    event.waitUntil(
        (async function () {
            try {
                const oldEndpoint = event.oldSubscription ? event.oldSubscription.endpoint : null;
                let newSubscription = event.newSubscription;

                if (!newSubscription) {
                    const keyUrl = getPushApiUrl('vapid_public_key');
                    const keyRes = await fetch(keyUrl, { cache: 'no-store' });
                    if (!keyRes.ok) {
                        return;
                    }

                    const keyData = await keyRes.json().catch(() => ({}));
                    if (!keyData || !keyData.public_key) {
                        return;
                    }

                    const applicationServerKey = urlBase64ToUint8Array(keyData.public_key);
                    newSubscription = await self.registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: applicationServerKey
                    });
                }

                if (!newSubscription) {
                    return;
                }

                const subJson = newSubscription.toJSON();
                const saveUrl = getPushApiUrl('subscribe');

                await fetch(saveUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        endpoint: subJson.endpoint,
                        p256dh: subJson.keys?.p256dh || '',
                        auth: subJson.keys?.auth || '',
                        content_encoding: subJson.contentEncoding || 'aes128gcm',
                        expiration_time: subJson.expirationTime || null,
                        old_endpoint: oldEndpoint
                    })
                });
            } catch (err) {
                // Silently tolerate background rotation errors
            }
        })()
    );

});


/* =========================================================
   NOTIFICATION CLICK
   ========================================================= */

self.addEventListener(
    'notificationclick',
    function (event) {

        event.notification.close();


        const data =
            event.notification.data || {};


        const targetUrl =
            data.url ||
            '/notifications.php';


        event.waitUntil(

            self.clients
                .matchAll({
                    type: 'window',
                    includeUncontrolled: true
                })
                .then(function (clients) {

                    for (
                        const client of clients
                    ) {

                        if (
                            client.url.includes('/notifications.php') ||
                            client.url.includes('/dashboard.php')
                        ) {

                            return client
                                .navigate(targetUrl)
                                .then(function () {
                                    return client.focus();
                                });

                        }

                    }


                    if (
                        self.clients.openWindow
                    ) {

                        return self.clients.openWindow(
                            targetUrl
                        );

                    }


                    return null;

                })

        );

    }
);


/* =========================================================
   NOTIFICATION CLOSE
   ========================================================= */

self.addEventListener(
    'notificationclose',
    function () {

        // Nothing required.

    }
);