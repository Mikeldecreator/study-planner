let ALL_DEADLINES = [];

let calMonth =
  new Date().getMonth();

let calYear =
  new Date().getFullYear();

let deadlineDonut = null;

let countdownTimer = null;

let deadlineView = 'all';

const URGENCY_LABEL = {
  upcoming: 'On Track',
  on_track: 'On Track',
  due_soon: 'Due Soon',
  overdue: 'Overdue'
};

const URGENCY_CLASSES = {
  upcoming:
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',

  on_track:
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',

  due_soon:
    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',

  overdue:
    'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'
};

const URGENCY_DOT = {
  upcoming: 'bg-emerald-500',
  on_track: 'bg-emerald-500',
  due_soon: 'bg-amber-500',
  overdue: 'bg-red-500'
};

const URGENCY_TEXT = {
  upcoming:
    'text-emerald-600 dark:text-emerald-400',

  on_track:
    'text-emerald-600 dark:text-emerald-400',

  due_soon:
    'text-amber-600 dark:text-amber-400',

  overdue:
    'text-red-600 dark:text-red-400'
};

// ------------------------------------------------------
// Helpers
// ------------------------------------------------------

function initLucide() {
  if (window.lucide) {
    window.lucide.createIcons();
  }
}

function getSearchValue() {

  const desktop =
    document.getElementById(
      'deadline-search'
    );

  const mobile =
    document.getElementById(
      'deadline-search-mobile'
    );

  return (
    desktop?.value ||
    mobile?.value ||
    ''
  )
    .trim()
    .toLowerCase();
}

function setSearchValue(value) {

  const desktop =
    document.getElementById(
      'deadline-search'
    );

  const mobile =
    document.getElementById(
      'deadline-search-mobile'
    );

  if (desktop) {
    desktop.value = value;
  }

  if (mobile) {
    mobile.value = value;
  }
}

function sameCalendarDay(
  dateA,
  dateB
) {

  return (
    dateA.getFullYear() ===
      dateB.getFullYear() &&
    dateA.getMonth() ===
      dateB.getMonth() &&
    dateA.getDate() ===
      dateB.getDate()
  );
}

function startOfToday() {

  const date =
    new Date();

  date.setHours(
    0,
    0,
    0,
    0
  );

  return date;
}

function endOfToday() {

  const date =
    new Date();

  date.setHours(
    23,
    59,
    59,
    999
  );

  return date;
}

function getWeekStart() {

  const today =
    new Date();

  today.setHours(
    0,
    0,
    0,
    0
  );

  today.setDate(
    today.getDate() -
      today.getDay()
  );

  return today;
}

function getWeekEnd() {

  const end =
    getWeekStart();

  end.setDate(
    end.getDate() + 6
  );

  end.setHours(
    23,
    59,
    59,
    999
  );

  return end;
}

function escapeAttribute(value) {

  return String(value)
    .replace(
      /&/g,
      '&amp;'
    )
    .replace(
      /"/g,
      '&quot;'
    )
    .replace(
      /</g,
      '&lt;'
    )
    .replace(
      />/g,
      '&gt;'
    );
}

function getPriorityLabel(deadline) {

  if (!deadline) {
    return '—';
  }

  const priority =
    String(
      deadline.priority ||
      deadline.priority_level ||
      ''
    ).toLowerCase();

  if (!priority) {
    return '—';
  }

  return (
    priority.charAt(0).toUpperCase() +
    priority.slice(1)
  );
}

function getPriorityClasses(
  deadline
) {

  const priority =
    String(
      deadline?.priority ||
      deadline?.priority_level ||
      ''
    ).toLowerCase();

  if (priority === 'high') {

    return `
      bg-red-50
      text-red-600
      dark:bg-red-500/10
      dark:text-red-300
    `;
  }

  if (priority === 'medium') {

    return `
      bg-amber-50
      text-amber-600
      dark:bg-amber-500/10
      dark:text-amber-300
    `;
  }

  if (priority === 'low') {

    return `
      bg-sky-50
      text-sky-600
      dark:bg-sky-500/10
      dark:text-sky-300
    `;
  }

  return `
    bg-gray-100
    text-gray-500
    dark:bg-white/5
    dark:text-gray-400
  `;
}

// ------------------------------------------------------
// Load Data
// ------------------------------------------------------

async function loadDeadlines() {

  try {

    const res =
      await fetch(
        `${API}/deadlines.php?include_completed=1`
      );

    if (!res.ok) {
      return;
    }

    const data =
      await res.json();

    ALL_DEADLINES =
      Array.isArray(
        data.deadlines
      )
        ? data.deadlines
        : [];

    renderStatCards(
      data.summary
    );

    renderDonut(
      data.summary
    );

    renderAttentionBanner();

    applyTableFilters();

    renderUpcoming7();

    renderCalendar();

    startCountdownTicker();

    initLucide();

  } catch (error) {

    console.error(
      'Could not load deadlines:',
      error
    );
  }
}

// ------------------------------------------------------
// Statistics
// ------------------------------------------------------

function renderStatCards(
  summary
) {

  const target =
    document.getElementById(
      'deadline-stat-cards'
    );

  if (!target) return;

  target.innerHTML = `

    ${statCard(
      'check-circle-2',
      'emerald',
      summary.on_track,
      'On Track',
      'Deadlines with enough time'
    )}

    ${statCard(
      'clock-3',
      'amber',
      summary.due_soon,
      'Due Soon',
      'Requires attention'
    )}

    ${statCard(
      'alert-circle',
      'red',
      summary.overdue,
      'Overdue',
      'Past the due date'
    )}

    ${statCard(
      'calendar-days',
      'blue',
      summary.total,
      'Total Deadlines',
      'This semester'
    )}

  `;

  initLucide();

  document
    .querySelectorAll(
      '[data-stat-value]'
    )
    .forEach(element => {

      const value =
        Number(
          element.dataset
            .statValue
        );

      if (
        window.animateCounter
      ) {

        window.animateCounter(
          element,
          value,
          {}
        );

      } else {

        element.textContent =
          value;
      }
    });
}

function statCard(
  icon,
  tone,
  value,
  label,
  helper
) {

  const tones = {

    emerald: {
      icon:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
    },

    amber: {
      icon:
        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
    },

    red: {
      icon:
        'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'
    },

    blue: {
      icon:
        'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300'
    }
  };

  return `

    <div
      class="metric-card surface rounded-2xl p-4 sm:p-5 flex items-center gap-3.5 sm:gap-4"
    >

      <div
        class="metric-icon ${tones[tone].icon}"
      >
        <i
          data-lucide="${icon}"
          class="w-6 h-6"
        ></i>
      </div>

      <div class="min-w-0">

        <div
          class="text-2xl sm:text-[28px] font-bold leading-none"
          data-stat-value="${value}"
        >
          0
        </div>

        <div
          class="text-xs sm:text-sm font-semibold text-gray-700 dark:text-gray-200 mt-1"
        >
          ${label}
        </div>

        <div
          class="text-[10px] sm:text-xs text-gray-400 dark:text-gray-500 mt-1 truncate"
        >
          ${helper}
        </div>

      </div>

    </div>
  `;
}

// ------------------------------------------------------
// Donut
// ------------------------------------------------------

function renderDonut(
  summary
) {

  const canvas =
    document.getElementById(
      'deadline-donut'
    );

  const legend =
    document.getElementById(
      'deadline-donut-legend'
    );

  const total =
    document.getElementById(
      'deadline-donut-total'
    );

  if (!canvas) {
    return;
  }

  const labels = [
    'On Track',
    'Due Soon',
    'Overdue'
  ];

  const data = [
    Number(summary.on_track || 0),
    Number(summary.due_soon || 0),
    Number(summary.overdue || 0)
  ];

  const colors = [
    '#059669',
    '#f59e0b',
    '#ef4444'
  ];

  if (deadlineDonut) {

    deadlineDonut.destroy();

    deadlineDonut = null;
  }

  deadlineDonut =
    new Chart(
      canvas,
      {
        type: 'doughnut',

        data: {
          labels,

          datasets: [
            {
              data,
              backgroundColor:
                colors,
              borderWidth: 0
            }
          ]
        },

        options: {

          cutout: '70%',

          responsive: true,

          maintainAspectRatio:
            false,

          plugins: {
            legend: {
              display: false
            }
          }
        }
      }
    );

  if (total) {
    total.textContent =
      summary.total ?? 0;
  }

  if (!legend) {
    return;
  }

  legend.innerHTML =
    labels
      .map(
        (label, index) => `
          <div class="flex items-center gap-2">

            <span
              class="w-2.5 h-2.5 rounded-full shrink-0"
              style="background:${colors[index]}"
            ></span>

            <span class="text-gray-600 dark:text-gray-300 truncate">
              ${label}
            </span>

            <span class="ml-auto font-bold">
              ${data[index]}
            </span>

          </div>
        `
      )
      .join('');
}

// ------------------------------------------------------
// Filtering
// ------------------------------------------------------

function esc(str) {
  return typeof window.escapeHtml === 'function' ? window.escapeHtml(str) : String(str ?? '');
}

function safeColor(c) {
  return /^#[0-9a-fA-F]{3,8}$/.test(String(c || '').trim()) ? c : '#059669';
}

function applyTableFilters() {
  const selectedStatus = document.getElementById('deadline-status-filter')?.value || '';
  const query = getSearchValue();
  let rows = [...ALL_DEADLINES];
  const now = new Date();

  if (deadlineView === 'overdue') {
    rows = rows.filter(d => (d.urgency === 'overdue') && d.system_status !== 'completed' && d.status !== 'completed');
  } else if (deadlineView === 'today') {
    const start = startOfToday();
    const end = endOfToday();
    rows = rows.filter(d => {
      const due = new Date(d.due_at);
      return due >= start && due <= end && d.urgency !== 'completed' && d.system_status !== 'completed' && d.status !== 'completed';
    });
  } else if (deadlineView === 'due_soon') {
    rows = rows.filter(d => (d.urgency === 'due_soon') && d.system_status !== 'completed' && d.status !== 'completed');
  } else if (deadlineView === 'upcoming') {
    rows = rows.filter(d => (d.urgency === 'upcoming' || d.urgency === 'on_track') && d.system_status !== 'completed' && d.status !== 'completed');
  } else if (deadlineView === 'completed') {
    rows = rows.filter(d => d.urgency === 'completed' || d.system_status === 'completed' || d.status === 'completed');
  } else if (deadlineView === 'week') {
    const start = getWeekStart();
    const end = getWeekEnd();
    rows = rows.filter(d => {
      const due = new Date(d.due_at);
      return due >= start && due <= end;
    });
  } else if (deadlineView === 'month') {
    rows = rows.filter(d => {
      const due = new Date(d.due_at);
      return due.getMonth() === now.getMonth() && due.getFullYear() === now.getFullYear();
    });
  } else {
    if (selectedStatus !== 'completed') {
      rows = rows.filter(d => d.urgency !== 'completed' && d.system_status !== 'completed' && d.status !== 'completed');
    }
  }

  if (selectedStatus) {
    if (selectedStatus === 'completed') {
      rows = rows.filter(d => d.urgency === 'completed' || d.system_status === 'completed' || d.status === 'completed');
    } else {
      rows = rows.filter(d => d.urgency === selectedStatus);
    }
  }

  if (query) {
    rows = rows.filter(deadline => {
      const title = String(deadline.title || '').toLowerCase();
      const description = String(deadline.description || '').toLowerCase();
      const courseCode = String(deadline.course_code || '').toLowerCase();
      const courseName = String(deadline.course_name || '').toLowerCase();
      return (
        title.includes(query) ||
        description.includes(query) ||
        courseCode.includes(query) ||
        courseName.includes(query)
      );
    });
  }

  rows.sort((a, b) => new Date(a.due_at) - new Date(b.due_at));
  renderTable(rows);
  updateFilterUI();
}

function updateFilterUI() {
  document.querySelectorAll('.deadline-filter-btn').forEach(button => {
    const active = button.dataset.deadlineView === deadlineView;
    button.classList.toggle('active', active);
  });

  const clear = document.getElementById('deadline-clear-filter');
  const hasSearch = getSearchValue() !== '';
  const hasStatus = document.getElementById('deadline-status-filter')?.value !== '';

  if (clear) {
    clear.classList.toggle('hidden', !(hasSearch || hasStatus || deadlineView !== 'all'));
  }

  const subtitle = document.getElementById('deadline-table-subtitle');
  if (subtitle) {
    if (deadlineView === 'overdue') {
      subtitle.textContent = "Overdue deadlines requiring attention.";
    } else if (deadlineView === 'today') {
      subtitle.textContent = "Deadlines due today.";
    } else if (deadlineView === 'due_soon') {
      subtitle.textContent = "Deadlines due soon.";
    } else if (deadlineView === 'upcoming') {
      subtitle.textContent = "Upcoming deadlines on track.";
    } else if (deadlineView === 'completed') {
      subtitle.textContent = "Completed deadlines.";
    } else if (deadlineView === 'week') {
      subtitle.textContent = "Deadlines due this week.";
    } else if (deadlineView === 'month') {
      subtitle.textContent = "Deadlines due this month.";
    } else {
      subtitle.textContent = "Your deadlines sorted by due date.";
    }
  }
}

function renderAttentionBanner() {
  const banner = document.getElementById('deadline-attention-banner');
  if (!banner) return;

  const activeDeadlines = ALL_DEADLINES.filter(d => {
    return d.urgency !== 'completed' && d.system_status !== 'completed' && d.status !== 'completed';
  });

  if (!activeDeadlines.length) {
    banner.classList.remove('hidden');
    if (!ALL_DEADLINES.length) {
      banner.innerHTML = `
        <div class="surface rounded-2xl p-4 sm:p-5 border-l-4 border-emerald-500 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div class="flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center shrink-0">
              <i data-lucide="sparkles" class="w-5 h-5"></i>
            </div>
            <div>
              <div class="font-bold text-sm text-emerald-800 dark:text-emerald-300">Welcome to Due Soon!</div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Add your assignments, coursework, and exams to track what's due next.</div>
            </div>
          </div>
          <a href="tasks.php?add_task=1" class="btn-press inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white font-semibold text-xs shadow-sm transition shrink-0">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Work
          </a>
        </div>
      `;
    } else {
      banner.innerHTML = `
        <div class="surface rounded-2xl p-4 sm:p-5 border-l-4 border-emerald-500 flex items-center justify-between gap-4">
          <div class="flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center shrink-0">
              <i data-lucide="shield-check" class="w-5 h-5"></i>
            </div>
            <div>
              <div class="font-bold text-sm text-emerald-800 dark:text-emerald-300">All Caught Up!</div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">No overdue or urgent deadlines right now. Great job keeping your academic workload on track!</div>
            </div>
          </div>
          <a href="tasks.php?add_task=1" class="btn-press hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 font-semibold text-xs border border-emerald-200 dark:border-emerald-500/20">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Work
          </a>
        </div>
      `;
    }
    initLucide();
    return;
  }

  const overdues = activeDeadlines.filter(d => d.urgency === 'overdue');
  const dueSoons = activeDeadlines.filter(d => d.urgency === 'due_soon');

  let target = null;
  let tone = 'emerald';
  let badgeText = 'Next Upcoming';

  if (overdues.length > 0) {
    target = overdues[0];
    tone = 'red';
    badgeText = 'Needs Attention: Overdue';
  } else if (dueSoons.length > 0) {
    target = dueSoons[0];
    tone = 'amber';
    badgeText = 'Needs Attention: Due Soon';
  } else {
    target = activeDeadlines[0];
    tone = 'emerald';
    badgeText = 'Next Deadline';
  }

  const dueDate = new Date(target.due_at);
  const dueFormatted = !Number.isNaN(dueDate.getTime()) ? dueDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';

  const toneClasses = {
    red: 'border-l-4 border-red-500 bg-red-50/40 dark:bg-red-500/5',
    amber: 'border-l-4 border-amber-500 bg-amber-50/40 dark:bg-amber-500/5',
    emerald: 'border-l-4 border-emerald-500 bg-emerald-50/40 dark:bg-emerald-500/5'
  };

  const badgeClasses = {
    red: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'
  };

  const iconName = tone === 'red' ? 'alert-triangle' : (tone === 'amber' ? 'clock' : 'calendar-clock');

  banner.classList.remove('hidden');
  banner.innerHTML = `
    <div class="surface rounded-2xl p-4 sm:p-5 ${toneClasses[tone]} flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div class="flex items-start sm:items-center gap-3.5 min-w-0">
        <div class="w-10 h-10 rounded-xl ${badgeClasses[tone]} flex items-center justify-center shrink-0 mt-0.5 sm:mt-0">
          <i data-lucide="${iconName}" class="w-5 h-5"></i>
        </div>
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold ${badgeClasses[tone]} uppercase tracking-wide">
              ${badgeText}
            </span>
            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">
              ${escapeHtml(target.course_code || target.course_name || 'Academic')}
            </span>
          </div>
          <div class="text-base sm:text-lg font-bold text-gray-900 dark:text-white truncate mt-1">
            ${escapeHtml(target.title)}
          </div>
          <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400 mt-1 flex-wrap">
            <span>Due: <strong class="text-gray-700 dark:text-gray-200">${dueFormatted}</strong></span>
            ${target.remaining_hours !== undefined && target.remaining_hours !== null && Number(target.remaining_hours) > 0 ? `<span>• Workload: <strong>${target.remaining_hours}h left</strong></span>` : ''}
            ${target.recommended_action ? `<span class="hidden lg:inline">• Recommended: <em>${escapeHtml(target.recommended_action)}</em></span>` : ''}
          </div>
        </div>
      </div>
      <div class="flex items-center gap-2 shrink-0 self-end md:self-center">
        <button type="button" class="btn-press px-3 py-2 rounded-xl border border-gray-200 dark:border-white/10 hover:bg-white dark:hover:bg-white/10 text-xs font-semibold view-deadline-detail-btn" data-id="${target.id}">
          Details
        </button>
        <a href="tasks.php?focus_task_id=${target.id}" class="btn-press px-3.5 py-2 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold flex items-center gap-1.5 shadow-sm">
          <i data-lucide="timer" class="w-3.5 h-3.5"></i> Start Studying
        </a>
      </div>
    </div>
  `;
  banner.querySelectorAll('.view-deadline-detail-btn').forEach(btn => {
    btn.addEventListener('click', () => openDeadlineModal(target));
  });
  initLucide();
}

function renderTable(rows) {
  const tbody = document.getElementById('deadline-table-body');
  const footer = document.getElementById('deadline-table-footer');
  if (!tbody) return;

  if (!rows.length) {
    if (!ALL_DEADLINES.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="py-14 px-5 text-center">
            <div class="w-12 h-12 mx-auto rounded-xl bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
              <i data-lucide="check-circle-2" class="w-6 h-6"></i>
            </div>
            <div class="font-bold text-base mt-3 text-gray-900 dark:text-white">No work due soon</div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-sm mx-auto">You're all caught up! Add your next assignment or exam when ready.</div>
            <div class="mt-4">
              <a href="tasks.php?add_task=1" class="btn-press inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold shadow-sm transition">
                <i data-lucide="plus" class="w-4 h-4"></i> Add Work
              </a>
            </div>
          </td>
        </tr>
      `;
    } else {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" class="py-14 px-5 text-center">
            <div class="w-12 h-12 mx-auto rounded-xl bg-gray-100 dark:bg-white/5 flex items-center justify-center text-gray-400 dark:text-gray-500">
              <i data-lucide="calendar-x-2" class="w-6 h-6"></i>
            </div>
            <div class="font-semibold mt-3 text-gray-800 dark:text-gray-200">No work matches your filters</div>
            <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">Try changing your filters or search.</div>
          </td>
        </tr>
      `;
    }

    if (footer) {
      footer.innerHTML = '<span class="text-xs text-gray-400">0 items</span>';
    }

    initLucide();
    return;
  }

  tbody.innerHTML = rows.map((deadline, index) => deadlineRow(deadline, index)).join('');

  tbody.querySelectorAll('.view-deadline-btn, .view-detail-trigger').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const item = ALL_DEADLINES.find(d => String(d.id) === String(id));
      if (item) openDeadlineModal(item);
    });
  });

  if (footer) {
    footer.innerHTML = `
      <span class="text-xs text-gray-400 dark:text-gray-500">
        Showing ${rows.length} of ${ALL_DEADLINES.length} deadline${ALL_DEADLINES.length === 1 ? '' : 's'}
      </span>
      <div class="flex items-center gap-1">
        <button type="button" class="w-7 h-7 rounded-lg border border-gray-200 dark:border-white/10 text-gray-400 flex items-center justify-center" disabled>
          <i data-lucide="chevron-left" class="w-3.5 h-3.5"></i>
        </button>
        <span class="w-7 h-7 rounded-lg bg-emerald-700 text-white text-xs flex items-center justify-center font-semibold">1</span>
        <button type="button" class="w-7 h-7 rounded-lg border border-gray-200 dark:border-white/10 text-gray-400 flex items-center justify-center" disabled>
          <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
        </button>
      </div>
    `;
  }

  tickCountdowns();
  initLucide();
}

function deadlineRow(deadline, index) {
  const urgency = deadline.urgency || 'on_track';
  const dueDate = new Date(deadline.due_at);
  const dueDateText = !Number.isNaN(dueDate.getTime()) ? dueDate.toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  }) : '—';
  const dueTimeText = !Number.isNaN(dueDate.getTime()) ? dueDate.toLocaleTimeString(undefined, {
    hour: 'numeric',
    minute: '2-digit'
  }) : '';

  const courseCode = deadline.course_code ? escapeHtml(deadline.course_code) : 'Academic';
  const courseColor = safeColor(deadline.course_color || '#059669');
  const systemStatus = deadline.system_status || deadline.status || 'pending';
  const systemProgress = Math.round(Number(deadline.system_progress ?? deadline.progress ?? 0));
  const hasDiscrepancy = Boolean(deadline.progress_discrepancy || (deadline.user_status === 'completed' && deadline.system_status !== 'completed'));

  const statusToneClasses = {
    completed: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    in_progress: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
    pending: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    not_started: 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'
  };

  const urgencyIcon = {
    overdue: 'alert-circle',
    due_soon: 'clock',
    upcoming: 'check-circle-2',
    on_track: 'check-circle-2',
    completed: 'check'
  }[urgency] || 'calendar';

  return `
    <tr
      class="deadline-row border-b border-gray-100 dark:border-white/[.06] last:border-0 hover:bg-emerald-50/[.03] dark:hover:bg-white/[.015] transition-colors"
      data-due="${escapeAttribute(deadline.due_at)}"
      data-urgency="${escapeAttribute(urgency)}"
    >
      <td class="py-3.5 px-3 sm:px-4 text-center">
        <span class="w-6 h-6 rounded-md bg-gray-100 dark:bg-white/5 text-[10px] font-bold text-gray-500 dark:text-gray-400 inline-flex items-center justify-center">
          ${index + 1}
        </span>
      </td>

      <td class="py-3.5 px-4 min-w-[240px]">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg ${URGENCY_CLASSES[urgency] || URGENCY_CLASSES.on_track} flex items-center justify-center shrink-0">
            <i data-lucide="${urgencyIcon}" class="w-4 h-4"></i>
          </div>
          <div class="min-w-0 flex-1">
            <div
              class="font-bold text-sm text-gray-900 dark:text-white truncate cursor-pointer hover:text-emerald-700 dark:hover:text-emerald-400 view-detail-trigger"
              data-id="${deadline.id}"
              title="Click to view full details"
            >
              ${escapeHtml(deadline.title)}
            </div>
            <div class="flex items-center gap-2 mt-0.5 text-xs text-gray-500 dark:text-gray-400 flex-wrap">
              <a href="courses.php" class="inline-flex items-center gap-1 font-semibold text-emerald-800 dark:text-emerald-400 hover:underline">
                <span class="w-1.5 h-1.5 rounded-full" style="background:${courseColor}"></span>
                ${courseCode}
              </a>
              ${deadline.type ? `<span class="inline-block px-1.5 py-0.5 rounded bg-gray-100 dark:bg-white/5 text-[10px] text-gray-500 dark:text-gray-400 capitalize">${escapeHtml(deadline.type.replace('_', ' '))}</span>` : ''}
              ${deadline.remaining_hours !== undefined && deadline.remaining_hours !== null && Number(deadline.remaining_hours) > 0 ? `<span class="text-[10px] text-gray-400">${deadline.remaining_hours}h remaining</span>` : ''}
            </div>
          </div>
        </div>
      </td>

      <td class="py-3.5 px-4 whitespace-nowrap w-36">
        <div class="font-semibold text-xs text-gray-800 dark:text-gray-200">${dueDateText}</div>
        <div class="text-[11px] text-gray-400 mt-0.5">${dueTimeText}</div>
      </td>

      <td class="py-3.5 px-4 whitespace-nowrap w-36">
        <span class="status-badge inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold ${URGENCY_CLASSES[urgency] || URGENCY_CLASSES.on_track}">
          <span class="w-1.5 h-1.5 rounded-full ${URGENCY_DOT[urgency] || URGENCY_DOT.on_track}"></span>
          ${URGENCY_LABEL[urgency] || 'On Track'}
        </span>
        <div class="text-[11px] font-bold time-left-cell ${URGENCY_TEXT[urgency] || ''} mt-1">…</div>
      </td>

      <td class="py-3.5 px-4 min-w-[170px]">
        <div class="flex items-center justify-between text-[11px] mb-1">
          <span class="font-semibold capitalize text-gray-700 dark:text-gray-300 ${statusToneClasses[systemStatus] || ''} px-1.5 py-0.2 rounded">${escapeHtml(systemStatus.replace('_', ' '))}</span>
          <span class="font-bold text-gray-600 dark:text-gray-400">${systemProgress}%</span>
        </div>
        <div class="w-full h-1.5 bg-gray-100 dark:bg-white/10 rounded-full overflow-hidden">
          <div class="h-full rounded-full transition-all duration-300" style="width:${systemProgress}%;background:${systemProgress >= 70 ? '#059669' : (systemProgress >= 30 ? '#d97706' : '#dc2626')}"></div>
        </div>
        ${hasDiscrepancy ? `<div class="text-[10px] text-amber-600 dark:text-amber-400 mt-0.5 flex items-center gap-1 truncate" title="Discrepancy between user completion and objective time evidence"><i data-lucide="info" class="w-3 h-3 shrink-0"></i> Discrepancy noted</div>` : ''}
      </td>

      <td class="py-3.5 px-4 text-right whitespace-nowrap min-w-[160px]">
        <div class="flex items-center justify-end gap-1.5">
          <button type="button" class="btn-press p-1.5 rounded-lg border border-gray-200 dark:border-white/10 hover:bg-emerald-50 dark:hover:bg-white/5 text-gray-600 dark:text-gray-300 view-deadline-btn" data-id="${deadline.id}" title="View full details">
            <i data-lucide="eye" class="w-4 h-4"></i>
          </button>
          <a href="tasks.php?task_id=${deadline.id}" class="btn-press p-1.5 rounded-lg border border-gray-200 dark:border-white/10 hover:bg-emerald-50 dark:hover:bg-white/5 text-gray-600 dark:text-gray-300" title="View in My Work">
            <i data-lucide="external-link" class="w-4 h-4"></i>
          </a>
          <a href="tasks.php?focus_task_id=${deadline.id}" class="btn-press p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 hover:bg-emerald-100 dark:hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400" title="Start Studying">
            <i data-lucide="timer" class="w-4 h-4"></i>
          </a>
        </div>
      </td>
    </tr>
  `;
}

function openDeadlineModal(deadline) {
  const modal = document.getElementById('deadline-detail-modal');
  if (!modal || !deadline) return;

  const dueDate = new Date(deadline.due_at);
  const dueFormatted = !Number.isNaN(dueDate.getTime())
    ? dueDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
    : 'No due date';

  document.getElementById('modal-deadline-title').textContent = deadline.title || 'Untitled Task';
  document.getElementById('modal-deadline-course').textContent = (deadline.course_code ? `${deadline.course_code} — ` : '') + (deadline.course_name || 'Academic Task');
  document.getElementById('modal-deadline-due').textContent = `Due: ${dueFormatted}`;

  const openTaskBtn = document.getElementById('modal-open-task-btn');
  if (openTaskBtn) openTaskBtn.href = `tasks.php?task_id=${deadline.id}`;

  const focusTimerBtn = document.getElementById('modal-focus-timer-btn');
  if (focusTimerBtn) focusTimerBtn.href = `tasks.php?focus_task_id=${deadline.id}`;

  const systemStatus = deadline.system_status || deadline.status || 'pending';
  const systemProgress = Math.round(Number(deadline.system_progress ?? deadline.progress ?? 0));
  const userProgress = deadline.user_progress !== undefined ? Math.round(Number(deadline.user_progress)) : null;

  const focusedHours = deadline.total_focused_seconds ? (deadline.total_focused_seconds / 3600).toFixed(1) : '0.0';
  const estHours = deadline.estimated_duration ? (deadline.estimated_duration / 60).toFixed(1) : null;
  const remHours = deadline.remaining_hours !== undefined && deadline.remaining_hours !== null ? Number(deadline.remaining_hours).toFixed(1) : null;

  const bodyEl = document.getElementById('modal-deadline-body');
  bodyEl.innerHTML = `
    ${deadline.description ? `
      <div class="p-3 rounded-xl bg-gray-50 dark:bg-white/[0.03] border border-gray-100 dark:border-white/5">
        <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Description / Notes</div>
        <p class="text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap">${escapeHtml(deadline.description)}</p>
      </div>
    ` : ''}

    <div class="grid grid-cols-2 gap-2.5">
      <div class="p-2.5 rounded-xl bg-gray-50 dark:bg-white/[0.03] border border-gray-100 dark:border-white/5">
        <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">System Task State</div>
        <div class="mt-1 flex items-center gap-2">
          <span class="font-bold capitalize text-xs text-emerald-700 dark:text-emerald-400">${escapeHtml(systemStatus.replace('_', ' '))}</span>
          <span class="text-xs font-semibold text-gray-500">(${systemProgress}%)</span>
        </div>
        ${userProgress !== null && userProgress !== systemProgress ? `
          <div class="text-[10px] text-gray-400 mt-1">User report: ${userProgress}%</div>
        ` : ''}
      </div>

      <div class="p-2.5 rounded-xl bg-gray-50 dark:bg-white/[0.03] border border-gray-100 dark:border-white/5">
        <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wide">Study Time Tracked</div>
        <div class="mt-1 font-bold text-xs text-gray-800 dark:text-gray-200">
          ${focusedHours}h focused
        </div>
        <div class="text-[10px] text-gray-400 mt-0.5">
          ${estHours ? `Est: ${estHours}h • ` : ''}${remHours !== null ? `${remHours}h remaining` : ''}
        </div>
      </div>
    </div>

    ${deadline.smart_priority_label || deadline.priority_reason ? `
      <div class="p-2.5 rounded-xl bg-emerald-50/50 dark:bg-emerald-500/5 border border-emerald-100 dark:border-emerald-500/10">
        <div class="flex items-center justify-between text-[10px] font-bold text-emerald-800 dark:text-emerald-400 uppercase tracking-wide">
          <span>Suggested Study Order</span>
          <span>${Number(deadline.smart_priority_score) >= 70 ? 'Study this first' : 'On track'}</span>
        </div>
        <div class="text-xs font-semibold text-gray-800 dark:text-gray-200 mt-0.5">${escapeHtml(deadline.smart_priority_label || 'Calculated Priority')}${deadline.deadline_pressure ? ` • Pressure: ${escapeHtml(deadline.deadline_pressure)}` : ''}</div>
        ${deadline.priority_reason ? `<div class="text-[11px] text-gray-600 dark:text-gray-400 mt-1">${escapeHtml(deadline.priority_reason)}</div>` : ''}
      </div>
    ` : ''}

    ${deadline.task_risk && deadline.task_risk !== 'low' ? `
      <div class="p-2.5 rounded-xl bg-amber-50/50 dark:bg-amber-500/5 border border-amber-100 dark:border-amber-500/10">
        <div class="flex items-center justify-between text-[10px] font-bold text-amber-800 dark:text-amber-400 uppercase tracking-wide">
          <span>Status & Urgency</span>
          <span>${(deadline.task_risk === 'high' || deadline.task_risk === 'critical') ? 'Needs attention' : 'On track'}</span>
        </div>
        ${deadline.recommended_action ? `
          <div class="text-[11px] text-gray-700 dark:text-gray-300 mt-1 flex items-center gap-1.5">
            <i data-lucide="sparkles" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0"></i>
            <span>${escapeHtml(deadline.recommended_action)}</span>
          </div>
        ` : ''}
      </div>
    ` : ''}

    ${deadline.progress_discrepancy ? `
      <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 text-amber-800 dark:text-amber-300 text-[11px]">
        <div class="font-bold flex items-center gap-1 mb-0.5"><i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Progress Discrepancy</div>
        <div>${escapeHtml(deadline.progress_discrepancy)}</div>
      </div>
    ` : ''}
  `;

  modal.classList.remove('hidden');
  initLucide();
}

function closeDeadlineModal() {
  const modal = document.getElementById('deadline-detail-modal');
  if (modal) modal.classList.add('hidden');
}

// ------------------------------------------------------
// Countdown
// ------------------------------------------------------

function tickCountdowns() {

  const now =
    new Date();

  document
    .querySelectorAll(
      '.time-left-cell'
    )
    .forEach(cell => {

      const row =
        cell.closest('tr');

      if (!row) return;

      const due =
        new Date(
          row.dataset.due
        );

      if (
        Number.isNaN(
          due.getTime()
        )
      ) {
        return;
      }

      const diff =
        due - now;

      const absolute =
        Math.abs(diff);

      const days =
        Math.floor(
          absolute /
            86400000
        );

      const hours =
        Math.floor(
          (
            absolute %
            86400000
          ) /
            3600000
        );

      const minutes =
        Math.floor(
          (
            absolute %
            3600000
          ) /
            60000
        );

      const parts =
        days > 0
          ? `${days}d ${hours}h ${minutes}m`
          : hours > 0
            ? `${hours}h ${minutes}m`
            : `${minutes}m`;

      const urgency =
        row.dataset.urgency;

      cell.classList.remove(
        'countdown-on-track',
        'countdown-due-soon',
        'countdown-overdue'
      );

      if (diff < 0) {

        cell.textContent =
          `Overdue ${parts}`;

        cell.classList.add(
          'countdown-overdue'
        );

      } else {

        cell.textContent =
          parts;

        if (
          urgency === 'due_soon'
        ) {

          cell.classList.add(
            'countdown-due-soon'
          );

        } else {

          cell.classList.add(
            'countdown-on-track'
          );
        }
      }
    });
}

function startCountdownTicker() {

  if (countdownTimer) {
    clearInterval(
      countdownTimer
    );
  }

  countdownTimer =
    setInterval(
      tickCountdowns,
      60000
    );

  tickCountdowns();
}

// ------------------------------------------------------
// Upcoming 7 Days
// ------------------------------------------------------

function renderUpcoming7() {

  const container =
    document.getElementById(
      'deadline-upcoming-7'
    );

  if (!container) {
    return;
  }

  const now =
    Date.now();

  const sevenDays =
    now +
    7 * 86400000;

  const rows =
    ALL_DEADLINES
      .filter(deadline => {

        const due =
          new Date(
            deadline.due_at
          ).getTime();

        return (
          due <= sevenDays ||
          deadline.urgency ===
            'overdue'
        );
      })
      .sort(
        (a, b) =>
          new Date(a.due_at) -
          new Date(b.due_at)
      )
      .slice(0, 5);

  if (!rows.length) {

    container.innerHTML = `

      <div class="px-5 py-8 text-center">

        <div
          class="w-10 h-10 mx-auto rounded-xl bg-gray-100 dark:bg-white/5 flex items-center justify-center text-gray-400"
        >
          <i
            data-lucide="calendar-check-2"
            class="w-5 h-5"
          ></i>
        </div>

        <div class="text-xs font-semibold mt-2">
          Nothing due soon
        </div>

        <div class="text-[10px] text-gray-400 mt-1">
          No deadlines in the next 7 days.
        </div>

      </div>
    `;

    initLucide();

    return;
  }

  container.innerHTML =
    rows
      .map(
        deadline => {

          const due =
            new Date(
              deadline.due_at
            );

          const dueLabel =
            due.toLocaleDateString(
              undefined,
              {
                month: 'short',
                day: 'numeric'
              }
            );

          return `

            <div
              class="px-5 py-3.5 flex items-center gap-3"
            >

              <span
                class="w-9 h-9 rounded-lg ${
                  deadline.urgency === 'overdue'
                    ? 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300'
                    : deadline.urgency === 'due_soon'
                      ? 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300'
                      : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                } flex items-center justify-center shrink-0"
              >

                <i
                  data-lucide="file-text"
                  class="w-4 h-4"
                ></i>

              </span>

              <div class="min-w-0 flex-1">

                <div class="font-semibold text-xs truncate">
                  ${escapeHtml(
                    deadline.title
                  )}
                </div>

                <div class="text-[10px] text-gray-400 mt-0.5 truncate">
                  ${escapeHtml(
                    deadline.course_code || ''
                  )}
                  ${deadline.remaining_hours !== undefined && deadline.remaining_hours !== null && Number(deadline.remaining_hours) > 0 ? ` • ${deadline.remaining_hours}h left` : ''}
                </div>

                ${deadline.recommended_action ? `
                  <div class="text-[10px] text-emerald-700 dark:text-emerald-400 font-medium truncate mt-0.5 flex items-center gap-1" title="${escapeAttribute(deadline.recommended_action)}">
                    <i data-lucide="sparkles" class="w-2.5 h-2.5 shrink-0 text-emerald-600"></i> ${escapeHtml(deadline.recommended_action)}
                  </div>
                ` : ''}

              </div>

              <div class="text-right shrink-0">

                <div
                  class="text-[10px] font-bold ${
                    deadline.urgency === 'overdue'
                      ? 'text-red-600 dark:text-red-400'
                      : deadline.urgency === 'due_soon'
                        ? 'text-amber-600 dark:text-amber-400'
                        : 'text-emerald-600 dark:text-emerald-400'
                  }"
                >
                  ${
                    deadline.urgency ===
                    'overdue'
                      ? 'Overdue'
                      : `Due ${dueLabel}`
                  }
                </div>

                <div class="text-[10px] text-gray-400 mt-1">
                  ${deadline.due_label || ''}
                </div>

              </div>

            </div>
          `;
        }
      )
      .join('');

  initLucide();
}

// ------------------------------------------------------
// Calendar
// ------------------------------------------------------

function renderCalendar() {

  const title =
    document.getElementById(
      'cal-title'
    );

  const grid =
    document.getElementById(
      'cal-grid'
    );

  if (!grid) {
    return;
  }

  const monthNames = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December'
  ];

  if (title) {

    title.textContent =
      `${monthNames[calMonth]} ${calYear}`;
  }

  const firstDay =
    new Date(
      calYear,
      calMonth,
      1
    );

  const firstDow =
    firstDay.getDay();

  const daysInMonth =
    new Date(
      calYear,
      calMonth + 1,
      0
    ).getDate();

  const previousMonthDays =
    new Date(
      calYear,
      calMonth,
      0
    ).getDate();

  const today =
    new Date();

  const isCurrentMonth =
    today.getMonth() ===
      calMonth &&
    today.getFullYear() ===
      calYear;

  // Map deadlines to calendar day
  const byDay = {};

  ALL_DEADLINES.forEach(
    deadline => {

      const due =
        new Date(
          deadline.due_at
        );

      if (
        due.getMonth() ===
          calMonth &&
        due.getFullYear() ===
          calYear
      ) {

        const day =
          due.getDate();

        const rank = {
          overdue: 3,
          due_soon: 2,
          on_track: 1
        };

        if (
          !byDay[day] ||
          (
            rank[deadline.urgency] ||
            0
          ) >
            (
              rank[
                byDay[day]
              ] || 0
            )
        ) {

          byDay[day] =
            deadline.urgency;
        }
      }
    }
  );

  let html = '';

  // Previous month trailing dates
  for (
    let i = firstDow - 1;
    i >= 0;
    i--
  ) {

    const day =
      previousMonthDays -
      i;

    html += `
      <div
        class="deadline-calendar-day flex flex-col items-center justify-center rounded-lg text-[11px] text-gray-300 dark:text-gray-700 min-h-[38px]"
      >
        ${day}
      </div>
    `;
  }

  // Current month
  for (
    let day = 1;
    day <= daysInMonth;
    day++
  ) {

    const date =
      new Date(
        calYear,
        calMonth,
        day
      );

    const urgency =
      byDay[day];

    const isToday =
      isCurrentMonth &&
      day ===
        today.getDate();

    const isSelected =
      date.getDate() ===
        today.getDate() &&
      date.getMonth() ===
        today.getMonth() &&
      date.getFullYear() ===
        today.getFullYear();

    const textClass =
      isToday
        ? 'is-today'
        : isSelected
          ? 'bg-emerald-50 dark:bg-emerald-500/10'
          : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5';

    const dotClass =
      urgency === 'overdue'
        ? 'bg-red-500'
        : urgency === 'due_soon'
          ? 'bg-amber-500'
          : 'bg-emerald-500';

    html += `

      <button
        type="button"
        class="deadline-calendar-day flex flex-col items-center justify-center rounded-lg min-h-[38px] ${textClass}"
        data-calendar-date="${calYear}-${String(
          calMonth + 1
        ).padStart(
          2,
          '0'
        )}-${String(day).padStart(
          2,
          '0'
        )}"
      >

        <span
          class="text-[11px] font-semibold"
        >
          ${day}
        </span>

        ${
          urgency
            ? `
              <span
                class="calendar-event-dot mt-1 ${dotClass}"
              ></span>
            `
            : ''
        }

      </button>
    `;
  }

  // Next month padding
  const totalCells =
    firstDow +
    daysInMonth;

  const remaining =
    totalCells <= 35
      ? 35 - totalCells
      : 42 - totalCells;

  for (
    let i = 1;
    i <= remaining;
    i++
  ) {

    html += `
      <div
        class="deadline-calendar-day flex items-center justify-center rounded-lg text-[11px] text-gray-300 dark:text-gray-700 min-h-[38px]"
      >
        ${i}
      </div>
    `;
  }

  grid.innerHTML =
    html;

  // Calendar date interaction
  grid
    .querySelectorAll(
      '[data-calendar-date]'
    )
    .forEach(button => {

      button.addEventListener(
        'click',
        () => {

          const [
            year,
            month,
            day
          ] =
            button.dataset
              .calendarDate
              .split('-')
              .map(Number);

          const selected =
            new Date(
              year,
              month - 1,
              day
            );

          // Switch the main list to that date
          setSearchValue('');

          const statusFilter =
            document.getElementById(
              'deadline-status-filter'
            );

          if (statusFilter) {
            statusFilter.value =
              '';
          }

          deadlineView =
            'all';

          document
            .querySelectorAll(
              '.deadline-filter-btn'
            )
            .forEach(
              filterButton => {

                filterButton.classList.toggle(
                  'active',
                  filterButton.dataset
                    .deadlineView ===
                    'all'
                );
              }
            );

          renderTable(
            ALL_DEADLINES
              .filter(deadline =>
                sameCalendarDay(
                  new Date(
                    deadline.due_at
                  ),
                  selected
                )
              )
              .sort(
                (a, b) =>
                  new Date(
                    a.due_at
                  ) -
                  new Date(
                    b.due_at
                  )
              )
          );

          const subtitle =
            document.getElementById(
              'deadline-table-subtitle'
            );

          if (subtitle) {

            subtitle.textContent =
              `Deadlines for ${selected.toLocaleDateString(
                undefined,
                {
                  month: 'long',
                  day: 'numeric',
                  year: 'numeric'
                }
              )}.`;
          }

          updateFilterUI();
        }
      );
    });

  initLucide();
}

// ------------------------------------------------------
// Calendar Navigation
// ------------------------------------------------------

document
  .getElementById(
    'cal-prev'
  )
  ?.addEventListener(
    'click',
    () => {

      calMonth--;

      if (
        calMonth < 0
      ) {

        calMonth = 11;
        calYear--;
      }

      renderCalendar();
    }
  );

document
  .getElementById(
    'cal-next'
  )
  ?.addEventListener(
    'click',
    () => {

      calMonth++;

      if (
        calMonth > 11
      ) {

        calMonth = 0;
        calYear++;
      }

      renderCalendar();
    }
  );

// ------------------------------------------------------
// View Buttons
// ------------------------------------------------------

document
  .querySelectorAll(
    '.deadline-filter-btn'
  )
  .forEach(button => {

    button.addEventListener(
      'click',
      () => {

        deadlineView =
          button.dataset
            .deadlineView ||
          'all';

        applyTableFilters();
      }
    );
  });

// ------------------------------------------------------
// Search
// ------------------------------------------------------

document
  .getElementById(
    'deadline-search'
  )
  ?.addEventListener(
    'input',
    event => {

      setSearchValue(
        event.target.value
      );

      applyTableFilters();
    }
  );

document
  .getElementById(
    'deadline-search-mobile'
  )
  ?.addEventListener(
    'input',
    event => {

      setSearchValue(
        event.target.value
      );

      applyTableFilters();
    }
  );

// ------------------------------------------------------
// Status Filter
// ------------------------------------------------------

document
  .getElementById(
    'deadline-status-filter'
  )
  ?.addEventListener(
    'change',
    () => {

      applyTableFilters();
    }
  );

// ------------------------------------------------------
// Clear Filters
// ------------------------------------------------------

document
  .getElementById(
    'deadline-clear-filter'
  )
  ?.addEventListener(
    'click',
    () => {

      deadlineView =
        'all';

      setSearchValue('');

      const filter =
        document.getElementById(
          'deadline-status-filter'
        );

      if (filter) {
        filter.value = '';
      }

      applyTableFilters();
    }
  );

// ------------------------------------------------------
// Modal Wire-up
// ------------------------------------------------------

document.getElementById('close-deadline-modal')?.addEventListener('click', closeDeadlineModal);
const detailModal = document.getElementById('deadline-detail-modal');
if (detailModal) {
  detailModal.addEventListener('click', e => {
    if (e.target === detailModal) closeDeadlineModal();
  });
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeDeadlineModal();
});

// ------------------------------------------------------
// Initialisation
// ------------------------------------------------------

window.APP_READY.then(
  me => {

    if (!me) {
      return;
    }

    const params = new URLSearchParams(window.location.search);
    const filterParam = params.get('filter');
    if (filterParam && ['all', 'overdue', 'today', 'due_soon', 'upcoming', 'completed'].includes(filterParam)) {
      deadlineView = filterParam;
      document.querySelectorAll('.deadline-filter-btn').forEach(btn => {
        btn.classList.toggle('active', (btn.dataset.deadlineView || btn.dataset.view) === filterParam);
      });
      const filterSelect = document.getElementById('deadline-status-filter');
      if (filterSelect) {
        filterSelect.value = filterParam === 'all' ? '' : filterParam;
      }
    }

    const searchParam = params.get('q') || params.get('search');
    if (searchParam && typeof setSearchValue === 'function') {
      setSearchValue(searchParam);
    }

    initLucide();

    loadDeadlines();
  }
);