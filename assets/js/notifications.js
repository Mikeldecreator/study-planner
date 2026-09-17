/* =========================================================
   STUDY PLANNER — NOTIFICATIONS
   In-app + Browser Notifications
========================================================= */

(function () {

    'use strict';


    /* =====================================================
       ELEMENTS
    ===================================================== */

    const bellBtn =
        document.getElementById(
            'bell-btn'
        );

    const bellBadge =
        document.getElementById(
            'bell-badge'
        );

    const bellDropdown =
        document.getElementById(
            'bell-dropdown'
        );


    if (
        !bellBtn ||
        !bellDropdown
    ) {

        return;

    }


    /* =====================================================
       STATE
    ===================================================== */

    let notifications = [];

    let loaded =
        false;

    let loading =
        false;

    let dropdownOpen =
        false;


    /*
     * Prevent the same browser notification
     * from being shown repeatedly during polling.
     */

    const shownNotificationIds =
        new Set();


    /* =====================================================
       HELPERS
    ===================================================== */

    function escapeHtml(
        value
    ) {

        return String(
            value ?? ''
        )
            .replace(
                /&/g,
                '&amp;'
            )
            .replace(
                /</g,
                '&lt;'
            )
            .replace(
                />/g,
                '&gt;'
            )
            .replace(
                /"/g,
                '&quot;'
            )
            .replace(
                /'/g,
                '&#039;'
            );

    }


    function initIcons() {

        if (
            window.lucide &&
            typeof window.lucide.createIcons ===
                'function'
        ) {

            window.lucide.createIcons();

        }

    }


    function formatDate(
        value
    ) {

        if (!value) {
            return '';
        }


        const date =
            new Date(
                String(
                    value
                ).replace(
                    ' ',
                    'T'
                )
            );


        if (
            Number.isNaN(
                date.getTime()
            )
        ) {

            return String(
                value
            );

        }


        return date.toLocaleString(
            [],
            {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            }
        );

    }


    function getNotificationId(
        item,
        index
    ) {

        return String(
            item?.id ??
            `${item?.title || 'notification'}-${item?.created_at || index}`
        );

    }


    function isUnread(
        item
    ) {

        return !Boolean(
            item?.is_read ??
            item?.read ??
            false
        );

    }


    /* =====================================================
       DROPDOWN POSITIONING
    ===================================================== */

    function moveDropdownToBody() {

        if (
            bellDropdown.parentElement !==
            document.body
        ) {

            document.body.appendChild(
                bellDropdown
            );

        }

    }


    function positionDropdown() {

        if (
            bellDropdown.classList.contains(
                'hidden'
            )
        ) {

            return;

        }


        const rect =
            bellBtn.getBoundingClientRect();


        const width =
            Math.min(
                360,
                window.innerWidth - 24
            );


        const gap =
            10;


        let left =
            rect.right -
            width;


        left =
            Math.max(
                12,
                left
            );


        left =
            Math.min(
                left,
                window.innerWidth -
                    width -
                    12
            );


        let top =
            rect.bottom +
            gap;


        const maxHeight =
            Math.min(
                500,
                window.innerHeight -
                    top -
                    12
            );


        if (
            maxHeight < 180
        ) {

            top =
                Math.max(
                    12,
                    rect.top -
                        500 -
                        gap
                );

        }


        bellDropdown.style.position =
            'fixed';

        bellDropdown.style.left =
            `${left}px`;

        bellDropdown.style.right =
            'auto';

        bellDropdown.style.top =
            `${top}px`;

        bellDropdown.style.width =
            `${width}px`;

        bellDropdown.style.maxWidth =
            `calc(100vw - 24px)`;

        bellDropdown.style.maxHeight =
            `calc(100vh - ${top + 12}px)`;

        bellDropdown.style.zIndex =
            '9999';

    }


    /* =====================================================
       NOTIFICATION TYPE
    ===================================================== */

    function getIcon(
        item
    ) {

        const type =
            String(
                item?.type ||
                item?.category ||
                ''
            ).toLowerCase();


        if (
            type.includes(
                'deadline'
            )
        ) {

            return 'alarm-clock';

        }


        if (
            type.includes(
                'task'
            )
        ) {

            return 'clipboard-check';

        }


        if (
            type.includes(
                'schedule'
            )
        ) {

            return 'calendar-days';

        }


        if (
            type.includes(
                'course'
            )
        ) {

            return 'book-open';

        }


        if (
            type.includes(
                'warning'
            ) ||
            type.includes(
                'overdue'
            )
        ) {

            return 'triangle-alert';

        }


        return 'bell';

    }


    function getTone(
        item
    ) {

        const type =
            String(
                item?.type ||
                item?.category ||
                ''
            ).toLowerCase();


        if (
            type.includes(
                'overdue'
            ) ||
            type.includes(
                'warning'
            ) ||
            type.includes(
                'urgent'
            )
        ) {

            return {

                bg:
                    'bg-red-50 dark:bg-red-500/10',

                text:
                    'text-red-600 dark:text-red-300'

            };

        }


        if (
            type.includes(
                'deadline'
            ) ||
            type.includes(
                'task'
            )
        ) {

            return {

                bg:
                    'bg-amber-50 dark:bg-amber-500/10',

                text:
                    'text-amber-700 dark:text-amber-300'

            };

        }


        if (
            type.includes(
                'success'
            )
        ) {

            return {

                bg:
                    'bg-emerald-50 dark:bg-emerald-500/10',

                text:
                    'text-emerald-700 dark:text-emerald-300'

            };

        }


        return {

            bg:
                'bg-blue-50 dark:bg-blue-500/10',

            text:
                'text-blue-700 dark:text-blue-300'

        };

    }


    /* =====================================================
       BADGE
    ===================================================== */

    function updateBadge() {

        if (!bellBadge) {
            return;
        }


        const count =
            notifications.filter(
                isUnread
            ).length;


        if (
            count <= 0
        ) {

            bellBadge.classList.add(
                'hidden'
            );

            bellBadge.classList.remove(
                'flex'
            );

            bellBadge.textContent =
                '';

            return;

        }


        bellBadge.textContent =
            count > 99
                ? '99+'
                : String(
                    count
                );


        bellBadge.classList.remove(
            'hidden'
        );

        bellBadge.classList.add(
            'flex'
        );

    }


    /* =====================================================
       BROWSER NOTIFICATION
    ===================================================== */

    function browserNotificationsAllowed() {

        return (
            window.StudyPlannerPush &&
            typeof window.StudyPlannerPush.status ===
                'function'
        );

    }


    async function showBrowserNotification(
        item,
        index
    ) {

        if (
            !browserNotificationsAllowed()
        ) {

            return;

        }


        const id =
            getNotificationId(
                item,
                index
            );


        if (
            shownNotificationIds.has(
                id
            )
        ) {

            return;

        }


        /*
         * Only show unread notifications.
         */

        if (
            !isUnread(item)
        ) {

            shownNotificationIds.add(
                id
            );

            return;

        }


        try {

            const status =
                await window
                    .StudyPlannerPush
                    .status();


            if (
                !status.supported ||
                !status.subscribed ||
                status.permission !==
                    'granted'
            ) {

                return;

            }


            const title =
                item.title ||
                item.subject ||
                'Study Planner';


            const message =
                item.message ||
                item.body ||
                item.description ||
                'You have a new Study Planner notification.';


            const registration =
                await window
                    .StudyPlannerPush
                    .register();


            if (
                registration &&
                registration.showNotification
            ) {

                await registration.showNotification(
                    title,
                    {

                        body:
                            message,

                        icon:
                            '/favicon.ico',

                        badge:
                            '/favicon.ico',

                        tag:
                            `study-planner-${id}`,

                        renotify:
                            true,

                        data: {
                            notification_id:
                                id,

                            url:
                                item.url ||
                                'notifications.php'
                        }

                    }
                );

            }


            shownNotificationIds.add(
                id
            );


        } catch (
            error
        ) {

            console.error(
                'Could not display browser notification:',
                error
            );

        }

    }


    async function processBrowserNotifications() {

        if (
            !notifications.length
        ) {

            return;

        }


        for (
            let i = 0;
            i < notifications.length;
            i++
        ) {

            /*
             * Browser Notification API calls are intentionally
             * serialized to avoid notification spam.
             */

            await showBrowserNotification(
                notifications[i],
                i
            );

        }

    }


    /* =====================================================
       RENDER DROPDOWN
    ===================================================== */

    function renderNotifications() {

        if (
            !notifications.length
        ) {

            bellDropdown.innerHTML = `

                <div class="p-5">

                    <div
                        class="py-7 flex flex-col items-center justify-center text-center"
                    >

                        <div
                            class="w-11 h-11 rounded-full bg-gray-100 dark:bg-white/5 flex items-center justify-center mb-3"
                        >

                            <i
                                data-lucide="bell-off"
                                class="w-5 h-5 text-gray-400"
                            ></i>

                        </div>

                        <div
                            class="text-sm font-semibold text-gray-700 dark:text-gray-200"
                        >
                            No notifications
                        </div>

                        <div
                            class="text-xs text-gray-400 mt-1"
                        >
                            You're all caught up.
                        </div>

                    </div>

                </div>

            `;


            initIcons();

            return;

        }


        bellDropdown.innerHTML = `

            <div
                class="sticky top-0 z-10 bg-white dark:bg-[#181d1c] border-b border-gray-100 dark:border-white/10 px-4 py-3 flex items-center justify-between gap-3"
            >

                <div>

                    <h3
                        class="text-sm font-bold text-[#073b35] dark:text-white"
                    >
                        Notifications
                    </h3>

                    <p
                        class="text-[11px] text-gray-400 mt-0.5"
                    >
                        ${notifications.length}
                        notification${
                            notifications.length === 1
                                ? ''
                                : 's'
                        }
                    </p>

                </div>

                <button
                    type="button"
                    id="mark-all-notifications-read"
                    class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-300 hover:underline"
                >
                    Mark all read
                </button>

            </div>


            <div class="p-2">

                ${
                    notifications
                        .map(
                            (
                                item,
                                index
                            ) =>
                                renderNotificationItem(
                                    item,
                                    index
                                )
                        )
                        .join('')
                }

            </div>


            <div
                class="border-t border-gray-100 dark:border-white/10 px-4 py-3"
            >

                <a
                    href="notifications.php"
                    class="block text-center text-xs font-semibold text-emerald-700 dark:text-emerald-300 hover:underline"
                >
                    View all notifications
                </a>

            </div>

        `;


        document
            .getElementById(
                'mark-all-notifications-read'
            )
            ?.addEventListener(
                'click',
                markAllAsRead
            );


        bellDropdown
            .querySelectorAll(
                '[data-notification-id]'
            )
            .forEach(
                element => {

                    element.addEventListener(
                        'click',
                        () => {

                            markNotificationAsRead(
                                element.dataset
                                    .notificationId
                            );

                        }
                    );

                }
            );


        initIcons();


        if (
            dropdownOpen
        ) {

            requestAnimationFrame(
                positionDropdown
            );

        }

    }


    function renderNotificationItem(
        item,
        index
    ) {

        const tone =
            getTone(
                item
            );


        const title =
            item.title ||
            item.subject ||
            item.name ||
            'Notification';


        const message =
            item.message ||
            item.body ||
            item.description ||
            '';


        const date =
            item.created_at ||
            item.createdAt ||
            item.date ||
            '';


        const unread =
            isUnread(
                item
            );


        return `

            <button
                type="button"

                data-notification-id="${escapeHtml(
                    getNotificationId(
                        item,
                        index
                    )
                )}"

                class="w-full text-left rounded-xl px-3 py-3 flex items-start gap-3 transition-colors ${
                    unread
                        ? 'bg-emerald-50/60 dark:bg-emerald-500/[.04]'
                        : 'hover:bg-gray-50 dark:hover:bg-white/[.03]'
                }"
            >

                <span
                    class="w-9 h-9 rounded-xl ${tone.bg} flex items-center justify-center shrink-0"
                >

                    <i
                        data-lucide="${getIcon(
                            item
                        )}"
                        class="w-4 h-4 ${tone.text}"
                    ></i>

                </span>


                <span
                    class="min-w-0 flex-1"
                >

                    <span
                        class="flex items-start gap-2"
                    >

                        <span
                            class="font-semibold text-xs text-gray-800 dark:text-gray-100 truncate"
                        >
                            ${escapeHtml(
                                title
                            )}
                        </span>

                        ${
                            unread
                                ? `
                                    <span
                                        class="w-2 h-2 rounded-full bg-emerald-600 shrink-0 mt-1"
                                    ></span>
                                `
                                : ''
                        }

                    </span>


                    ${
                        message
                            ? `
                                <span
                                    class="block text-[11px] text-gray-500 dark:text-gray-400 mt-1 leading-4"
                                >
                                    ${escapeHtml(
                                        message
                                    )}
                                </span>
                            `
                            : ''
                    }


                    ${
                        date
                            ? `
                                <span
                                    class="block text-[10px] text-gray-400 mt-1"
                                >
                                    ${escapeHtml(
                                        formatDate(
                                            date
                                        )
                                    )}
                                </span>
                            `
                            : ''
                    }

                </span>

            </button>

        `;

    }


    /* =====================================================
       OPEN / CLOSE
    ===================================================== */

    function openDropdown() {

        moveDropdownToBody();


        bellDropdown.classList.remove(
            'hidden'
        );


        dropdownOpen =
            true;


        requestAnimationFrame(
            positionDropdown
        );

    }


    function closeDropdown() {

        bellDropdown.classList.add(
            'hidden'
        );


        dropdownOpen =
            false;

    }


    /* =====================================================
       LOAD NOTIFICATIONS
    ===================================================== */

    async function loadNotifications() {

        if (loading) {
            return;
        }


        loading =
            true;


        try {

            const response =
                await fetch(
                    `${API}/notifications.php`,
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


            if (
                !response.ok
            ) {

                throw new Error(
                    `HTTP ${response.status}`
                );

            }


            const data =
                await response.json();


            if (
                Array.isArray(
                    data
                )
            ) {

                notifications =
                    data;

            } else if (
                Array.isArray(
                    data.notifications
                )
            ) {

                notifications =
                    data.notifications;

            } else if (
                Array.isArray(
                    data.items
                )
            ) {

                notifications =
                    data.items;

            } else {

                notifications =
                    [];

            }


            loaded =
                true;


            updateBadge();

            renderNotifications();

            processBrowserNotifications();


        } catch (
            error
        ) {

            console.error(
                'Could not load notifications:',
                error
            );


            if (
                !loaded
            ) {

                notifications =
                    [];

                renderNotifications();

            }

        } finally {

            loading =
                false;

        }

    }


    /* =====================================================
       MARK ONE READ
    ===================================================== */

    async function markNotificationAsRead(
        id
    ) {

        const item =
            notifications.find(
                notification =>
                    String(
                        notification.id
                    ) ===
                    String(
                        id
                    )
            );


        if (item) {

            item.is_read =
                true;

            item.read =
                true;

        }


        updateBadge();

        renderNotifications();


        try {

            await fetch(
                `${API}/notifications.php`,
                {
                    method: 'PUT',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body:
                        JSON.stringify({

                            id:
                                id,

                            is_read:
                                true,

                            csrf_token:
                                window.CSRF_TOKEN

                        })
                }
            );

        } catch (
            error
        ) {

            console.error(
                'Could not mark notification as read:',
                error
            );

        }

    }


    /* =====================================================
       MARK ALL READ
    ===================================================== */

    async function markAllAsRead() {

        notifications.forEach(
            item => {

                item.is_read =
                    true;

                item.read =
                    true;

                shownNotificationIds.add(
                    getNotificationId(
                        item,
                        0
                    )
                );

            }
        );


        updateBadge();

        renderNotifications();


        try {

            await fetch(
                `${API}/notifications.php`,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body:
                        JSON.stringify({

                            action:
                                'mark_all_read',

                            csrf_token:
                                window.CSRF_TOKEN

                        })
                }
            );

        } catch (
            error
        ) {

            console.error(
                'Could not mark all notifications read:',
                error
            );

        }

    }


    /* =====================================================
       BELL CLICK
    ===================================================== */

    bellBtn.addEventListener(
        'click',
        event => {

            event.preventDefault();

            event.stopPropagation();


            if (
                bellDropdown.classList.contains(
                    'hidden'
                )
            ) {

                openDropdown();

            } else {

                closeDropdown();

            }


            /*
             * Refresh immediately when the user
             * opens the notification panel.
             */

            if (
                dropdownOpen
            ) {

                loadNotifications();

            }

        }
    );


    /* =====================================================
       CLICK OUTSIDE
    ===================================================== */

    document.addEventListener(
        'click',
        event => {

            if (
                !dropdownOpen
            ) {
                return;
            }


            if (
                bellDropdown.contains(
                    event.target
                )
                ||
                bellBtn.contains(
                    event.target
                )
            ) {

                return;

            }


            closeDropdown();

        }
    );


    /* =====================================================
       ESCAPE
    ===================================================== */

    document.addEventListener(
        'keydown',
        event => {

            if (
                event.key ===
                'Escape'
            ) {

                closeDropdown();

            }

        }
    );


    /* =====================================================
       RESIZE / SCROLL
    ===================================================== */

    window.addEventListener(
        'resize',
        () => {

            if (
                dropdownOpen
            ) {

                positionDropdown();

            }

        }
    );


    window.addEventListener(
        'scroll',
        () => {

            if (
                dropdownOpen
            ) {

                positionDropdown();

            }

        },
        true
    );


    /* =====================================================
       AUTOMATIC POLLING
    ===================================================== */

    function startPolling() {

        /*
         * Check every 30 seconds.
         * The actual database reminder system should
         * create the notification once per day.
         */

        window.setInterval(
            () => {

                loadNotifications();

            },
            30000
        );

    }


    /* =====================================================
       INITIALIZATION
    ===================================================== */

    moveDropdownToBody();

    initIcons();


    if (
        window.APP_READY &&
        typeof window.APP_READY.then ===
            'function'
    ) {

        window.APP_READY.then(
            () => {

                loadNotifications();

                startPolling();

            }
        );

    } else {

        loadNotifications();

        startPolling();

    }


})();