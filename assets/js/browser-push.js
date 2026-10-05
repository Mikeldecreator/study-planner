/* =========================================================
   STUDY PLANNER
   Browser Push Notifications
   Composer-free version
========================================================= */

(function () {

    'use strict';

    if (window.__STUDY_PLANNER_PUSH_INIT__) {
        return;
    }
    window.__STUDY_PLANNER_PUSH_INIT__ = true;

    let serviceWorkerRegistration = null;


    /* -----------------------------------------------------
       API ENDPOINT RESOLUTION
    ----------------------------------------------------- */

    function getPushApiUrl(action) {
        let base = window.API || '../api';
        const url = new URL(
            base.endsWith('/') ? base + 'push.php' : base + '/push.php',
            window.location.href
        );
        if (action) {
            url.searchParams.set('action', action);
        }
        return url.toString();
    }


    /* -----------------------------------------------------
       BROWSER SUPPORT
    ----------------------------------------------------- */

    function isSupported() {
        return (
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window
        );
    }


    /* -----------------------------------------------------
       BASE64 → UINT8ARRAY
    ----------------------------------------------------- */

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }


    /* -----------------------------------------------------
       SERVICE WORKER URL & REGISTRATION
    ----------------------------------------------------- */

    function getServiceWorkerUrl() {
        const loc = window.location.pathname;
        if (loc.includes('/public/')) {
            const publicBase = loc.substring(0, loc.indexOf('/public/') + 8);
            return new URL(publicBase + 'service-worker.js', window.location.origin);
        }
        return new URL('/service-worker.js', window.location.origin);
    }

    async function getRegistration() {
        if (!isSupported()) {
            throw new Error('This browser does not support push notifications.');
        }

        if (serviceWorkerRegistration) {
            return serviceWorkerRegistration;
        }

        const serviceWorkerUrl = getServiceWorkerUrl();
        const loc = window.location.pathname;
        const swScope = loc.includes('/public/')
            ? loc.substring(0, loc.indexOf('/public/') + 8)
            : '/';

        serviceWorkerRegistration = await navigator.serviceWorker.register(
            serviceWorkerUrl.pathname,
            { scope: swScope }
        );

        await navigator.serviceWorker.ready;
        return serviceWorkerRegistration;
    }


    /* -----------------------------------------------------
       GET VAPID PUBLIC KEY
    ----------------------------------------------------- */

    async function getVapidPublicKey() {
        const response = await fetch(getPushApiUrl('vapid_public_key'), {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            cache: 'no-store'
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(
                data.error || data.message || 'Could not load push notification settings.'
            );
        }

        if (!data.public_key) {
            throw new Error('VAPID public key is missing on the server.');
        }

        return data.public_key;
    }


    /* -----------------------------------------------------
       SAVE SUBSCRIPTION
    ----------------------------------------------------- */

    async function saveSubscription(subscription, oldEndpoint) {
        const json = subscription.toJSON();
        const payload = {
            endpoint: json.endpoint,
            p256dh: json.keys?.p256dh || '',
            auth: json.keys?.auth || '',
            content_encoding: json.contentEncoding || 'aes128gcm',
            expiration_time: json.expirationTime || null,
            csrf_token: window.CSRF_TOKEN || ''
        };

        if (oldEndpoint) {
            payload.old_endpoint = oldEndpoint;
        }

        const response = await fetch(getPushApiUrl('subscribe'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.ok === false) {
            throw new Error(
                data.error || data.message || 'Could not save the browser notification subscription.'
            );
        }

        try {
            localStorage.setItem('study_planner_push_last_sync', Date.now().toString());
            localStorage.setItem('study_planner_push_endpoint', json.endpoint);
        } catch (e) {
            // LocalStorage exceptions ignored
        }

        return data;
    }


    /* -----------------------------------------------------
       REMOVE SUBSCRIPTION
    ----------------------------------------------------- */

    async function removeSubscription(subscription) {
        if (!subscription) {
            return;
        }

        const json = subscription.toJSON();

        const response = await fetch(getPushApiUrl('unsubscribe'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                endpoint: json.endpoint,
                csrf_token: window.CSRF_TOKEN || ''
            })
        });

        try {
            localStorage.removeItem('study_planner_push_last_sync');
            localStorage.removeItem('study_planner_push_endpoint');
        } catch (e) {
            // LocalStorage exceptions ignored
        }

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(
                data.error || data.message || 'Could not remove browser notification subscription.'
            );
        }
    }


    /* -----------------------------------------------------
       REQUEST PERMISSION
    ----------------------------------------------------- */

    async function requestPermission() {
        if (!isSupported()) {
            throw new Error('Your browser does not support push notifications.');
        }

        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
            if (permission === 'denied') {
                throw new Error(
                    'Notifications are blocked for this site. Allow notifications in your browser settings and try again.'
                );
            }
            throw new Error('Notification permission was not granted.');
        }

        return permission;
    }


    /* -----------------------------------------------------
       ENABLE (EXPLICIT USER ACTION)
    ----------------------------------------------------- */

    async function enable() {
        if (!isSupported()) {
            throw new Error('Push notifications are not supported by this browser.');
        }

        await requestPermission();
        const registration = await getRegistration();
        const publicKey = await getVapidPublicKey();

        let subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey)
            });
        }

        let lastEndpoint = '';
        try {
            lastEndpoint = localStorage.getItem('study_planner_push_endpoint') || '';
        } catch (e) {}

        await saveSubscription(subscription, lastEndpoint || undefined);
        return subscription;
    }


    /* -----------------------------------------------------
       DISABLE (EXPLICIT USER ACTION)
    ----------------------------------------------------- */

    async function disable() {
        if (!isSupported()) {
            return;
        }

        const registration = await navigator.serviceWorker.getRegistration();
        if (!registration) {
            return;
        }

        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            return;
        }

        await removeSubscription(subscription);
        await subscription.unsubscribe();
    }


    /* -----------------------------------------------------
       STATUS
    ----------------------------------------------------- */

    async function status() {
        if (!isSupported()) {
            return {
                supported: false,
                permission: 'unsupported',
                subscribed: false,
                browserSubscribed: false,
                serverSubscribed: false,
                serverCount: 0
            };
        }

        const permission = Notification.permission;
        let browserSubscribed = false;
        let serverSubscribed = false;
        let serverCount = 0;

        try {
            const registration = await navigator.serviceWorker.getRegistration();
            if (registration) {
                const subscription = await registration.pushManager.getSubscription();
                browserSubscribed = !!subscription;
            }
        } catch (e) {}

        try {
            const res = await fetch(getPushApiUrl('status'), {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (res.ok) {
                const data = await res.json().catch(() => ({}));
                if (data && data.success) {
                    serverSubscribed = !!data.enabled;
                    serverCount = data.count || 0;
                }
            }
        } catch (e) {}

        return {
            supported: true,
            permission: permission,
            subscribed: browserSubscribed && serverSubscribed,
            browserSubscribed: browserSubscribed,
            serverSubscribed: serverSubscribed,
            serverCount: serverCount
        };
    }


    /* -----------------------------------------------------
       TEST PUSH
    ----------------------------------------------------- */

    async function test() {
        const response = await fetch(getPushApiUrl('test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                csrf_token: window.CSRF_TOKEN || ''
            })
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.success === false || data.ok === false) {
            throw new Error(
                data.message || data.error || 'Could not send test notification.'
            );
        }

        return data;
    }


    /* -----------------------------------------------------
       AUTO-SYNC & SELF-HEALING (SAFE: ONLY IF GRANTED)
    ----------------------------------------------------- */

    async function autoSync() {
        if (!isSupported()) {
            return;
        }

        /*
         * We ONLY auto-sync when the browser has already granted
         * notification permission. We NEVER trigger a prompt unexpectedly.
         */
        if (Notification.permission !== 'granted') {
            return;
        }

        try {
            const registration = await getRegistration();
            if (!registration) {
                return;
            }

            // Immediately check for updated service worker (e.g. telemetry instrumentation)
            try {
                await registration.update();
            } catch (e) {}

            let subscription = await registration.pushManager.getSubscription();
            let lastEndpoint = '';
            try {
                lastEndpoint = localStorage.getItem('study_planner_push_endpoint') || '';
            } catch (e) {}

            // Check server status to verify if server actually has our subscription
            let serverHasEndpoint = false;
            try {
                const statusRes = await fetch(getPushApiUrl('status'), {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                if (statusRes.ok) {
                    const statusData = await statusRes.json().catch(() => ({}));
                    if (statusData && Array.isArray(statusData.subscriptions) && subscription) {
                        serverHasEndpoint = statusData.subscriptions.some(function (s) {
                            return s.endpoint === subscription.endpoint;
                        });
                    }
                }
            } catch (e) {
                // If status check fails, proceed with client-side check
            }

            if (!subscription) {
                // Permission granted, but no browser subscription: subscribe and register with server
                const publicKey = await getVapidPublicKey();
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicKey)
                });
                await saveSubscription(subscription, lastEndpoint || undefined);
            } else if (!serverHasEndpoint || subscription.endpoint !== lastEndpoint) {
                // Browser has subscription, but server is missing it or endpoint changed: re-save immediately!
                await saveSubscription(subscription, lastEndpoint && lastEndpoint !== subscription.endpoint ? lastEndpoint : undefined);
            }
        } catch (err) {
            // Silently tolerate sync errors during normal background navigation
            console.debug('Study Planner push auto-sync notice:', err.message);
        }
    }


    /* -----------------------------------------------------
       AUTO-RUN ON DOMContentLoaded
    ----------------------------------------------------- */

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(autoSync, 1000);
            });
        } else {
            setTimeout(autoSync, 1000);
        }
    }


    /* -----------------------------------------------------
       PUBLIC API
    ----------------------------------------------------- */

    window.StudyPlannerPush = {
        supported: isSupported,
        enable: enable,
        disable: disable,
        status: status,
        test: test,
        register: getRegistration,
        autoSync: autoSync
    };

})();