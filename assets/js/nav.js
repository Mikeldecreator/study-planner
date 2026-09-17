
const API = '../api';

window.APP_READY = (async function bootstrap() {
  const sidebarSlot = document.getElementById('sidebar-slot');

  if (sidebarSlot) {
    try {
      const response = await fetch('../assets/partials/sidebar.html');

      if (!response.ok) {
        throw new Error('Could not load sidebar');
      }

      const html = await response.text();
      sidebarSlot.outerHTML = html;

      // Wait until the sidebar is actually in the DOM,
      // then create the Lucide icons.
      requestAnimationFrame(() => {
        if (window.lucide) {
          window.lucide.createIcons();
        }
      });

    } catch (error) {
      console.error('Sidebar loading failed:', error);
    }
  }

  highlightActiveNavLink();
  wireMobileDrawer();
  wireLogout();
  wireDarkToggleInputs();
  wireStudyAI();

  let meRes;

  try {
    meRes = await fetch(`${API}/me.php`, {
      credentials: 'same-origin'
    });
  } catch (error) {
    window.location.href = 'login.php';
    return null;
  }

  if (meRes.status === 401) {
    window.location.href = 'login.php';
    return null;
  }

  if (!meRes.ok) {
    window.location.href = 'login.php';
    return null;
  }

  let me;

  try {
    me = await meRes.json();
  } catch (error) {
    window.location.href = 'login.php';
    return null;
  }

  let csrf;

  try {
    const csrfRes = await fetch(`${API}/csrf.php`, {
      credentials: 'same-origin'
    });

    if (csrfRes.ok) {
      csrf = await csrfRes.json();
      window.CSRF_TOKEN = csrf.csrf_token;
    }
  } catch (error) {
    console.error('CSRF request failed:', error);
  }

  window.CURRENT_USER = me;

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

  syncDarkModeUI(me.dark_mode);

  return me;
})();

function highlightActiveNavLink() {
  const page = document.body.dataset.page;

  document.querySelectorAll('[data-nav]').forEach(link => {
    if (link.dataset.nav === page) {
      link.classList.remove('text-white/80', 'hover:bg-white/10');
      link.classList.add(
        'bg-emerald-600',
        'text-white',
        'font-semibold'
      );
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

  aiButton.addEventListener('click', () => {
    window.dispatchEvent(
      new CustomEvent('study-ai:open')
    );
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

  const open = () => {
    sidebar?.classList.remove('-translate-x-full');
    backdrop?.classList.remove('hidden');
  };

  const close = () => {
    sidebar?.classList.add('-translate-x-full');
    backdrop?.classList.add('hidden');
  };

  openBtn?.addEventListener('click', open);
  closeBtn?.addEventListener('click', close);
  backdrop?.addEventListener('click', close);
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

/**
 * Lightweight toast notifications, used across pages instead of alert()
 * for success/info messages (alert() is still used for anything that
 * truly needs to block the user, e.g. confirming a delete).
 * Usage: window.showToast('Course added', 'success' | 'error' | 'info')
 */
window.showToast = function showToast(message, type) {
  type = type || 'info';

  let stack = document.getElementById('toast-stack');

  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'toast-stack';
    document.body.appendChild(stack);
  }

  const toast = document.createElement('div');

  toast.className = `toast toast-${type}`;

  const icon =
    type === 'success'
      ? 'check-circle'
      : (type === 'error' ? 'alert-circle' : 'info');

  toast.innerHTML = `
    <i data-lucide="${icon}" class="w-4 h-4 shrink-0"></i>
    <span>${escapeHtml(message)}</span>
  `;

  stack.appendChild(toast);

  if (window.lucide) {
    window.lucide.createIcons();
  }

  setTimeout(() => {
    toast.classList.add('toast-out');

    setTimeout(() => toast.remove(), 200);
  }, 3200);
};

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

