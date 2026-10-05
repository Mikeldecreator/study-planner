
const API = '../api';
window.API = API;

window.APP_READY = (async function bootstrap() {
  const sidebarSlot = document.getElementById('sidebar-slot');

  if (sidebarSlot) {
    const cachedSidebar = sessionStorage.getItem('app_sidebar_html_v9');
    if (cachedSidebar) {
      sidebarSlot.outerHTML = cachedSidebar;
      requestAnimationFrame(() => {
        if (window.lucide) {
          window.lucide.createIcons();
        }
      });
    } else {
      try {
        const response = await fetch('../assets/partials/sidebar.html');
        if (response.ok) {
          const html = await response.text();
          sessionStorage.setItem('app_sidebar_html_v9', html);
          sidebarSlot.outerHTML = html;
          requestAnimationFrame(() => {
            if (window.lucide) {
              window.lucide.createIcons();
            }
          });
        }
      } catch (error) {
        console.error('Sidebar loading failed:', error);
      }
    }
  }

  highlightActiveNavLink();
  wireMobileDrawer();
  wireLogout();
  wireDarkToggleInputs();
  wireStudyAI();

  let me = null;

  try {
    const bootRes = await fetch(`${API}/bootstrap.php`, {
      credentials: 'same-origin'
    });

    if (bootRes.status === 401) {
      window.location.href = 'login.php';
      return null;
    }

    if (bootRes.ok) {
      const bootData = await bootRes.json();
      me = bootData.user;
      window.CSRF_TOKEN = bootData.csrf_token;
      window.CURRENT_USER = me;

      // Synchronize unread badge immediately
      const unreadCount = bootData.unread_notifications || 0;
      updateBellBadges(unreadCount);

      // Redirect un-onboarded students to onboarding.php
      if (me && !me.onboarding_completed) {
        const curPath = window.location.pathname;
        if (!curPath.endsWith('onboarding.php') && !curPath.endsWith('login.php') && !curPath.endsWith('register.php')) {
          window.location.replace('onboarding.php');
          return null;
        }
      }

      // Check for incoming academic notifications from Aiven
      if (Array.isArray(bootData.latest_notifications) && bootData.latest_notifications.length > 0) {
        displayIncomingNotifications(bootData.latest_notifications);
      }
    }
  } catch (err) {
    console.warn('Bootstrap endpoint fallback to legacy auth:', err);
  }

  // Fallback if bootstrap was not available
  if (!me) {
    let meRes;
    try {
      meRes = await fetch(`${API}/me.php`, { credentials: 'same-origin' });
    } catch (error) {
      window.location.href = 'login.php';
      return null;
    }

    if (!meRes.ok) {
      window.location.href = 'login.php';
      return null;
    }

    try {
      me = await meRes.json();
      window.CURRENT_USER = me;
    } catch (error) {
      window.location.href = 'login.php';
      return null;
    }

    try {
      const csrfRes = await fetch(`${API}/csrf.php`, { credentials: 'same-origin' });
      if (csrfRes.ok) {
        const csrf = await csrfRes.json();
        window.CSRF_TOKEN = csrf.csrf_token;
      }
    } catch (error) {
      console.error('CSRF request failed:', error);
    }
  }

  document.querySelectorAll('[data-user-name]').forEach(el => {
    el.textContent = me.name || 'Student';
  });

  document.querySelectorAll('[data-user-initial]').forEach(el => {
    el.textContent = me.name
      ? me.name.charAt(0).toUpperCase()
      : '?';
  });

  document.querySelectorAll('[data-user-meta]').forEach(function (element) {
    element.textContent = me.email || '';
  });

  updateUserAvatars(me);

  syncDarkModeUI(me.dark_mode);

  initNotificationPolling();

  return me;
})();

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function resolveAvatarUrl(path) {
  if (!path) return '';
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('/')) {
    return path;
  }
  try {
    return new URL(
      path,
      window.location.origin +
      window.location.pathname.replace(/\/public\/[^/]*$/, '/public/')
    ).pathname;
  } catch (e) {
    return path;
  }
}

window.resolveAvatarUrl = resolveAvatarUrl;

function updateUserAvatars(user) {
  if (!user) return;
  const name = user.name || user.full_name || 'Student';
  const initial = name ? name.charAt(0).toUpperCase() : '?';
  const avatarPath = user.avatar_path ? resolveAvatarUrl(user.avatar_path) : '';

  const containers = document.querySelectorAll('[data-top-avatar], [data-user-avatar], #app-sidebar [data-user-initial], #mobile-more-sheet [data-user-initial]');
  containers.forEach(container => {
    container.classList.add('overflow-hidden', 'rounded-full');
    if (avatarPath) {
      const img = document.createElement('img');
      img.src = avatarPath;
      img.alt = name;
      img.className = 'w-full h-full object-cover rounded-full';
      img.onerror = () => {
        container.innerHTML = `<span data-user-initial>${escapeHtml(initial)}</span>`;
      };
      container.innerHTML = '';
      container.appendChild(img);
    } else {
      container.innerHTML = `<span data-user-initial>${escapeHtml(initial)}</span>`;
    }
  });

  document.querySelectorAll('[data-user-initial]').forEach(el => {
    if (!el.closest('[data-top-avatar], [data-user-avatar], #app-sidebar, #mobile-more-sheet')) {
      el.textContent = initial;
    }
  });
}

window.updateUserAvatars = updateUserAvatars;

function highlightActiveNavLink() {
  const page = document.body.dataset.page;
  const isFocusUrl = (page === 'tasks' && (window.location.search.includes('focus=1') || window.location.search.includes('focus_task_id')));
  const activeKey = (isFocusUrl || page === 'study') ? 'focus' : page;

  document.querySelectorAll('[data-nav]').forEach(link => {
    if (link.dataset.nav === activeKey) {
      link.classList.remove('text-white/80', 'hover:bg-white/10');
      link.classList.add(
        'bg-emerald-600',
        'text-white',
        'font-semibold'
      );
    }
  });

  // Mobile Bottom Navigation active state
  let mobileActiveKey = page;
  if (isFocusUrl || page === 'study') {
    mobileActiveKey = 'study';
  } else if (['courses', 'schedule', 'deadlines', 'reports', 'notifications', 'settings', 'study-ai'].includes(page)) {
    mobileActiveKey = 'more';
  }

  document.querySelectorAll('[data-mobile-nav]').forEach(btn => {
    const isCurrent = btn.dataset.mobileNav === mobileActiveKey;
    if (isCurrent) {
      btn.classList.remove('text-gray-500', 'dark:text-gray-400');
      btn.classList.add('text-emerald-700', 'dark:text-emerald-400', 'font-bold');
      const icon = btn.querySelector('svg, i');
      if (icon) {
        icon.classList.add('stroke-[2.5px]');
      }
    } else {
      btn.classList.remove('text-emerald-700', 'dark:text-emerald-400', 'font-bold');
      btn.classList.add('text-gray-500', 'dark:text-gray-400');
    }
  });
}

function syncDarkModeUI(isDark) {
  document.documentElement.classList.toggle('dark', !!isDark);

  localStorage.setItem(
    'darkMode',
    isDark ? 'true' : 'false'
  );

  document.querySelectorAll('[data-dark-toggle]').forEach(toggle => {
    toggle.checked = !!isDark;
  });
}

window.setDarkMode = async function setDarkMode(isDark) {
  syncDarkModeUI(isDark);

  try {
    await fetch(`${API}/settings.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        dark_mode: isDark,
        csrf_token: window.CSRF_TOKEN
      })
    });
  } catch (error) {
    console.error('Dark mode update failed:', error);
  }
}

function wireDarkToggleInputs() {
  document.querySelectorAll('[data-dark-toggle]').forEach(toggle => {
    toggle.addEventListener('change', e => {
      setDarkMode(e.target.checked);
    });
  });
}

/**
 * Study AI integration point.
 *
 * This does not generate fake AI responses or create AI functionality yet.
 * It simply provides a clean event that the future Study AI interface
 * can listen for.
 */
function wireStudyAI() {
  const aiButton = document.getElementById('study-ai-btn');

  if (!aiButton) {
    return;
  }

  aiButton.addEventListener('click', (e) => {
    if (document.body.dataset.page === 'study-ai') {
      e.preventDefault();
      window.dispatchEvent(
        new CustomEvent('study-ai:open')
      );
    }
  });
}

function wireLogout() {
  const logoutBtn = document.getElementById('logout-btn');

  if (!logoutBtn) {
    return;
  }

  logoutBtn.addEventListener('click', async e => {
    e.preventDefault();

    try {
      await fetch(`${API}/logout.php`, {
        method: 'POST',
        credentials: 'same-origin'
      });
    } catch (error) {
      console.error('Logout failed:', error);
    }

    window.location.href = 'login.php';
  });
}

function wireMobileDrawer() {
  const sidebar = document.getElementById('app-sidebar');
  const backdrop = document.getElementById('sidebar-backdrop');
  const openBtn = document.getElementById('hamburger-btn');
  const closeBtn = document.getElementById('sidebar-close-btn');

  // Mobile "More" Sheet elements
  const moreSheet = document.getElementById('mobile-more-sheet');
  const moreBackdrop = document.getElementById('mobile-more-backdrop');
  const moreBtn = document.getElementById('mobile-more-btn');
  const moreCloseBtn = document.getElementById('mobile-more-close');
  const mobileLogoutBtn = document.getElementById('mobile-logout-btn');

  const openSidebar = () => {
    sidebar?.classList.remove('-translate-x-full');
    backdrop?.classList.remove('hidden');
  };

  const closeSidebar = () => {
    sidebar?.classList.add('-translate-x-full');
    backdrop?.classList.add('hidden');
  };

  const openMoreSheet = () => {
    if (moreSheet) {
      moreSheet.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (window.lucide) {
        window.lucide.createIcons();
      }
    }
  };

  const closeMoreSheet = () => {
    if (moreSheet) {
      moreSheet.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }
  };

  openBtn?.addEventListener('click', () => {
    // If on a phone with bottom nav, open the convenient More sheet; otherwise open sidebar
    if (window.innerWidth < 768 && moreSheet) {
      openMoreSheet();
    } else {
      openSidebar();
    }
  });

  closeBtn?.addEventListener('click', closeSidebar);
  backdrop?.addEventListener('click', closeSidebar);

  moreBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    openMoreSheet();
  });
  moreCloseBtn?.addEventListener('click', closeMoreSheet);
  moreBackdrop?.addEventListener('click', closeMoreSheet);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeSidebar();
      closeMoreSheet();
    }
  });

  if (mobileLogoutBtn) {
    mobileLogoutBtn.addEventListener('click', async (e) => {
      e.preventDefault();
      try {
        await fetch(`${API}/logout.php`, {
          method: 'POST',
          credentials: 'same-origin'
        });
      } catch (_) {}
      window.location.href = 'login.php';
    });
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

function capitalize(str) {
  return str
    ? str.charAt(0).toUpperCase() + str.slice(1)
    : '';
}

function updateBellBadges(unreadCount) {
  unreadCount = Math.max(0, Number(unreadCount) || 0);
  const sidebarBadge = document.getElementById('sidebar-unread-badge');
  if (sidebarBadge) {
    if (unreadCount > 0) {
      sidebarBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
      sidebarBadge.classList.remove('hidden');
      sidebarBadge.classList.add('flex');
    } else {
      sidebarBadge.classList.add('hidden');
      sidebarBadge.classList.remove('flex');
      sidebarBadge.textContent = '';
    }
  }
  const bellBadge = document.getElementById('bell-badge');
  if (bellBadge) {
    if (unreadCount > 0) {
      bellBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
      bellBadge.classList.remove('hidden');
      bellBadge.classList.add('flex');
    } else {
      bellBadge.classList.add('hidden');
      bellBadge.classList.remove('flex');
      bellBadge.textContent = '';
    }
  }

  // Mobile Bottom Nav & More Sheet badges
  const mobileNavDot = document.getElementById('mobile-nav-unread-dot');
  if (mobileNavDot) {
    mobileNavDot.classList.toggle('hidden', unreadCount <= 0);
  }
  const mobileSheetBadge = document.getElementById('mobile-sheet-unread-badge');
  if (mobileSheetBadge) {
    mobileSheetBadge.classList.toggle('hidden', unreadCount <= 0);
  }
}
window.updateBellBadges = updateBellBadges;

function displayIncomingNotifications(notifications) {
  if (!Array.isArray(notifications) || notifications.length === 0) return;

  const now = Date.now();
  const FIFTEEN_MINS_MS = 15 * 60 * 1000;
  const unseen = [];
  for (const n of notifications) {
    if (!n || !n.id) continue;

    // Filter out notifications older than 15 minutes to avoid popup storms on reopen
    if (n.send_at || n.created_at) {
      const sendTs = new Date(n.send_at || n.created_at).getTime();
      if (!isNaN(sendTs) && (now - sendTs) > FIFTEEN_MINS_MS) {
        continue;
      }
    }

    const seenIdKey = 'seen_popup_' + n.id;
    const seenEventKey = n.event_key ? ('seen_popup_key_' + n.event_key) : null;
    const localKey = 'notif_popped_' + (n.event_key || ('id_' + n.id));

    if (sessionStorage.getItem(seenIdKey)) continue;
    if (seenEventKey && sessionStorage.getItem(seenEventKey)) continue;

    const lastPopped = localStorage.getItem(localKey);
    if (lastPopped && (now - Number(lastPopped)) < 3600 * 1000 * 4) {
      continue;
    }

    // Mark seen immediately across session and local storage
    sessionStorage.setItem(seenIdKey, '1');
    if (seenEventKey) sessionStorage.setItem(seenEventKey, '1');
    localStorage.setItem(localKey, String(now));

    unseen.push(n);
  }

  // Show at most 2 per page load, spaced out
  const toShow = unseen.slice(0, 2);
  toShow.forEach((n, idx) => {
    setTimeout(() => {
      let priority = 'important';
      if (n.category === 'overdue' || n.tone === 'urgent') {
        priority = 'high';
      } else if (n.category === 'completion' || n.tone === 'success') {
        priority = 'normal';
      }

      window.showToast({
        title: n.title || 'Notification',
        message: n.message || '',
        type: n.category || n.tone || 'info',
        priority: priority,
        action: n.action_label && n.action_url ? {
          label: n.action_label,
          url: n.action_url
        } : null,
        notifId: n.id,
        eventKey: n.event_key
      });
    }, idx * 1200);
  });
}
window.displayIncomingNotifications = displayIncomingNotifications;

/**
 * Immediate notification dispatch helper for student-initiated API mutations.
 * Immediately invokes showToast, updates badges, and records popup deduplication
 * so subsequent polling runs never replay the notification.
 */
function dispatchImmediateNotification(notification) {
  if (!notification || !notification.message) return;

  const notifId = notification.id || null;
  const eventKey = notification.event_key || null;
  const seenIdKey = notifId ? ('seen_popup_' + notifId) : null;
  const seenEventKey = eventKey ? ('seen_popup_key_' + eventKey) : null;
  const localKey = 'notif_popped_' + (eventKey || ('id_' + notifId));

  // Mark seen immediately across session and local storage so polling never duplicates it
  if (seenIdKey) sessionStorage.setItem(seenIdKey, '1');
  if (seenEventKey) sessionStorage.setItem(seenEventKey, '1');
  localStorage.setItem(localKey, String(Date.now()));

  // Trigger immediate toast
  if (typeof window.showToast === 'function') {
    let priority = 'important';
    if (notification.category === 'overdue' || notification.tone === 'urgent') {
      priority = 'high';
    } else if (notification.category === 'completion' || notification.tone === 'success') {
      priority = 'normal';
    }

    window.showToast({
      title: notification.title || 'Notification',
      message: notification.message || '',
      type: notification.category || notification.tone || 'info',
      priority: priority,
      action: notification.action_label && notification.action_url ? {
        label: notification.action_label,
        url: notification.action_url
      } : null,
      notifId: notifId,
      eventKey: eventKey
    });
  }

  // Update badges immediately
  if (typeof notification.unread_count === 'number') {
    updateBellBadges(notification.unread_count);
  } else {
    const currentBadge = document.getElementById('bell-badge');
    const currentCount = currentBadge && !currentBadge.classList.contains('hidden')
      ? (parseInt(currentBadge.textContent, 10) || 0)
      : 0;
    updateBellBadges(currentCount + 1);
  }

  // If notifications page or dropdown is active, refresh the feed
  if (typeof window.refreshNotifications === 'function') {
    const feedEl = document.getElementById('notification-feed');
    const bellDropdown = document.getElementById('bell-dropdown');
    const isDropdownOpen = bellDropdown && !bellDropdown.classList.contains('hidden');
    if (feedEl || isDropdownOpen) {
      window.refreshNotifications();
    }
  }
}
window.dispatchImmediateNotification = dispatchImmediateNotification;

/**
 * Lightweight real-time polling fallback (5-10s interval when visible).
 * Does not touch user last active timestamp.
 */
function initNotificationPolling() {
  if (window.__notificationPollTimer) {
    return;
  }

  const curPath = window.location.pathname.toLowerCase();
  const isPublicPage = curPath.endsWith('login.php') ||
                       curPath.endsWith('register.php') ||
                       curPath.endsWith('index.php') ||
                       curPath.endsWith('forgot-password.php') ||
                       curPath.endsWith('reset-password.php') ||
                       curPath.endsWith('verify-email.php');
  if (isPublicPage) {
    return;
  }

  let isPolling = false;
  let lastUnreadCount = null;
  let lastPollTime = Date.now();

  async function poll() {
    if (isPolling) return;
    isPolling = true;
    lastPollTime = Date.now();

    try {
      const res = await fetch(`${API}/notification_poll.php`, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store'
      });

      if (res.status === 401) {
        if (window.__notificationPollTimer) {
          clearTimeout(window.__notificationPollTimer);
          window.__notificationPollTimer = null;
        }
        return;
      }

      if (res.ok) {
        const data = await res.json();
        if (data && typeof data.unread_count === 'number') {
          const newCount = data.unread_count;
          updateBellBadges(newCount);

          if (lastUnreadCount !== null && newCount !== lastUnreadCount) {
            if (typeof window.refreshNotifications === 'function') {
              const feedEl = document.getElementById('notification-feed');
              const bellDropdown = document.getElementById('bell-dropdown');
              const isDropdownOpen = bellDropdown && !bellDropdown.classList.contains('hidden');
              if (feedEl || isDropdownOpen) {
                window.refreshNotifications();
              }
            }
          }
          lastUnreadCount = newCount;

          if (Array.isArray(data.latest_notifications) && data.latest_notifications.length > 0) {
            displayIncomingNotifications(data.latest_notifications);
          }
        }
      }
    } catch (e) {
      // Non-blocking network fallback
    } finally {
      isPolling = false;
      scheduleNext();
    }
  }

  function scheduleNext() {
    if (window.__notificationPollTimer) {
      clearTimeout(window.__notificationPollTimer);
    }
    // 8 seconds when page is visible, 60 seconds when backgrounded
    const delay = document.visibilityState === 'visible' ? 8000 : 60000;
    window.__notificationPollTimer = setTimeout(poll, delay);
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      const elapsed = Date.now() - lastPollTime;
      if (elapsed >= 8000) {
        poll();
      } else {
        scheduleNext();
      }
    } else {
      scheduleNext();
    }
  });

  // Start polling cycle
  scheduleNext();
}
window.initNotificationPolling = initNotificationPolling;

/**
 * Polished, non-blocking in-app notification popup / toast engine.
 * Usage:
 *   window.showToast('Work added successfully', 'success')
 *   window.showToast({
 *     title: 'Work added',
 *     message: 'Assignment 1 was added to Calculus.',
 *     type: 'success',
 *     priority: 'normal',
 *     action: { label: 'View Work', url: 'tasks.php' }
 *   })
 */
window.showToast = function showToast(messageOrOptions, typeOrOptions = 'info', extraOptions = {}) {
  let opts = {};
  if (typeof messageOrOptions === 'object' && messageOrOptions !== null) {
    opts = { ...messageOrOptions };
  } else if (typeof typeOrOptions === 'object' && typeOrOptions !== null) {
    opts = { message: String(messageOrOptions || ''), ...typeOrOptions };
  } else {
    opts = {
      message: String(messageOrOptions || ''),
      type: String(typeOrOptions || 'info'),
      ...extraOptions
    };
  }

  const rawMessage = String(opts.message || '');
  let type = String(opts.type || 'info').toLowerCase();

  // Deduce title if not explicitly provided
  let title = opts.title ? String(opts.title) : '';
  let cleanMessage = rawMessage;

  // Clean redundant notification prefixes
  cleanMessage = cleanMessage
    .replace(/^(Deadline Reminder \([^)]+\)|Urgent Deadline \([^)]+\)|Overdue Task|Class Reminder \([^)]+\)|Study suggestion|Study opportunity|Academic Calendar|Examination Alert|Revision Week Alert)[:\s-]*/i, '')
    .trim() || cleanMessage;

  if (!title) {
    if (/^work added/i.test(rawMessage) || /^task added/i.test(rawMessage)) {
      title = 'Work added';
      cleanMessage = rawMessage.replace(/^(work added|task added)[:\s-]*/i, '').trim() || rawMessage;
    } else if (/^work completed/i.test(rawMessage) || /^marked as completed/i.test(rawMessage)) {
      title = 'Work completed';
      cleanMessage = rawMessage.replace(/^(work completed|marked as completed)[:\s-]*/i, '').trim() || rawMessage;
    } else if (/^work reopened/i.test(rawMessage)) {
      title = 'Work reopened';
      cleanMessage = rawMessage.replace(/^work reopened[:\s-]*/i, '').trim() || rawMessage;
    } else if (/^work edited/i.test(rawMessage) || /^task updated/i.test(rawMessage)) {
      title = 'Work edited';
      cleanMessage = rawMessage.replace(/^(work edited|task updated)[:\s-]*/i, '').trim() || rawMessage;
    } else if (/^due soon/i.test(rawMessage) || /deadline reminder/i.test(rawMessage)) {
      title = 'Due soon';
      type = 'deadline';
    } else if (/^overdue/i.test(rawMessage)) {
      title = 'Overdue';
      type = 'overdue';
    } else if (/^class reminder/i.test(rawMessage)) {
      title = 'Class reminder';
      type = 'class_reminder';
    } else if (/^study suggestion/i.test(rawMessage) || /study opportunity/i.test(rawMessage)) {
      title = 'Study suggestion';
      type = 'study_suggestion';
    } else if (/^academic update/i.test(rawMessage) || /academic calendar/i.test(rawMessage) || /examination alert/i.test(rawMessage)) {
      title = 'Academic update';
      type = 'academic';
    } else if (type === 'success') {
      title = 'Success';
    } else if (type === 'error') {
      title = 'Error';
    } else if (type === 'warning') {
      title = 'Attention';
    } else {
      title = 'Notice';
    }
  }

  // Deduce priority
  let priority = opts.priority;
  if (!priority) {
    if (type === 'error' || type === 'overdue') {
      priority = 'high';
    } else if (['deadline', 'due_soon', 'study_suggestion', 'study', 'smart_study', 'class_reminder', 'academic', 'curriculum', 'warning'].includes(type)) {
      priority = 'important';
    } else {
      priority = 'normal';
    }
  }

  // Deduce duration
  let duration = opts.duration;
  if (!duration) {
    if (priority === 'high') {
      duration = 8500;
    } else if (priority === 'important') {
      duration = 7000;
    } else {
      duration = 4500;
    }
  }

  // Resolve actions
  let action = opts.action;
  if (!action && opts.action_label && opts.action_url) {
    action = { label: opts.action_label, url: opts.action_url };
  } else if (!action && opts.notifId) {
    if (type === 'overdue' || type === 'deadline' || type === 'due_soon') {
      action = { label: 'View Work', url: 'tasks.php' };
    } else if (type === 'study' || type === 'study_suggestion' || type === 'smart_study') {
      action = { label: 'Study Now', url: 'study.php' };
    } else if (type === 'class_reminder') {
      action = { label: 'View Class', url: 'schedule.php' };
    } else if (type === 'academic' || type === 'curriculum') {
      action = { label: 'View Schedule', url: 'schedule.php' };
    }
  }

  // Color styles
  let badgeBg = 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400';
  let borderClass = 'border-emerald-200/90 dark:border-emerald-800/60';
  let accentClass = 'bg-emerald-500';
  let iconSvg = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>`;

  if (priority === 'high' || type === 'error' || type === 'overdue') {
    badgeBg = 'bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400';
    borderClass = 'border-rose-300 dark:border-rose-700/60';
    accentClass = 'bg-rose-600';
    iconSvg = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>`;
  } else if (type === 'deadline' || type === 'due_soon' || type === 'warning') {
    badgeBg = 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400';
    borderClass = 'border-amber-300/90 dark:border-amber-700/60';
    accentClass = 'bg-amber-500';
    iconSvg = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>`;
  } else if (type === 'study' || type === 'study_suggestion' || type === 'smart_study') {
    badgeBg = 'bg-teal-100 dark:bg-teal-950/60 text-teal-700 dark:text-teal-400';
    borderClass = 'border-teal-300/90 dark:border-teal-700/60';
    accentClass = 'bg-teal-500';
    iconSvg = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>`;
  } else if (type === 'class_reminder') {
    badgeBg = 'bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-400';
    borderClass = 'border-indigo-300/90 dark:border-indigo-700/60';
    accentClass = 'bg-indigo-500';
    iconSvg = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>`;
  } else if (type === 'academic' || type === 'curriculum') {
    badgeBg = 'bg-blue-100 dark:bg-blue-950/60 text-blue-700 dark:text-blue-400';
    borderClass = 'border-blue-300/90 dark:border-blue-700/60';
    accentClass = 'bg-blue-500';
    iconSvg = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>`;
  } else if (type === 'info') {
    badgeBg = 'bg-sky-100 dark:bg-sky-950/60 text-sky-700 dark:text-sky-400';
    borderClass = 'border-sky-200 dark:border-sky-800/60';
    accentClass = 'bg-sky-500';
    iconSvg = `<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>`;
  }

  // Ensure stack exists
  let stack = document.getElementById('toast-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'toast-stack';
    stack.className = 'fixed bottom-4 sm:bottom-6 right-4 sm:right-6 left-4 sm:left-auto z-[9998] flex flex-col gap-2.5 max-w-full sm:max-w-sm sm:w-96 pointer-events-none transition-all duration-200';
    stack.setAttribute('aria-live', priority === 'high' ? 'assertive' : 'polite');
    stack.setAttribute('aria-atomic', 'false');
    document.body.appendChild(stack);
  }

  const toast = document.createElement('div');
  toast.className = `pointer-events-auto bg-white/95 dark:bg-[#15231c]/95 text-gray-900 dark:text-gray-100 rounded-2xl shadow-xl dark:shadow-2xl border p-4 font-sans flex items-start gap-3.5 relative overflow-hidden backdrop-blur-md ${borderClass}`;
  toast.style.opacity = '0';
  toast.style.transform = 'translateY(12px) scale(0.96)';
  toast.style.transition = 'all 0.22s cubic-bezier(0.16, 1, 0.3, 1)';
  toast.setAttribute('role', priority === 'high' ? 'alert' : 'status');

  const actionHtml = action ? `
    <div class="mt-2.5">
      <a href="${action.url || '#'}" class="toast-action-btn inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/60 border border-emerald-200 dark:border-emerald-800/60 transition shadow-xs">
        <span>${escapeHtml(action.label)}</span>
        <span aria-hidden="true">→</span>
      </a>
    </div>
  ` : '';

  toast.innerHTML = `
    <div class="absolute left-0 top-0 bottom-0 w-1 ${accentClass}"></div>
    <div class="w-9 h-9 rounded-xl ${badgeBg} flex items-center justify-center shrink-0 mt-0.5 shadow-xs">
      ${iconSvg}
    </div>
    <div class="flex-1 min-w-0 pr-6">
      <div class="flex items-center gap-2 mb-0.5">
        <h4 class="text-xs sm:text-sm font-bold text-gray-900 dark:text-gray-100 leading-tight">
          ${escapeHtml(title)}
        </h4>
        ${priority === 'high' ? `<span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-rose-100 dark:bg-rose-900/50 text-rose-700 dark:text-rose-300 uppercase tracking-wide">High</span>` : ''}
        ${priority === 'important' && (type === 'deadline' || type === 'due_soon') ? `<span class="px-1.5 py-0.5 text-[9px] font-bold rounded bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300 uppercase tracking-wide">Soon</span>` : ''}
      </div>
      <p class="text-xs text-gray-600 dark:text-gray-300 leading-relaxed">${escapeHtml(cleanMessage)}</p>
      ${actionHtml}
    </div>
    <button type="button" class="toast-close-btn absolute right-2.5 top-2.5 p-1 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/5 transition" aria-label="Dismiss notification">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
    </button>
  `;

  // Limit max simultaneous popups to 2 at any time
  while (stack.children.length >= 2) {
    stack.firstElementChild.remove();
  }

  stack.appendChild(toast);

  // Animate in
  requestAnimationFrame(() => {
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0) scale(1)';
  });

  const dismiss = () => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(8px) scale(0.96)';
    setTimeout(() => toast.remove(), 220);
  };

  // Close button
  const closeBtn = toast.querySelector('.toast-close-btn');
  if (closeBtn) {
    closeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      dismiss();
    });
  }

  // Action button
  if (action) {
    const actionBtn = toast.querySelector('.toast-action-btn');
    if (actionBtn) {
      actionBtn.addEventListener('click', (e) => {
        if (typeof action.onClick === 'function') {
          e.preventDefault();
          action.onClick();
        }
        if (opts.notifId) {
          const csrf = window.CSRF_TOKEN || '';
          fetch(`${API}/notifications.php`, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ id: Number(opts.notifId), csrf_token: csrf })
          }).then(r => r.json()).then(d => {
            if (d && typeof d.unread_count === 'number') {
              updateBellBadges(d.unread_count);
            }
          }).catch(() => {});
        }
        dismiss();
      });
    }
  }

  // Auto-dismiss with hover pause
  let timer = setTimeout(dismiss, duration);
  toast.addEventListener('mouseenter', () => clearTimeout(timer));
  toast.addEventListener('mouseleave', () => {
    timer = setTimeout(dismiss, Math.min(duration, 3000));
  });
};

window.showNotificationPopup = window.showToast;

/**
 * Animates a number counting up from 0 (or its current value) to `value`
 * inside `el`. Used for stat-card headline numbers. Purely a visual
 * effect on top of a real value already fetched from the API — it never
 * invents data, just animates the reveal of it.
 */
window.animateCounter = function animateCounter(el, value, opts) {
  opts = opts || {};

  const duration = opts.duration || 600;
  const decimals = opts.decimals || 0;
  const suffix = opts.suffix || '';

  const start = 0;
  const startTime = performance.now();

  function tick(now) {
    const progress = Math.min(
      1,
      (now - startTime) / duration
    );

    const eased = 1 - Math.pow(1 - progress, 3);

    const current =
      start + (value - start) * eased;

    el.textContent =
      current.toFixed(decimals) + suffix;

    if (progress < 1) {
      requestAnimationFrame(tick);
    } else {
      el.textContent =
        value.toFixed(decimals) + suffix;
    }
  }

  requestAnimationFrame(tick);
};

