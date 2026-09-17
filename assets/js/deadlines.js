let ALL_DEADLINES = [];

let calMonth =
  new Date().getMonth();

let calYear =
  new Date().getFullYear();

let deadlineDonut = null;

let countdownTimer = null;

let deadlineView = 'all';

const URGENCY_LABEL = {
  on_track: 'On Track',
  due_soon: 'Due Soon',
  overdue: 'Overdue'
};

const URGENCY_CLASSES = {
  on_track:
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',

  due_soon:
    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',

  overdue:
    'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'
};

const URGENCY_DOT = {
  on_track: 'bg-emerald-500',
  due_soon: 'bg-amber-500',
  overdue: 'bg-red-500'
};

const URGENCY_TEXT = {
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
        `${API}/deadlines.php`
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

function applyTableFilters() {

  const selectedStatus =
    document.getElementById(
      'deadline-status-filter'
    )?.value || '';

  const query =
    getSearchValue();

  let rows =
    [...ALL_DEADLINES];

  const now =
    new Date();

  if (
    deadlineView === 'today'
  ) {

    const start =
      startOfToday();

    const end =
      endOfToday();

    rows =
      rows.filter(
        deadline => {

          const due =
            new Date(
              deadline.due_at
            );

          return (
            due >= start &&
            due <= end
          );
        }
      );

  } else if (
    deadlineView === 'week'
  ) {

    const start =
      getWeekStart();

    const end =
      getWeekEnd();

    rows =
      rows.filter(
        deadline => {

          const due =
            new Date(
              deadline.due_at
            );

          return (
            due >= start &&
            due <= end
          );
        }
      );

  } else if (
    deadlineView === 'month'
  ) {

    rows =
      rows.filter(
        deadline => {

          const due =
            new Date(
              deadline.due_at
            );

          return (
            due.getMonth() ===
              now.getMonth() &&
            due.getFullYear() ===
              now.getFullYear()
          );
        }
      );
  }

  if (selectedStatus) {

    rows =
      rows.filter(
        deadline =>
          deadline.urgency ===
          selectedStatus
      );
  }

  if (query) {

    rows =
      rows.filter(
        deadline => {

          const title =
            String(
              deadline.title || ''
            ).toLowerCase();

          const description =
            String(
              deadline.description || ''
            ).toLowerCase();

          const courseCode =
            String(
              deadline.course_code || ''
            ).toLowerCase();

          const courseName =
            String(
              deadline.course_name || ''
            ).toLowerCase();

          return (
            title.includes(query) ||
            description.includes(query) ||
            courseCode.includes(query) ||
            courseName.includes(query)
          );
        }
      );
  }

  rows.sort(
    (a, b) =>
      new Date(a.due_at) -
      new Date(b.due_at)
  );

  renderTable(rows);

  updateFilterUI();
}

// ------------------------------------------------------
// Filter UI
// ------------------------------------------------------

function updateFilterUI() {

  document
    .querySelectorAll(
      '.deadline-filter-btn'
    )
    .forEach(button => {

      const active =
        button.dataset
          .deadlineView ===
        deadlineView;

      button.classList.toggle(
        'active',
        active
      );
    });

  const clear =
    document.getElementById(
      'deadline-clear-filter'
    );

  const hasSearch =
    getSearchValue() !== '';

  const hasStatus =
    document.getElementById(
      'deadline-status-filter'
    )?.value !== '';

  if (clear) {

    clear.classList.toggle(
      'hidden',
      !(
        hasSearch ||
        hasStatus ||
        deadlineView !== 'all'
      )
    );
  }

  const subtitle =
    document.getElementById(
      'deadline-table-subtitle'
    );

  if (!subtitle) {
    return;
  }

  if (
    deadlineView === 'today'
  ) {

    subtitle.textContent =
      "Deadlines due today.";

  } else if (
    deadlineView === 'week'
  ) {

    subtitle.textContent =
      "Deadlines due this week.";

  } else if (
    deadlineView === 'month'
  ) {

    subtitle.textContent =
      "Deadlines due this month.";

  } else {

    subtitle.textContent =
      "Your deadlines sorted by due date.";
  }
}

// ------------------------------------------------------
// Table
// ------------------------------------------------------

function renderTable(
  rows
) {

  const tbody =
    document.getElementById(
      'deadline-table-body'
    );

  const footer =
    document.getElementById(
      'deadline-table-footer'
    );

  if (!tbody) {
    return;
  }

  if (!rows.length) {

    tbody.innerHTML = `

      <tr>

        <td
          colspan="7"
          class="py-14 px-5 text-center"
        >

          <div
            class="w-12 h-12 mx-auto rounded-xl bg-gray-100 dark:bg-white/5 flex items-center justify-center text-gray-400 dark:text-gray-500"
          >
            <i
              data-lucide="calendar-x-2"
              class="w-6 h-6"
            ></i>
          </div>

          <div class="font-semibold mt-3">
            No deadlines found
          </div>

          <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">
            Try changing your filters or search.
          </div>

        </td>

      </tr>
    `;

    if (footer) {
      footer.innerHTML =
        '<span class="text-xs text-gray-400">0 deadlines</span>';
    }

    initLucide();

    return;
  }

  tbody.innerHTML =
    rows
      .map(
        (deadline, index) =>
          deadlineRow(
            deadline,
            index
          )
      )
      .join('');

  if (footer) {

    footer.innerHTML = `

      <span class="text-xs text-gray-400 dark:text-gray-500">
        Showing ${rows.length} of ${ALL_DEADLINES.length} deadline${ALL_DEADLINES.length === 1 ? '' : 's'}
      </span>

      <div class="flex items-center gap-1">

        <button
          type="button"
          class="w-7 h-7 rounded-lg border border-gray-200 dark:border-white/10 text-gray-400 flex items-center justify-center"
          disabled
        >
          <i data-lucide="chevron-left" class="w-3.5 h-3.5"></i>
        </button>

        <span
          class="w-7 h-7 rounded-lg bg-emerald-700 text-white text-xs flex items-center justify-center font-semibold"
        >
          1
        </span>

        <button
          type="button"
          class="w-7 h-7 rounded-lg border border-gray-200 dark:border-white/10 text-gray-400 flex items-center justify-center"
          disabled
        >
          <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
        </button>

      </div>
    `;
  }

  tickCountdowns();

  initLucide();
}

function deadlineRow(
  deadline,
  index
) {

  const urgency =
    deadline.urgency ||
    'on_track';

  const priorityLabel =
    getPriorityLabel(
      deadline
    );

  const priorityClasses =
    getPriorityClasses(
      deadline
    );

  const dueDate =
    new Date(
      deadline.due_at
    );

  const dueDateText =
    dueDate.toLocaleDateString(
      undefined,
      {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
      }
    );

  const dueTimeText =
    dueDate.toLocaleTimeString(
      undefined,
      {
        hour: 'numeric',
        minute: '2-digit'
      }
    );

  const description =
    deadline.description
      ? escapeHtml(
          deadline.description
        )
      : '';

  const courseCode =
    deadline.course_code
      ? escapeHtml(
          deadline.course_code
        )
      : '—';

  const courseName =
    deadline.course_name
      ? escapeHtml(
          deadline.course_name
        )
      : '';

  return `

    <tr
      class="deadline-row border-b border-gray-100 dark:border-white/[.06] last:border-0"
      data-due="${escapeAttribute(
        deadline.due_at
      )}"
      data-urgency="${escapeAttribute(
        urgency
      )}"
    >

      <td class="py-4 px-4 sm:px-5">

        <div
          class="w-6 h-6 rounded-md bg-gray-50 dark:bg-white/5 text-[10px] font-bold text-gray-400 flex items-center justify-center"
        >
          ${index + 1}
        </div>

      </td>

      <td class="py-4 px-4">

        <div class="flex items-start gap-3">

          <div
            class="w-9 h-9 rounded-lg ${
              urgency === 'overdue'
                ? 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300'
                : urgency === 'due_soon'
                  ? 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300'
                  : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
            } flex items-center justify-center shrink-0"
          >
            <i
              data-lucide="${
                urgency === 'overdue'
                  ? 'file-warning'
                  : urgency === 'due_soon'
                    ? 'file-clock'
                    : 'file-check-2'
              }"
              class="w-4 h-4"
            ></i>
          </div>

          <div class="min-w-0">

            <div class="font-bold text-gray-800 dark:text-gray-100 truncate">
              ${escapeHtml(
                deadline.title
              )}
            </div>

            ${
              description
                ? `
                  <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 truncate max-w-[280px]">
                    ${description}
                  </div>
                `
                : ''
            }

          </div>

        </div>

      </td>

      <td class="py-4 px-4">

        <div class="font-semibold text-gray-700 dark:text-gray-200">
          ${courseCode}
        </div>

        ${
          courseName
            ? `
              <div class="text-[11px] text-gray-400 mt-1">
                ${courseName}
              </div>
            `
            : ''
        }

      </td>

      <td class="py-4 px-4">

        <div class="font-semibold text-gray-700 dark:text-gray-200">
          ${dueDateText}
        </div>

        <div class="text-[11px] text-gray-400 mt-1">
          ${dueTimeText}
        </div>

      </td>

      <td
        class="py-4 px-4 text-xs font-bold time-left-cell ${URGENCY_TEXT[urgency] || ''}"
      >
        …
      </td>

      <td class="py-4 px-4">

        <span
          class="priority-badge inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold ${priorityClasses}"
        >
          ${priorityLabel}
        </span>

      </td>

      <td class="py-4 px-4">

        <span
          class="status-badge inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold ${URGENCY_CLASSES[urgency] || URGENCY_CLASSES.on_track}"
        >

          <span
            class="w-1.5 h-1.5 rounded-full ${URGENCY_DOT[urgency] || URGENCY_DOT.on_track}"
          ></span>

          ${URGENCY_LABEL[urgency] || 'On Track'}

        </span>

      </td>

    </tr>
  `;
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

                <div class="text-[10px] text-gray-400 mt-1">
                  ${escapeHtml(
                    deadline.course_code || ''
                  )}
                </div>

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
// Initialisation
// ------------------------------------------------------

window.APP_READY.then(
  me => {

    if (!me) {
      return;
    }

    initLucide();

    loadDeadlines();
  }
);