
let ALL_TASKS = [];
let ACTIVE_TAB = 'all';
let COURSES_CACHE = [];
let CURRENT_PAGE = 1;
const PAGE_SIZE = 7;
let summaryChart = null;
let searchDebounce = null;
let requestSerial = 0;
let taskLiveTimer = null;
let taskLiveLastBucketSignature = '';

const TYPE_LABELS = {
  assignment: 'Assignment',
  project: 'Project',
  test: 'Test',
  exam: 'Exam',
  research: 'Research',
  lab_report: 'Lab Report',
  study_session: 'Study Session',
  other: 'Other'
};

const STATUS_LABELS = {
  not_started: 'Not Started',
  pending: 'Pending',
  in_progress: 'In Progress',
  completed: 'Completed'
};

const STATUS_CLASSES = {
  not_started:
    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',

  pending:
    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',

  in_progress:
    'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400',

  completed:
    'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-400'
};

const PRIORITY_DOT = {
  high: 'bg-red-500',
  medium: 'bg-amber-500',
  low: 'bg-green-500'
};


/* =========================================================
   HELPERS
========================================================= */

const esc = value =>
  typeof window.escapeHtml === 'function'
    ? window.escapeHtml(String(value ?? ''))
    : String(value ?? '').replace(/[&<>'"]/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#039;',
        '"': '&quot;'
      }[c]));

function toast(message, type = 'info') {
  if (window.showToast) {
    window.showToast(message, type);
  } else if (type === 'error') {
    alert(message);
  }
}

function getEl(id) {
  return document.getElementById(id);
}

function localDate(value) {
  if (!value) return null;

  const d = new Date(
    String(value).replace(' ', 'T')
  );

  return Number.isNaN(d.getTime()) ? null : d;
}

function datetimeLocalValue(value) {
  const d = localDate(value);

  if (!d) return '';

  const pad = n => String(n).padStart(2, '0');

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(
    d.getDate()
  )}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function sqlDateTime(value) {
  if (!value) return '';

  const raw = String(value)
    .trim()
    .replace('T', ' ');

  return raw.length === 16
    ? `${raw}:00`
    : raw;
}

function setButtonBusy(button, busy, busyText = 'Saving…') {
  if (!button) return;

  button.disabled = busy;

  button.classList.toggle(
    'opacity-60',
    busy
  );

  button.classList.toggle(
    'cursor-not-allowed',
    busy
  );

  const span = button.querySelector('span');

  if (
    !button.dataset.originalText &&
    span
  ) {
    button.dataset.originalText =
      span.textContent;
  }

  if (span) {
    span.textContent = busy
      ? busyText
      : (
          button.dataset.originalText ||
          'Save Task'
        );
  }
}


/* =========================================================
   LIVE TASK INTELLIGENCE

   This layer is display-only and deliberately reuses the
   existing task/API data. It does not change the page layout,
   database schema, or existing CRUD behavior.
========================================================= */

function clampNumber(value, min, max, fallback = 0) {
  const n = Number(value);
  if (!Number.isFinite(n)) return fallback;
  return Math.min(max, Math.max(min, n));
}

function getLiveTaskState(task, nowMs = Date.now()) {
  const due = localDate(task?.due_at);
  const progress = clampNumber(task?.progress_percent, 0, 100, 0);
  const duration = Math.max(0, Number(task?.duration_hours) || 0);
  const backendRemaining = Number(task?.remaining_hours);

  const progressRemaining = duration > 0
    ? Math.max(0, duration * (1 - progress / 100))
    : 0;

  const remainingHours = Number.isFinite(backendRemaining)
    ? Math.max(
        0,
        Math.min(
          backendRemaining,
          progressRemaining || backendRemaining
        )
      )
    : progressRemaining;

  const completed =
    String(task?.status || '') === 'completed' ||
    progress >= 100;

  const timeRemainingMs =
    due
      ? due.getTime() - nowMs
      : null;

  const timeRemainingHours =
    timeRemainingMs === null
      ? null
      : timeRemainingMs / 3600000;

  let urgency = 'no_deadline';

  if (completed) {
    urgency = 'completed';
  } else if (due && timeRemainingMs <= 0) {
    urgency = 'overdue';
  } else if (due && timeRemainingHours <= 1) {
    urgency = 'critical';
  } else if (due && timeRemainingHours <= 4) {
    urgency = 'urgent';
  } else if (due && timeRemainingHours <= 24) {
    urgency = 'due_soon';
  } else if (due) {
    urgency = 'upcoming';
  }

  let pressureScore = 0;

  if (!completed && due) {

    if (timeRemainingHours <= 0) {

      pressureScore = 100;

    } else if (remainingHours <= 0) {

      pressureScore = 0;

    } else {

      const requiredFraction =
        remainingHours /
        Math.max(
          timeRemainingHours,
          0.25
        );

      pressureScore =
        Math.min(
          100,
          Math.round(
            requiredFraction * 70 +
            (
              timeRemainingHours <= 24
                ? 20
                : 0
            )
          )
        );

    }
  }

  const backendRisk =
    Number(
      task?.task_risk_score
    );

  const riskScore =
    Number.isFinite(backendRisk) &&
    backendRisk > 0
      ? Math.max(
          pressureScore,
          Math.min(
            100,
            backendRisk
          )
        )
      : pressureScore;

  return {
    due,
    completed,
    progress,
    durationHours: duration,
    remainingHours,
    timeRemainingMs,
    timeRemainingHours,
    urgency,
    riskScore
  };
}

function formatLiveCountdown(state) {

  if (state.completed) {
    return 'Completed';
  }

  if (!state.due) {
    return 'No deadline';
  }

  const ms =
    state.timeRemainingMs;

  const absMs =
    Math.abs(ms);

  const totalMinutes =
    Math.floor(
      absMs / 60000
    );

  const days =
    Math.floor(
      totalMinutes / 1440
    );

  const hours =
    Math.floor(
      (totalMinutes % 1440) / 60
    );

  const minutes =
    totalMinutes % 60;

  const seconds =
    Math.floor(
      (absMs % 60000) / 1000
    );

  const unit = [];

  if (days) {
    unit.push(
      `${days}d`
    );
  }

  if (hours || days) {
    unit.push(
      `${hours}h`
    );
  }

  if (!days && minutes) {
    unit.push(
      `${minutes}m`
    );
  }

  if (
    !days &&
    hours === 0 &&
    minutes === 0
  ) {
    unit.push(
      `${seconds}s`
    );
  } else if (
    !days &&
    hours === 0 &&
    minutes < 60
  ) {
    unit.push(
      `${seconds}s`
    );
  }

  if (!unit.length) {
    unit.push('0s');
  }

  return ms < 0
    ? `Overdue by ${unit.join(' ')}`
    : `${unit.join(' ')} remaining`;
}

function getLiveUrgencyText(state) {

  if (state.completed) {
    return 'Completed';
  }

  if (!state.due) {
    return 'No deadline';
  }

  if (
    state.urgency ===
    'overdue'
  ) {
    return formatLiveCountdown(
      state
    );
  }

  return formatLiveCountdown(
    state
  );
}

function refreshLiveTaskSummary(
  nowMs = Date.now()
) {

  if (!Array.isArray(ALL_TASKS)) {
    return;
  }

  const summary = {
    total: ALL_TASKS.length,
    due_soon: 0,
    overdue: 0,
    in_progress: 0,
    completed: 0,
    pending: 0,
    remaining_hours: 0,
    at_risk: 0
  };

  ALL_TASKS.forEach(
    task => {

      const state =
        getLiveTaskState(
          task,
          nowMs
        );

      if (
        state.urgency === 'due_soon' ||
        state.urgency === 'urgent' ||
        state.urgency === 'critical'
      ) {
        summary.due_soon++;
      }

      if (
        state.urgency ===
        'overdue'
      ) {
        summary.overdue++;
      }

      if (
        String(task.status) ===
        'in_progress'
      ) {
        summary.in_progress++;
      }

      if (
        String(task.status) ===
          'completed' ||
        state.completed
      ) {
        summary.completed++;
      }

      if (
        String(task.status) !==
          'completed' &&
        [
          'pending',
          'not_started'
        ].includes(
          String(task.status)
        )
      ) {
        summary.pending++;
      }

      summary.remaining_hours +=
        state.remainingHours;

      if (
        !state.completed &&
        state.riskScore >= 60
      ) {
        summary.at_risk++;
      }

    }
  );

  summary.remaining_hours =
    Number(
      summary.remaining_hours.toFixed(2)
    );

  renderStatCards(
    summary
  );

  renderTabCounts(
    summary
  );
}

function updateLiveTaskDisplays({
  rerenderUpcoming = false
} = {}) {

  if (
    !Array.isArray(ALL_TASKS) ||
    !ALL_TASKS.length
  ) {
    return;
  }

  const now =
    Date.now();

  const states =
    new Map();

  const signatureParts = [];

  ALL_TASKS.forEach(
    task => {

      const state =
        getLiveTaskState(
          task,
          now
        );

      states.set(
        String(task.id),
        state
      );

      signatureParts.push(
        `${task.id}:${state.urgency}`
      );

      task.live_time_remaining_hours =
        state.timeRemainingHours;

      task.live_remaining_hours =
        state.remainingHours;

      task.live_urgency =
        state.urgency;

      task.live_risk_score =
        state.riskScore;
    }
  );

  document
    .querySelectorAll(
      '[data-task-deadline-id]'
    )
    .forEach(
      cell => {

        const id =
          String(
            cell.dataset.taskDeadlineId
          );

        const state =
          states.get(id);

        if (!state) {
          return;
        }

        const countdown =
          cell.querySelector(
            '[data-task-countdown]'
          );

        if (countdown) {

          countdown.textContent =
            getLiveUrgencyText(
              state
            );

          countdown.title =
            state.due
              ? `Remaining work: ${state.remainingHours.toFixed(1)}h`
              : '';
        }

        cell.classList.toggle(
          'text-red-600',
          state.urgency === 'overdue' ||
            state.urgency === 'critical'
        );

        cell.classList.toggle(
          'dark:text-red-400',
          state.urgency === 'overdue' ||
            state.urgency === 'critical'
        );

        cell.classList.toggle(
          'text-amber-600',
          state.urgency === 'due_soon' ||
            state.urgency === 'urgent'
        );

        cell.classList.toggle(
          'dark:text-amber-400',
          state.urgency === 'due_soon' ||
            state.urgency === 'urgent'
        );

      }
    );

  document
    .querySelectorAll(
      '[data-upcoming-task-id]'
    )
    .forEach(
      item => {

        const id =
          String(
            item.dataset.upcomingTaskId
          );

        const state =
          states.get(id);

        if (!state) {
          return;
        }

        const countdown =
          item.querySelector(
            '[data-upcoming-countdown]'
          );

        if (countdown) {
          countdown.textContent =
            getLiveUrgencyText(
              state
            );
        }

        const icon =
          item.querySelector(
            '.task-upcoming-icon'
          );

        if (icon) {

          icon.classList.toggle(
            'overdue',
            state.urgency ===
              'overdue'
          );

        }

      }
    );

  const nextSignature =
    signatureParts.join('|');

  const bucketChanged =
    taskLiveLastBucketSignature &&
    nextSignature !==
      taskLiveLastBucketSignature;

  if (
    rerenderUpcoming ||
    bucketChanged
  ) {
    renderUpcoming();
  }

  if (bucketChanged) {
    refreshLiveTaskSummary(
      now
    );
  }

  taskLiveLastBucketSignature =
    nextSignature;
}

function startTaskLiveClock() {

  if (taskLiveTimer) {
    clearInterval(
      taskLiveTimer
    );
  }

  updateLiveTaskDisplays();

  taskLiveTimer =
    window.setInterval(
      () => {
        updateLiveTaskDisplays();
      },
      1000
    );
}


/* =========================================================
   API
========================================================= */

async function apiJson(
  url,
  options = {}
) {

  const response =
    await fetch(
      url,
      {
        credentials:
          'same-origin',

        ...options,

        headers: {
          Accept:
            'application/json',

          ...(options.headers || {})
        }
      }
    );

  const data =
    await response
      .json()
      .catch(
        () => ({})
      );

  if (!response.ok) {
    throw new Error(
      data.error ||
      `Request failed (${response.status})`
    );
  }

  return data;
}


/* =========================================================
   COURSES
========================================================= */

async function loadCourseOptions() {

  try {

    const data =
      await apiJson(
        `${API}/courses.php`
      );

    COURSES_CACHE =
      Array.isArray(
        data.courses
      )
        ? data.courses
        : [];

    const opts =
      COURSES_CACHE
        .map(
          course => `
          <option value="${esc(course.id)}">
            ${esc(course.code)} — ${esc(course.name)}
          </option>
        `
        )
        .join('');

    const taskCourse =
      getEl(
        'task-course-select'
      );

    const mainCourse =
      getEl(
        'filter-course'
      );

    const sideCourse =
      getEl(
        'filter-course-side'
      );

    if (taskCourse) {
      taskCourse.insertAdjacentHTML(
        'beforeend',
        opts
      );
    }

    if (mainCourse) {
      mainCourse.insertAdjacentHTML(
        'beforeend',
        opts
      );
    }

    if (sideCourse) {
      sideCourse.insertAdjacentHTML(
        'beforeend',
        opts
      );
    }

  } catch (error) {

    console.error(
      'Course options failed:',
      error
    );

    toast(
      'Courses could not be loaded. You can still add tasks without a course.',
      'error'
    );

  }
}


/* =========================================================
   QUERY / FILTERS
========================================================= */

function buildQuery() {

  const params =
    new URLSearchParams();

  if (
    ACTIVE_TAB !== 'all' &&
    ACTIVE_TAB !== 'overdue'
  ) {
    params.set(
      'status',
      ACTIVE_TAB
    );
  }

  if (
    ACTIVE_TAB ===
    'overdue'
  ) {
    params.set(
      'status',
      'overdue'
    );
  }

  const course =
    getEl('filter-course')
      ?.value ||
    '';

  const type =
    getEl('filter-type')
      ?.value ||
    '';

  const priority =
    getEl('filter-priority')
      ?.value ||
    '';

  const q =
    getEl('task-search')
      ?.value
      .trim() ||
    '';

  if (course) {
    params.set(
      'course_id',
      course
    );
  }

  if (type) {
    params.set(
      'type',
      type
    );
  }

  if (priority) {
    params.set(
      'priority',
      priority
    );
  }

  if (q) {
    params.set(
      'q',
      q
    );
  }

  return params.toString();
}


/* =========================================================
   LOADING
========================================================= */

function showTaskLoading() {

  const tbody =
    getEl(
      'task-table-body'
    );

  if (!tbody) {
    return;
  }

  tbody.innerHTML = `
    <tr>
      <td colspan="9" class="py-16 text-center">
        <div class="flex flex-col items-center gap-3 text-[#78918B] dark:text-gray-400">

          <div
            class="w-8 h-8 rounded-full border-2
                   border-emerald-200
                   border-t-emerald-700
                   animate-spin">
          </div>

          <span class="text-xs">
            Loading tasks…
          </span>

        </div>
      </td>
    </tr>
  `;
}


/* =========================================================
   LOAD TASKS
========================================================= */

async function loadTasks() {

  const serial =
    ++requestSerial;

  showTaskLoading();

  try {

    const query =
      buildQuery();

    const data =
      await apiJson(
        `${API}/tasks.php${
          query
            ? `?${query}`
            : ''
        }`
      );

    if (
      serial !==
      requestSerial
    ) {
      return;
    }

    ALL_TASKS =
      Array.isArray(
        data.tasks
      )
        ? data.tasks
        : [];

    CURRENT_PAGE = 1;

    renderStatCards(
      data.summary || {}
    );

    renderTabCounts(
      data.summary || {}
    );

    renderTable(
      ALL_TASKS
    );

    renderSummaryDonut(
      data.summary || {}
    );

    renderUpcoming();

    startTaskLiveClock();

    updateLiveTaskDisplays({
      rerenderUpcoming:
        true
    });

  } catch (error) {

    if (
      serial !==
      requestSerial
    ) {
      return;
    }

    console.error(
      'Tasks load failed:',
      error
    );

    const tbody =
      getEl(
        'task-table-body'
      );

    if (tbody) {

      tbody.innerHTML = `
        <tr>
          <td colspan="9" class="py-16 text-center">

            <div class="task-empty-state">

              <i
                data-lucide="triangle-alert"
                class="w-7 h-7 text-red-500">
              </i>

              <strong>
                Unable to load tasks
              </strong>

              <span>
                ${esc(error.message)}
              </span>

              <button
                type="button"
                id="retry-tasks"
                class="mt-2 text-emerald-700
                       dark:text-emerald-400
                       font-semibold">

                Try again

              </button>

            </div>

          </td>
        </tr>
      `;

      if (window.lucide) {
        window.lucide.createIcons();
      }

      getEl(
        'retry-tasks'
      )?.addEventListener(
        'click',
        loadTasks
      );
    }

    if (
      getEl(
        'task-results-label'
      )
    ) {
      getEl(
        'task-results-label'
      ).textContent =
        'Unable to load tasks';
    }

    if (
      getEl(
        'task-pagination'
      )
    ) {
      getEl(
        'task-pagination'
      ).innerHTML = '';
    }
  }
}


/* =========================================================
   STAT CARDS
========================================================= */

function renderStatCards(s) {

  const cards = [
    [
      'square-check-big',
      'emerald',
      Number(s.total || 0),
      'Total Tasks',
      'All work currently tracked'
    ],

    [
      'hourglass',
      'amber',
      Number(s.due_soon || 0),
      'Due Soon',
      'Needs attention shortly'
    ],

    [
      'circle-alert',
      'red',
      Number(s.overdue || 0),
      'Overdue',
      'Past the deadline'
    ],

    [
      'loader-circle',
      'blue',
      Number(s.in_progress || 0),
      'In Progress',
      'Currently being worked on'
    ],

    [
      'circle-check-big',
      'green',
      Number(s.completed || 0),
      'Completed',
      'Successfully finished'
    ]
  ];

  const tones = {

    emerald:
      'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',

    amber:
      'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',

    red:
      'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',

    blue:
      'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400',

    green:
      'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-400'
  };

  const container =
    getEl(
      'task-stat-cards'
    );

  if (!container) {
    return;
  }

  container.innerHTML =
    cards
      .map(
        ([
          icon,
          tone,
          value,
          label,
          hint
        ]) => `
          <div class="tasks-stat-card">

            <div
              class="tasks-stat-icon ${tones[tone]}">

              <i
                data-lucide="${icon}"
                class="w-5 h-5">
              </i>

            </div>

            <div class="min-w-0">

              <div
                class="text-2xl sm:text-3xl
                       font-bold leading-none
                       tracking-tight"
                data-stat-value="${value}">

                0

              </div>

              <div
                class="text-xs font-semibold
                       text-[#496861]
                       dark:text-gray-300 mt-1">

                ${label}

              </div>

              <div
                class="text-[10px]
                       text-[#8AA09B]
                       dark:text-gray-500
                       mt-1 truncate">

                ${hint}

              </div>

            </div>

          </div>
        `
      )
      .join('');

  if (window.lucide) {
    window.lucide.createIcons();
  }

  container
    .querySelectorAll(
      '[data-stat-value]'
    )
    .forEach(
      el => {

        if (
          window.animateCounter
        ) {

          window.animateCounter(
            el,
            Number(
              el.dataset.statValue
            )
          );

        } else {

          el.textContent =
            el.dataset.statValue;

        }

      }
    );
}


/* =========================================================
   TAB COUNTS
========================================================= */

function renderTabCounts(s) {

  const total =
    Number(
      s.total || 0
    );

  const completed =
    Number(
      s.completed || 0
    );

  const inProgress =
    Number(
      s.in_progress || 0
    );

  const overdue =
    Number(
      s.overdue || 0
    );

  const counts = {

    all:
      total,

    pending:
      Math.max(
        0,
        total -
        completed -
        inProgress -
        overdue
      ),

    in_progress:
      inProgress,

    completed:
      completed,

    overdue:
      overdue

  };

  Object.entries(
    counts
  ).forEach(
    ([key, value]) => {

      const el =
        document.querySelector(
          `[data-tab-count="${key}"]`
        );

      if (!el) {
        return;
      }

      el.textContent =
        `(${value})`;

      el.className =
        'ml-1 opacity-75';
    }
  );
}


/* =========================================================
   ACTIVE TAB
========================================================= */

function setActiveTabUI() {

  document
    .querySelectorAll(
      '.task-tab-btn'
    )
    .forEach(
      btn => {

        const active =
          btn.dataset.tab ===
          ACTIVE_TAB;

        btn.classList.toggle(
          'active',
          active
        );

        btn.setAttribute(
          'aria-selected',
          active
            ? 'true'
            : 'false'
        );

      }
    );
}


/* =========================================================
   PAGINATION DATA
========================================================= */

function filteredForPage(
  tasks
) {

  const totalPages =
    Math.max(
      1,
      Math.ceil(
        tasks.length /
        PAGE_SIZE
      )
    );

  CURRENT_PAGE =
    Math.min(
      Math.max(
        1,
        CURRENT_PAGE
      ),
      totalPages
    );

  const start =
    (CURRENT_PAGE - 1) *
    PAGE_SIZE;

  return tasks.slice(
    start,
    start + PAGE_SIZE
  );
}


/* =========================================================
   TABLE
========================================================= */

function renderTable(tasks) {

  const tbody =
    getEl(
      'task-table-body'
    );

  if (!tbody) {
    return;
  }

  const visible =
    filteredForPage(
      tasks
    );

  const selectAll =
    getEl(
      'select-all-tasks'
    );

  if (selectAll) {

    selectAll.checked =
      false;

    selectAll.indeterminate =
      false;
  }

  if (!tasks.length) {

    tbody.innerHTML = `
      <tr>
        <td
          colspan="9"
          class="py-16 text-center">

          <div class="task-empty-state">

            <i
              data-lucide="clipboard-list"
              class="w-8 h-8">
            </i>

            <strong>
              No tasks found
            </strong>

            <span>
              No tasks match your current filters.
            </span>

            <button
              type="button"
              id="clear-task-filters"
              class="mt-2 text-emerald-700
                     dark:text-emerald-400
                     font-semibold">

              Clear filters

            </button>

          </div>

        </td>
      </tr>
    `;

    getEl(
      'clear-task-filters'
    )?.addEventListener(
      'click',
      resetFilters
    );

    renderPagination(
      0
    );

    if (window.lucide) {
      window.lucide.createIcons();
    }

    return;
  }

  tbody.innerHTML =
    visible
      .map(
        task => {

          const progress =
            Math.min(
              100,
              Math.max(
                0,
                Number(
                  task.progress_percent
                ) || 0
              )
            );

          const d =
            localDate(
              task.due_at
            );

          const dateText =
            d
              ? d.toLocaleDateString(
                  undefined,
                  {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                  }
                )
              : '—';

          const timeText =
            d
              ? d.toLocaleTimeString(
                  undefined,
                  {
                    hour: 'numeric',
                    minute: '2-digit'
                  }
                )
              : '—';

          const priority =
            capitalizeSafe(
              task.priority
            );

          const status =
            STATUS_LABELS[
              task.status
            ] ||
            capitalizeSafe(
              task.status
            );

          const type =
            TYPE_LABELS[
              task.type
            ] ||
            task.type ||
            'Other';

          const accent =
            task.urgency ===
              'overdue'
              ? 'bg-red-500'
              : task.priority ===
                'high'
                ? 'bg-emerald-600'
                : 'bg-blue-500';

          const deadlineClass =
            task.urgency ===
              'overdue'
              ? 'text-red-600 dark:text-red-400'
              : task.urgency ===
                  'due_soon'
                ? 'text-amber-600 dark:text-amber-400'
                : 'text-[#8AA09B] dark:text-gray-500';

          return `
          <tr
            class="task-row align-top"
            data-task-id="${esc(task.id)}">

            <!-- Checkbox -->
            <td
              class="py-3.5 px-3 text-center">

              <input
                type="checkbox"
                class="task-checkbox row-task-checkbox"
                data-id="${esc(task.id)}"
                aria-label="Select ${esc(task.title)}">

            </td>


            <!-- Task -->
            <td class="py-3.5 px-3">

              <div
                class="flex items-start gap-2.5">

                <span
                  class="task-row-accent ${accent}">
                </span>

                <div
                  class="min-w-0">

                  <div
                    class="font-semibold
                           text-[#183E36]
                           dark:text-gray-100
                           truncate
                           max-w-[230px]">

                    ${esc(task.title)}

                  </div>

                  ${
                    task.description
                      ? `
                        <div
                          class="text-[11px]
                                 text-[#78918B]
                                 dark:text-gray-500
                                 mt-1 truncate
                                 max-w-[250px]">

                          ${esc(task.description)}

                        </div>
                      `
                      : ''
                  }

                </div>

              </div>

            </td>


            <!-- Course -->
            <td class="py-3.5 px-3">

              <div
                class="font-semibold
                       text-[#315B52]
                       dark:text-gray-300">

                ${esc(
                  task.course_code ||
                  '—'
                )}

              </div>

              ${
                task.course_name
                  ? `
                    <div
                      class="text-[10px]
                             text-[#78918B]
                             dark:text-gray-500
                             mt-0.5 truncate
                             max-w-[100px]">

                      ${esc(
                        task.course_name
                      )}

                    </div>
                  `
                  : ''
              }

            </td>


            <!-- Type -->
            <td class="py-3.5 px-3">

              <span
                class="task-type-pill">

                ${esc(type)}

              </span>

            </td>


            <!-- Priority -->
            <td class="py-3.5 px-3">

              <span
                class="inline-flex
                       items-center gap-2
                       text-xs font-semibold">

                <span
                  class="w-2 h-2 rounded-full
                         ${
                           PRIORITY_DOT[
                             task.priority
                           ] ||
                           'bg-gray-400'
                         }">
                </span>

                ${esc(priority)}

              </span>

            </td>


            <!-- Deadline -->
            <td
              class="py-3.5 px-3"
              data-task-deadline-id="${esc(task.id)}">

              <div
                class="text-xs font-semibold
                       text-[#315B52]
                       dark:text-gray-300
                       whitespace-nowrap">

                ${dateText}

              </div>

              <div
                class="text-[10px]
                       font-medium mt-1
                       ${deadlineClass}"
                data-task-countdown>

                ${timeText}
                ·
                ${esc(
                  task.due_label ||
                  ''
                )}

              </div>

            </td>


            <!-- Progress -->
            <td class="py-3.5 px-3">

              <div
                class="flex items-center gap-2
                       min-w-[100px]">

                <div
                  class="flex-1 h-1.5
                         bg-[#E7EFED]
                         dark:bg-white/10
                         rounded-full
                         overflow-hidden">

                  <div
                    class="h-full
                           bg-emerald-600
                           rounded-full
                           transition-all
                           duration-500"
                    data-work-progress-item="task:${esc(task.id)}"
                    style="
                      width:${progress}%
                    ">
                  </div>

                </div>

                <span
                  class="text-[11px]
                         font-semibold
                         text-[#496861]
                         dark:text-gray-400">

                  ${progress}%

                </span>

              </div>

            </td>


            <!-- Status -->
            <td class="py-3.5 px-3">

              <span
                class="task-status-pill
                       ${
                         STATUS_CLASSES[
                           task.status
                         ] ||
                         STATUS_CLASSES.not_started
                       }">

                ${esc(status)}

              </span>

            </td>


            <!-- Actions -->
            <td
              class="py-3.5 px-3
                     whitespace-nowrap
                     text-center">

              ${task.status === 'completed' ? '' : `<button
                type="button"
                class="task-action-btn text-emerald-700 dark:text-emerald-300 timer-task-btn"
                data-work-item-type="task"
                data-work-item-id="${esc(task.id)}"
                data-work-item-title="${esc(task.title)}"
                title="Start or pause timer">
                <i data-lucide="timer" class="w-4 h-4"></i>
              </button>`}

              <button
                type="button"
                class="task-action-btn edit-task-btn"
                data-id="${esc(task.id)}"
                title="Edit task"
                aria-label="Edit ${esc(task.title)}">

                <i
                  data-lucide="pencil"
                  class="w-4 h-4">
                </i>

              </button>

              <button
                type="button"
                class="task-action-btn danger
                       delete-task-btn ml-1"
                data-id="${esc(task.id)}"
                title="Delete task"
                aria-label="Delete ${esc(task.title)}">

                <i
                  data-lucide="trash-2"
                  class="w-4 h-4">
                </i>

              </button>

            </td>

          </tr>
        `;
        }
      )
      .join('');


  /* Edit buttons */

  tbody
    .querySelectorAll(
      '.edit-task-btn'
    )
    .forEach(
      btn => {

        btn.addEventListener(
          'click',
          () =>
            openEditTask(
              btn.dataset.id
            )
        );

      }
    );


  /* Delete buttons */

  tbody
    .querySelectorAll(
      '.delete-task-btn'
    )
    .forEach(
      btn => {

        btn.addEventListener(
          'click',
          () =>
            deleteTask(
              btn.dataset.id
            )
        );

      }
    );


  /* Row checkboxes */

  tbody
    .querySelectorAll(
      '.row-task-checkbox'
    )
    .forEach(
      cb => {

        cb.addEventListener(
          'change',
          syncSelectAllState
        );

      }
    );


  const start =
    (CURRENT_PAGE - 1) *
      PAGE_SIZE +
    1;

  const end =
    Math.min(
      CURRENT_PAGE * PAGE_SIZE,
      tasks.length
    );

  const resultLabel =
    getEl(
      'task-results-label'
    );

  if (resultLabel) {

    resultLabel.textContent =
      `Showing ${start}–${end} of ${tasks.length} tasks`;
  }

  renderPagination(
    tasks.length
  );

  if (window.lucide) {
    window.lucide.createIcons();
  }
}


/* =========================================================
   PAGINATION
========================================================= */

function renderPagination(total) {

  const box =
    getEl(
      'task-pagination'
    );

  if (!box) {
    return;
  }

  const pages =
    Math.max(
      1,
      Math.ceil(
        total /
        PAGE_SIZE
      )
    );

  if (
    total <= PAGE_SIZE
  ) {
    box.innerHTML = '';
    return;
  }

  const buttons = [];

  buttons.push(`
    <button
      type="button"
      class="task-pagination-btn"
      data-page-action="prev"
      ${
        CURRENT_PAGE === 1
          ? 'disabled'
          : ''
      }
      aria-label="Previous page">

      <i
        data-lucide="chevron-left"
        class="w-4 h-4">
      </i>

    </button>
  `);


  for (
    let p = 1;
    p <= pages;
    p++
  ) {

    if (
      pages > 6 &&
      p > 3 &&
      p < pages - 2 &&
      Math.abs(
        p - CURRENT_PAGE
      ) > 1
    ) {

      if (
        p === 4
      ) {

        buttons.push(`
          <span
            class="px-1 text-gray-400">

            …

          </span>
        `);

      }

      continue;
    }

    buttons.push(`
      <button
        type="button"
        class="task-pagination-btn
               ${
                 p === CURRENT_PAGE
                   ? 'active'
                   : ''
               }"
        data-page="${p}"
        aria-label="Page ${p}"
        ${
          p === CURRENT_PAGE
            ? 'aria-current="page"'
            : ''
        }>

        ${p}

      </button>
    `);
  }


  buttons.push(`
    <button
      type="button"
      class="task-pagination-btn"
      data-page-action="next"
      ${
        CURRENT_PAGE === pages
          ? 'disabled'
          : ''
      }
      aria-label="Next page">

      <i
        data-lucide="chevron-right"
        class="w-4 h-4">
      </i>

    </button>
  `);


  box.innerHTML =
    buttons.join('');


  box
    .querySelectorAll(
      '[data-page]'
    )
    .forEach(
      btn => {

        btn.addEventListener(
          'click',
          () => {

            CURRENT_PAGE =
              Number(
                btn.dataset.page
              );

            renderTable(
              ALL_TASKS
            );

          }
        );

      }
    );


  box
    .querySelector(
      '[data-page-action="prev"]'
    )
    ?.addEventListener(
      'click',
      () => {

        if (
          CURRENT_PAGE > 1
        ) {

          CURRENT_PAGE--;

          renderTable(
            ALL_TASKS
          );

        }

      }
    );


  box
    .querySelector(
      '[data-page-action="next"]'
    )
    ?.addEventListener(
      'click',
      () => {

        if (
          CURRENT_PAGE < pages
        ) {

          CURRENT_PAGE++;

          renderTable(
            ALL_TASKS
          );

        }

      }
    );


  if (window.lucide) {
    window.lucide.createIcons();
  }
}


/* =========================================================
   SELECT ALL
========================================================= */

function syncSelectAllState() {

  const all = [
    ...document.querySelectorAll(
      '.row-task-checkbox'
    )
  ];

  const selected =
    all.filter(
      checkbox =>
        checkbox.checked
    );

  const master =
    getEl(
      'select-all-tasks'
    );

  if (!master) {
    return;
  }

  master.checked =
    all.length > 0 &&
    selected.length ===
      all.length;

  master.indeterminate =
    selected.length > 0 &&
    selected.length <
      all.length;
}


/* =========================================================
   SUMMARY DONUT
========================================================= */

function renderSummaryDonut(s) {

  const total =
    Number(
      s.total || 0
    );

  const completed =
    Number(
      s.completed || 0
    );

  const inProgress =
    Number(
      s.in_progress || 0
    );

  const overdue =
    Number(
      s.overdue || 0
    );

  const pending =
    Math.max(
      0,
      total -
      completed -
      inProgress -
      overdue
    );

  const labels = [
    'Completed',
    'In Progress',
    'Pending',
    'Overdue'
  ];

  const data = [
    completed,
    inProgress,
    pending,
    overdue
  ];

  const colors = [
    '#14945f',
    '#2f80ed',
    '#f5a623',
    '#ef4444'
  ];

  const canvas =
    getEl(
      'task-summary-chart'
    );

  if (
    !canvas ||
    typeof Chart ===
      'undefined'
  ) {
    return;
  }

  if (summaryChart) {
    summaryChart.destroy();
  }

  summaryChart =
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

          responsive: true,

          maintainAspectRatio:
            false,

          cutout: '64%',

          plugins: {

            legend: {
              display: false
            },

            tooltip: {
              enabled: true
            }

          }

        }
      }
    );


  const totalEl =
    getEl(
      'task-summary-total'
    );

  if (totalEl) {

    totalEl.innerHTML = `
      <strong
        class="text-xl font-bold leading-none">

        ${total}

      </strong>

      <span
        class="text-[10px]
               text-[#78918B]
               dark:text-gray-500
               mt-1">

        Total

      </span>
    `;
  }


  const legend =
    getEl(
      'task-summary-legend'
    );

  if (!legend) {
    return;
  }

  legend.innerHTML =
    labels
      .map(
        (
          label,
          i
        ) => `
          <div
            class="flex items-center gap-2">

            <span
              class="w-2 h-2 rounded-full
                     shrink-0"
              style="
                background:${colors[i]}
              ">
            </span>

            <span
              class="text-[#496861]
                     dark:text-gray-400
                     truncate">

              ${label}

            </span>

            <span
              class="ml-auto
                     font-semibold
                     text-[#183E36]
                     dark:text-gray-200">

              ${data[i]}

            </span>

          </div>
        `
      )
      .join('');
}


/* =========================================================
   UPCOMING DEADLINES
========================================================= */

function renderUpcoming() {

  const upcoming =
    [...ALL_TASKS]
      .filter(
        task =>
          task.status !==
          'completed'
      )
      .sort(
        (a, b) =>
          (
            localDate(
              a.due_at
            )?.getTime() ||
            Infinity
          ) -
          (
            localDate(
              b.due_at
            )?.getTime() ||
            Infinity
          )
      )
      .slice(
        0,
        4
      );

  const el =
    getEl(
      'task-upcoming-deadlines'
    );

  if (!el) {
    return;
  }

  if (!upcoming.length) {

    el.innerHTML = `
      <div
        class="task-empty-small">

        <i
          data-lucide="calendar-check"
          class="w-5 h-5">
        </i>

        <span>
          Nothing due soon.
        </span>

      </div>
    `;

    if (window.lucide) {
      window.lucide.createIcons();
    }

    return;
  }


  el.innerHTML =
    upcoming
      .map(
        task => {

          const high =
            task.priority ===
            'high';

          const overdue =
            task.urgency ===
            'overdue';

          const tone =
            overdue
              ? 'overdue'
              : high
                ? 'high'
                : '';

          return `
          <div
            class="task-upcoming-item"
            data-upcoming-task-id="${esc(task.id)}">

            <div
              class="task-upcoming-icon
                     ${tone}">

              <i
                data-lucide="${
                  overdue
                    ? 'circle-alert'
                    : 'calendar-clock'
                }"
                class="w-4 h-4">
              </i>

            </div>


            <div
              class="min-w-0 flex-1">

              <div
                class="text-xs font-semibold
                       text-[#23483F]
                       dark:text-gray-200
                       truncate">

                ${esc(
                  task.title
                )}

              </div>

              <div
                class="text-[10px]
                       text-[#78918B]
                       dark:text-gray-500
                       mt-0.5">

                ${esc(
                  task.course_code ||
                  'No course'
                )}

              </div>

            </div>


            <div
              class="text-[10px]
                     font-semibold
                     shrink-0 ml-2
                     ${
                       overdue
                         ? 'text-red-600 dark:text-red-400'
                         : high
                           ? 'text-red-600 dark:text-red-400'
                           : 'text-amber-600 dark:text-amber-400'
                     }"
              data-upcoming-countdown>

              ${esc(
                task.due_label ||
                ''
              )}

            </div>

          </div>
        `;
        }
      )
      .join('');


  if (window.lucide) {
    window.lucide.createIcons();
  }
}


/* =========================================================
   FILTER SYNCHRONIZATION
========================================================= */

function syncSideFiltersFromMain() {

  const pairs = [
    [
      'filter-course',
      'filter-course-side'
    ],

    [
      'filter-type',
      'filter-type-side'
    ],

    [
      'filter-priority',
      'filter-priority-side'
    ]
  ];

  pairs.forEach(
    ([main, side]) => {

      const mainEl =
        getEl(
          main
        );

      const sideEl =
        getEl(
          side
        );

      if (
        mainEl &&
        sideEl
      ) {

        sideEl.value =
          mainEl.value;
      }

    }
  );


  const sideSearch =
    getEl(
      'filter-search-side'
    );

  const mainSearch =
    getEl(
      'task-search'
    );

  if (
    sideSearch &&
    mainSearch
  ) {

    sideSearch.value =
      mainSearch.value;
  }
}


function syncMainFiltersFromSide() {

  const pairs = [
    [
      'filter-course-side',
      'filter-course'
    ],

    [
      'filter-type-side',
      'filter-type'
    ],

    [
      'filter-priority-side',
      'filter-priority'
    ]
  ];

  pairs.forEach(
    ([side, main]) => {

      const sideEl =
        getEl(
          side
        );

      const mainEl =
        getEl(
          main
        );

      if (
        sideEl &&
        mainEl
      ) {

        mainEl.value =
          sideEl.value;
      }

    }
  );


  const mainSearch =
    getEl(
      'task-search'
    );

  const sideSearch =
    getEl(
      'filter-search-side'
    );

  if (
    mainSearch &&
    sideSearch
  ) {

    mainSearch.value =
      sideSearch.value;
  }
}


/* =========================================================
   RESET FILTERS
========================================================= */

function resetFilters() {

  [
    'filter-course',
    'filter-type',
    'filter-priority',

    'filter-course-side',
    'filter-type-side',
    'filter-priority-side',

    'filter-status-side',

    'task-search',
    'filter-search-side'
  ].forEach(
    id => {

      const el =
        getEl(
          id
        );

      if (el) {
        el.value = '';
      }

    }
  );

  ACTIVE_TAB =
    'all';

  CURRENT_PAGE =
    1;

  setActiveTabUI();

  loadTasks();
}


/* =========================================================
   FILTER EVENTS
========================================================= */

function bindFilters() {

  [
    'filter-course',
    'filter-type',
    'filter-priority'
  ].forEach(
    id => {

      getEl(id)
        ?.addEventListener(
          'change',
          () => {

            syncSideFiltersFromMain();

            CURRENT_PAGE =
              1;

            loadTasks();

          }
        );

    }
  );


  [
    'filter-course-side',
    'filter-type-side',
    'filter-priority-side'
  ].forEach(
    id => {

      getEl(id)
        ?.addEventListener(
          'change',
          () => {

            syncMainFiltersFromSide();

            CURRENT_PAGE =
              1;

          }
        );

    }
  );


  getEl(
    'filter-status-side'
  )?.addEventListener(
    'change',
    event => {

      const value =
        event.target.value;

      if (
        value ===
        'overdue'
      ) {

        ACTIVE_TAB =
          'overdue';

      } else if (
        value
      ) {

        ACTIVE_TAB =
          value;

      } else {

        ACTIVE_TAB =
          'all';
      }

      setActiveTabUI();

      CURRENT_PAGE =
        1;

      loadTasks();

    }
  );


  getEl(
    'apply-filters'
  )?.addEventListener(
    'click',
    () => {

      syncMainFiltersFromSide();

      const status =
        getEl(
          'filter-status-side'
        )?.value ||
        '';

      if (
        status ===
        'overdue'
      ) {

        ACTIVE_TAB =
          'overdue';

      } else if (
        status
      ) {

        ACTIVE_TAB =
          status;

      } else {

        ACTIVE_TAB =
          'all';
      }

      setActiveTabUI();

      CURRENT_PAGE =
        1;

      loadTasks();

    }
  );


  getEl(
    'reset-filters'
  )?.addEventListener(
    'click',
    resetFilters
  );


  /* Main search */

  getEl(
    'task-search'
  )?.addEventListener(
    'input',
    () => {

      syncSideFiltersFromMain();

      clearTimeout(
        searchDebounce
      );

      searchDebounce =
        setTimeout(
          () => {

            CURRENT_PAGE =
              1;

            loadTasks();

          },
          350
        );

    }
  );


  /* Side search */

  getEl(
    'filter-search-side'
  )?.addEventListener(
    'input',
    () => {

      const mainSearch =
        getEl(
          'task-search'
        );

      const sideSearch =
        getEl(
          'filter-search-side'
        );

      if (
        mainSearch &&
        sideSearch
      ) {

        mainSearch.value =
          sideSearch.value;
      }

      clearTimeout(
        searchDebounce
      );

      searchDebounce =
        setTimeout(
          () => {

            CURRENT_PAGE =
              1;

            loadTasks();

          },
          350
        );

    }
  );
}


/* =========================================================
   TABS
========================================================= */

function bindTabs() {

  document
    .querySelectorAll(
      '.task-tab-btn'
    )
    .forEach(
      btn => {

        btn.addEventListener(
          'click',
          () => {

            ACTIVE_TAB =
              btn.dataset.tab ||
              'all';

            const statusSide =
              getEl(
                'filter-status-side'
              );

            if (statusSide) {

              statusSide.value =
                ACTIVE_TAB ===
                'overdue'

                  ? 'overdue'

                  : ACTIVE_TAB ===
                      'all'

                    ? ''

                    : ACTIVE_TAB;
            }

            CURRENT_PAGE =
              1;

            setActiveTabUI();

            loadTasks();

          }
        );

      }
    );
}


/* =========================================================
   MODAL
========================================================= */

function openModal() {

  getEl(
    'task-modal'
  )?.classList.remove(
    'hidden'
  );

  document.body.classList.add(
    'overflow-hidden'
  );

  setTimeout(
    () => {

      getEl(
        'task-form'
      )?.elements.title
        ?.focus();

    },
    30
  );
}


function closeModal() {

  getEl(
    'task-modal'
  )?.classList.add(
    'hidden'
  );

  document.body.classList.remove(
    'overflow-hidden'
  );
}


function resetTaskForm() {

  const form =
    getEl(
      'task-form'
    );

  if (!form) {
    return;
  }

  form.reset();

  if (form.elements.status) form.elements.status.value = 'pending';

  if (
    form.elements.id
  ) {

    form.elements.id.value =
      '';
  }

  if (
    form.elements.progress_percent
  ) {

    form.elements
      .progress_percent
      .value = 0;
  }

  const progressLabel =
    getEl(
      'progress-value-label'
    );

  if (progressLabel) {

    progressLabel.textContent =
      '0%';
  }

  const modalTitle =
    getEl(
      'task-modal-title'
    );

  if (modalTitle) {

    modalTitle.textContent =
      'Add Task';
  }
}


function openAddTask() {

  resetTaskForm();

  openModal();
}


/* =========================================================
   EDIT TASK
========================================================= */

function openEditTask(id) {

  const task =
    ALL_TASKS.find(
      item =>
        String(item.id) ===
        String(id)
    );

  if (!task) {

    toast(
      'Task could not be found. Refreshing…',
      'error'
    );

    loadTasks();

    return;
  }

  const form =
    getEl(
      'task-form'
    );

  if (!form) {
    return;
  }


  if (
    form.elements.id
  ) {

    form.elements.id.value =
      task.id;
  }

  if (
    form.elements.title
  ) {

    form.elements.title.value =
      task.title || '';
  }

  if (
    form.elements.description
  ) {

    form.elements.description.value =
      task.description || '';
  }

  if (
    form.elements.course_id
  ) {

    form.elements.course_id.value =
      task.course_id || '';
  }

  if (
    form.elements.type
  ) {

    form.elements.type.value =
      task.type ||
      'assignment';
  }

  if (
    form.elements.priority
  ) {

    form.elements.priority.value =
      task.priority ||
      'medium';
  }

  if (
    form.elements.status
  ) {

    form.elements.status.value =
      task.status ||
      'not_started';
  }

  if (
    form.elements.duration_hours
  ) {

    form.elements.duration_hours.value =
      task.duration_hours ??
      '';
  }

  if (
    form.elements.due_at
  ) {

    form.elements.due_at.value =
      datetimeLocalValue(
        task.due_at
      );
  }

  const progress =
    Number(
      task.progress_percent ||
      0
    );

  if (
    form.elements.progress_percent
  ) {

    form.elements
      .progress_percent
      .value =
      progress;
  }

  const progressLabel =
    getEl(
      'progress-value-label'
    );

  if (progressLabel) {

    progressLabel.textContent =
      `${progress}%`;
  }

  const modalTitle =
    getEl(
      'task-modal-title'
    );

  if (modalTitle) {

    modalTitle.textContent =
      'Edit Task';
  }

  openModal();
}


/* =========================================================
   DELETE TASK
========================================================= */

async function deleteTask(id) {

  const task =
    ALL_TASKS.find(
      item =>
        String(item.id) ===
        String(id)
    );

  const label =
    task?.title
      ? `Delete "${task.title}"? This cannot be undone.`
      : 'Delete this task? This cannot be undone.';

  if (
    !confirm(label)
  ) {
    return;
  }

  try {

    await apiJson(
      `${API}/tasks.php`,
      {
        method:
          'DELETE',

        headers: {
          'Content-Type':
            'application/x-www-form-urlencoded'
        },

        body:
          `id=${encodeURIComponent(id)}` +
          `&csrf_token=${encodeURIComponent(
            window.CSRF_TOKEN ||
            ''
          )}`
      }
    );

    toast(
      'Task deleted',
      'success'
    );

    CURRENT_PAGE =
      1;

    await loadTasks();

  } catch (error) {

    console.error(
      'Delete task failed:',
      error
    );

    toast(
      error.message ||
        'Could not delete task.',
      'error'
    );
  }
}


/* =========================================================
   MODAL EVENTS
========================================================= */

function bindModal() {

  const modal =
    getEl(
      'task-modal'
    );

  const form =
    getEl(
      'task-form'
    );

  const progress =
    getEl(
      'progress-range'
    );


  getEl(
    'open-add-task'
  )?.addEventListener(
    'click',
    openAddTask
  );


  getEl(
    'cancel-task'
  )?.addEventListener(
    'click',
    closeModal
  );


  getEl(
    'cancel-task-bottom'
  )?.addEventListener(
    'click',
    closeModal
  );


  modal?.addEventListener(
    'click',
    event => {

      if (
        event.target ===
        modal
      ) {

        closeModal();

      }

    }
  );


  document.addEventListener(
    'keydown',
    event => {

      if (
        event.key ===
        'Escape' &&
        modal &&
        !modal.classList.contains(
          'hidden'
        )
      ) {

        closeModal();

      }

    }
  );


  progress?.addEventListener(
    'input',
    () => {

      const label =
        getEl(
          'progress-value-label'
        );

      if (label) {

        label.textContent =
          `${progress.value}%`;
      }

    }
  );


  form?.addEventListener(
    'submit',
    async event => {

      event.preventDefault();

      const submit =
        getEl(
          'save-task-btn'
        );

      const fd =
        new FormData(
          form
        );

      const payload =
        Object.fromEntries(
          fd.entries()
        );


      payload.csrf_token =
        window.CSRF_TOKEN ||
        '';


      payload.due_at =
        sqlDateTime(
          payload.due_at
        );


      payload.progress_percent =
        Number(
          payload.progress_percent ||
          0
        );


      payload.duration_hours =
        payload.duration_hours ===
        ''
          ? 0
          : Number(
              payload.duration_hours
            );


      /* Validate title */

      if (
        !payload.title ||
        !payload.title.trim()
      ) {

        toast(
          'Task title is required.',
          'error'
        );

        form.elements.title?.focus();

        return;
      }


      /* Validate deadline */

      if (
        !payload.due_at
      ) {

        toast(
          'Deadline is required.',
          'error'
        );

        form.elements.due_at?.focus();

        return;
      }


      const isEdit =
        Boolean(
          payload.id
        );


      setButtonBusy(
        submit,
        true,
        isEdit
          ? 'Updating…'
          : 'Saving…'
      );


      try {

        await apiJson(
          `${API}/tasks.php`,
          {

            method:
              isEdit
                ? 'PUT'
                : 'POST',

            headers: {
              'Content-Type':
                'application/json'
            },

            body:
              JSON.stringify(
                payload
              )
          }
        );


        closeModal();


        toast(
          isEdit
            ? 'Task updated successfully'
            : 'Task added successfully',
          'success'
        );


        await loadTasks();

      } catch (error) {

        console.error(
          'Save task failed:',
          error
        );

        toast(
          error.message ||
            'Could not save task.',
          'error'
        );

      } finally {

        setButtonBusy(
          submit,
          false
        );

      }

    }
  );
}


/* =========================================================
   SELECT ALL
========================================================= */

function bindSelectAll() {

  getEl(
    'select-all-tasks'
  )?.addEventListener(
    'change',
    event => {

      document
        .querySelectorAll(
          '.row-task-checkbox'
        )
        .forEach(
          checkbox => {

            checkbox.checked =
              event.target.checked;

          }
        );

      event.target.indeterminate =
        false;

    }
  );
}


/* =========================================================
   IMPORT TASKS
========================================================= */

function bindImport() {

  const button =
    getEl(
      'open-import-task'
    );

  const fileInput =
    getEl(
      'task-import-file'
    );

  if (
    !button ||
    !fileInput
  ) {
    return;
  }


  button.addEventListener(
    'click',
    () =>
      fileInput.click()
  );


  fileInput.addEventListener(
    'change',
    async () => {

      const file =
        fileInput.files?.[0];

      fileInput.value = '';

      if (!file) {
        return;
      }


      try {

        const text =
          await file.text();

        const rows =
          parseCsv(
            text
          );


        if (
          rows.length < 2
        ) {

          throw new Error(
            'The CSV file does not contain any task rows.'
          );

        }


        const headers =
          rows[0].map(
            normalizeHeader
          );


        const index =
          name =>
            headers.indexOf(
              name
            );


        const titleIndex =
          index(
            'title'
          );


        const dueIndex =
          index(
            'due_at'
          ) >= 0
            ? index(
                'due_at'
              )
            : index(
                'deadline'
              );


        if (
          titleIndex < 0 ||
          dueIndex < 0
        ) {

          throw new Error(
            'CSV must contain "title" and "due_at" (or "deadline") columns.'
          );

        }


        let success =
          0;

        let failed =
          0;


        for (
          const row of
          rows.slice(1)
        ) {

          const title =
            String(
              row[titleIndex] ||
              ''
            ).trim();


          const due =
            String(
              row[dueIndex] ||
              ''
            ).trim();


          if (
            !title ||
            !due
          ) {

            failed++;

            continue;
          }


          const payload = {

            title,

            due_at:
              sqlDateTime(
                due
              ),

            description:
              valueFromRow(
                row,
                headers,
                'description'
              ),

            course_id:
              valueFromRow(
                row,
                headers,
                'course_id'
              ),

            type:
              valueFromRow(
                row,
                headers,
                'type'
              ) ||
              'assignment',

            priority:
              valueFromRow(
                row,
                headers,
                'priority'
              ) ||
              'medium',

            status:
              valueFromRow(
                row,
                headers,
                'status'
              ) ||
              'not_started',

            progress_percent:
              Number(
                valueFromRow(
                  row,
                  headers,
                  'progress_percent'
                ) || 0
              ),

            duration_hours:
              Number(
                valueFromRow(
                  row,
                  headers,
                  'duration_hours'
                ) || 0
              ),

            csrf_token:
              window.CSRF_TOKEN ||
              ''

          };


          try {

            await apiJson(
              `${API}/tasks.php`,
              {

                method:
                  'POST',

                headers: {
                  'Content-Type':
                    'application/json'
                },

                body:
                  JSON.stringify(
                    payload
                  )

              }
            );

            success++;

          } catch (error) {

            console.error(
              'Import row failed:',
              error
            );

            failed++;
          }

        }


        toast(
          `${success} task${
            success === 1
              ? ''
              : 's'
          } imported${
            failed
              ? `, ${failed} skipped`
              : ''
          }.`,
          failed
            ? 'info'
            : 'success'
        );


        await loadTasks();

      } catch (error) {

        console.error(
          'Import tasks failed:',
          error
        );

        toast(
          error.message ||
            'Could not import tasks.',
          'error'
        );

      }

    }
  );
}


/* =========================================================
   CSV HELPERS
========================================================= */

function normalizeHeader(
  value
) {

  return String(
    value || ''
  )
    .trim()
    .toLowerCase()
    .replace(
      /\s+/g,
      '_'
    );
}


function valueFromRow(
  row,
  headers,
  key
) {

  const index =
    headers.indexOf(
      key
    );

  return index >= 0
    ? String(
        row[index] ?? ''
      ).trim()
    : '';
}


function parseCsv(
  text
) {

  const rows = [];

  let row = [];

  let field = '';

  let quoted =
    false;


  for (
    let i = 0;
    i < text.length;
    i++
  ) {

    const ch =
      text[i];

    const next =
      text[i + 1];


    if (
      ch === '"'
    ) {

      if (
        quoted &&
        next === '"'
      ) {

        field += '"';

        i++;

      } else {

        quoted =
          !quoted;

      }

    } else if (
      ch === ',' &&
      !quoted
    ) {

      row.push(
        field
      );

      field = '';

    } else if (
      (
        ch === '\n' ||
        ch === '\r'
      ) &&
      !quoted
    ) {

      if (
        ch === '\r' &&
        next === '\n'
      ) {

        i++;

      }

      row.push(
        field
      );


      if (
        row.some(
          value =>
            String(
              value
            ).trim() !== ''
        )
      ) {

        rows.push(
          row
        );

      }


      row = [];

      field = '';

    } else {

      field += ch;

    }
  }


  if (
    field !== '' ||
    row.length
  ) {

    row.push(
      field
    );


    if (
      row.some(
        value =>
          String(
            value
          ).trim() !== ''
      )
    ) {

      rows.push(
        row
      );

    }

  }


  return rows;
}


/* =========================================================
   TEXT HELPERS
========================================================= */

function capitalizeSafe(
  value
) {

  const s =
    String(
      value || ''
    );

  return s
    ? s.charAt(0).toUpperCase() +
        s.slice(1)
    : '';
}





/* =========================================================
   INITIALIZE
========================================================= */

function initializeTasksPage() {

  bindTabs();

  bindFilters();

  bindModal();

  bindSelectAll();

  bindImport();

  setActiveTabUI();

  if (window.lucide) {
    window.lucide.createIcons();
  }

  startTaskLiveClock();
}


/* =========================================================
   APP BOOT
========================================================= */

window.addEventListener('work-item-updated', e => { if (e.detail?.type === 'task') loadTasks(); });

window.APP_READY.then(
  async me => {

    if (!me) {
      return;
    }

    initializeTasksPage();

    await loadCourseOptions();

    await loadTasks();

  }
);
