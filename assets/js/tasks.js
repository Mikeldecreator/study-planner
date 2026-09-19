
let ALL_TASKS = [];
let ALL_LOADED_COMPLETED_TASKS = [];
let ACTIVE_TAB = 'all';
let COURSES_CACHE = [];
let coursesLoadingPromise = null;
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

function formatProgressPercent(val) {
  const n = Math.min(100, Math.max(0, Number(val) || 0));
  if (n === 0) return '0%';
  if (n >= 100) return '100%';
  if (Number.isInteger(n)) return `${n}%`;
  return `${n.toFixed(2)}%`;
}

function resolveTaskProgress(task) {
  const status = String(task?.system_status || task?.status || '');
  if (status === 'completed' || task?.is_system_completed) {
    return 100;
  }
  const focusedSec = Number(task?.focused_seconds || task?.total_focused_seconds || 0);
  const timeProgress = Number(task?.time_progress_percent ?? task?.time_progress ?? task?.system_progress ?? 0);
  const manualProgress = Number(task?.user_progress ?? task?.progress_percent ?? 0);

  let p = 0;
  if (focusedSec > 0 && timeProgress > 0) {
    p = manualProgress > 0 ? Math.max(timeProgress, manualProgress) : timeProgress;
  } else if (timeProgress > 0) {
    p = timeProgress;
  } else {
    p = manualProgress;
  }
  return Math.min(100, Math.max(0, p));
}

function getLiveTaskState(task, nowMs = Date.now()) {
  const due = localDate(task?.due_at);
  const progress = resolveTaskProgress(task);
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

  const rawStatus = String(task?.system_status || task?.status || '');
  const completed =
    rawStatus === 'completed' ||
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

function populateCourseDropdowns() {
  const opts = COURSES_CACHE
    .map(
      course => `
      <option value="${esc(course.id)}">
        ${esc(course.code)} — ${esc(course.name || course.title || '')}
      </option>
    `
    )
    .join('');

  const taskCourse = getEl('task-course-select');
  const mainCourse = getEl('filter-course');
  const sideCourse = getEl('filter-course-side');

  if (taskCourse) {
    const prev = taskCourse.value;
    taskCourse.innerHTML = '<option value="">No course (General Task)</option>' + opts;
    if (prev && Array.from(taskCourse.options).some(o => o.value === String(prev))) {
      taskCourse.value = prev;
    }
  }

  if (mainCourse) {
    const prev = mainCourse.value;
    mainCourse.innerHTML = '<option value="">All Courses</option>' + opts;
    if (prev && Array.from(mainCourse.options).some(o => o.value === String(prev))) {
      mainCourse.value = prev;
    }
  }

  if (sideCourse) {
    const prev = sideCourse.value;
    sideCourse.innerHTML = '<option value="">All Courses</option>' + opts;
    if (prev && Array.from(sideCourse.options).some(o => o.value === String(prev))) {
      sideCourse.value = prev;
    }
  }
}

function loadCourseOptions(force = false) {
  if (coursesLoadingPromise && !force) {
    return coursesLoadingPromise;
  }
  coursesLoadingPromise = (async () => {
    try {
      const apiEndpoint = (typeof API !== 'undefined' ? API : (window.API || 'api')) + '/courses.php';
      const data = await apiJson(apiEndpoint);
      COURSES_CACHE = Array.isArray(data.courses) ? data.courses : [];
      populateCourseDropdowns();
      return COURSES_CACHE;
    } catch (error) {
      console.error('Course options failed:', error);
      return COURSES_CACHE;
    }
  })();
  return coursesLoadingPromise;
}

// Proactively initiate loading courses immediately on script load
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => { loadCourseOptions(); });
} else {
  loadCourseOptions();
}


/* =========================================================
   QUERY / FILTERS
========================================================= */

function buildQuery() {

  const params =
    new URLSearchParams();

  if (ACTIVE_TAB !== 'all') {
    params.set(
      'status',
      ACTIVE_TAB
    );
  }

  const course =
    getEl('filter-course')?.value ||
    getEl('filter-course-side')?.value ||
    '';

  const type =
    getEl('filter-type')?.value ||
    getEl('filter-type-side')?.value ||
    '';

  const priority =
    getEl('filter-priority')?.value ||
    getEl('filter-priority-side')?.value ||
    '';

  const sort =
    getEl('filter-sort')?.value ||
    getEl('filter-sort-side')?.value ||
    '';

  const q =
    getEl('task-search')?.value.trim() ||
    getEl('filter-search-side')?.value.trim() ||
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

  if (sort && sort !== 'smart') {
    params.set(
      'sort',
      sort
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

    renderSmartTaskFocus(
      ALL_TASKS
    );

    renderStatCards(
      data.summary || {}
    );

    renderTabCounts(
      data.summary || {}
    );

    if (ACTIVE_TAB === 'all' || !ALL_LOADED_COMPLETED_TASKS.length) {
      ALL_LOADED_COMPLETED_TASKS = ALL_TASKS.filter(t => (t.system_status || t.status) === 'completed');
    }

    renderTable(
      ALL_TASKS
    );

    renderCompletedTasks(
      ALL_TASKS
    );

    renderSummaryDonut(
      data.summary || {}
    );

    renderUpcoming();

    startTaskLiveClock();
  bindCompletionAndDeletionEvents();

    updateLiveTaskDisplays({
      rerenderUpcoming:
        true
    });

    if (typeof populateTimerTaskDropdown === 'function') {
      populateTimerTaskDropdown();
    }

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
                       font-semibold hover:underline">

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
   SMART TASK FOCUS
========================================================= */

function renderSmartTaskFocus(tasks) {
  const container = getEl('smart-task-focus-card');
  if (!container) return;

  const activeTasks = (Array.isArray(tasks) ? tasks : []).filter(t => {
    const rawStatus = String(t.system_status || t.status);
    const prog = Number(t.system_progress !== undefined ? t.system_progress : (t.time_progress_percent !== undefined ? t.time_progress_percent : (t.progress_percent || 0)));
    return rawStatus !== 'completed' && prog < 100;
  });

  if (!activeTasks.length) {
    container.innerHTML = '';
    container.classList.add('hidden');
    return;
  }

  const topTask = [...activeTasks].sort((a, b) => {
    const scoreA = Number(a.smart_priority_score) || 0;
    const scoreB = Number(b.smart_priority_score) || 0;
    if (scoreB !== scoreA) return scoreB - scoreA;
    const dueA = localDate(a.due_at)?.getTime() || Infinity;
    const dueB = localDate(b.due_at)?.getTime() || Infinity;
    return dueA - dueB;
  })[0];

  if (!topTask) {
    container.innerHTML = '';
    container.classList.add('hidden');
    return;
  }

  const priorityScore = Math.round(Number(topTask.smart_priority_score) || 0);
  const priorityLabel = esc(topTask.smart_priority_label || capitalizeSafe(topTask.priority || 'medium'));
  const riskLabel = esc(topTask.risk_label || 'Low');
  const remainingHours = Number(topTask.remaining_hours) || 0;
  const remainingText = remainingHours > 0 ? `${remainingHours}h remaining workload` : 'Under 1h remaining';
  const dueDisplay = esc(topTask.deadline_display || topTask.due_label || 'Upcoming');
  const reason = esc(topTask.priority_reason || 'Identified as your top academic priority based on deadline proximity and course weight.');
  const action = esc(topTask.recommended_action || 'Review and take action on this task.');

  container.classList.remove('hidden');
  container.innerHTML = `
    <div class="smart-task-focus">
      <div class="smart-task-focus-icon">
        <i data-lucide="target" class="w-6 h-6"></i>
      </div>
      <div class="smart-task-focus-content">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="smart-task-focus-eyebrow">
            RECOMMENDED ACADEMIC FOCUS • ${priorityLabel.toUpperCase()} PRIORITY (${priorityScore}/100)
          </div>
          <button type="button" class="text-xs font-bold text-emerald-700 dark:text-emerald-400 hover:underline flex items-center gap-1" onclick="openEditTask(${topTask.id})">
            Edit Task <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
          </button>
        </div>
        <h3 class="smart-task-focus-title">
          ${esc(topTask.title)}
          ${topTask.course_code ? `<span class="ml-2 text-xs font-semibold px-2 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300">${esc(topTask.course_code)}</span>` : ''}
        </h3>
        <p class="smart-task-focus-reason">${reason}</p>
        <div class="smart-task-focus-meta">
          <span><i data-lucide="clock" class="w-3 h-3 mr-1 text-emerald-600"></i> ${remainingText}</span>
          <span><i data-lucide="calendar" class="w-3 h-3 mr-1 text-emerald-600"></i> Due: ${dueDisplay}</span>
          <span><i data-lucide="shield-alert" class="w-3 h-3 mr-1 ${topTask.task_risk === 'high' || topTask.task_risk === 'critical' ? 'text-red-500' : 'text-amber-500'}"></i> ${riskLabel} Risk</span>
          <span class="bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 border-emerald-200 dark:border-emerald-800/40 font-bold">
            <i data-lucide="sparkles" class="w-3 h-3 mr-1 text-emerald-600"></i> Action: ${action}
          </span>
        </div>
      </div>
    </div>
  `;
  if (window.lucide) {
    window.lucide.createIcons();
  }
}


/* =========================================================
   COMPLETED TASKS (ARCHIVE / HISTORY)
========================================================= */

function renderCompletedTasks(tasks) {
  const tbody = getEl('completed-tasks-table-body');
  const countBadge = getEl('completed-tasks-count-badge');
  if (!tbody) return;

  let completed = (Array.isArray(tasks) ? tasks : []).filter(t => {
    const s = String(t.system_status || t.status || '');
    return s === 'completed';
  });

  if (!completed.length && ALL_LOADED_COMPLETED_TASKS.length && ACTIVE_TAB !== 'completed') {
    completed = ALL_LOADED_COMPLETED_TASKS;
  }

  if (countBadge) {
    countBadge.textContent = String(completed.length);
  }

  if (!completed.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" class="py-10 text-center">
          <div class="task-empty-state">
            <i data-lucide="archive" class="w-7 h-7 text-gray-400 dark:text-gray-500"></i>
            <strong class="text-xs">No completed tasks yet</strong>
            <span class="text-[11px] text-gray-500 dark:text-gray-400">Tasks you finish will be archived here with recorded focus time and progress history.</span>
          </div>
        </td>
      </tr>
    `;
    if (window.lucide) window.lucide.createIcons();
    return;
  }

  tbody.innerHTML = completed.map(task => {
    const progress = 100;

    const totalSec = Number(task.total_focused_seconds || task.focused_seconds || 0);
    const focusedText = totalSec > 0 ? formatDurationHuman(totalSec) : '0m';

    const compDate = localDate(task.completed_at || task.updated_at);
    const compText = compDate
      ? compDate.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
        ' ' + compDate.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
      : 'Completed';

    return `
      <tr class="completed-task-row" data-task-id="${esc(task.id)}">
        <!-- Task Title -->
        <td class="py-3 px-3.5">
          <div class="flex items-center gap-2">
            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0"></i>
            <span class="completed-task-title font-semibold text-xs text-[#183E36] dark:text-gray-200 truncate max-w-[260px]" title="${esc(task.title)}">
              ${esc(task.title)}
            </span>
          </div>
        </td>

        <!-- Course -->
        <td class="py-3 px-3.5">
          ${task.course_code ? `
            <span class="inline-flex items-center text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
              ${esc(task.course_code)}
            </span>
          ` : `
            <span class="text-[11px] text-gray-400 dark:text-gray-500">General</span>
          `}
        </td>

        <!-- Completion Status -->
        <td class="py-3 px-3.5">
          <span class="completed-task-badge">
            <i data-lucide="check" class="w-3 h-3"></i>
            Completed
          </span>
          ${task.progress_discrepancy ? `
            <div class="text-[10px] text-amber-600 dark:text-amber-400 font-medium mt-1 flex items-center gap-1" title="${esc(task.discrepancy_note || 'Study-time records are lower than expected')}">
              <i data-lucide="info" class="w-3 h-3 shrink-0"></i>
              <span>Study time lower than estimate</span>
            </div>
          ` : ''}
        </td>

        <!-- System Progress -->
        <td class="py-3 px-3.5">
          <div class="flex items-center gap-2 min-w-[110px]">
            <div class="flex-1 h-1.5 bg-[#E7EFED] dark:bg-white/10 rounded-full overflow-hidden">
              <div class="h-full bg-emerald-600 rounded-full" style="width: ${progress}%"></div>
            </div>
            <span class="text-[11px] font-bold text-emerald-700 dark:text-emerald-400">${progress}%</span>
          </div>
        </td>

        <!-- Focused Time -->
        <td class="py-3 px-3.5">
          <span class="text-xs font-semibold text-[#183E36] dark:text-gray-300 flex items-center gap-1">
            <i data-lucide="clock" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400"></i>
            ${focusedText} focused
          </span>
        </td>

        <!-- Completion Date / Time -->
        <td class="py-3 px-3.5 text-xs text-[#64807A] dark:text-gray-400">
          ${compText}
        </td>

        <!-- Actions (Undo & Delete) -->
        <td class="py-3 px-3.5 text-center">
          <div class="flex items-center justify-center gap-1.5">
            <button
              type="button"
              class="task-action-btn undo-task-btn text-gray-500 hover:text-emerald-700 dark:text-gray-400 dark:hover:text-emerald-300"
              data-id="${esc(task.id)}"
              title="Undo completion (move back to active)"
              aria-label="Undo completion for ${esc(task.title)}">
              <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
            </button>
            <button
              type="button"
              class="task-action-btn delete-completed-task-btn text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400"
              data-id="${esc(task.id)}"
              title="Delete this completed work"
              aria-label="Delete completed work for ${esc(task.title)}">
              <i data-lucide="trash-2" class="w-4 h-4"></i>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');

  tbody.querySelectorAll('.undo-task-btn').forEach(btn => {
    btn.addEventListener('click', () => handleUndoComplete(btn.dataset.id));
  });

  tbody.querySelectorAll('.delete-completed-task-btn').forEach(btn => {
    btn.addEventListener('click', () => openDeleteCompletedTaskModal(btn.dataset.id));
  });

  if (window.lucide) window.lucide.createIcons();
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
      'My Work',
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
      'Done',
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
      s.pending !== undefined
        ? Number(s.pending)
        : Math.max(
            0,
            total -
            completed -
            inProgress
          ),

    in_progress:
      inProgress,

    completed:
      completed,

    due_soon:
      Number(s.due_soon || 0),

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

  const isCompletedTab = ACTIVE_TAB === 'completed';
  const allList = Array.isArray(tasks) ? tasks : [];
  let displayTasks = allList;
  if (ACTIVE_TAB === 'completed') {
    displayTasks = allList.filter(t => String(t.system_status || t.status || '') === 'completed');
  } else if (ACTIVE_TAB === 'pending') {
    displayTasks = allList.filter(t => ['pending', 'not_started'].includes(String(t.system_status || t.status || '')));
  } else if (ACTIVE_TAB === 'in_progress') {
    displayTasks = allList.filter(t => String(t.system_status || t.status || '') === 'in_progress');
  } else if (ACTIVE_TAB === 'due_soon') {
    displayTasks = allList.filter(t => t.urgency === 'due_soon');
  } else if (ACTIVE_TAB === 'overdue') {
    displayTasks = allList.filter(t => t.urgency === 'overdue' || (t.status !== 'completed' && localDate(t.due_at) && localDate(t.due_at) < new Date()));
  }

  const visible =
    filteredForPage(
      displayTasks
    );

  if (!displayTasks.length) {
    let emptyIcon = 'clipboard-list';
    let emptyTitle = 'Nothing to study yet';
    let emptySub = 'Add your first assignment, reading, or prep to study.';
    let emptyButton = `
      <button
        type="button"
        id="empty-add-task-btn"
        class="inline-flex items-center gap-1.5 px-4 py-2 mt-3 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold shadow-sm transition">
        <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> + Add Work
      </button>
    `;

    if (isCompletedTab) {
      emptyIcon = 'archive';
      emptyTitle = 'No completed tasks yet';
      emptySub = 'Completed tasks and recorded study sessions will appear here.';
      emptyButton = '';
    } else {
      const hasAnyCompleted = (Array.isArray(tasks) ? tasks : []).some(t => String(t.system_status || t.status || '') === 'completed');
      const isSystemEmpty = !ALL_TASKS.length;

      if (hasAnyCompleted) {
        emptyIcon = 'check-circle-2';
        emptyTitle = 'All active tasks completed!';
        emptySub = 'All tasks in this view are completed. Check the Completed tab or archive below.';
        emptyButton = '';
      } else if (!isSystemEmpty) {
        emptyIcon = 'filter';
        emptyTitle = 'No work matches your filters';
        emptySub = 'Try clearing your search or filters to see your work.';
        emptyButton = `
          <button
            type="button"
            id="clear-task-filters"
            class="mt-2 text-emerald-700 dark:text-emerald-400 font-semibold text-xs hover:underline">
            Clear filters
          </button>
        `;
      }
    }

    tbody.innerHTML = `
      <tr>
        <td colspan="9" class="py-16 text-center">
          <div class="task-empty-state">
            <i data-lucide="${emptyIcon}" class="w-8 h-8 ${isCompletedTab ? 'text-emerald-600' : 'text-gray-400'}"></i>
            <strong>${emptyTitle}</strong>
            <span>${emptySub}</span>
            ${emptyButton}
          </div>
        </td>
      </tr>
    `;

    getEl('clear-task-filters')?.addEventListener('click', resetFilters);
    getEl('empty-add-task-btn')?.addEventListener('click', openAddTask);

    const resultLabel = getEl('task-results-label');
    if (resultLabel) {
      resultLabel.textContent = isCompletedTab ? 'Showing 0 completed tasks' : 'Showing 0 active tasks';
    }

    renderPagination(0);

    if (window.lucide) {
      window.lucide.createIcons();
    }

    return;
  }

  tbody.innerHTML =
    visible
      .map(
        task => {

          const progress = resolveTaskProgress(task);

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

          const rawStatus = task.system_status || task.status;

          const status =
            STATUS_LABELS[
              rawStatus
            ] ||
            capitalizeSafe(
              rawStatus
            );

          const type =
            TYPE_LABELS[
              task.type
            ] ||
            task.type ||
            'Other';

          const isCompleted =
            String(rawStatus) === 'completed' ||
            progress >= 100;

          const accent =
            isCompleted
              ? 'bg-emerald-300 dark:bg-emerald-700/50'
              : task.urgency === 'overdue'
                ? 'bg-red-500'
                : Number(task.smart_priority_score) >= 70
                  ? 'bg-emerald-600'
                  : task.priority === 'high'
                    ? 'bg-amber-500'
                    : 'bg-blue-500';

          const deadlineClass =
            isCompleted
              ? 'text-gray-400 dark:text-gray-500'
              : task.urgency === 'overdue'
                ? 'text-red-600 dark:text-red-400'
                : task.urgency === 'due_soon'
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

                  ${
                    isCompleted
                      ? `
                        <div class="flex items-center gap-1 text-[10px] text-green-600 dark:text-green-400 font-semibold mt-1">
                          <i data-lucide="check-circle-2" class="w-3 h-3"></i> Completed
                        </div>
                      `
                      : `
                        <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                          ${task.remaining_hours !== undefined && task.remaining_hours !== null && Number(task.remaining_hours) > 0
                            ? `<span class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-slate-100 dark:bg-white/5 text-slate-600 dark:text-slate-300" title="Estimated remaining study time"><i data-lucide="clock" class="w-3 h-3"></i> ~${task.remaining_hours} hrs left</span>`
                            : ''}
                          ${task.task_risk && task.task_risk !== 'low'
                            ? `<span class="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded ${task.task_risk === 'critical' ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' : task.task_risk === 'high' ? 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'}" title="Priority evaluation: ${task.task_risk_score || ''}"><i data-lucide="alert-triangle" class="w-3 h-3"></i> Needs attention</span>`
                            : ''}
                          ${task.recommended_action
                            ? `<span class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300 truncate max-w-[280px]" title="${esc(task.priority_reason || task.recommended_action)}"><i data-lucide="sparkles" class="w-3 h-3 shrink-0 text-emerald-600 dark:text-emerald-400"></i> ${esc(task.recommended_action)}</span>`
                            : ''}
                        </div>
                      `
                  }

                  ${
                    Number(task.focused_seconds || task.total_focused_seconds || 0) > 0
                      ? `
                        <div class="mt-1">
                          <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" title="Total focused study time recorded">
                            <i data-lucide="timer" class="w-3 h-3"></i> ${formatDurationHuman(Number(task.focused_seconds || task.total_focused_seconds || 0))} focused
                          </span>
                        </div>
                      `
                      : ''
                  }

                </div>

              </div>

            </td>


            <!-- Course -->
            <td class="py-3.5 px-3">

              ${
                task.course_id && task.course_code
                  ? `
                    <a href="courses.php" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold hover:opacity-85 transition-opacity"
                       style="background-color: ${esc(task.course_color || '#059669')}18; color: ${esc(task.course_color || '#059669')}; border: 1px solid ${esc(task.course_color || '#059669')}35;"
                       title="${esc(task.course_name || task.course_code)}">
                      <span class="w-1.5 h-1.5 rounded-full shrink-0" style="background-color: ${esc(task.course_color || '#059669')};"></span>
                      ${esc(task.course_code)}
                    </a>
                    ${
                      task.course_name
                        ? `
                          <div
                            class="text-[10px]
                                   text-[#78918B]
                                   dark:text-gray-500
                                   mt-1 truncate
                                   max-w-[120px]"
                            title="${esc(task.course_name)}">
                            ${esc(task.course_name)}
                          </div>
                        `
                        : ''
                    }
                  `
                  : `
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium text-gray-500 bg-gray-100 dark:bg-white/5 dark:text-gray-400">
                      General
                    </span>
                  `
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

              ${
                !isCompleted && task.smart_priority_label
                  ? `
                    <div
                      class="text-[10px] text-[#78918B] dark:text-gray-400 font-medium mt-0.5"
                      title="Priority score: ${Math.round(task.smart_priority_score || 0)}/100">
                      ${task.smart_priority_score >= 70 ? 'Study this first' : esc(task.smart_priority_label || 'Normal')}
                    </div>
                  `
                  : ''
              }

            </td>


            <!-- Deadline -->
            <td
              class="py-3.5 px-3"
              data-task-deadline-id="${esc(task.id)}">

              <a
                href="deadlines.php"
                class="text-xs font-semibold
                       text-[#315B52]
                       dark:text-gray-300
                       hover:text-emerald-700
                       dark:hover:text-emerald-400
                       whitespace-nowrap block transition-colors"
                title="View in Deadlines">

                ${dateText}

              </a>

              <div
                class="text-[10px]
                       font-medium mt-1
                       ${deadlineClass}"
                data-task-countdown>

                ${isCompleted ? 'Finished' : `${timeText} · ${esc(task.due_label || '')}`}

              </div>

              ${
                !isCompleted && (task.deadline_pressure === 'urgent' || task.deadline_pressure === 'critical')
                  ? `
                    <span class="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 mt-1 rounded ${task.deadline_pressure === 'critical' ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'}">
                      <i data-lucide="flame" class="w-2.5 h-2.5"></i> ${esc(capitalizeSafe(task.deadline_pressure))} Pressure
                    </span>
                  `
                  : ''
              }

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

                  ${formatProgressPercent(progress)}

                </span>

              </div>

            </td>


            <!-- Status -->
            <td class="py-3.5 px-3">

              <span
                class="task-status-pill
                       ${
                         STATUS_CLASSES[
                           rawStatus
                         ] ||
                         STATUS_CLASSES[
                           task.status
                         ] ||
                         STATUS_CLASSES.not_started
                       }">

                ${esc(status)}

              </span>
              ${task.progress_discrepancy ? `
                <div class="text-[10px] text-amber-600 dark:text-amber-400 font-medium mt-1 flex items-center gap-1" title="${esc(task.discrepancy_note || 'Progress discrepancy detected')}">
                  <i data-lucide="alert-triangle" class="w-3 h-3 shrink-0"></i> Discrepancy
                </div>
              ` : ''}

            </td>


            <!-- Actions -->
            <td
              class="py-3.5 px-3
                     whitespace-nowrap
                     text-center">

              ${isCompleted ? `
                <button
                  type="button"
                  class="task-action-btn undo-task-btn text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 mr-1"
                  data-id="${esc(task.id)}"
                  title="Undo completion (move back to active)"
                  aria-label="Undo completion for ${esc(task.title)}">
                  <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                </button>
              ` : `
                <button
                  type="button"
                  class="task-action-btn complete-task-btn text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 mr-1"
                  data-id="${esc(task.id)}"
                  title="Mark task completed"
                  aria-label="Mark completed ${esc(task.title)}">
                  <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                </button>

                <button
                  type="button"
                  class="task-action-btn text-emerald-700 dark:text-emerald-300 timer-task-btn"
                  data-work-item-type="task"
                  data-work-item-id="${esc(task.id)}"
                  data-work-item-title="${esc(task.title)}"
                  title="Start or pause timer">
                  <i data-lucide="timer" class="w-4 h-4"></i>
                </button>

                <button
                  type="button"
                  class="task-action-btn focus-task-btn mr-1"
                  data-id="${esc(task.id)}"
                  title="Focus on this task"
                  aria-label="Focus on ${esc(task.title)}">
                  <i data-lucide="crosshair" class="w-4 h-4"></i>
                </button>
              `}

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


  /* Undo / Reopen buttons */
  tbody
    .querySelectorAll('.undo-task-btn')
    .forEach(btn => {
      btn.addEventListener('click', () => handleUndoComplete(btn.dataset.id));
    });

  /* Complete task buttons */
  tbody
    .querySelectorAll('.complete-task-btn')
    .forEach(btn => {
      btn.addEventListener('click', () => openCompleteTaskConfirmModal(btn.dataset.id));
    });

  /* Timer buttons on rows */
  tbody
    .querySelectorAll('.timer-task-btn')
    .forEach(btn => {
      btn.addEventListener('click', async () => {
        const taskId = btn.dataset.workItemId || btn.dataset.id;
        if (!taskId) return;
        if (CURRENT_FOCUS_SESSION && String(CURRENT_FOCUS_SESSION.task_id) === String(taskId)) {
          if (CURRENT_FOCUS_SESSION.status === 'running') {
            await handleTimerPause();
          } else if (CURRENT_FOCUS_SESSION.status === 'paused') {
            await handleTimerResume();
          }
        } else {
          await selectTaskForTimer(taskId, true);
          if (!CURRENT_FOCUS_SESSION) {
            await handleTimerStart();
          }
        }
      });
    });

  /* Focus buttons */

  tbody
    .querySelectorAll(
      '.focus-task-btn'
    )
    .forEach(
      btn => {

        btn.addEventListener(
          'click',
          () =>
            selectTaskForTimer(
              btn.dataset.id,
              true
            )
        );

      }
    );


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


  const total = displayTasks.length;
  const start = total > 0 ? (CURRENT_PAGE - 1) * PAGE_SIZE + 1 : 0;
  const end = Math.min(CURRENT_PAGE * PAGE_SIZE, total);

  const resultLabel = getEl('task-results-label');
  if (resultLabel) {
    resultLabel.textContent = isCompletedTab
      ? `Showing ${start}–${end} of ${total} completed tasks`
      : `Showing ${start}–${end} of ${total} active tasks`;
  }

  renderPagination(total);

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
                       mt-0.5 truncate">

                ${esc(
                  task.course_code ||
                  'No course'
                )}
                ${task.remaining_hours !== undefined && task.remaining_hours !== null && Number(task.remaining_hours) > 0 ? ` • ${task.remaining_hours}h left` : ''}

              </div>

              ${task.recommended_action ? `
                <div class="text-[10px] text-emerald-700 dark:text-emerald-400 font-medium truncate mt-0.5 flex items-center gap-1" title="${esc(task.recommended_action)}">
                  <i data-lucide="sparkles" class="w-2.5 h-2.5 shrink-0 text-emerald-600"></i> ${esc(task.recommended_action)}
                </div>
              ` : ''}

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
    ],

    [
      'filter-sort',
      'filter-sort-side'
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
    ],

    [
      'filter-sort-side',
      'filter-sort'
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
    'filter-sort',

    'filter-course-side',
    'filter-type-side',
    'filter-priority-side',
    'filter-sort-side',

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
    'filter-priority',
    'filter-sort'
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
    'filter-priority-side',
    'filter-sort-side'
  ].forEach(
    id => {

      getEl(id)
        ?.addEventListener(
          'change',
          () => {

            syncMainFiltersFromSide();

            CURRENT_PAGE =
              1;

            loadTasks();

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

  const progressBadge =
    getEl(
      'task-modal-progress-badge'
    );
  if (progressBadge) {
    progressBadge.textContent = '0%';
  }

  const progressBar =
    getEl(
      'task-modal-progress-bar'
    );
  if (progressBar) {
    progressBar.style.width = '0%';
  }

  const focusedTimeEl =
    getEl(
      'task-modal-focused-time'
    );
  if (focusedTimeEl) {
    focusedTimeEl.textContent = '0m';
  }

  const remainingWorkEl =
    getEl(
      'task-modal-remaining-work'
    );
  if (remainingWorkEl) {
    remainingWorkEl.textContent = '—';
  }

  const focusActionEl =
    getEl(
      'task-modal-focus-action'
    );
  if (focusActionEl) {
    focusActionEl.classList.add('hidden');
  }

  const modalTitle =
    getEl(
      'task-modal-title'
    );

  if (modalTitle) {

    modalTitle.textContent =
      'Add Task';
  }

  const intelBlock =
    getEl(
      'task-modal-intelligence'
    );

  if (intelBlock) {
    intelBlock.classList.add(
      'hidden'
    );
  }

  const timeTrackingBlock =
    getEl(
      'task-modal-time-tracking'
    );

  if (timeTrackingBlock) {
    timeTrackingBlock.classList.add(
      'hidden'
    );
  }
}


async function openAddTask(defaultCourseId = null) {

  resetTaskForm();

  // If defaultCourseId is a DOM Event (e.g. click handler), ignore it
  let sanitizedCourseId = null;
  if (typeof defaultCourseId === 'string' || typeof defaultCourseId === 'number') {
    sanitizedCourseId = String(defaultCourseId);
  }

  if (!COURSES_CACHE || COURSES_CACHE.length === 0) {
    await loadCourseOptions();
  } else {
    populateCourseDropdowns();
  }

  const form = getEl('task-form');
  if (form && form.elements.course_id) {
    const courseToSelect = sanitizedCourseId 
      || getEl('filter-course')?.value 
      || getEl('filter-course-side')?.value 
      || new URLSearchParams(window.location.search).get('course_id') 
      || '';

    if (courseToSelect && Array.from(form.elements.course_id.options).some(o => o.value === String(courseToSelect))) {
      form.elements.course_id.value = String(courseToSelect);
    }
  }

  openModal();
}


/* =========================================================
   EDIT TASK
========================================================= */

async function openEditTask(id) {

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

  if (!COURSES_CACHE || COURSES_CACHE.length === 0) {
    await loadCourseOptions();
  } else {
    populateCourseDropdowns();
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
    if (task.course_id) {
      const courseIdStr = String(task.course_id);
      const hasOption = Array.from(form.elements.course_id.options).some(o => o.value === courseIdStr);
      if (!hasOption) {
        const opt = document.createElement('option');
        opt.value = courseIdStr;
        opt.textContent = `${task.course_code || 'Course'} — ${task.course_name || ''}`;
        form.elements.course_id.appendChild(opt);
      }
      form.elements.course_id.value = courseIdStr;
    } else {
      form.elements.course_id.value = '';
    }
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

  const progress = resolveTaskProgress(task);

  const progressBadge =
    getEl(
      'task-modal-progress-badge'
    );
  if (progressBadge) {
    progressBadge.textContent = formatProgressPercent(progress);
  }

  const progressBar =
    getEl(
      'task-modal-progress-bar'
    );
  if (progressBar) {
    progressBar.style.width = `${progress}%`;
  }

  const focusedTimeEl =
    getEl(
      'task-modal-focused-time'
    );
  if (focusedTimeEl) {
    const sec = Number(task.focused_seconds) || 0;
    focusedTimeEl.textContent = formatDurationHuman(sec);
  }

  const remainingWorkEl =
    getEl(
      'task-modal-remaining-work'
    );
  if (remainingWorkEl) {
    const rem = task.remaining_hours !== undefined && task.remaining_hours !== null ? task.remaining_hours : 0;
    remainingWorkEl.textContent = `${rem}h`;
  }

  const focusActionEl =
    getEl(
      'task-modal-focus-action'
    );
  if (focusActionEl) {
    focusActionEl.classList.remove('hidden');
  }

  const modalTitle =
    getEl(
      'task-modal-title'
    );

  if (modalTitle) {

    modalTitle.textContent =
      'Edit Task';
  }

  const intelBlock =
    getEl(
      'task-modal-intelligence'
    );

  if (intelBlock) {
    const isCompleted =
      String(task.system_status || task.status) === 'completed' ||
      progress >= 100;

    intelBlock.classList.remove('hidden');

    const priorityLabelEl =
      getEl('task-modal-smart-priority');
    const reasonEl =
      getEl('task-modal-reason');
    const workloadEl =
      getEl('task-modal-workload');
    const riskEl =
      getEl('task-modal-risk');
    const actionEl =
      getEl('task-modal-action');

    if (isCompleted) {
      if (priorityLabelEl) {
        priorityLabelEl.textContent = 'Status: Completed';
      }
      if (reasonEl) {
        reasonEl.textContent = 'This task is fully completed. No remaining academic workload or deadline pressure.';
      }
      if (workloadEl) {
        workloadEl.innerHTML = '<i data-lucide="check-circle-2" class="w-3 h-3 inline mr-1 text-green-600"></i>0h remaining';
      }
      if (riskEl) {
        riskEl.innerHTML = '<i data-lucide="shield-check" class="w-3 h-3 inline mr-1 text-green-600"></i>No Risk';
      }
      if (actionEl) {
        actionEl.innerHTML = '<i data-lucide="check" class="w-3 h-3 inline mr-1 text-green-600"></i>Completed';
      }
    } else {
      if (priorityLabelEl) {
        const pLabel = esc(task.smart_priority_label || capitalizeSafe(task.priority || 'Medium'));
        priorityLabelEl.textContent = Number(task.smart_priority_score) >= 70 ? 'Study this first' : `${pLabel} Priority`;
      }
      if (reasonEl) {
        reasonEl.textContent = task.priority_reason || 'Academic priority evaluated based on deadline, course weight, and required prep.';
      }
      if (workloadEl) {
        const rem = task.remaining_hours !== undefined && task.remaining_hours !== null ? task.remaining_hours : 0;
        workloadEl.innerHTML = `<i data-lucide="clock" class="w-3 h-3 inline mr-1"></i>${rem > 0 ? `~${rem} hrs left` : 'Under 1 hr left'}`;
      }
      if (riskEl) {
        const isUrgent = (task.task_risk === 'high' || task.task_risk === 'critical');
        riskEl.innerHTML = `<i data-lucide="${isUrgent ? 'alert-triangle' : 'shield-check'}" class="w-3 h-3 inline mr-1"></i>${isUrgent ? 'Needs attention' : 'On track'}`;
      }
      if (actionEl) {
        actionEl.innerHTML = `<i data-lucide="sparkles" class="w-3 h-3 inline mr-1"></i>Action: ${esc(task.recommended_action || 'Review and take action')}`;
      }
    }

    if (window.lucide) {
      window.lucide.createIcons();
    }
  }

  if (typeof studySessionApi === 'function') {
    studySessionApi('GET', { task_id: task.id }).then(res => {
      if (res.ok && res.data) {
        const d = res.data;
        const livePct = d.time_progress?.time_progress_percent !== undefined
          ? d.time_progress.time_progress_percent
          : progress;
        const liveProgress = Math.min(100, Math.max(0, Number(livePct) || 0));
        if (progressBadge) {
          progressBadge.textContent = `${liveProgress}%`;
        }
        if (progressBar) {
          progressBar.style.width = `${liveProgress}%`;
        }
        if (focusedTimeEl) {
          focusedTimeEl.textContent = formatDurationHuman(d.total_focused_seconds || 0);
        }
        if (remainingWorkEl) {
          const remH = d.time_progress?.time_remaining_hours !== undefined
            ? d.time_progress.time_remaining_hours
            : (task.remaining_hours || 0);
          remainingWorkEl.textContent = `${remH}h`;
        }
      }
    }).catch(() => {});
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


      if ('progress_percent' in payload && payload.progress_percent !== '') {
        payload.progress_percent = Number(payload.progress_percent || 0);
      } else {
        delete payload.progress_percent;
      }


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

/* =========================================================
   UNIVERSAL WORK & ASSIGNMENTS IMPORT
========================================================= */

function bindImport() {
  const openBtn = getEl('open-import-task');
  const modal = getEl('work-import-modal');
  const closeBtn = getEl('close-work-import-modal');
  const cancelBtn = getEl('cancel-work-import');
  const dropzone = getEl('work-dropzone');
  const fileInput = getEl('work-file-input');
  const fileChosen = getEl('work-file-chosen');
  const textInput = getEl('work-text-input');
  const extractBtn = getEl('extract-work-btn');
  const errorBox = getEl('work-import-error');
  const stepUpload = getEl('work-import-step-upload');
  const stepReview = getEl('work-import-step-review');
  const reviewTbody = getEl('work-review-tbody');
  const reviewCount = getEl('work-review-count');
  const reviewError = getEl('work-review-error');
  const backBtn = getEl('back-work-btn');
  const cancelReviewBtn = getEl('cancel-review-work');
  const confirmBtn = getEl('confirm-import-work-btn');
  const addItemBtn = getEl('add-review-work-btn');
  const manualFallbackBtn = getEl('open-manual-work-fallback');

  if (!openBtn || !modal) return;

  let selectedFile = null;
  let reviewedItems = [];

  function esc(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function openModal() {
    selectedFile = null;
    if (fileInput) fileInput.value = '';
    if (textInput) textInput.value = '';
    if (fileChosen) { fileChosen.textContent = ''; fileChosen.classList.add('hidden'); }
    if (errorBox) { errorBox.innerHTML = ''; errorBox.classList.add('hidden'); }
    if (reviewError) { reviewError.innerHTML = ''; reviewError.classList.add('hidden'); }
    stepUpload?.classList.remove('hidden');
    stepReview?.classList.add('hidden');
    modal.classList.remove('hidden');
    if (window.lucide) window.lucide.createIcons();
  }

  function closeModal() {
    modal.classList.add('hidden');
    selectedFile = null;
  }

  openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (cancelReviewBtn) cancelReviewBtn.addEventListener('click', closeModal);

  modal.addEventListener('click', e => {
    if (e.target === modal) closeModal();
  });

  if (backBtn) {
    backBtn.addEventListener('click', () => {
      stepReview?.classList.add('hidden');
      stepUpload?.classList.remove('hidden');
      if (window.lucide) window.lucide.createIcons();
    });
  }

  if (manualFallbackBtn) {
    manualFallbackBtn.addEventListener('click', () => {
      closeModal();
      getEl('open-add-task')?.click();
    });
  }

  // Dropzone setup
  if (dropzone && fileInput) {
    dropzone.addEventListener('click', e => {
      if (e.target !== fileInput) fileInput.click();
    });
    fileInput.addEventListener('change', () => {
      if (fileInput.files?.length) {
        selectedFile = fileInput.files[0];
        if (fileChosen) {
          fileChosen.textContent = `Selected: ${selectedFile.name} (${Math.round(selectedFile.size / 1024)} KB)`;
          fileChosen.classList.remove('hidden');
        }
      }
    });
    dropzone.addEventListener('dragover', e => {
      e.preventDefault();
      dropzone.classList.add('border-emerald-500', 'bg-emerald-50/40');
    });
    dropzone.addEventListener('dragleave', () => {
      dropzone.classList.remove('border-emerald-500', 'bg-emerald-50/40');
    });
    dropzone.addEventListener('drop', e => {
      e.preventDefault();
      dropzone.classList.remove('border-emerald-500', 'bg-emerald-50/40');
      if (e.dataTransfer.files?.length) {
        selectedFile = e.dataTransfer.files[0];
        if (fileChosen) {
          fileChosen.textContent = `Selected: ${selectedFile.name} (${Math.round(selectedFile.size / 1024)} KB)`;
          fileChosen.classList.remove('hidden');
        }
      }
    });
  }

  const TASK_TYPES = [
    { val: 'assignment', label: 'Assignment' },
    { val: 'project', label: 'Project' },
    { val: 'test', label: 'Test / Quiz' },
    { val: 'exam', label: 'Exam' },
    { val: 'lab_report', label: 'Lab Report' },
    { val: 'research', label: 'Research' },
    { val: 'study_session', label: 'Study Session' },
    { val: 'other', label: 'Other' }
  ];

  const PRIORITIES = [
    { val: 'high', label: 'High' },
    { val: 'medium', label: 'Medium' },
    { val: 'low', label: 'Low' }
  ];

  function renderReviewTable() {
    if (!reviewTbody) return;
    if (reviewCount) {
      reviewCount.textContent = `${reviewedItems.length} item${reviewedItems.length === 1 ? '' : 's'} ready for review`;
    }

    reviewTbody.innerHTML = reviewedItems.map((item, idx) => {
      const statusPill = item.already_exists
        ? `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 whitespace-nowrap">Exists (skip)</span>`
        : `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 whitespace-nowrap">New</span>`;

      const typeOpts = TASK_TYPES.map(t => `<option value="${t.val}" ${item.type === t.val ? 'selected' : ''}>${t.label}</option>`).join('');
      const priorityOpts = PRIORITIES.map(p => `<option value="${p.val}" ${item.priority === p.val ? 'selected' : ''}>${p.label}</option>`).join('');

      const cleanItemCode = String(item.course_code || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
      let selectedCourseId = item.course_id ? String(item.course_id) : '';
      if (!selectedCourseId && cleanItemCode && COURSES_CACHE.length > 0) {
        const found = COURSES_CACHE.find(c => String(c.code).toUpperCase().replace(/[^A-Z0-9]/g, '') === cleanItemCode);
        if (found) selectedCourseId = String(found.id);
      }

      const courseOpts = '<option value="">General / No course</option>' +
        COURSES_CACHE.map(c => `<option value="${esc(c.id)}" ${String(c.id) === selectedCourseId ? 'selected' : ''}>${esc(c.code)} — ${esc(c.name || c.title || '')}</option>`).join('');

      return `
        <tr data-index="${idx}" class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
          <td class="p-2">
            <input type="text" class="task-form-control text-xs p-1.5 min-h-[32px] font-medium w-full" data-field="title" placeholder="Task title" value="${esc(item.title || '')}" required>
          </td>
          <td class="p-2">
            <select class="task-form-control text-xs p-1.5 min-h-[32px] w-full" data-field="course_id">
              ${courseOpts}
            </select>
            <input type="hidden" data-field="course_code" value="${esc(item.course_code || '')}">
          </td>
          <td class="p-2">
            <select class="task-form-control text-xs p-1.5 min-h-[32px] w-full" data-field="type">
              ${typeOpts}
            </select>
          </td>
          <td class="p-2">
            <input type="date" class="task-form-control text-xs p-1.5 min-h-[32px] font-mono w-full" data-field="due_date" value="${esc(item.due_date || '')}">
          </td>
          <td class="p-2">
            <input type="time" class="task-form-control text-xs p-1.5 min-h-[32px] font-mono w-full" data-field="due_time" value="${esc((item.due_time || '23:59:00').substring(0, 5))}">
          </td>
          <td class="p-2">
            <select class="task-form-control text-xs p-1.5 min-h-[32px] w-full" data-field="priority">
              ${priorityOpts}
            </select>
          </td>
          <td class="p-2 text-center">${statusPill}</td>
          <td class="p-2 text-right">
            <button type="button" class="text-red-500 hover:text-red-700 p-1 remove-review-work-btn" title="Remove item">
              <i data-lucide="trash-2" class="w-4 h-4"></i>
            </button>
          </td>
        </tr>
      `;
    }).join('');

    reviewTbody.querySelectorAll('.remove-review-work-btn').forEach(btn => {
      btn.addEventListener('click', e => {
        const tr = e.target.closest('tr');
        const idx = Number(tr.dataset.index);
        reviewedItems.splice(idx, 1);
        renderReviewTable();
      });
    });

    if (window.lucide) window.lucide.createIcons();
  }

  if (addItemBtn) {
    addItemBtn.addEventListener('click', () => {
      reviewedItems.push({
        title: '',
        course_code: '',
        type: 'assignment',
        due_date: '',
        due_time: '23:59:00',
        priority: 'medium',
        already_exists: false
      });
      renderReviewTable();
      const inputs = reviewTbody?.querySelectorAll('input[data-field="title"]');
      if (inputs?.length) inputs[inputs.length - 1].focus();
    });
  }

  // Extract action
  if (extractBtn) {
    extractBtn.addEventListener('click', async () => {
      if (errorBox) { errorBox.innerHTML = ''; errorBox.classList.add('hidden'); }
      const textVal = (textInput?.value || '').trim();
      if (!selectedFile && !textVal) {
        if (errorBox) {
          errorBox.innerHTML = 'Please choose a document (.pdf, .docx, .txt) or paste coursework text.';
          errorBox.classList.remove('hidden');
        }
        return;
      }

      extractBtn.disabled = true;
      extractBtn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Extracting…`;
      if (window.lucide) window.lucide.createIcons();

      try {
        let res;
        if (selectedFile) {
          const fd = new FormData();
          fd.append('action', 'extract');
          fd.append('domain', 'work');
          fd.append('document', selectedFile);
          fd.append('csrf_token', window.CSRF_TOKEN || '');
          res = await fetch(`${API}/document_import.php`, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
          });
        } else {
          res = await fetch(`${API}/document_import.php`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
              action: 'extract',
              domain: 'work',
              text: textVal,
              csrf_token: window.CSRF_TOKEN || ''
            })
          });
        }

        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          let errMsg = data.error || "We couldn't extract academic work from this document.";
          if (data.is_scanned || data.error_code === 'SCANNED_PDF_NO_OCR') {
            errMsg = `<strong>Scanned PDF Detected:</strong> ${data.error} <div class="mt-2"><button type="button" onclick="document.getElementById('open-manual-work-fallback').click()" class="underline font-bold">Add Work Manually &rarr;</button></div>`;
          } else if (data.manual_entry) {
            errMsg = `${data.error} <div class="mt-2"><button type="button" onclick="document.getElementById('open-manual-work-fallback').click()" class="underline font-bold">Add Work Manually &rarr;</button></div>`;
          }
          if (errorBox) {
            errorBox.innerHTML = errMsg;
            errorBox.classList.remove('hidden');
          }
          return;
        }

        reviewedItems = Array.isArray(data.items) ? data.items : [];
        if (reviewedItems.length === 0) {
          if (errorBox) {
            errorBox.innerHTML = "No assignments or school work were identified. Try another document or add manually.";
            errorBox.classList.remove('hidden');
          }
          return;
        }

        renderReviewTable();
        stepUpload?.classList.add('hidden');
        stepReview?.classList.remove('hidden');
        if (window.lucide) window.lucide.createIcons();
      } catch (err) {
        console.error('Extract work error:', err);
        if (errorBox) {
          errorBox.innerHTML = 'An unexpected error occurred while reading the document.';
          errorBox.classList.remove('hidden');
        }
      } finally {
        extractBtn.disabled = false;
        extractBtn.innerHTML = `<i data-lucide="sparkles" class="w-4 h-4"></i> Extract School Work`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  // Confirm action
  if (confirmBtn) {
    confirmBtn.addEventListener('click', async () => {
      if (reviewError) { reviewError.innerHTML = ''; reviewError.classList.add('hidden'); }

      const rows = reviewTbody?.querySelectorAll('tr') || [];
      const itemsToSave = [];
      rows.forEach(tr => {
        const title = (tr.querySelector('input[data-field="title"]')?.value || '').trim();
        const courseIdVal = tr.querySelector('select[data-field="course_id"]')?.value || '';
        const code = (tr.querySelector('input[data-field="course_code"]')?.value || '').trim();
        const type = tr.querySelector('select[data-field="type"]')?.value || 'assignment';
        const dueDate = tr.querySelector('input[data-field="due_date"]')?.value || '';
        const dueTime = tr.querySelector('input[data-field="due_time"]')?.value || '23:59';
        const priority = tr.querySelector('select[data-field="priority"]')?.value || 'medium';

        if (title) {
          itemsToSave.push({
            title,
            course_id: courseIdVal ? Number(courseIdVal) : null,
            course_code: code,
            type,
            due_date: dueDate,
            due_time: dueTime.length === 5 ? dueTime + ':00' : dueTime,
            priority
          });
        }
      });

      if (itemsToSave.length === 0) {
        if (reviewError) {
          reviewError.textContent = 'Please provide at least one work item with a title.';
          reviewError.classList.remove('hidden');
        }
        return;
      }

      confirmBtn.disabled = true;
      confirmBtn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving…`;
      if (window.lucide) window.lucide.createIcons();

      try {
        const res = await fetch(`${API}/document_import.php`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({
            action: 'confirm',
            domain: 'work',
            items: itemsToSave,
            csrf_token: window.CSRF_TOKEN || ''
          })
        });

        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Failed to save school work.');
        }

        closeModal();
        if (typeof toast === 'function') {
          toast(data.message || `Successfully imported ${data.imported_count} work items!`, 'success');
        } else if (window.showToast) {
          window.showToast(data.message || `Successfully imported ${data.imported_count} work items!`, 'success');
        }
        await loadTasks();
      } catch (err) {
        console.error('Confirm work error:', err);
        if (reviewError) {
          reviewError.textContent = err.message || 'Error saving school work.';
          reviewError.classList.remove('hidden');
        }
      } finally {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = `<i data-lucide="check" class="w-3.5 h-3.5"></i> Confirm & Add Work`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }
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



/* =========================================================
   TASK COMPLETION CONFIRMATION & DELETION MODAL WORKFLOWS
========================================================= */

let PENDING_COMPLETE_TASK_ID = null;
let PENDING_DELETE_TASK_ID = null;

function openCompleteTaskConfirmModal(taskId = null) {
  if (taskId && typeof taskId === 'object') {
    taskId = null;
  }
  if (typeof IS_TIMER_BUSY !== 'undefined' && IS_TIMER_BUSY) return;

  const modal = getEl('complete-task-modal');
  if (!modal) {
    if (taskId) {
      executeTaskCompletion(taskId);
    } else if (typeof handleTimerComplete === 'function') {
      handleTimerComplete();
    }
    return;
  }

  let targetTaskId = taskId;
  if (!targetTaskId && typeof CURRENT_FOCUS_SESSION !== 'undefined' && CURRENT_FOCUS_SESSION) {
    targetTaskId = CURRENT_FOCUS_SESSION.task_id;
  }

  PENDING_COMPLETE_TASK_ID = targetTaskId;

  const matchedTask = (Array.isArray(ALL_TASKS) ? ALL_TASKS : []).find(t => String(t.id) === String(targetTaskId));
  const title = matchedTask?.title || (typeof CURRENT_FOCUS_SESSION !== 'undefined' ? CURRENT_FOCUS_SESSION?.task_title : null) || 'Work Item';
  const courseCode = matchedTask?.course_code || (typeof CURRENT_FOCUS_SESSION !== 'undefined' ? CURRENT_FOCUS_SESSION?.course_code : '') || '';

  let elapsedSec = 0;
  if (typeof CURRENT_FOCUS_SESSION !== 'undefined' && CURRENT_FOCUS_SESSION && String(CURRENT_FOCUS_SESSION.task_id) === String(targetTaskId)) {
    elapsedSec = CURRENT_FOCUS_SESSION.duration_seconds || CURRENT_FOCUS_SESSION.current_elapsed_seconds || 0;
  } else if (matchedTask) {
    elapsedSec = Number(matchedTask.total_focused_seconds || matchedTask.focused_seconds || 0);
  }

  const titleEl = getEl('complete-modal-task-title');
  if (titleEl) titleEl.textContent = title;

  const courseBadge = getEl('complete-modal-course-badge');
  if (courseBadge) {
    courseBadge.textContent = courseCode || 'General';
  }

  const timeBadge = getEl('complete-modal-time-badge');
  if (timeBadge) {
    timeBadge.textContent = elapsedSec > 0 ? `${formatDurationHuman(elapsedSec)} study time recorded` : 'No study timer recorded';
  }

  modal.classList.remove('hidden');
  if (window.lucide) window.lucide.createIcons();
}

function closeCompleteTaskConfirmModal() {
  const modal = getEl('complete-task-modal');
  if (modal) modal.classList.add('hidden');
  PENDING_COMPLETE_TASK_ID = null;
}

async function executeTaskCompletion(taskId) {
  if (!taskId) return;
  const btn = getEl('confirm-complete-task');
  setButtonBusy(btn, true, 'Completing…');

  try {
    if (typeof CURRENT_FOCUS_SESSION !== 'undefined' && CURRENT_FOCUS_SESSION && String(CURRENT_FOCUS_SESSION.task_id) === String(taskId)) {
      try {
        await studySessionApi('POST', {}, {
          action: 'complete',
          session_id: CURRENT_FOCUS_SESSION.id
        });
      } catch (sessErr) {
        console.warn('Could not complete study session along with task:', sessErr);
      }
      if (typeof stopFocusTicker === 'function') stopFocusTicker();
      CURRENT_FOCUS_SESSION = null;
      const digitsEl = getEl('timer-digits');
      if (digitsEl) digitsEl.textContent = '00:00:00';
      if (typeof setTimerUIState === 'function') setTimerUIState('idle');
      const select = getEl('timer-task-select');
      if (select) select.disabled = false;
    }

    await apiJson(`${API}/tasks.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: taskId,
        status: 'completed',
        progress_percent: 100,
        csrf_token: window.CSRF_TOKEN || ''
      })
    });

    toast('Work marked as completed.', 'success');
    closeCompleteTaskConfirmModal();
    await loadTasks();
  } catch (err) {
    console.error('Task completion error:', err);
    toast(err.message || 'Could not complete task.', 'error');
  } finally {
    setButtonBusy(btn, false, 'Yes, Complete Task');
    PENDING_COMPLETE_TASK_ID = null;
  }
}

async function handleUndoComplete(taskId) {
  if (!taskId) return;
  try {
    await apiJson(`${API}/tasks.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: taskId,
        status: 'pending',
        progress_percent: 0,
        csrf_token: window.CSRF_TOKEN || ''
      })
    });
    toast('Task moved back to active work.', 'info');
    await loadTasks();
  } catch (err) {
    console.error('Task undo error:', err);
    toast(err.message || 'Could not reopen task.', 'error');
  }
}

function openDeleteCompletedTaskModal(taskId) {
  if (!taskId) return;
  PENDING_DELETE_TASK_ID = taskId;
  const modal = getEl('delete-task-modal');
  if (!modal) {
    if (confirm('Delete this completed work? This cannot be undone.')) {
      executeDeleteCompletedTask(taskId);
    }
    return;
  }
  const matched = (Array.isArray(ALL_TASKS) ? ALL_TASKS : []).find(t => String(t.id) === String(taskId));
  const titleEl = getEl('delete-modal-task-title');
  if (titleEl) titleEl.textContent = matched?.title || 'Completed Task';
  const badgeEl = getEl('delete-modal-course-badge');
  if (badgeEl) badgeEl.textContent = matched?.course_code || 'General';
  const timeEl = getEl('delete-modal-time-badge');
  if (timeEl) {
    const sec = Number(matched?.total_focused_seconds || matched?.focused_seconds || 0);
    timeEl.textContent = sec > 0 ? `${formatDurationHuman(sec)} recorded` : '';
  }
  modal.classList.remove('hidden');
  if (window.lucide) window.lucide.createIcons();
}

function closeDeleteCompletedTaskModal() {
  const modal = getEl('delete-task-modal');
  if (modal) modal.classList.add('hidden');
  PENDING_DELETE_TASK_ID = null;
}

async function executeDeleteCompletedTask(taskId) {
  if (!taskId) return;
  const btn = getEl('confirm-delete-task');
  setButtonBusy(btn, true, 'Deleting…');
  try {
    await apiJson(`${API}/tasks.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `id=${encodeURIComponent(taskId)}&csrf_token=${encodeURIComponent(window.CSRF_TOKEN || '')}`
    });
    toast('Task deleted.', 'success');
    closeDeleteCompletedTaskModal();
    await loadTasks();
  } catch (err) {
    console.error('Delete completed task error:', err);
    toast(err.message || 'Could not delete task.', 'error');
  } finally {
    setButtonBusy(btn, false, 'Delete');
    PENDING_DELETE_TASK_ID = null;
  }
}

function bindCompletionAndDeletionEvents() {
  getEl('cancel-complete-task')?.addEventListener('click', closeCompleteTaskConfirmModal);
  getEl('confirm-complete-task')?.addEventListener('click', () => {
    if (PENDING_COMPLETE_TASK_ID) {
      executeTaskCompletion(PENDING_COMPLETE_TASK_ID);
    } else {
      closeCompleteTaskConfirmModal();
    }
  });

  getEl('complete-task-modal')?.addEventListener('click', e => {
    if (e.target === getEl('complete-task-modal')) closeCompleteTaskConfirmModal();
  });

  getEl('cancel-delete-task')?.addEventListener('click', closeDeleteCompletedTaskModal);
  getEl('confirm-delete-task')?.addEventListener('click', () => {
    if (PENDING_DELETE_TASK_ID) {
      executeDeleteCompletedTask(PENDING_DELETE_TASK_ID);
    } else {
      closeDeleteCompletedTaskModal();
    }
  });

  getEl('delete-task-modal')?.addEventListener('click', e => {
    if (e.target === getEl('delete-task-modal')) closeDeleteCompletedTaskModal();
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      if (!getEl('complete-task-modal')?.classList.contains('hidden')) {
        closeCompleteTaskConfirmModal();
      }
      if (!getEl('delete-task-modal')?.classList.contains('hidden')) {
        closeDeleteCompletedTaskModal();
      }
    }
  });
}

/* =========================================================
   FOCUS TIMER (FOUNDATION 8B)
========================================================= */

let CURRENT_FOCUS_SESSION = null;
let TIMER_SELECTED_TASK_ID = null;
let FOCUS_TICKER_INTERVAL = null;
let FOCUS_CLIENT_START_TIME = 0;
let FOCUS_BASE_ELAPSED_SEC = 0;
let IS_TIMER_BUSY = false;

function formatHms(totalSeconds) {
  const s = Math.max(0, Math.floor(Number(totalSeconds) || 0));
  const hrs = Math.floor(s / 3600);
  const mins = Math.floor((s % 3600) / 60);
  const secs = s % 60;
  return `${String(hrs).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function formatDurationHuman(totalSeconds) {
  const s = Math.max(0, Math.floor(Number(totalSeconds) || 0));
  if (s < 60) {
    return `${s}s`;
  }
  const mins = Math.floor(s / 60);
  if (mins < 60) {
    return `${mins}m`;
  }
  const hrs = Math.floor(mins / 60);
  const remMins = mins % 60;
  return remMins > 0 ? `${hrs}h ${remMins}m` : `${hrs}h`;
}

function setTimerFeedback(message, type = 'info') {
  const el = getEl('timer-feedback');
  if (!el) return;
  if (!message) {
    el.className = 'hidden';
    el.textContent = '';
    return;
  }
  let colorClasses = 'bg-blue-50 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300 border border-blue-200 dark:border-blue-800/40';
  if (type === 'success') {
    colorClasses = 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40';
  } else if (type === 'error') {
    colorClasses = 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300 border border-red-200 dark:border-red-800/40';
  } else if (type === 'warning') {
    colorClasses = 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800/40';
  }
  el.className = `mb-3 text-xs px-3 py-2 rounded-lg ${colorClasses}`;
  el.textContent = message;
}

async function studySessionApi(method, params = {}, body = null) {
  let url = `${API}/study-sessions.php`;
  if (method === 'GET' && Object.keys(params).length) {
    url += '?' + new URLSearchParams(params).toString();
  }
  const options = {
    method,
    headers: {
      Accept: 'application/json'
    }
  };
  if (body) {
    options.headers['Content-Type'] = 'application/json';
    options.headers['X-CSRF-Token'] = window.CSRF_TOKEN || '';
    if (!body.csrf_token) {
      body.csrf_token = window.CSRF_TOKEN || '';
    }
    options.body = JSON.stringify(body);
  }
  const response = await fetch(url, {
    credentials: 'same-origin',
    ...options
  });
  const data = await response.json().catch(() => ({}));
  return {
    ok: response.ok,
    status: response.status,
    data
  };
}

function startFocusTicker(baseSeconds) {
  clearInterval(FOCUS_TICKER_INTERVAL);
  FOCUS_BASE_ELAPSED_SEC = Math.max(0, Math.floor(Number(baseSeconds) || 0));
  FOCUS_CLIENT_START_TIME = Date.now();
  const digitsEl = getEl('timer-digits');
  if (digitsEl) {
    digitsEl.textContent = formatHms(FOCUS_BASE_ELAPSED_SEC);
  }
  FOCUS_TICKER_INTERVAL = setInterval(() => {
    const elapsedNow = FOCUS_BASE_ELAPSED_SEC + Math.floor((Date.now() - FOCUS_CLIENT_START_TIME) / 1000);
    if (digitsEl) {
      digitsEl.textContent = formatHms(elapsedNow);
    }
  }, 1000);
}

function stopFocusTicker() {
  if (FOCUS_TICKER_INTERVAL) {
    clearInterval(FOCUS_TICKER_INTERVAL);
    FOCUS_TICKER_INTERVAL = null;
  }
}

function setTimerUIState(status) {
  const card = getEl('focus-timer-card');
  const badge = getEl('timer-status-badge');
  const dot = getEl('timer-status-dot');
  const text = getEl('timer-status-text');
  const iconWrap = getEl('timer-icon-wrap');
  const sub = getEl('timer-digits-sub');
  const btnStart = getEl('timer-btn-start');
  const activeControls = getEl('timer-active-controls');
  const btnPause = getEl('timer-btn-pause');
  const btnResume = getEl('timer-btn-resume');
  const btnStop = getEl('timer-btn-stop');
  const btnComplete = getEl('timer-btn-complete');
  const select = getEl('timer-task-select');

  if (card) {
    card.classList.remove('is-running', 'is-paused');
  }

  if (status === 'running') {
    if (card) card.classList.add('is-running');
    if (badge) badge.className = 'inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300';
    if (dot) dot.className = 'w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse';
    if (text) text.textContent = 'Running';
    if (iconWrap) iconWrap.className = 'w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-400 flex items-center justify-center transition-all animate-pulse';
    if (sub) sub.textContent = 'Active Session Elapsed';
    if (btnStart) btnStart.classList.add('hidden');
    if (activeControls) activeControls.classList.remove('hidden');
    if (btnPause) btnPause.classList.remove('hidden');
    if (btnResume) btnResume.classList.add('hidden');
    if (btnStop) btnStop.classList.remove('hidden');
    if (btnComplete) btnComplete.classList.remove('hidden');
    if (select) select.disabled = true;
  } else if (status === 'paused') {
    if (card) card.classList.add('is-paused');
    if (badge) badge.className = 'inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300';
    if (dot) dot.className = 'w-1.5 h-1.5 rounded-full bg-amber-500';
    if (text) text.textContent = 'Paused';
    if (iconWrap) iconWrap.className = 'w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400 flex items-center justify-center transition-all';
    if (sub) sub.textContent = 'Session Paused';
    if (btnStart) btnStart.classList.add('hidden');
    if (activeControls) activeControls.classList.remove('hidden');
    if (btnPause) btnPause.classList.remove('hidden');
    if (btnResume) btnResume.classList.remove('hidden');
    if (btnStop) btnStop.classList.remove('hidden');
    if (btnComplete) btnComplete.classList.remove('hidden');
    if (select) select.disabled = true;
  } else {
    // idle
    if (badge) badge.className = 'inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300';
    if (dot) dot.className = 'w-1.5 h-1.5 rounded-full bg-gray-400';
    if (text) text.textContent = 'Idle';
    if (iconWrap) iconWrap.className = 'w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 flex items-center justify-center transition-all';
    if (sub) sub.textContent = 'Session Duration';
    if (btnStart) {
      btnStart.classList.remove('hidden');
      btnStart.disabled = !TIMER_SELECTED_TASK_ID;
    }
    if (activeControls) activeControls.classList.add('hidden');
    if (select) select.disabled = false;
  }
  if (window.lucide) {
    window.lucide.createIcons();
  }
}

function populateTimerTaskDropdown() {
  const select = getEl('timer-task-select');
  if (!select) return;

  const currentVal = select.value || (TIMER_SELECTED_TASK_ID ? String(TIMER_SELECTED_TASK_ID) : '');

  select.innerHTML = '<option value="">— Select a task to focus —</option>';

  const allList = Array.isArray(ALL_TASKS) ? ALL_TASKS : [];
  const pendingOrInProgress = allList.filter(t => String(t.system_status || t.status || '').toLowerCase() !== 'completed');
  const completed = allList.filter(t => String(t.system_status || t.status || '').toLowerCase() === 'completed');

  const countHint = getEl('timer-task-count-hint');
  if (countHint) {
    countHint.textContent = `${pendingOrInProgress.length} active`;
  }

  if (pendingOrInProgress.length) {
    const optgroup = document.createElement('optgroup');
    optgroup.label = 'Active & Pending Tasks';
    pendingOrInProgress.forEach(t => {
      const opt = document.createElement('option');
      opt.value = String(t.id);
      const prefix = t.course_code ? `[${t.course_code}] ` : '';
      opt.textContent = `${prefix}${t.title}`;
      optgroup.appendChild(opt);
    });
    select.appendChild(optgroup);
  }

  if (completed.length) {
    const optgroup = document.createElement('optgroup');
    optgroup.label = 'Completed Tasks';
    completed.forEach(t => {
      const opt = document.createElement('option');
      opt.value = String(t.id);
      const prefix = t.course_code ? `[${t.course_code}] ` : '';
      opt.textContent = `${prefix}${t.title} (Completed)`;
      optgroup.appendChild(opt);
    });
    select.appendChild(optgroup);
  }

  if (CURRENT_FOCUS_SESSION && (CURRENT_FOCUS_SESSION.status === 'running' || CURRENT_FOCUS_SESSION.status === 'paused')) {
    select.value = String(CURRENT_FOCUS_SESSION.task_id);
    select.disabled = true;
  } else if (currentVal && Array.from(select.options).some(o => o.value === currentVal)) {
    select.value = currentVal;
    select.disabled = false;
  } else if (!currentVal && pendingOrInProgress.length && !TIMER_SELECTED_TASK_ID) {
    selectTaskForTimer(pendingOrInProgress[0].id, false);
  }
}

async function updateTimerTaskMeta(task, cachedMetrics = null) {
  const metaBox = getEl('timer-task-meta');
  if (!metaBox || !task) return;

  metaBox.classList.remove('hidden');

  const titleEl = getEl('timer-task-title');
  if (titleEl) {
    titleEl.textContent = task.title || 'Untitled Task';
    titleEl.title = task.title || '';
  }

  const courseBadge = getEl('timer-course-badge');
  if (courseBadge) {
    courseBadge.textContent = task.course_code || task.course_name || 'General';
  }

  const priorityEl = getEl('timer-task-priority');
  if (priorityEl) {
    priorityEl.textContent = `• ${capitalizeSafe(task.priority || 'Medium')}`;
  }

  const estEl = getEl('timer-task-estimated');
  if (estEl) {
    estEl.textContent = task.duration_hours && Number(task.duration_hours) > 0
      ? `${Number(task.duration_hours)} hrs`
      : 'No estimate';
  }

  const applyMetrics = metrics => {
    const focusedEl = getEl('timer-task-focused');
    if (focusedEl) {
      focusedEl.textContent = formatDurationHuman(metrics.total_focused_seconds || 0);
    }
    const pctEl = getEl('timer-progress-pct');
    const barEl = getEl('timer-progress-bar');
    const pct = metrics.time_progress?.time_progress_percent ?? 0;
    if (pctEl) {
      pctEl.textContent = `${pct}%`;
    }
    if (barEl) {
      barEl.style.width = `${Math.min(100, Math.max(0, pct))}%`;
    }
  };

  // Provide immediate visual feedback from task data before network call
  const initialFocusedSeconds = Number(task.focused_seconds || task.total_focused_seconds || 0);
  const initialPct = Number(task.system_progress !== undefined ? task.system_progress : (task.time_progress_percent !== undefined ? task.time_progress_percent : (task.progress_percent || 0)));
  const focusedEl = getEl('timer-task-focused');
  if (focusedEl) {
    focusedEl.textContent = formatDurationHuman(initialFocusedSeconds);
  }
  const pctEl = getEl('timer-progress-pct');
  const barEl = getEl('timer-progress-bar');
  if (pctEl) {
    pctEl.textContent = `${initialPct}%`;
  }
  if (barEl) {
    barEl.style.width = `${Math.min(100, Math.max(0, initialPct))}%`;
  }

  if (cachedMetrics) {
    applyMetrics(cachedMetrics);
  } else {
    try {
      const res = await studySessionApi('GET', { task_id: task.id });
      if (res.ok && res.data) {
        applyMetrics(res.data);
      }
    } catch (_) {}
  }
}

async function selectTaskForTimer(taskId, shouldScroll = false) {
  if (!taskId) {
    TIMER_SELECTED_TASK_ID = null;
    const metaBox = getEl('timer-task-meta');
    if (metaBox) metaBox.classList.add('hidden');
    const select = getEl('timer-task-select');
    if (select) select.value = '';
    const btnStart = getEl('timer-btn-start');
    if (btnStart) btnStart.disabled = true;
    return false;
  }

  // Prevent switching if session is active on a different task
  if (CURRENT_FOCUS_SESSION && (CURRENT_FOCUS_SESSION.status === 'running' || CURRENT_FOCUS_SESSION.status === 'paused')) {
    if (String(CURRENT_FOCUS_SESSION.task_id) !== String(taskId)) {
      const activeTitle = CURRENT_FOCUS_SESSION.task_title || 'another task';
      setTimerFeedback(`A session is active for "${activeTitle}". Please pause, stop, or complete it before switching tasks.`, 'warning');
      if (shouldScroll) {
        getEl('focus-timer-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      return false;
    }
  }

  TIMER_SELECTED_TASK_ID = Number(taskId);

  const select = getEl('timer-task-select');
  if (select && select.value !== String(taskId)) {
    select.value = String(taskId);
  }

  const btnStart = getEl('timer-btn-start');
  if (btnStart && (!CURRENT_FOCUS_SESSION || CURRENT_FOCUS_SESSION.status === 'stopped' || CURRENT_FOCUS_SESSION.status === 'completed')) {
    btnStart.disabled = false;
  }

  let task = ALL_TASKS.find(t => String(t.id) === String(taskId));
  if (task) {
    await updateTimerTaskMeta(task);
  } else {
    try {
      const res = await studySessionApi('GET', { task_id: taskId });
      if (res.ok && res.data) {
        updateTimerTaskMeta({
          id: taskId,
          title: res.data.task_title,
          course_code: '',
          duration_hours: res.data.time_progress?.estimated_hours || 0,
          priority: 'medium'
        }, res.data);
      }
    } catch (_) {}
  }

  if (shouldScroll) {
    const card = getEl('focus-timer-card');
    if (card) {
      card.scrollIntoView({ behavior: 'smooth', block: 'center' });
      card.classList.add('focus-timer-highlight');
      setTimeout(() => {
        card.classList.remove('focus-timer-highlight');
      }, 1500);
    }
  }

  return true;
}

async function handleTimerStart() {
  if (IS_TIMER_BUSY) return;

  if (!TIMER_SELECTED_TASK_ID) {
    setTimerFeedback('Please select a task to focus on first.', 'warning');
    getEl('timer-task-select')?.focus();
    return;
  }

  if (CURRENT_FOCUS_SESSION && (CURRENT_FOCUS_SESSION.status === 'running' || CURRENT_FOCUS_SESSION.status === 'paused')) {
    setTimerFeedback('A session is already active.', 'warning');
    return;
  }

  IS_TIMER_BUSY = true;
  const btnStart = getEl('timer-btn-start');
  setButtonBusy(btnStart, true, 'Starting…');
  setTimerFeedback('');

  try {
    const res = await studySessionApi('POST', {}, {
      action: 'start',
      task_id: TIMER_SELECTED_TASK_ID
    });

    if (res.ok && res.data.session) {
      CURRENT_FOCUS_SESSION = res.data.session;
      setTimerUIState('running');
      startFocusTicker(0);
      setTimerFeedback('Focus session started! Stay focused.', 'success');
      toast('Focus session started', 'success');
    } else if (res.status === 409) {
      setTimerFeedback(res.data.error || 'An active session is already in progress.', 'warning');
      await recoverActiveFocusSession();
    } else {
      setTimerFeedback(res.data.error || 'Could not start study session.', 'error');
    }
  } catch (err) {
    setTimerFeedback('Network error while starting session.', 'error');
  } finally {
    IS_TIMER_BUSY = false;
    setButtonBusy(btnStart, false, 'Start Focus Session');
  }
}

async function handleTimerPause() {
  if (IS_TIMER_BUSY || !CURRENT_FOCUS_SESSION) return;

  IS_TIMER_BUSY = true;
  const btnPause = getEl('timer-btn-pause');
  setButtonBusy(btnPause, true, 'Pausing…');
  setTimerFeedback('');

  try {
    const res = await studySessionApi('POST', {}, {
      action: 'pause',
      session_id: CURRENT_FOCUS_SESSION.id
    });

    if (res.ok && res.data.session) {
      stopFocusTicker();
      CURRENT_FOCUS_SESSION = res.data.session;
      const digitsEl = getEl('timer-digits');
      if (digitsEl) {
        digitsEl.textContent = formatHms(res.data.session.duration_seconds);
      }
      setTimerUIState('paused');
      setTimerFeedback('Session paused.', 'info');
      toast('Focus session paused', 'info');
    } else {
      setTimerFeedback(res.data.error || 'Could not pause session.', 'error');
    }
  } catch (err) {
    setTimerFeedback('Network error while pausing session.', 'error');
  } finally {
    IS_TIMER_BUSY = false;
    setButtonBusy(btnPause, false, 'Pause');
  }
}

async function handleTimerResume() {
  if (IS_TIMER_BUSY || !CURRENT_FOCUS_SESSION) return;

  IS_TIMER_BUSY = true;
  const btnResume = getEl('timer-btn-resume');
  setButtonBusy(btnResume, true, 'Resuming…');
  setTimerFeedback('');

  try {
    const res = await studySessionApi('POST', {}, {
      action: 'resume',
      session_id: CURRENT_FOCUS_SESSION.id
    });

    if (res.ok && res.data.session) {
      CURRENT_FOCUS_SESSION = res.data.session;
      setTimerUIState('running');
      startFocusTicker(res.data.session.duration_seconds || 0);
      setTimerFeedback('Session resumed. Keep going!', 'success');
      toast('Focus session resumed', 'success');
    } else {
      setTimerFeedback(res.data.error || 'Could not resume session.', 'error');
    }
  } catch (err) {
    setTimerFeedback('Network error while resuming session.', 'error');
  } finally {
    IS_TIMER_BUSY = false;
    setButtonBusy(btnResume, false, 'Resume');
  }
}

async function handleTimerStop() {
  if (IS_TIMER_BUSY || !CURRENT_FOCUS_SESSION) return;

  IS_TIMER_BUSY = true;
  const btnStop = getEl('timer-btn-stop');
  setButtonBusy(btnStop, true, 'Stopping…');
  setTimerFeedback('');

  try {
    const res = await studySessionApi('POST', {}, {
      action: 'stop',
      session_id: CURRENT_FOCUS_SESSION.id
    });

    if (res.ok && res.data.session) {
      stopFocusTicker();
      const finalSec = res.data.session.duration_seconds || 0;
      const taskId = CURRENT_FOCUS_SESSION.task_id;
      CURRENT_FOCUS_SESSION = null;

      const digitsEl = getEl('timer-digits');
      if (digitsEl) digitsEl.textContent = '00:00:00';

      setTimerUIState('idle');
      const select = getEl('timer-task-select');
      if (select) select.disabled = false;

      const humanDur = formatDurationHuman(finalSec);
      setTimerFeedback(`Session stopped. ${humanDur} recorded.`, 'info');
      toast(`Study session stopped (${humanDur} recorded)`, 'info');

      if (taskId) {
        selectTaskForTimer(taskId, false);
      }
      await loadTasks();
    } else {
      setTimerFeedback(res.data.error || 'Could not stop session.', 'error');
    }
  } catch (err) {
    setTimerFeedback('Network error while stopping session.', 'error');
  } finally {
    IS_TIMER_BUSY = false;
    setButtonBusy(btnStop, false, 'Stop');
  }
}

async function handleTimerComplete() {
  if (IS_TIMER_BUSY || !CURRENT_FOCUS_SESSION) return;

  IS_TIMER_BUSY = true;
  const btnComplete = getEl('timer-btn-complete');
  setButtonBusy(btnComplete, true, 'Completing…');
  setTimerFeedback('');

  try {
    const res = await studySessionApi('POST', {}, {
      action: 'complete',
      session_id: CURRENT_FOCUS_SESSION.id
    });

    if (res.ok && res.data.session) {
      stopFocusTicker();
      const finalSec = res.data.session.duration_seconds || 0;
      const taskId = CURRENT_FOCUS_SESSION.task_id;
      CURRENT_FOCUS_SESSION = null;

      const digitsEl = getEl('timer-digits');
      if (digitsEl) digitsEl.textContent = '00:00:00';

      setTimerUIState('idle');
      const select = getEl('timer-task-select');
      if (select) select.disabled = false;

      const humanDur = formatDurationHuman(finalSec);
      setTimerFeedback(`Study session completed! ${humanDur} recorded.`, 'success');
      toast(`Study session completed (${humanDur} recorded)`, 'success');

      if (taskId) {
        selectTaskForTimer(taskId, false);
      }
      await loadTasks();
    } else {
      setTimerFeedback(res.data.error || 'Could not complete session.', 'error');
    }
  } catch (err) {
    setTimerFeedback('Network error while completing session.', 'error');
  } finally {
    IS_TIMER_BUSY = false;
    setButtonBusy(btnComplete, false, 'Complete Session');
  }
}

async function recoverActiveFocusSession() {
  try {
    const res = await studySessionApi('GET', { action: 'active' });
    const session = (res.ok && res.data) ? (res.data.active_session || res.data.session) : null;
    if (session) {
      CURRENT_FOCUS_SESSION = session;
      TIMER_SELECTED_TASK_ID = Number(session.task_id);

      const select = getEl('timer-task-select');
      if (select) {
        select.value = String(session.task_id);
        select.disabled = true;
      }

      updateTimerTaskMeta({
        id: session.task_id,
        title: session.task_title || 'Focus Task',
        course_code: session.course_code || '',
        course_name: session.course_name || '',
        duration_hours: session.task_duration_hours || 0,
        priority: 'medium'
      }, {
        total_focused_seconds: session.task_total_focused_seconds,
        time_progress: session.time_progress
      });

      if (session.status === 'running') {
        setTimerUIState('running');
        startFocusTicker(session.current_elapsed_seconds || 0);
      } else if (session.status === 'paused') {
        setTimerUIState('paused');
        const digitsEl = getEl('timer-digits');
        if (digitsEl) {
          digitsEl.textContent = formatHms(session.duration_seconds || 0);
        }
      }
    } else {
      CURRENT_FOCUS_SESSION = null;
      setTimerUIState('idle');
      const select = getEl('timer-task-select');
      if (select) select.disabled = false;
    }
  } catch (err) {
    console.warn('Could not recover active focus session:', err);
    setTimerUIState('idle');
  }
}

function bindFocusTimer() {
  getEl('timer-task-select')?.addEventListener('change', e => {
    const val = e.target.value;
    selectTaskForTimer(val, false);
  });

  getEl('timer-btn-start')?.addEventListener('click', handleTimerStart);
  getEl('timer-btn-pause')?.addEventListener('click', handleTimerPause);
  getEl('timer-btn-resume')?.addEventListener('click', handleTimerResume);
  getEl('timer-btn-stop')?.addEventListener('click', handleTimerStop);
  getEl('timer-btn-complete')?.addEventListener('click', handleTimerComplete);

  getEl('task-modal-focus-btn')?.addEventListener('click', () => {
    const form = getEl('task-form');
    const taskId = form?.elements?.id?.value;
    if (taskId) {
      closeModal();
      selectTaskForTimer(taskId, true);
    }
  });
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

  bindFocusTimer();

  setActiveTabUI();

  if (window.lucide) {
    window.lucide.createIcons();
  }

  startTaskLiveClock();
  bindCompletionAndDeletionEvents();
  recoverActiveFocusSession();
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

    const urlParams = new URLSearchParams(window.location.search);
    const courseIdParam = urlParams.get('course_id');
    const addTaskParam = urlParams.get('add_task') === '1' || urlParams.get('add') === '1';
    const taskIdParam = urlParams.get('task_id');
    const focusTaskIdParam = urlParams.get('focus_task_id');

    if (courseIdParam) {
      const courseFilter = getEl('filter-course');
      if (courseFilter) {
        courseFilter.value = courseIdParam;
      }
      const sideCourseFilter = getEl('filter-course-side');
      if (sideCourseFilter) {
        sideCourseFilter.value = courseIdParam;
      }
    }

    await loadTasks();

    if (addTaskParam) {
      openAddTask();
      if (courseIdParam) {
        const formCourse = getEl('task-form')?.elements?.course_id;
        if (formCourse) {
          formCourse.value = courseIdParam;
        }
      }
    }

    if (taskIdParam) {
      const targetTask = ALL_TASKS.find(t => String(t.id) === String(taskIdParam));
      if (targetTask) {
        if (targetTask.status === 'completed' && ACTIVE_TAB === 'pending') {
          ACTIVE_TAB = 'all';
          setActiveTabUI();
          renderTable(ALL_TASKS);
        } else if (targetTask.status !== 'completed' && ACTIVE_TAB === 'completed') {
          ACTIVE_TAB = 'all';
          setActiveTabUI();
          renderTable(ALL_TASKS);
        }

        const taskIndex = ALL_TASKS.findIndex(t => String(t.id) === String(taskIdParam));
        if (taskIndex !== -1) {
          CURRENT_PAGE = Math.floor(taskIndex / PAGE_SIZE) + 1;
          renderTable(ALL_TASKS);
        }

        setTimeout(() => {
          const row = document.querySelector(`[data-task-id="${taskIdParam}"]`);
          if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.classList.add('ring-2', 'ring-emerald-400', 'bg-emerald-50/50', 'dark:bg-emerald-950/30');
            setTimeout(() => {
              row.classList.remove('ring-2', 'ring-emerald-400');
            }, 3000);
          }
        }, 200);
      }
    }

    if (focusTaskIdParam) {
      if (typeof selectTaskForTimer === 'function') {
        selectTaskForTimer(focusTaskIdParam, true);
      }
      const timerCard = getEl('focus-timer-card');
      if (timerCard) {
        timerCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    } else if (urlParams.get('focus') === '1') {
      const timerCard = getEl('focus-timer-card');
      if (timerCard) {
        timerCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

  }
);
