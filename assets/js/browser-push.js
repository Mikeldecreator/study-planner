/* =========================================================
   STUDY PLANNER
   Browser Push Notifications
   Composer-free version
========================================================= */

(function () {

    'use strict';

    /* -----------------------------------------------------
       API
    ----------------------------------------------------- */

    const API_BASE =
        window.API ||
        '../api';

    const PUSH_API =
        `${API_BASE}/push.php`;

    let serviceWorkerRegistration = null;


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

    function urlBase64ToUint8Array(
        base64String
    ) {

        const padding =
            '='.repeat(
                (
                    4 -
                    base64String.length % 4
                ) % 4
            );

        const base64 =
            (
                base64String +
                padding
            )
                .replace(
                    /-/g,
                    '+'
                )
                .replace(
                    /_/g,
                    '/'
                );

        const rawData =
            window.atob(
                base64
            );

        const outputArray =
            new Uint8Array(
                rawData.length
            );

        for (
            let i = 0;
            i < rawData.length;
            i++
        ) {

            outputArray[i] =
                rawData.charCodeAt(i);

        }

        return outputArray;

    }


    /* -----------------------------------------------------
       SERVICE WORKER URL
    ----------------------------------------------------- */

    function getServiceWorkerUrl() {

        /*
         * Browser should resolve this relative to
         * the current Settings page.
         *
         * Example:
         *
         * /study-planner/public/settings.php
         *
         * becomes:
         *
         * /study-planner/public/service-worker.js
         */

        return new URL(
            'service-worker.js',
            window.location.href
        );

    }


    /* -----------------------------------------------------
       REGISTER SERVICE WORKER
    ----------------------------------------------------- */

    async function getRegistration() {

        if (
            !isSupported()
        ) {

            throw new Error(
                'This browser does not support push notifications.'
            );

        }

        if (
            serviceWorkerRegistration
        ) {

            return serviceWorkerRegistration;

        }


        const serviceWorkerUrl =
            getServiceWorkerUrl();


        serviceWorkerRegistration =
            await navigator.serviceWorker.register(
                serviceWorkerUrl.pathname,
                {
                    scope:
                        serviceWorkerUrl.pathname
                            .replace(
                                /service-worker\.js$/,
                                ''
                            )
                }
            );


        await navigator.serviceWorker.ready;


        return serviceWorkerRegistration;

    }


    /* -----------------------------------------------------
       GET VAPID PUBLIC KEY
    ----------------------------------------------------- */

    async function getVapidPublicKey() {

        const response =
            await fetch(
                `${PUSH_API}?action=vapid_public_key`,
                {
                    method: 'GET',

                    headers: {
                        'Accept':
                            'application/json'
                    },

                    cache:
                        'no-store'
                }
            );


        const data =
            await response
                .json()
                .catch(
                    () => ({})
                );


        if (
            !response.ok
        ) {

            throw new Error(
                data.error ||
                'Could not load push notification settings.'
            );

        }


        if (
            !data.public_key
        ) {

            throw new Error(
                'VAPID public key is missing on the server.'
            );

        }


        return data.public_key;

    }


    /* -----------------------------------------------------
       SAVE SUBSCRIPTION
    ----------------------------------------------------- */

    async function saveSubscription(
        subscription
    ) {

        const json =
            subscription.toJSON();


        const response =
            await fetch(
                `${PUSH_API}?action=subscribe`,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'Accept':
                            'application/json'
                    },

                    body:
                        JSON.stringify({

                            endpoint:
                                json.endpoint,

                            p256dh:
                                json.keys?.p256dh ||
                                '',

                            auth:
                                json.keys?.auth ||
                                '',

                            content_encoding:
                                json.contentEncoding ||
                                'aes128gcm',

                            expiration_time:
                                json.expirationTime ||
                                null,

                            csrf_token:
                                window.CSRF_TOKEN ||
                                ''

                        })
                }
            );


        const data =
            await response
                .json()
                .catch(
                    () => ({})
                );


        if (
            !response.ok ||
            data.ok === false
        ) {

            throw new Error(
                data.error ||
                'Could not save the browser notification subscription.'
            );

        }


        return data;

    }


    /* -----------------------------------------------------
       REMOVE SUBSCRIPTION
    ----------------------------------------------------- */

    async function removeSubscription(
        subscription
    ) {

        if (
            !subscription
        ) {

            return;

        }


        const json =
            subscription.toJSON();


        const response =
            await fetch(
                `${PUSH_API}?action=unsubscribe`,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'Accept':
                            'application/json'
                    },

                    body:
                        JSON.stringify({

                            endpoint:
                                json.endpoint,

                            csrf_token:
                                window.CSRF_TOKEN ||
                                ''

                        })
                }
            );


        if (
            !response.ok
        ) {

            const data =
                await response
                    .json()
                    .catch(
                        () => ({})
                    );


            throw new Error(
                data.error ||
                'Could not remove browser notification subscription.'
            );

        }

    }


    /* -----------------------------------------------------
       REQUEST PERMISSION
    ----------------------------------------------------- */

    async function requestPermission() {

        if (
            !isSupported()
        ) {

            throw new Error(
                'Your browser does not support push notifications.'
            );

        }


        const permission =
            await Notification.requestPermission();


        if (
            permission !==
            'granted'
        ) {

            if (
                permission ===
                'denied'
            ) {

                throw new Error(
                    'Notifications are blocked for this site. Allow notifications in your browser settings and try again.'
                );

            }


            throw new Error(
                'Notification permission was not granted.'
            );

        }


        return permission;

    }


    /* -----------------------------------------------------
       ENABLE
    ----------------------------------------------------- */

    async function enable() {

        if (
            !isSupported()
        ) {

            throw new Error(
                'Push notifications are not supported by this browser.'
            );

        }


        /*
         * Ask permission.
         */

        await requestPermission();


        /*
         * Register service worker.
         */

        const registration =
            await getRegistration();


        /*
         * Get VAPID public key.
         */

        const publicKey =
            await getVapidPublicKey();


        /*
         * Check for existing subscription.
         */

        let subscription =
            await registration
                .pushManager
                .getSubscription();


        /*
         * Create subscription if necessary.
         */

        if (
            !subscription
        ) {

            subscription =
                await registration
                    .pushManager
                    .subscribe({

                        userVisibleOnly:
                            true,

                        applicationServerKey:
                            urlBase64ToUint8Array(
                                publicKey
                            )

                    });

        }


        /*
         * Save to database.
         */

        await saveSubscription(
            subscription
        );


        return subscription;

    }


    /* -----------------------------------------------------
       DISABLE
    ----------------------------------------------------- */

    async function disable() {

        if (
            !isSupported()
        ) {

            return;

        }


        const registration =
            await navigator
                .serviceWorker
                .getRegistration();


        if (
            !registration
        ) {

            return;

        }


        const subscription =
            await registration
                .pushManager
                .getSubscription();


        if (
            !subscription
        ) {

            return;

        }


        await removeSubscription(
            subscription
        );


        await subscription.unsubscribe();

    }


    /* -----------------------------------------------------
       STATUS
    ----------------------------------------------------- */

    async function status() {

        if (
            !isSupported()
        ) {

            return {

                supported:
                    false,

                permission:
                    'unsupported',

                subscribed:
                    false

            };

        }


        const permission =
            Notification.permission;


        const registration =
            await navigator
                .serviceWorker
                .getRegistration();


        let subscribed =
            false;


        if (
            registration
        ) {

            const subscription =
                await registration
                    .pushManager
                    .getSubscription();


            subscribed =
                !!subscription;

        }


        return {

            supported:
                true,

            permission:
                permission,

            subscribed:
                subscribed

        };

    }


    /* -----------------------------------------------------
       TEST PUSH
    ----------------------------------------------------- */

    async function test() {

        const response =
            await fetch(
                `${PUSH_API}?action=test`,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'Accept':
                            'application/json'
                    },

                    body:
                        JSON.stringify({

                            csrf_token:
                                window.CSRF_TOKEN ||
                                ''

                        })
                }
            );


        const data =
            await response
                .json()
                .catch(
                    () => ({})
                );


        if (
            !response.ok ||
            data.ok === false
        ) {

            throw new Error(
                data.error ||
                'Could not send test notification.'
            );

        }


        return data;

    }


    /* -----------------------------------------------------
       PUBLIC API
    ----------------------------------------------------- */

    window.StudyPlannerPush = {

        supported:
            isSupported,

        enable:
            enable,

        disable:
            disable,

        status:
            status,

        test:
            test,

        register:
            getRegistration

    };

})();