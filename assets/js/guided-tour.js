/**
 * Guided "Teacher" Tour & Contextual Tips Engine
 * Student Study Planner — Interactive UX Onboarding
 */

(function () {
  'use strict';

  const TOUR_STEPS = [
    {
      target: '[data-nav="dashboard"]',
      fallbackTarget: '#nav-home',
      title: 'Welcome to Your Academic Home',
      description: 'Think of this as your personal university hub. Here you get a daily briefing of your workload, today\'s schedule, and what needs your attention next.',
      position: 'right'
    },
    {
      target: '[data-nav="courses"]',
      fallbackTarget: '#nav-courses',
      title: 'My Courses',
      description: 'All your registered subjects live here. View your course codes, credit units, lecturer details, and syllabus breakdown at any time.',
      position: 'right'
    },
    {
      target: '[data-nav="schedule"]',
      fallbackTarget: '#nav-schedule',
      title: 'Class Timetable & Schedule',
      description: 'Never miss a lecture or lab. Your weekly class schedule automatically shows you what is happening today and what classes are coming up next.',
      position: 'right'
    },
    {
      target: '[data-nav="tasks"]',
      fallbackTarget: '#nav-tasks',
      title: 'Assignments, Projects & Tests',
      description: 'Keep all your academic tasks organized in one place with automated priority ranking, progress tracking, and deadline countdowns.',
      position: 'right'
    },
    {
      target: '[data-nav="focus"]',
      fallbackTarget: '#focus-timer-card',
      title: 'Study Sessions & Focus Timer',
      description: 'The built-in study timer helps you stay focused during study blocks and automatically logs your study hours toward your weekly academic goals.',
      position: 'right'
    },
    {
      target: '[data-nav="deadlines"]',
      fallbackTarget: '#nav-deadlines',
      title: 'Upcoming Deadlines',
      description: 'Clear countdowns for upcoming assignments, tests, and exams so you always know how many days you have left to submit.',
      position: 'right'
    },
    {
      target: '[data-nav="progress"]',
      fallbackTarget: '#nav-progress',
      title: 'Academic Standing & Progress',
      description: 'Monitor your completion rates, weekly study streaks, and estimated GPA across the semester to stay on track for graduation.',
      position: 'right'
    },
    {
      target: '[data-nav="notifications"]',
      fallbackTarget: '#bell-btn',
      title: 'Reminders & Alerts',
      description: 'Receive gentle alerts when a deadline is approaching, a lecture is starting, or you have study goals to meet.',
      position: 'right'
    },
    {
      target: '[data-nav="settings"]',
      fallbackTarget: '#nav-settings',
      title: 'Settings & Tour Replay',
      description: 'Customize your dark mode, weekly study goal hours, notification preferences, or restart this guided tour whenever you need a refresher.',
      position: 'right'
    }
  ];

  class GuidedTour {
    constructor() {
      this.currentStep = 0;
      this.isActive = false;
      this.overlay = null;
      this.popover = null;
      this.highlight = null;
      this.boundKeyHandler = this.handleKeydown.bind(this);
      this.boundResizeHandler = this.reposition.bind(this);
    }

    async start(startStep = 0) {
      if (this.isActive) return;

      // Ensure sidebar is loaded before targeting sidebar navigation elements
      const maxWait = 20; // 2 seconds max
      let waited = 0;
      while (!document.querySelector('[data-nav="dashboard"]') && !document.getElementById('app-sidebar') && waited < maxWait) {
        await new Promise(r => setTimeout(r, 100));
        waited++;
      }

      this.isActive = true;
      this.currentStep = startStep;

      // Ensure mobile sidebar is accessible or visible if target is in sidebar
      this.ensureSidebarVisible();

      this.createDOM();
      document.addEventListener('keydown', this.boundKeyHandler);
      window.addEventListener('resize', this.boundResizeHandler);
      window.addEventListener('scroll', this.boundResizeHandler, true);

      this.renderStep(this.currentStep);
    }

    ensureSidebarVisible() {
      const sidebar = document.getElementById('app-sidebar');
      if (sidebar && window.innerWidth < 1280) {
        sidebar.classList.remove('-translate-x-full');
      }
    }

    createDOM() {
      // Backdrop Overlay
      this.overlay = document.createElement('div');
      this.overlay.id = 'tour-backdrop-overlay';
      this.overlay.className = 'fixed inset-0 z-50 pointer-events-auto transition-opacity duration-300';
      this.overlay.style.backgroundColor = 'rgba(7, 25, 18, 0.65)';
      this.overlay.style.backdropFilter = 'blur(2px)';

      // Spotlight Box
      this.highlight = document.createElement('div');
      this.highlight.id = 'tour-highlight-box';
      this.highlight.className = 'fixed z-50 pointer-events-none rounded-xl transition-all duration-300';
      this.highlight.style.boxShadow = '0 0 0 4px #10b981, 0 0 25px rgba(16, 185, 129, 0.45)';

      // Popover Card
      this.popover = document.createElement('div');
      this.popover.id = 'tour-popover-card';
      this.popover.className = 'fixed z-50 w-80 sm:w-96 max-w-[calc(100vw-32px)] bg-white dark:bg-[#15231c] text-gray-900 dark:text-gray-100 rounded-2xl shadow-2xl border border-emerald-500/30 p-5 transition-all duration-300 font-sans';
      this.popover.setAttribute('role', 'dialog');
      this.popover.setAttribute('aria-modal', 'true');

      document.body.appendChild(this.overlay);
      document.body.appendChild(this.highlight);
      document.body.appendChild(this.popover);
    }

    resolveTarget(step) {
      let el = document.querySelector(step.target);
      if (!el && step.fallbackTarget) {
        el = document.querySelector(step.fallbackTarget);
      }
      return el;
    }

    renderStep(index) {
      if (index < 0 || index >= TOUR_STEPS.length) {
        this.finish();
        return;
      }

      this.currentStep = index;
      const step = TOUR_STEPS[index];
      const targetEl = this.resolveTarget(step);

      if (!targetEl) {
        // Retry once after 200ms before skipping to allow dynamic elements to settle
        if (!step._retried) {
          step._retried = true;
          setTimeout(() => this.renderStep(index), 200);
          return;
        }
        // Skip step if target not found on this page
        if (index < TOUR_STEPS.length - 1) {
          this.renderStep(index + 1);
        } else {
          this.finish();
        }
        return;
      }

      // Scroll target into view
      targetEl.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });

      // Highlight positioning
      setTimeout(() => {
        this.reposition();
      }, 100);

      const total = TOUR_STEPS.length;
      const isFirst = index === 0;
      const isLast = index === total - 1;

      this.popover.innerHTML = `
        <div class="flex items-center justify-between pb-3 mb-3 border-b border-gray-100 dark:border-white/10">
          <div class="flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 flex items-center justify-center text-xs font-bold">🎓</span>
            <span class="text-xs font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
              Step ${index + 1} of ${total}
            </span>
          </div>
          <button id="tour-skip-btn" type="button" class="text-xs font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition px-2 py-1 rounded">
            Skip Tour
          </button>
        </div>

        <h3 class="text-base sm:text-lg font-bold text-gray-900 dark:text-gray-100 mb-1.5 leading-snug">
          ${step.title}
        </h3>

        <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-300 leading-relaxed mb-5">
          ${step.description}
        </p>

        <div class="flex items-center justify-between pt-1">
          <div class="flex items-center gap-1">
            ${TOUR_STEPS.map((_, i) => `
              <span class="w-1.5 h-1.5 rounded-full ${i === index ? 'bg-emerald-600 dark:bg-emerald-400 w-4' : 'bg-gray-200 dark:bg-white/20'} transition-all duration-200"></span>
            `).join('')}
          </div>

          <div class="flex items-center gap-2">
            ${!isFirst ? `
              <button id="tour-back-btn" type="button" class="px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg transition">
                Back
              </button>
            ` : ''}

            <button id="tour-next-btn" type="button" class="px-4 py-1.5 text-xs font-semibold bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg shadow-sm transition flex items-center gap-1">
              ${isLast ? 'Finish 🎓' : 'Next →'}
            </button>
          </div>
        </div>
      `;

      // Wire buttons
      const nextBtn = this.popover.querySelector('#tour-next-btn');
      const backBtn = this.popover.querySelector('#tour-back-btn');
      const skipBtn = this.popover.querySelector('#tour-skip-btn');

      if (nextBtn) {
        nextBtn.focus();
        nextBtn.addEventListener('click', () => {
          if (isLast) this.finish();
          else this.renderStep(index + 1);
        });
      }

      if (backBtn) {
        backBtn.addEventListener('click', () => {
          this.renderStep(index - 1);
        });
      }

      if (skipBtn) {
        skipBtn.addEventListener('click', () => this.skip());
      }
    }

    reposition() {
      if (!this.isActive || !this.popover || !this.highlight) return;
      const step = TOUR_STEPS[this.currentStep];
      const targetEl = this.resolveTarget(step);
      if (!targetEl) return;

      const rect = targetEl.getBoundingClientRect();
      const pad = 6;

      // Position highlight box
      this.highlight.style.top = `${Math.max(0, rect.top - pad)}px`;
      this.highlight.style.left = `${Math.max(0, rect.left - pad)}px`;
      this.highlight.style.width = `${rect.width + pad * 2}px`;
      this.highlight.style.height = `${rect.height + pad * 2}px`;

      // Position popover card
      const popoverRect = this.popover.getBoundingClientRect();
      const viewportWidth = window.innerWidth;
      const viewportHeight = window.innerHeight;

      let top = rect.bottom + 14;
      let left = rect.left;

      // If sidebar link on desktop, place to the right
      if (rect.left < 280 && rect.width < 280 && viewportWidth >= 768) {
        top = Math.max(16, rect.top);
        left = rect.right + 18;
      } else {
        // If bottom overflow, place above
        if (top + popoverRect.height > viewportHeight - 16) {
          top = Math.max(16, rect.top - popoverRect.height - 14);
        }
      }

      // Constrain horizontally within viewport
      if (left + popoverRect.width > viewportWidth - 16) {
        left = viewportWidth - popoverRect.width - 16;
      }
      if (left < 16) {
        left = 16;
      }

      // Constrain vertically within viewport
      if (top + popoverRect.height > viewportHeight - 16) {
        top = viewportHeight - popoverRect.height - 16;
      }
      if (top < 16) {
        top = 16;
      }

      this.popover.style.top = `${top}px`;
      this.popover.style.left = `${left}px`;
    }

    handleKeydown(e) {
      if (!this.isActive) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        this.skip();
      } else if (e.key === 'ArrowRight' || e.key === 'Enter') {
        e.preventDefault();
        if (this.currentStep < TOUR_STEPS.length - 1) {
          this.renderStep(this.currentStep + 1);
        } else {
          this.finish();
        }
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        if (this.currentStep > 0) {
          this.renderStep(this.currentStep - 1);
        }
      }
    }

    async persistCompletion() {
      try {
        const csrf = window.CSRF_TOKEN || '';
        await fetch('../api/settings.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf
          },
          body: JSON.stringify({
            csrf_token: csrf,
            tour_completed: 1
          })
        });
        if (window.CURRENT_USER) {
          window.CURRENT_USER.tour_completed = true;
        }
      } catch (err) {
        console.warn('Could not persist tour completion:', err);
      }
    }

    cleanup() {
      this.isActive = false;
      document.removeEventListener('keydown', this.boundKeyHandler);
      window.removeEventListener('resize', this.boundResizeHandler);
      window.removeEventListener('scroll', this.boundResizeHandler, true);

      if (this.overlay) {
        this.overlay.remove();
        this.overlay = null;
      }
      if (this.highlight) {
        this.highlight.remove();
        this.highlight = null;
      }
      if (this.popover) {
        this.popover.remove();
        this.popover = null;
      }
    }

    finish() {
      this.cleanup();
      this.persistCompletion();
    }

    skip() {
      this.cleanup();
      this.persistCompletion();
    }
  }

  // =========================================================================
  // CONTEXTUAL TIPS SYSTEM (Dismissible 💡 tips on key features)
  // =========================================================================
  const CONTEXTUAL_TIPS = {
    'tasks': {
      selector: '#tasks-list-container, #tasks-page-header, main',
      id: 'tip-tasks',
      title: 'Quick Tip: Organizing Your Academic Work',
      text: 'Add assignments, projects, and upcoming test dates here. The system will automatically calculate urgency, order your priorities, and display countdowns on your dashboard.',
      actionText: 'Got it'
    },
    'focus': {
      selector: '#focus-timer-card, #timer-container, main',
      id: 'tip-focus',
      title: 'Quick Tip: Study Hours & Streaks',
      text: 'Starting a focus timer logs your active study hours toward your weekly goal and strengthens your consistency streak.',
      actionText: 'Got it'
    },
    'courses': {
      selector: '#courses-grid, #courses-header, main',
      id: 'tip-courses',
      title: 'Quick Tip: Document Import',
      text: 'You can upload your university course registration form (.pdf) to import all course codes, credit units, and titles instantly without typing them one-by-one.',
      actionText: 'Got it'
    }
  };

  async function dismissContextualTip(tipId, tipCardEl) {
    if (tipCardEl) {
      tipCardEl.style.opacity = '0';
      tipCardEl.style.transform = 'translateY(-6px)';
      setTimeout(() => tipCardEl.remove(), 250);
    }
    try {
      const csrf = window.CSRF_TOKEN || '';
      await fetch('../api/settings.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf
        },
        body: JSON.stringify({
          csrf_token: csrf,
          action: 'dismiss_tip',
          tip_id: tipId
        })
      });
      if (window.CURRENT_USER && Array.isArray(window.CURRENT_USER.dismissed_tips)) {
        window.CURRENT_USER.dismissed_tips.push(tipId);
      }
    } catch (err) {
      console.warn('Could not record tip dismissal:', err);
    }
  }

  function renderContextualTip(key, dismissedList = []) {
    const tip = CONTEXTUAL_TIPS[key];
    if (!tip) return;
    if (dismissedList.includes(tip.id)) return;
    if (document.getElementById(tip.id)) return;

    const parent = document.querySelector(tip.selector);
    if (!parent) return;

    const tipCard = document.createElement('div');
    tipCard.id = tip.id;
    tipCard.className = 'mb-6 p-4 rounded-xl bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-emerald-950/30 dark:to-teal-950/20 border border-emerald-200/80 dark:border-emerald-800/40 text-gray-800 dark:text-gray-200 flex items-start gap-3 transition-all duration-200 shadow-sm';
    tipCard.innerHTML = `
      <span class="text-xl shrink-0 mt-0.5" aria-hidden="true">💡</span>
      <div class="flex-1 min-w-0">
        <h4 class="text-xs sm:text-sm font-bold text-emerald-900 dark:text-emerald-300 mb-0.5">
          ${tip.title}
        </h4>
        <p class="text-xs text-emerald-950/80 dark:text-emerald-200/70 leading-relaxed">
          ${tip.text}
        </p>
      </div>
      <button type="button" class="shrink-0 text-xs font-semibold text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 px-2.5 py-1 rounded-lg transition" aria-label="Dismiss tip">
        ${tip.actionText}
      </button>
    `;

    const closeBtn = tipCard.querySelector('button');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => dismissContextualTip(tip.id, tipCard));
    }

    if (parent.firstChild) {
      parent.insertBefore(tipCard, parent.firstChild);
    } else {
      parent.appendChild(tipCard);
    }
  }

  // Export to window
  window.GuidedTour = new GuidedTour();
  window.renderContextualTip = renderContextualTip;

  // Auto-trigger on page load
  async function initTourBootstrap() {
    if (window.APP_READY) {
      try {
        const me = await window.APP_READY;
        if (!me) return;

        // Check if URL has ?tour=start
        const params = new URLSearchParams(window.location.search);
        if (params.get('tour') === 'start') {
          setTimeout(() => window.GuidedTour.start(), 400);
          return;
        }

        // Check if new user who has not completed the tour
        const isTourDone = me.tour_completed === true || me.tour_completed === 1 || me.tour_completed === '1';
        if (!isTourDone) {
          const page = document.body.dataset.page || '';
          if (page === 'dashboard' || window.location.pathname.endsWith('dashboard.php') || window.location.pathname.endsWith('/')) {
            setTimeout(() => window.GuidedTour.start(), 800);
          }
        }

        // Render contextual tips for current page
        const dismissed = Array.isArray(me.dismissed_tips) ? me.dismissed_tips : [];
        const page = document.body.dataset.page || '';
        if (page === 'tasks' || window.location.pathname.includes('tasks')) {
          renderContextualTip('tasks', dismissed);
        } else if (page === 'courses' || window.location.pathname.includes('courses')) {
          renderContextualTip('courses', dismissed);
        } else if (page === 'schedule' || window.location.pathname.includes('schedule')) {
          renderContextualTip('focus', dismissed);
        }
      } catch (e) {
        console.warn('Tour bootstrap check failed:', e);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTourBootstrap);
  } else {
    initTourBootstrap();
  }

})();
