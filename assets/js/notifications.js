/* =========================================================
   STUDY PLANNER — NOTIFICATIONS CONTROLLER
   Full In-App Notifications, Bell Preview, Filtering,
   Authoritative Quick Stats, and Browser Push
========================================================= */

(function () {
    'use strict';

    const API = (typeof window.API !== 'undefined' ? window.API : (typeof API !== 'undefined' ? API : '../api'));

    /* =====================================================
       DOM ELEMENTS
    ===================================================== */
    const bellBtn = document.getElementById('bell-btn');
    const bellBadge = document.getElementById('bell-badge');
    const bellDropdown = document.getElementById('bell-dropdown');
    const sidebarBadge = document.getElementById('sidebar-unread-badge');

    // Page-specific elements (notifications.php)
    const feedEl = document.getElementById('notification-feed');
    const loadingEl = document.getElementById('notification-loading');
    const searchInput = document.getElementById('notification-search');
    const searchInputMobile = document.getElementById('notification-search-mobile');
    const markAllBtn = document.getElementById('mark-all-read');
    const summaryEl = document.getElementById('notification-summary');

    // Quick Stats elements
    const statTotalEl = document.getElementById('stat-total');
    const statUnreadEl = document.getElementById('stat-unread');
    const statAcademicEl = document.getElementById('stat-academic');
    const statRemindersEl = document.getElementById('stat-reminders');

    /* =====================================================
       STATE
    ===================================================== */
    let allNotifications = [];
    let activeFilter = 'all';
    let searchQuery = '';
    let unreadCount = 0;
    let authoritativeStats = {
        total: 0,
        unread: 0,
        academic: 0,
        deadline: 0,
        class: 0,
        study: 0,
        general: 0
    };
    let isDropdownOpen = false;
    let isLoading = false;
    const shownNotificationIds = new Set();

    /* =====================================================
       HELPERS
    ===================================================== */
    function escapeHtml(val) {
        return String(val ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function initIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function formatRelativeTime(dateStr) {
        if (!dateStr) return '';
        const d = new Date(String(dateStr).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(dateStr);

        const now = new Date();
        const diffSecs = Math.floor((now - d) / 1000);

        if (diffSecs < 60) return 'Just now';
        if (diffSecs < 3600) return `${Math.floor(diffSecs / 60)}m ago`;
        if (diffSecs < 86400) return `${Math.floor(diffSecs / 3600)}h ago`;
        if (diffSecs < 172800) return 'Yesterday';
        if (diffSecs < 604800) return `${Math.floor(diffSecs / 86400)}d ago`;

        return d.toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function getCsrfToken() {
        return window.CSRF_TOKEN ||
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
            (window.CURRENT_USER && window.CURRENT_USER.csrf_token) ||
            '';
    }

    function isUnread(item) {
        return !item.read_at && !item.is_read;
    }

    function getIconName(item) {
        const cat = (item.category || '').toLowerCase();
        const msg = (item.message || '').toLowerCase();

        if (cat === 'class_reminder' || msg.includes('class') || msg.includes('lecture')) return 'calendar';
        if (cat === 'overdue' || msg.includes('overdue')) return 'alert-circle';
        if (cat === 'deadline' || msg.includes('deadline') || msg.includes('due')) return 'clock';
        if (cat === 'completion' || msg.includes('completed')) return 'check-circle-2';
        if (cat === 'curriculum' || msg.includes('exam') || msg.includes('revision')) return 'book-open';
        if (cat === 'smart_study' || msg.includes('free block') || msg.includes('study')) return 'lightbulb';
        if (cat === 'goal' || msg.includes('goal')) return 'target';
        return 'bell';
    }

    function getTone(item) {
        const cat = (item.category || '').toLowerCase();
        const msg = (item.message || '').toLowerCase();

        if (cat === 'overdue' || msg.includes('overdue') || msg.includes('(2h)') || msg.includes('urgent')) {
            return {
                bg: 'bg-red-50 dark:bg-red-500/10',
                text: 'text-red-600 dark:text-red-300',
                border: 'border-red-200 dark:border-red-900/40',
                badgeBg: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
                label: 'Urgent'
            };
        }
        if (cat === 'deadline' || msg.includes('deadline') || msg.includes('due tomorrow')) {
            return {
                bg: 'bg-amber-50 dark:bg-amber-500/10',
                text: 'text-amber-700 dark:text-amber-300',
                border: 'border-amber-200 dark:border-amber-900/40',
                badgeBg: 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
                label: 'Deadline'
            };
        }
        if (cat === 'class_reminder' || msg.includes('class') || msg.includes('lecture')) {
            return {
                bg: 'bg-blue-50 dark:bg-blue-500/10',
                text: 'text-blue-700 dark:text-blue-300',
                border: 'border-blue-200 dark:border-blue-900/40',
                badgeBg: 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
                label: 'Class'
            };
        }
        if (cat === 'completion' || msg.includes('completed') || msg.includes('goal')) {
            return {
                bg: 'bg-emerald-50 dark:bg-emerald-500/10',
                text: 'text-emerald-700 dark:text-emerald-300',
                border: 'border-emerald-200 dark:border-emerald-900/40',
                badgeBg: 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300',
                label: 'Academic'
            };
        }
        if (cat === 'smart_study' || msg.includes('study')) {
            return {
                bg: 'bg-purple-50 dark:bg-purple-500/10',
                text: 'text-purple-700 dark:text-purple-300',
                border: 'border-purple-200 dark:border-purple-900/40',
                badgeBg: 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300',
                label: 'Study'
            };
        }
        return {
            bg: 'bg-gray-50 dark:bg-white/5',
            text: 'text-gray-700 dark:text-gray-300',
            border: 'border-gray-200 dark:border-white/10',
            badgeBg: 'bg-gray-100 dark:bg-white/10 text-gray-700 dark:text-gray-300',
            label: 'General'
        };
    }

    function matchesCategory(item, category) {
        if (category === 'all') return true;
        if (category === 'unread') return isUnread(item);

        const cat = (item.category || '').toLowerCase();
        const msg = (item.message || '').toLowerCase();

        if (category === 'academic') {
            return cat === 'curriculum' || cat === 'completion' || cat === 'course' ||
                msg.includes('academic') || msg.includes('exam') || msg.includes('curriculum') ||
                msg.includes('grade') || msg.includes('completed');
        }
        if (category === 'deadline') {
            return cat === 'deadline' || cat === 'overdue' ||
                msg.includes('deadline') || msg.includes('due') || msg.includes('overdue');
        }
        if (category === 'class') {
            return cat === 'class_reminder' || cat === 'schedule' ||
                msg.includes('class') || msg.includes('lecture') || msg.includes('timetable');
        }
        if (category === 'study') {
            return cat === 'smart_study' || cat === 'goal' ||
                msg.includes('study') || msg.includes('focus') || msg.includes('goal') || msg.includes('free block');
        }
        if (category === 'general') {
            return !matchesCategory(item, 'academic') &&
                   !matchesCategory(item, 'deadline') &&
                   !matchesCategory(item, 'class') &&
                   !matchesCategory(item, 'study');
        }
        return true;
    }

    /* =====================================================
       BADGE & QUICK STATS SYNCHRONIZATION
    ===================================================== */
    function updateBadges() {
        const count = unreadCount;
        const text = count > 99 ? '99+' : String(count);

        if (bellBadge) {
            if (count > 0) {
                bellBadge.textContent = text;
                bellBadge.classList.remove('hidden');
                bellBadge.classList.add('flex');
            } else {
                bellBadge.classList.add('hidden');
                bellBadge.classList.remove('flex');
                bellBadge.textContent = '';
            }
        }

        if (sidebarBadge) {
            if (count > 0) {
                sidebarBadge.textContent = text;
                sidebarBadge.classList.remove('hidden');
                sidebarBadge.classList.add('flex');
            } else {
                sidebarBadge.classList.add('hidden');
                sidebarBadge.classList.remove('flex');
                sidebarBadge.textContent = '';
            }
        }
    }

    function updateQuickStats() {
        if (statTotalEl) statTotalEl.textContent = authoritativeStats.total || allNotifications.length;
        if (statUnreadEl) statUnreadEl.textContent = unreadCount;
        if (statAcademicEl) statAcademicEl.textContent = authoritativeStats.academic || allNotifications.filter(n => matchesCategory(n, 'academic')).length;
        if (statRemindersEl) {
            // Deadlines + Class reminders
            const deadlineCount = authoritativeStats.deadline || allNotifications.filter(n => matchesCategory(n, 'deadline')).length;
            const classCount = authoritativeStats.class || allNotifications.filter(n => matchesCategory(n, 'class')).length;
            statRemindersEl.textContent = deadlineCount + classCount;
        }

        // Tab count badges
        const tabCounts = {
            all: allNotifications.length,
            unread: unreadCount,
            academic: allNotifications.filter(n => matchesCategory(n, 'academic')).length,
            deadline: allNotifications.filter(n => matchesCategory(n, 'deadline')).length,
            class: allNotifications.filter(n => matchesCategory(n, 'class')).length,
            study: allNotifications.filter(n => matchesCategory(n, 'study')).length,
            general: allNotifications.filter(n => matchesCategory(n, 'general')).length
        };

        Object.entries(tabCounts).forEach(([cat, cnt]) => {
            const el = document.querySelector(`[data-tab-count="${cat}"]`);
            if (el) el.textContent = cnt;
            const filterCountEl = document.querySelector(`[data-filter-count="${cat}"]`);
            if (filterCountEl) filterCountEl.textContent = cnt;
        });

        // Summary hero text
        if (summaryEl) {
            if (unreadCount > 0) {
                summaryEl.textContent = `You have ${unreadCount} unread alert${unreadCount === 1 ? '' : 's'} requiring your attention.`;
            } else {
                summaryEl.textContent = 'All caught up! You have no unread notifications.';
            }
        }
    }

    /* =====================================================
       BELL DROPDOWN RENDERING
    ===================================================== */
    function positionDropdown() {
        if (!bellDropdown || bellDropdown.classList.contains('hidden')) return;

        const rect = bellBtn.getBoundingClientRect();
        const width = Math.min(360, window.innerWidth - 24);
        let left = rect.right - width;
        left = Math.max(12, Math.min(left, window.innerWidth - width - 12));

        bellDropdown.style.position = 'fixed';
        bellDropdown.style.left = `${left}px`;
        bellDropdown.style.top = `${rect.bottom + 8}px`;
        bellDropdown.style.width = `${width}px`;
        bellDropdown.style.zIndex = '1000';
    }

    function renderBellDropdown() {
        if (!bellDropdown) return;

        const recent = allNotifications.slice(0, 8);

        if (!recent.length) {
            bellDropdown.innerHTML = `
                <div class="p-6 text-center">
                    <div class="w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 mx-auto flex items-center justify-center mb-3">
                        <i data-lucide="bell-off" class="w-6 h-6"></i>
                    </div>
                    <h4 class="text-sm font-bold text-gray-800 dark:text-gray-100">No notifications yet</h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Reminders and deadline alerts will appear here.</p>
                </div>
            `;
            initIcons();
            return;
        }

        bellDropdown.innerHTML = `
            <div class="p-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between bg-white dark:bg-[#181d1c]">
                <div>
                    <h4 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">Notifications</h4>
                    <span class="text-[11px] text-gray-500 dark:text-gray-400">${unreadCount} unread</span>
                </div>
                ${unreadCount > 0 ? `
                    <button id="bell-mark-all" type="button" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline">
                        Mark all read
                    </button>
                ` : ''}
            </div>
            <div class="max-h-80 overflow-y-auto divide-y divide-gray-100 dark:divide-white/5">
                ${recent.map(item => {
                    const unread = isUnread(item);
                    const tone = getTone(item);
                    const icon = getIconName(item);
                    const relTime = formatRelativeTime(item.send_at || item.created_at);

                    return `
                        <div data-dropdown-id="${item.id}" class="p-3 flex items-start gap-3 hover:bg-gray-50 dark:hover:bg-white/[0.03] transition-colors cursor-pointer ${unread ? 'bg-emerald-50/50 dark:bg-emerald-900/10' : ''}">
                            <div class="w-8 h-8 rounded-lg ${tone.bg} ${tone.text} flex items-center justify-center shrink-0 mt-0.5">
                                <i data-lucide="${icon}" class="w-4 h-4"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-1">
                                    <span class="text-xs font-semibold text-gray-900 dark:text-gray-100 truncate">${escapeHtml(item.title || 'Notification')}</span>
                                    ${unread ? '<span class="w-2 h-2 rounded-full bg-emerald-600 shrink-0"></span>' : ''}
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-300 mt-0.5 line-clamp-2 leading-relaxed">${escapeHtml(item.message)}</p>
                                <span class="text-[10px] text-gray-400 mt-1 block">${relTime}</span>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
            <div class="p-2.5 border-t border-gray-100 dark:border-white/10 text-center bg-gray-50/50 dark:bg-white/[0.02]">
                <a href="notifications.php" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline inline-flex items-center gap-1">
                    View All Notifications &rarr;
                </a>
            </div>
        `;

        const markAllInBell = bellDropdown.querySelector('#bell-mark-all');
        if (markAllInBell) {
            markAllInBell.addEventListener('click', (e) => {
                e.stopPropagation();
                markAllAsRead();
            });
        }

        bellDropdown.querySelectorAll('[data-dropdown-id]').forEach(el => {
            el.addEventListener('click', () => {
                const id = el.dataset.dropdownId;
                const item = allNotifications.find(n => String(n.id) === String(id));
                markOneAsRead(id);
                if (item && item.action_url && item.action_url !== 'notifications.php') {
                    window.location.href = item.action_url;
                }
            });
        });

        initIcons();
        if (isDropdownOpen) {
            requestAnimationFrame(positionDropdown);
        }
    }

    /* =====================================================
       FEED RENDERING (notifications.php)
    ===================================================== */
    function renderFeed() {
        if (!feedEl) return;

        let filtered = allNotifications.filter(n => matchesCategory(n, activeFilter));

        if (searchQuery.trim()) {
            const q = searchQuery.toLowerCase().trim();
            filtered = filtered.filter(n => {
                const msg = (n.message || '').toLowerCase();
                const title = (n.title || '').toLowerCase();
                const task = (n.task_title || '').toLowerCase();
                return msg.includes(q) || title.includes(q) || task.includes(q);
            });
        }

        if (!filtered.length) {
            feedEl.innerHTML = `
                <div class="py-16 text-center px-4">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 mx-auto flex items-center justify-center mb-4 border border-emerald-100 dark:border-emerald-800/40">
                        <i data-lucide="bell-off" class="w-7 h-7"></i>
                    </div>
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">${searchQuery ? 'No matching notifications' : 'No notifications found'}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">
                        ${searchQuery ? `No notifications found matching &ldquo;${escapeHtml(searchQuery)}&rdquo;.` : 'There are no notifications in this category.'}
                    </p>
                    ${searchQuery || activeFilter !== 'all' ? `
                        <button id="clear-filter-btn" type="button" class="mt-4 px-4 py-2 text-xs font-semibold text-emerald-800 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 hover:bg-emerald-100 rounded-xl transition">
                            ${searchQuery ? 'Clear Search' : 'Reset Filters'}
                        </button>
                    ` : ''}
                </div>
            `;
            const clearBtn = feedEl.querySelector('#clear-filter-btn');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    activeFilter = 'all';
                    searchQuery = '';
                    if (searchInput) searchInput.value = '';
                    if (searchInputMobile) searchInputMobile.value = '';
                    updateActiveTabs();
                    renderFeed();
                });
            }
            initIcons();
            return;
        }

        feedEl.innerHTML = filtered.map(item => {
            const unread = isUnread(item);
            const tone = getTone(item);
            const icon = getIconName(item);
            const relTime = formatRelativeTime(item.send_at || item.created_at);

            return `
                <div data-feed-id="${item.id}" class="p-4 sm:p-5 flex items-start justify-between gap-4 transition-colors ${unread ? 'bg-emerald-50/40 dark:bg-emerald-950/15' : 'hover:bg-gray-50/60 dark:hover:bg-white/[0.02]'}">
                    <div class="flex items-start gap-3.5 min-w-0 flex-1">
                        <div class="w-10 h-10 rounded-xl ${tone.bg} ${tone.text} flex items-center justify-center shrink-0 mt-0.5 border ${tone.border}">
                            <i data-lucide="${icon}" class="w-5 h-5"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="text-xs font-bold text-gray-900 dark:text-gray-100">${escapeHtml(item.title || 'Academic Alert')}</span>
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full ${tone.badgeBg}">
                                    ${tone.label}
                                </span>
                                ${unread ? '<span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-900/50 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span> Unread</span>' : ''}
                            </div>
                            <p class="text-xs sm:text-sm text-gray-700 dark:text-gray-300 leading-relaxed">${escapeHtml(item.message)}</p>
                            <div class="flex items-center gap-3 mt-2 text-[11px] text-gray-400 dark:text-gray-500">
                                <span>${relTime}</span>
                                ${item.task_title ? `<span class="truncate max-w-[200px]">Task: ${escapeHtml(item.task_title)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        ${unread ? `
                            <button data-action="mark-read" data-id="${item.id}" type="button" class="px-3 py-1.5 text-xs font-semibold text-emerald-800 dark:text-emerald-300 bg-white dark:bg-[#1a2421] border border-emerald-300 dark:border-emerald-700/60 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 rounded-lg transition shadow-sm">
                                Mark read
                            </button>
                        ` : ''}
                        ${item.action_url ? `
                            <a href="${escapeHtml(item.action_url)}" class="p-1.5 text-gray-400 hover:text-emerald-600 dark:hover:text-emerald-400 transition rounded-lg hover:bg-gray-100 dark:hover:bg-white/5" title="View details">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');

        feedEl.querySelectorAll('[data-action="mark-read"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                markOneAsRead(btn.dataset.id);
            });
        });

        initIcons();
    }

    function updateActiveTabs() {
        document.querySelectorAll('[data-notification-tab]').forEach(tab => {
            const cat = tab.dataset.notificationTab;
            const isActive = (cat === activeFilter);
            tab.classList.toggle('is-active', isActive);
            tab.classList.toggle('border-emerald-600', isActive);
            tab.classList.toggle('text-emerald-700', isActive);
            tab.classList.toggle('dark:text-emerald-400', isActive);
            tab.classList.toggle('font-semibold', isActive);

            tab.classList.toggle('border-transparent', !isActive);
            tab.classList.toggle('text-[#55756D]', !isActive);
            tab.classList.toggle('dark:text-gray-400', !isActive);
            tab.classList.toggle('font-medium', !isActive);
        });

        document.querySelectorAll('[data-notification-filter]').forEach(cb => {
            cb.checked = (cb.dataset.notificationFilter === activeFilter);
        });
    }

    /* =====================================================
       API MUTATIONS
    ===================================================== */
    async function loadNotifications() {
        if (isLoading) return;
        isLoading = true;

        if (loadingEl) loadingEl.classList.remove('hidden');

        try {
            const res = await fetch(`${API}/notifications.php?limit=100`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();

            allNotifications = Array.isArray(data.notifications) ? data.notifications : [];
            unreadCount = typeof data.unread_count === 'number' ? data.unread_count : allNotifications.filter(isUnread).length;
            if (data.stats) authoritativeStats = data.stats;

            updateBadges();
            updateQuickStats();
            renderBellDropdown();
            renderFeed();
        } catch (err) {
            console.warn('Could not load notifications:', err);
        } finally {
            isLoading = false;
            if (loadingEl) loadingEl.classList.add('hidden');
        }
    }

    async function markOneAsRead(id) {
        if (!id) return;
        const item = allNotifications.find(n => String(n.id) === String(id));
        if (item) {
            item.read_at = new Date().toISOString();
            item.is_read = true;
        }

        unreadCount = Math.max(0, unreadCount - 1);
        if (authoritativeStats.unread) authoritativeStats.unread = Math.max(0, authoritativeStats.unread - 1);

        updateBadges();
        updateQuickStats();
        renderBellDropdown();
        renderFeed();

        try {
            await fetch(`${API}/notifications.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    id: (intId => isNaN(intId) ? id : intId)(parseInt(id, 10)),
                    csrf_token: getCsrfToken()
                })
            });
        } catch (err) {
            console.warn('Failed to mark notification read:', err);
        }
    }

    async function markAllAsRead() {
        allNotifications.forEach(n => {
            n.read_at = new Date().toISOString();
            n.is_read = true;
        });
        unreadCount = 0;
        if (authoritativeStats.unread) authoritativeStats.unread = 0;

        updateBadges();
        updateQuickStats();
        renderBellDropdown();
        renderFeed();

        try {
            await fetch(`${API}/notifications.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    all: true,
                    csrf_token: getCsrfToken()
                })
            });
            if (typeof window.showToast === 'function') {
                window.showToast('All notifications marked as read', 'success');
            }
        } catch (err) {
            console.warn('Failed to mark all notifications read:', err);
        }
    }

    /* =====================================================
       EVENT LISTENERS
    ===================================================== */
    function attachEventListeners() {
        // Bell toggle
        if (bellBtn && bellDropdown) {
            bellBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (isDropdownOpen) {
                    bellDropdown.classList.add('hidden');
                    isDropdownOpen = false;
                } else {
                    bellDropdown.classList.remove('hidden');
                    isDropdownOpen = true;
                    positionDropdown();
                    loadNotifications();
                }
            });

            document.addEventListener('click', (e) => {
                if (isDropdownOpen && !bellDropdown.contains(e.target) && !bellBtn.contains(e.target)) {
                    bellDropdown.classList.add('hidden');
                    isDropdownOpen = false;
                }
            });

            window.addEventListener('resize', positionDropdown);
            window.addEventListener('scroll', positionDropdown, true);
        }

        // Tab filters (notifications.php)
        document.querySelectorAll('[data-notification-tab]').forEach(tab => {
            tab.addEventListener('click', () => {
                activeFilter = tab.dataset.notificationTab || 'all';
                updateActiveTabs();
                renderFeed();
            });
        });

        // Sidebar filters (notifications.php)
        document.querySelectorAll('[data-notification-filter]').forEach(cb => {
            cb.addEventListener('change', () => {
                if (cb.checked) {
                    activeFilter = cb.dataset.notificationFilter || 'all';
                } else {
                    activeFilter = 'all';
                }
                updateActiveTabs();
                renderFeed();
            });
        });

        // Search inputs (desktop + mobile)
        let debounceTimer;
        const handleSearchInput = (e) => {
            const val = e.target.value;
            if (searchInput && searchInput !== e.target) searchInput.value = val;
            if (searchInputMobile && searchInputMobile !== e.target) searchInputMobile.value = val;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                searchQuery = val;
                renderFeed();
            }, 150);
        };

        const handleSearchKeydown = (e) => {
            if (e.key === 'Escape') {
                if (searchInput) searchInput.value = '';
                if (searchInputMobile) searchInputMobile.value = '';
                searchQuery = '';
                renderFeed();
            }
        };

        if (searchInput) {
            searchInput.addEventListener('input', handleSearchInput);
            searchInput.addEventListener('keydown', handleSearchKeydown);
        }
        if (searchInputMobile) {
            searchInputMobile.addEventListener('input', handleSearchInput);
            searchInputMobile.addEventListener('keydown', handleSearchKeydown);
        }

        // Hero mark all read
        if (markAllBtn) {
            markAllBtn.addEventListener('click', markAllAsRead);
        }
    }

    /* =====================================================
       INITIALIZATION
    ===================================================== */
    function initNotifications() {
        attachEventListeners();
        // On notifications center page, load feed immediately.
        // On all other pages, bootstrap provides the badge count and bell dropdown loads on-demand on click.
        if (feedEl) {
            loadNotifications();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNotifications);
    } else {
        initNotifications();
    }

    // Expose for external refreshes
    window.refreshNotifications = loadNotifications;
})();