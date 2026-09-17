'use strict';

/* =========================================================
   STUDY PLANNER SERVICE WORKER
   File:
   public/service-worker.js
   ========================================================= */


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


    const title =
        data.title ||
        'Study Planner';


    const options = {

        body:
            data.body ||
            'You have a new study reminder.',

        icon:
            data.icon ||
            '/study-planner10/study-planner/favicon.ico',

        badge:
            data.badge ||
            '/study-planner10/study-planner/favicon.ico',

        tag:
            data.tag ||
            'study-planner-reminder',

        renotify:
            data.renotify !== false,

        requireInteraction:
            data.requireInteraction === true,

        data:
            data.data || {
                url:
                    '/study-planner10/study-planner/public/notifications.php'
            }

    };


    event.waitUntil(

        self.registration.showNotification(
            title,
            options
        )

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
            '/study-planner10/study-planner/public/notifications.php';


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
                            client.url.includes(
                                '/study-planner10/study-planner/'
                            )
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