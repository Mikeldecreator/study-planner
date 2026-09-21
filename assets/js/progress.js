let overallDonut = null;
let progressChart = null;

let currentRange = 'semester';
let progressRequest = null;

const RATING_CLASSES = {
  'Good':
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',

  'Average':
    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',

  'Needs Improvement':
    'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'
};

const PROGRESS_BAR_COLOR = pct => {
  if (pct >= 70) return '#059669';
  if (pct >= 40) return '#d97706';
  return '#dc2626';
};

const CHART_COLORS = {
  overall: '#059669',
  courses: '#3b82f6',
  tasks: '#f59e0b',
  sessions: '#8b5cf6'
};

// ------------------------------------------------------
// Helpers
// ------------------------------------------------------

function initLucide() {
  if (window.lucide) {
    window.lucide.createIcons();
  }
}

function setText(id, value) {
  const el = document.getElementById(id);

  if (el) {
    el.textContent =
      value === null ||
      value === undefined
        ? '–'
        : value;
  }
}

function clamp(value, min, max) {
  return Math.max(
    min,
    Math.min(max, Number(value) || 0)
  );
}

function escapeAttribute(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function formatPercent(pct) {
  const n = Number(pct);
  if (!Number.isFinite(n) || n === 0) return '0%';
  return n % 1 === 0 ? `${n}%` : `${n.toFixed(2)}%`;
}

function showProgressError(message) {
  const existing =
    document.getElementById(
      'progress-load-error'
    );

  if (existing) {
    existing.remove();
  }

  const main =
    document.querySelector(
      'main'
    );

  if (!main) return;

  const error = document.createElement('div');

  error.id =
    'progress-load-error';

  error.className =
    'mx-4 sm:mx-6 xl:mx-8 mt-4 rounded-xl border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-300 px-4 py-3 text-sm';

  error.innerHTML = `
    <div class="flex items-center gap-2">
      <i data-lucide="triangle-alert" class="w-4 h-4 shrink-0"></i>
      <span>${escapeHtml(message)}</span>
    </div>
  `;

  main.insertBefore(
    error,
    main.children[1]
  );

  initLucide();
}

function clearProgressError() {
  const error =
    document.getElementById(
      'progress-load-error'
    );

  if (error) {
    error.remove();
  }
}

function setLoading(isLoading) {

  const buttons =
    document.querySelectorAll(
      '.range-button'
    );

  buttons.forEach(
    button => {
      button.disabled =
        isLoading;

      button.classList.toggle(
        'opacity-60',
        isLoading
      );

      button.classList.toggle(
        'cursor-wait',
        isLoading
      );
    }
  );

  const select =
    document.getElementById(
      'range-select'
    );

  if (select) {
    select.disabled =
      isLoading;
  }
}

// ------------------------------------------------------
// Range Controls
// ------------------------------------------------------

function updateRangeButtons(
  range
) {

  document
    .querySelectorAll(
      '.range-button'
    )
    .forEach(button => {

      const active =
        button.dataset.range ===
        range;

      button.classList.toggle(
        'active',
        active
      );

      button.classList.toggle(
        'text-gray-500',
        !active
      );

      button.classList.toggle(
        'dark:text-gray-300',
        !active
      );
    });
}

function setRange(
  range,
  reload = true
) {

  if (
    ![
      'week',
      'month',
      'semester'
    ].includes(range)
  ) {
    range = 'semester';
  }

  currentRange =
    range;

  const select =
    document.getElementById(
      'range-select'
    );

  if (select) {
    select.value =
      range;
  }

  updateRangeButtons(
    range
  );

  if (reload) {
    loadProgress(
      range
    );
  }
}

// ------------------------------------------------------
// Load Progress
// ------------------------------------------------------

async function loadProgress(
  range = currentRange
) {

  currentRange =
    range;

  updateRangeButtons(
    range
  );

  setLoading(true);

  clearProgressError();

  /*
   * Abort the previous request.
   * This prevents rapid Week → Month → Semester
   * clicks from allowing an old response to
   * overwrite the newest selection.
   */
  if (progressRequest) {
    progressRequest.abort();
  }

  progressRequest =
    new AbortController();

  try {

    const apiBase = typeof API !== 'undefined' ? API : (window.API || '../api');
    const response =
      await fetch(
        `${apiBase}/progress.php?range=${encodeURIComponent(range)}`,
        {
          method: 'GET',
          credentials: 'same-origin',
          headers: {
            'Accept':
              'application/json'
          },
          signal:
            progressRequest.signal
        }
      );

    if (!response.ok) {
      throw new Error(
        `Progress request failed (${response.status})`
      );
    }

    const data =
      await response.json();
    
    console.log(
      'Progress API response:',
      data
    );

    console.log(
      'Courses returned:',
      data.courses
    );

    renderOverall(
      data
    );

    renderCourseList(
      Array.isArray(
        data.courses
      )
        ? data.courses
        : []
    );

    renderSecondaryStats(
      data
    );

    renderProgressInsights(
      data.progress_insights
    );


    /*
     * Only update the UI if this request is
     * still the currently selected range.
     */
    if (
      currentRange === range
    ) {
      updateRangeButtons(
        range
      );
    }

    initLucide();

  } catch (error) {

    if (
      error.name !==
      'AbortError'
    ) {

      console.error(
        'Could not load progress:',
        error
      );

      showProgressError(
        'Unable to load your progress right now. Please try again.'
      );
    }

  } finally {

    if (
      progressRequest &&
      !progressRequest.signal.aborted
    ) {
      setLoading(false);
    }
  }
}

// ------------------------------------------------------
// Overall Progress
// ------------------------------------------------------

function renderOverall(
  data
) {

  const overall =
    clamp(
      data.overall_progress,
      0,
      100
    );

  setText(
    'overall-pct',
    formatPercent(overall)
  );

  setText(
    'overall-caption',
    `${formatPercent(overall)} of your academic goals completed`
  );

  setText(
    'overall-legend-value',
    formatPercent(overall)
  );

  const overallBar =
    document.getElementById(
      'overall-bar'
    );

  if (overallBar) {

    requestAnimationFrame(
      () => {
        overallBar.style.width =
          `${overall}%`;
      }
    );
  }

  const tasks =
    data.tasks || {};

  const sessions =
    data.study_sessions || {};

  const completedTasks =
    Number(
      tasks.completed || 0
    );

  const pendingTasks =
    Number(
      tasks.pending || 0
    );

  const overdueTasks =
    Number(
      tasks.overdue || 0
    );

  const completedSessions =
    Number(
      sessions.completed_sessions_count ??
      sessions.completed ??
      0
    );

  const scheduledSessions =
    Number(
      sessions.scheduled_study_sessions ??
      sessions.scheduled ??
      0
    );

  const totalTasks =
    Number(
      tasks.total ??
      (completedTasks + pendingTasks + overdueTasks)
    );

  const totalSessions =
    Math.max(
      completedSessions,
      scheduledSessions
    );

  setText(
    'progress-task-total',
    totalTasks
  );

  setText(
    'progress-session-total',
    totalSessions
  );

  setText(
    'progress-tasks-count',
    totalTasks
  );

  setText(
    'progress-sessions-count',
    totalSessions
  );

const courseCount =
  Array.isArray(data.courses)
    ? data.courses.length
    : 0;

setText(
  'progress-courses-count',
  courseCount
);

  const deadlineCount =
    Number(
      data.deadlines ??
      data.upcoming_deadlines ??
      0
    );

  setText(
    'progress-deadlines-count',
    deadlineCount
  );

  const taskProgress =
    totalTasks > 0
      ? Number(
          (
            (completedTasks / totalTasks) * 100
          ).toFixed(2)
        )
      : 0;

  const sessionProgress =
    totalSessions > 0
      ? Number(
          (
            (completedSessions / totalSessions) * 100
          ).toFixed(2)
        )
      : 0;

  renderOverallDonut(
    overall
  );

  renderProgressChart(
    data,
    overall,
    taskProgress,
    sessionProgress
  );

  renderTaskLegend(
    tasks
  );

  renderSessionLegend(
    sessions
  );
}

// ------------------------------------------------------
// Overall Donut
// ------------------------------------------------------

function renderOverallDonut(
  progress
) {

  const canvas =
    document.getElementById(
      'overall-donut'
    );

  if (!canvas) {
    return;
  }

  if (overallDonut) {
    overallDonut.destroy();
    overallDonut = null;
  }

  const numericProgress = clamp(progress, 0, 100);

  overallDonut =
    new Chart(
      canvas,
      {
        type: 'doughnut',

        data: {
          datasets: [
            {
              data: [
                numericProgress,
                Math.max(
                  0,
                  100 - numericProgress
                )
              ],

              backgroundColor: [
                '#059669',
                '#dfe9e5'
              ],

              borderWidth: 0
            }
          ]
        },

        options: {
          responsive: true,
          maintainAspectRatio: false,

          cutout: '77%',

          rotation: -90,

          circumference:
            360,

          plugins: {
            legend: {
              display: false
            },

            tooltip: {
              enabled: false
            }
          }
        }
      }
    );
}

// ------------------------------------------------------
// Progress Chart
// ------------------------------------------------------

function renderProgressChart(
  data,
  overall,
  taskProgress,
  sessionProgress
) {

  const canvas =
    document.getElementById(
      'progress-chart'
    );

  if (!canvas) {
    return;
  }

  if (progressChart) {
    progressChart.destroy();
    progressChart = null;
  }

  const courses =
    Array.isArray(
      data.courses
    ) &&
    data.courses.length
      ? Number(
          (
            data.courses.reduce(
              (
                total,
                course
              ) =>
                total +
                Number(
                  course.progress ||
                  0
                ),
              0
            ) /
              data.courses.length
          ).toFixed(2)
        )
      : overall;

  setText(
    'courses-legend-value',
    formatPercent(courses)
  );

  setText(
    'tasks-legend-value',
    formatPercent(taskProgress)
  );

  setText(
    'sessions-legend-value',
    formatPercent(sessionProgress)
  );

  progressChart =
    new Chart(
      canvas,
      {
        type: 'line',

        data: {
          labels: [
            'Overall',
            'Courses',
            'Tasks',
            'Study Sessions'
          ],

          datasets: [
            {
              label:
                'Academic Progress',

              data: [
                overall,
                courses,
                taskProgress,
                sessionProgress
              ],

              borderColor:
                CHART_COLORS.overall,

              backgroundColor:
                'rgba(5,150,105,.08)',

              fill: true,

              tension: 0.35,

              borderWidth: 2,

              pointRadius: 4,

              pointHoverRadius: 6,

              pointBackgroundColor: [
                CHART_COLORS.overall,
                CHART_COLORS.courses,
                CHART_COLORS.tasks,
                CHART_COLORS.sessions
              ],

              pointBorderWidth: 0
            }
          ]
        },

        options: {

          responsive: true,

          maintainAspectRatio: false,

          animation: {
            duration: 500
          },

          interaction: {
            mode: 'index',
            intersect: false
          },

          scales: {

            y: {

              min: 0,
              max: 100,

              ticks: {
                stepSize: 25,

                callback:
                  value =>
                    `${value}%`,

                font: {
                  size: 10
                }
              },

              grid: {
                color:
                  'rgba(148,163,184,.13)'
              }
            },

            x: {

              ticks: {
                font: {
                  size: 10
                }
              },

              grid: {
                color:
                  'rgba(148,163,184,.08)'
              }
            }
          },

          plugins: {

            legend: {
              display: false
            },

            tooltip: {

              displayColors:
                false,

              callbacks: {
                label:
                  context =>
                    formatPercent(context.parsed.y)
              }
            }
          }
        }
      }
    );
}

// ------------------------------------------------------
// Legends
// ------------------------------------------------------

function renderTaskLegend(
  tasks
) {

  const element =
    document.getElementById(
      'tasks-legend'
    );

  if (!element) {
    return;
  }

  element.innerHTML = `

    ${legendRow(
      '#059669',
      'Completed',
      Number(
        tasks.completed || 0
      )
    )}

    ${legendRow(
      '#f59e0b',
      'Pending',
      Number(
        tasks.pending || 0
      )
    )}

    ${legendRow(
      '#ef4444',
      'Overdue',
      Number(
        tasks.overdue || 0
      )
    )}

  `;

  initLucide();
}

function renderSessionLegend(
  sessions
) {

  const element =
    document.getElementById(
      'sessions-legend'
    );

  if (!element) {
    return;
  }

  element.innerHTML = `

    ${legendRow(
      '#059669',
      'Completed',
      Number(
        sessions.completed_sessions_count ??
        sessions.completed ??
        0
      )
    )}

    ${legendRow(
      '#3b82f6',
      'Scheduled',
      Number(
        sessions.scheduled_study_sessions ??
        sessions.scheduled ??
        0
      )
    )}

  `;
}

function legendRow(
  color,
  label,
  value
) {

  return `

    <div
      class="flex items-center gap-2 py-1"
    >

      <span
        class="w-2.5 h-2.5 rounded-full shrink-0"
        style="background:${color}"
      ></span>

      <span
        class="text-gray-600 dark:text-gray-300"
      >
        ${escapeHtml(label)}
      </span>

      <span
        class="ml-auto font-semibold"
      >
        ${value}
      </span>

    </div>
  `;
}

// ------------------------------------------------------
// Course List
// ------------------------------------------------------

function renderCourseList(courses) {

  const container =
    document.getElementById(
      'course-progress-list'
    );

  if (!container) {
    console.error(
      'course-progress-list element was not found.'
    );
    return;
  }

  /*
   * Always normalize the API response.
   * This prevents the card from failing when the
   * courses array is missing or empty.
   */
  const courseList =
    Array.isArray(courses)
      ? courses
      : [];

  if (!courseList.length) {

    container.innerHTML = `
      <div class="px-5 sm:px-6 py-12 text-center">

        <div
          class="w-12 h-12 mx-auto rounded-xl bg-gray-100 dark:bg-white/5 flex items-center justify-center text-gray-400 dark:text-gray-500"
        >
          <i
            data-lucide="book-open"
            class="w-6 h-6"
          ></i>
        </div>

        <h4
          class="mt-3 text-sm font-semibold text-gray-700 dark:text-gray-200"
        >
          No courses available
        </h4>

        <p
          class="mt-1 text-xs text-gray-400 dark:text-gray-500"
        >
          Add a course to start tracking your progress.
        </p>

        <a
          href="courses.php"
          class="inline-flex items-center gap-1.5 mt-4 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
        >
          Go to Courses
          <i
            data-lucide="arrow-right"
            class="w-3.5 h-3.5"
          ></i>
        </a>

      </div>
    `;

    initLucide();
    return;
  }

  container.innerHTML =
    courseList
      .map(
        (course, index) =>
          renderCourseRow(
            course,
            index
          )
      )
      .join('');

  initLucide();
}


/*
 * ================================================================
 * PROGRESS INSIGHTS (FOUNDATION 5)
 * ================================================================
 */

function renderProgressInsights(insights) {
  if (!insights) return;

  const strongest = insights.strongest_area;
  if (strongest) {
    setText('insight-strongest-label', strongest.label || '–');
    setText('insight-strongest-desc', strongest.description || '');
  }

  const attention = insights.needs_attention;
  if (attention) {
    setText('insight-attention-label', attention.label || '–');
    setText('insight-attention-desc', attention.description || '');
  }

  const workload = insights.remaining_workload;
  if (workload) {
    setText('insight-workload-label', workload.label || '–');
    setText('insight-workload-desc', workload.description || '');
  }

  const trend = insights.completion_trend;
  if (trend) {
    setText('insight-trend-label', trend.label || '–');
    setText('insight-trend-desc', trend.description || '');
  }

  const consistency = insights.study_consistency;
  if (consistency) {
    setText('insight-consistency-label', consistency.label || '–');
    setText('insight-consistency-desc', consistency.description || '');
  }
}


function renderCourseRow(
  course,
  index
) {

  const progress =
    Math.max(
      0,
      Math.min(
        100,
        Number(
          course.progress || 0
        )
      )
    );

  const code =
    course.code ||
    'Course';

  const name =
    course.name ||
    '';


  /*
   * Existing API fields are preserved.
   */
  const color =
    course.color ||
    '#059669';

  const icon =
    course.icon ||
    '📚';

  const rating =
    course.rating ||
    '';


  let ratingClass =
    'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300';

  if (rating === 'Good') {

    ratingClass =
      'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';

  } else if (
    rating === 'Average'
  ) {

    ratingClass =
      'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';

  } else if (
    rating === 'Needs Improvement'
  ) {

    ratingClass =
      'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300';
  }


  const progressColor =
    progress >= 70
      ? '#059669'
      : progress >= 40
        ? '#d97706'
        : '#dc2626';

  // Foundation 5: Academic Pressure and Workload
  const pressure = course.course_pressure || 'Low';
  let pressureClass = 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300';
  if (pressure === 'Critical') {
    pressureClass = 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300';
  } else if (pressure === 'High') {
    pressureClass = 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
  } else if (pressure === 'Moderate') {
    pressureClass = 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
  } else if (pressure === 'Low') {
    pressureClass = 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
  }

  const remainingHours = course.remaining_workload_hours !== undefined && course.remaining_workload_hours !== null
    ? Number(course.remaining_workload_hours)
    : null;

  const contextMessage = course.context_message || '';


  /*
   * Some installations may return one of these
   * completion fields. We safely support all of them.
   */
  const completed =
    course.completed ??
    course.completed_tasks ??
    null;

  const total =
    course.total ??
    course.total_tasks ??
    null;


  return `
    <div
      class="group px-5 sm:px-6 py-5 ${
        index > 0
          ? 'border-t border-gray-100 dark:border-white/[.06]'
          : ''
      } hover:bg-emerald-50/[.03] dark:hover:bg-white/[.015] transition-colors"
    >

      <!-- Top row -->
      <div
        class="flex items-start gap-3 sm:gap-4"
      >

        <!-- Course icon -->
        <div
          class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center text-white shrink-0 shadow-sm"
          style="background:${escapeAttribute(
            color
          )};"
        >
          ${escapeHtml(icon)}
        </div>


        <!-- Course information -->
        <div
          class="min-w-0 flex-1"
        >

          <div
            class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1.5 sm:gap-3"
          >

            <div class="min-w-0">

              <div
                class="flex items-center gap-2 flex-wrap"
              >
                <a
                  href="courses.php"
                  class="text-sm font-bold text-gray-900 dark:text-white truncate hover:text-emerald-700 dark:hover:text-emerald-400 transition-colors"
                  title="View Course"
                >
                  ${escapeHtml(code)}
                </a>

                <span
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ${pressureClass}"
                >
                  ${escapeHtml(pressure)} Pressure
                </span>

                ${
                  remainingHours !== null
                    ? `
                      <span
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 dark:bg-white/5 text-gray-600 dark:text-gray-300"
                      >
                        <i data-lucide="clock" class="w-3 h-3"></i>
                        ${remainingHours}h remaining
                      </span>
                    `
                    : ''
                }
              </div>

              <div
                class="text-xs text-gray-400 dark:text-gray-500 truncate mt-0.5"
              >
                ${escapeHtml(contextMessage || name)}
              </div>

            </div>



            <!-- Percentage -->
            <div
              class="flex items-center gap-2 shrink-0"
            >

              <span
                class="text-base font-bold text-gray-900 dark:text-white"
              >
                ${formatPercent(progress)}
              </span>

              ${
                rating
                  ? `
                    <span
                      class="hidden sm:inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold ${ratingClass}"
                    >
                      ${escapeHtml(
                        rating
                      )}
                    </span>
                  `
                  : ''
              }

            </div>

          </div>


          <!-- Progress bar -->
          <div class="mt-3">

            <div
              class="h-2.5 w-full rounded-full bg-gray-100 dark:bg-white/[.08] overflow-hidden"
            >

              <div
                class="h-full rounded-full transition-all duration-700 ease-out"
                style="
                  width:${progress}%;
                  background:${progressColor};
                "
              ></div>

            </div>

          </div>


          <!-- Bottom information -->
          <div
            class="flex items-center justify-between gap-3 mt-2.5"
          >

            <div class="flex items-center gap-3 flex-wrap">
              <span
                class="text-[10px] text-gray-400 dark:text-gray-500"
              >
                Course progress
              </span>
              ${
                course.id
                  ? `
                    <a
                      href="tasks.php?course_id=${escapeAttribute(course.id)}"
                      class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 hover:underline inline-flex items-center gap-1"
                      title="View tasks for ${escapeHtml(code)}"
                    >
                      <i data-lucide="list-todo" class="w-3 h-3"></i> View Tasks
                    </a>
                    <a
                      href="courses.php"
                      class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 inline-flex items-center gap-1"
                      title="View Course"
                    >
                      <i data-lucide="book-open" class="w-3 h-3"></i> Course
                    </a>
                  `
                  : ''
              }
            </div>


            <div
              class="flex items-center gap-3"
            >

              ${
                rating
                  ? `
                    <span
                      class="sm:hidden inline-flex items-center px-2 py-1 rounded-full text-[9px] font-bold ${ratingClass}"
                    >
                      ${escapeHtml(
                        rating
                      )}
                    </span>
                  `
                  : ''
              }

              ${
                completed !== null &&
                total !== null
                  ? `
                    <span
                      class="text-[10px] text-gray-400 dark:text-gray-500"
                    >
                      ${completed}/${total}
                    </span>
                  `
                  : completed !== null
                    ? `
                      <span
                        class="text-[10px] text-gray-400 dark:text-gray-500"
                      >
                        ${completed} completed
                      </span>
                    `
                    : ''
              }

            </div>

          </div>

        </div>

      </div>

    </div>
  `;
}

// ------------------------------------------------------
// Secondary Statistics
// ------------------------------------------------------

function renderSecondaryStats(
  data
) {

  const tasks =
    data.tasks || {};

  const sessions =
    data.study_sessions || {};

  const completedTasks =
    Number(
      tasks.completed || 0
    );

  const pendingTasks =
    Number(
      tasks.pending || 0
    );

  const overdueTasks =
    Number(
      tasks.overdue || 0
    );

  const totalTasks =
    Number(
      tasks.total ??
      (completedTasks + pendingTasks + overdueTasks)
    );

  const taskProgress =
    totalTasks > 0
      ? (
          completedTasks /
          totalTasks
        ) *
        100
      : 0;

  const completedSessions =
    Number(
      sessions.completed_sessions_count ??
      sessions.completed ??
      0
    );

  const scheduledSessions =
    Number(
      sessions.scheduled_study_sessions ??
      sessions.scheduled ??
      0
    );

  const totalSessions =
    Math.max(
      completedSessions,
      scheduledSessions
    );

  const sessionProgress =
    totalSessions > 0
      ? (
          completedSessions /
          totalSessions
        ) *
        100
      : 0;

  setText(
    'task-completed-count',
    completedTasks
  );

  setText(
    'task-pending-count',
    pendingTasks
  );

  setText(
    'task-overdue-count',
    overdueTasks
  );

  setText(
    'session-completed-count',
    completedSessions
  );

  setText(
    'session-scheduled-count',
    scheduledSessions
  );

  const taskBar =
    document.getElementById(
      'task-progress-bar'
    );

  if (taskBar) {

    requestAnimationFrame(
      () => {
        taskBar.style.width =
          `${Math.min(100, Math.max(0, taskProgress))}%`;
      }
    );
  }

  const sessionBar =
    document.getElementById(
      'session-progress-bar'
    );

  if (sessionBar) {

    requestAnimationFrame(
      () => {
        sessionBar.style.width =
          `${Math.min(100, Math.max(0, sessionProgress))}%`;
      }
    );
  }
}

// ------------------------------------------------------
// Range Buttons
// ------------------------------------------------------

document
  .querySelectorAll(
    '.range-button'
  )
  .forEach(button => {

    button.addEventListener(
      'click',
      event => {

        event.preventDefault();

        const range =
          button.dataset.range;

        if (
          ![
            'week',
            'month',
            'semester'
          ].includes(range)
        ) {
          return;
        }

        /*
         * Immediately update the selected state,
         * then load the new range.
         */
        setRange(
          range,
          true
        );
      }
    );
  });

// Existing select still works
const rangeSelect =
  document.getElementById(
    'range-select'
  );

if (rangeSelect) {

  rangeSelect.addEventListener(
    'change',
    event => {

      const range =
        event.target.value;

      setRange(
        range,
        true
      );
    }
  );
}

// ------------------------------------------------------
// Initialisation
// ------------------------------------------------------

async function initProgress() {
  initLucide();

  const initialRange =
    document.getElementById(
      'range-select'
    )?.value ||
    'semester';

  currentRange =
    initialRange;

  updateRangeButtons(
    initialRange
  );

  await loadProgress(
    initialRange
  );

  initLucide();
}

if (window.CURRENT_USER) {
  initProgress();
} else if (window.APP_READY && typeof window.APP_READY.then === 'function') {
  window.APP_READY.then(
    async me => {
      if (me || window.CURRENT_USER) {
        await initProgress();
      }
    }
  ).catch(async () => {
    await initProgress();
  });
} else {
  initProgress();
}