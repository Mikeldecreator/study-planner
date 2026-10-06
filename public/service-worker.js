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

    // Resolve correlation data from payload
    const extraData = data.data || {};
    const notificationId = extraData.notification_id || data.notification_id || null;
    const eventKey = extraData.event_key || data.event_key || null;
    const payloadSubHash = extraData.endpoint_hash || data.endpoint_hash || null;
    const payloadFingerprint = extraData.sub_fingerprint || data.sub_fingerprint || null;
    const subId = extraData.sub_id || data.sub_id || null;

    // Helper to safely derive active subscription fingerprint using existing web crypto
    async function getSubscriptionFingerprint() {
        try {
            const sub = await self.registration.pushManager.getSubscription();
            if (sub && sub.endpoint) {
                let hash = null;
                if (self.crypto && self.crypto.subtle) {
                    const msgUint8 = new TextEncoder().encode(sub.endpoint);
                    const hashBuffer = await self.crypto.subtle.digest('SHA-256', msgUint8);
                    hash = Array.from(new Uint8Array(hashBuffer))
                        .map(function (b) { return b.toString(16).padStart(2, '0'); })
                        .join('');
                }
                return {
                    endpoint: sub.endpoint,
                    endpointHash: hash,
                    fingerprint: hash ? hash.substring(0, 16) : null
                };
            }
        } catch (e) {}
        return { endpoint: null, endpointHash: null, fingerprint: null };
    }

    // Helper to send telemetry back to server with non-sensitive correlation data
    async function sendTelemetry(eventType, extraFields) {
        try {
            const subInfo = await getSubscriptionFingerprint();
            const logUrl = getPushApiUrl('sw_log');
            const epHash = subInfo.endpointHash || payloadSubHash || null;
            const fp = subInfo.fingerprint || payloadFingerprint || (epHash ? epHash.substring(0, 16) : null);
            const now = Date.now();

            const bodyData = Object.assign({
                event: eventType,
                endpoint: subInfo.endpoint || null,
                endpoint_hash: epHash,
                sub_fingerprint: fp,
                sub_id: subId,
                notification_id: notificationId,
                event_key: eventKey,
                tag: tag,
                title: title,
                timestamp: now,
                utc_timestamp: new Date(now).toISOString()
            }, extraFields || {});

            await fetch(logUrl, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(bodyData)
            });
        } catch (e) {
            // Telemetry failure should never prevent notification display
        }
    }

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

    // Guaranteed showNotification with empirical telemetry and graceful fallback
    const showPromise = (async function () {
        const receivedPromise = sendTelemetry('push_received', { title: title, tag: tag });
        let showResult;
        try {
            showResult = await self.registration.showNotification(title, options);
            await sendTelemetry('notification_shown_success', { title: title, tag: tag });
        } catch (err) {
            console.error('Service Worker showNotification error:', err);
            await sendTelemetry('notification_shown_error', {
                error: String(err),
                stack: err ? err.stack : null,
                tag: tag
            });
            // Fallback attempt with minimal options
            showResult = await self.registration.showNotification(title, {
                body: options.body,
                icon: iconUrl,
                tag: tag
            });
        }
        await receivedPromise;
        return showResult;
    })();

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

        const clickLogPromise = (async function () {
            try {
                const sub = await self.registration.pushManager.getSubscription();
                let hash = null;
                if (sub && sub.endpoint && self.crypto && self.crypto.subtle) {
                    const msgUint8 = new TextEncoder().encode(sub.endpoint);
                    const hashBuffer = await self.crypto.subtle.digest('SHA-256', msgUint8);
                    hash = Array.from(new Uint8Array(hashBuffer))
                        .map(function (b) { return b.toString(16).padStart(2, '0'); })
                        .join('');
                }
                const epHash = hash || data.endpoint_hash || null;
                const fp = (hash ? hash.substring(0, 16) : null) || data.sub_fingerprint || null;
                const now = Date.now();
                const logUrl = getPushApiUrl('sw_log');
                await fetch(logUrl, {
                    method: 'POST',
                    keepalive: true,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        event: 'notification_clicked',
                        endpoint: sub ? sub.endpoint : null,
                        endpoint_hash: epHash,
                        sub_fingerprint: fp,
                        sub_id: data.sub_id || null,
                        notification_id: data.notification_id || null,
                        event_key: data.event_key || null,
                        tag: event.notification ? event.notification.tag : null,
                        timestamp: now,
                        utc_timestamp: new Date(now).toISOString()
                    })
                });
            } catch (e) {}
        })();

        event.waitUntil(
            Promise.all([
                clickLogPromise,

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
            ])
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