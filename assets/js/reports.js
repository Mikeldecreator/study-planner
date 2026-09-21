let performanceTrendChart = null;
let taskStatusChart = null;

const INSIGHT_ICON = {
  success: 'check-circle-2',
  warning: 'alert-triangle',
  info: 'info'
};

const INSIGHT_COLOR = {
  success: 'text-green-700 dark:text-green-400',
  warning: 'text-amber-600 dark:text-amber-400',
  info: 'text-blue-600 dark:text-blue-400'
};

const STATUS_CONFIG = [
  {
    key: 'completed',
    label: 'Completed',
    color: '#12965f'
  },
  {
    key: 'in_progress',
    label: 'In Progress',
    color: '#f59e0b'
  },
  {
    key: 'pending',
    label: 'Pending',
    color: '#3b82f6'
  },
  {
    key: 'overdue',
    label: 'Overdue',
    color: '#ef4444'
  },
  {
    key: 'not_started',
    label: 'Not Started',
    color: '#a8b8b3'
  }
];

const COURSE_ICONS = [
  'code-2',
  'database',
  'sigma',
  'atom',
  'layers-3',
  'book-open'
];

function getChartTextColor() {
  return document.documentElement.classList.contains('dark')
    ? '#a8bbb5'
    : '#6a8580';
}

function getChartGridColor() {
  return document.documentElement.classList.contains('dark')
    ? 'rgba(255,255,255,.08)'
    : 'rgba(24,76,64,.08)';
}

function refreshLucide() {
  if (window.lucide) {
    window.lucide.createIcons();
  }
}

function safeNumber(value, fallback = 0) {
  const n = Number(value);

  return Number.isFinite(n)
    ? n
    : fallback;
}

function escapeValue(value) {

  if (typeof escapeHtml === 'function') {
    return escapeHtml(String(value ?? ''));
  }

  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function formatPercent(pct) {
  const n = Number(pct);
  if (!Number.isFinite(n) || n === 0) return '0%';
  return n % 1 === 0 ? `${n}%` : `${n.toFixed(2)}%`;
}


/*
 * ================================================================
 * LOAD REPORT
 * ================================================================
 */

async function loadReport() {

  const rangeSelect =
    document.getElementById('range-select');

  if (!rangeSelect) {
    return;
  }

  const range =
    rangeSelect.value;

  setReportLoading(true);

  try {
    const apiBase = typeof API !== 'undefined' ? API : (window.API || '../api');

    /*
     * Existing Reports API.
     */
    const reportRes =
      await fetch(
        `${apiBase}/reports.php?range=${range}`,
        {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        }
      );

    if (!reportRes.ok) {
      throw new Error(
        `Report request failed: ${reportRes.status}`
      );
    }

    const report =
      await reportRes.json();


    /*
     * Existing Stats API.
     */
    let stats = null;
    try {
      const statsRes =
        await fetch(`${apiBase}/stats.php`, {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
      stats = statsRes.ok ? await statsRes.json() : null;
    } catch (e) {
      console.warn('Stats fetch non-critical failure:', e);
    }


    /*
     * Existing Courses API.
     */
    let coursesData = null;
    try {
      const coursesRes =
        await fetch(`${apiBase}/courses.php`, {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
      coursesData = coursesRes.ok ? await coursesRes.json() : null;
    } catch (e) {
      console.warn('Courses fetch non-critical failure:', e);
    }


    try { renderReportLabel(report); } catch (e) { console.warn('renderReportLabel error:', e); }

    try { renderKpis(report); } catch (e) { console.warn('renderKpis error:', e); }

    try {
      renderPerformanceTrend(
        report,
        stats
      );
    } catch (e) {
      console.warn('renderPerformanceTrend error:', e);
    }

    try {
      renderTaskStatus(
        report,
        stats
      );
    } catch (e) {
      console.warn('renderTaskStatus error:', e);
    }

    try {
      renderWeeklyStudy(
        report
      );
    } catch (e) {
      console.warn('renderWeeklyStudy error:', e);
    }

    try {
      renderSubjectBreakdown(
        coursesData,
        report
      );
    } catch (e) {
      console.warn('renderSubjectBreakdown error:', e);
    }

    try {
      renderCoursePerformance(
        coursesData,
        report
      );
    } catch (e) {
      console.warn('renderCoursePerformance error:', e);
    }

    try {
      renderInsights(
        report
      );
    } catch (e) {
      console.warn('renderInsights error:', e);
    }

    try {
      renderRecentReports(
        report
      );
    } catch (e) {
      console.warn('renderRecentReports error:', e);
    }

    refreshLucide();

  } catch (error) {

    console.error(
      'Unable to load report:',
      error
    );

    renderGlobalError();

    refreshLucide();

  } finally {

    setReportLoading(false);

  }
}


/*
 * ================================================================
 * RANGE LABEL
 * ================================================================
 */

function renderReportLabel(report) {

  const rangeLabel =
    document.getElementById(
      'range-label'
    );

  if (!rangeLabel) {
    return;
  }

  rangeLabel.textContent =
    report.range_label ||
    'Selected reporting period';
}


/*
 * ================================================================
 * KPI CARDS
 * ================================================================
 */

function renderKpis(report) {

  const container =
    document.getElementById(
      'report-stat-rows'
    );

  if (!container) {
    return;
  }

  const cards = [

    {
      icon: 'clipboard-check',
      color:
        'text-green-700 dark:text-green-400',
      bg:
        'bg-green-50 dark:bg-green-950/30',
      label:
        'Tasks Completed',
      value:
        safeNumber(
          report.tasks_completed
        )
    },

    {
      icon: 'file-plus-2',
      color:
        'text-blue-600 dark:text-blue-400',
      bg:
        'bg-blue-50 dark:bg-blue-950/30',
      label:
        'Tasks Created',
      value:
        safeNumber(
          report.tasks_created
        )
    },

    {
      icon: 'trending-up',
      color:
        'text-purple-600 dark:text-purple-400',
      bg:
        'bg-purple-50 dark:bg-purple-950/30',
      label:
        'Completion Rate',
      value:
        formatPercent(
          safeNumber(
            report.completion_rate
          )
        )
    },

    {
      icon: 'alert-circle',
      color:
        'text-red-600 dark:text-red-400',
      bg:
        'bg-red-50 dark:bg-red-950/30',
      label:
        'Overdue Tasks',
      value:
        safeNumber(
          report.overdue_tasks
        )
    },

    {
      icon: 'clock-3',
      color:
        'text-cyan-600 dark:text-cyan-400',
      bg:
        'bg-cyan-50 dark:bg-cyan-950/30',
      label:
        'Study Hours',
      value:
        `${safeNumber(
          report.study_hours
        ).toFixed(1)}h`
    }

  ];


  container.innerHTML =
    cards.map(card => `

      <div
        class="report-kpi
               dark:bg-[#131A18]
               dark:border-white/10"
      >

        <div
          class="flex
                 items-center
                 gap-3.5"
        >

          <div
            class="w-11 h-11
                   rounded-xl
                   ${card.bg}
                   ${card.color}
                   flex
                   items-center
                   justify-center
                   shrink-0"
          >

            <i
              data-lucide="${card.icon}"
              class="w-5 h-5"
            ></i>

          </div>

          <div
            class="min-w-0"
          >

            <div
              class="text-[11px]
                     sm:text-xs
                     font-semibold
                     text-[#718982]
                     dark:text-gray-400"
            >
              ${card.label}
            </div>

            <div
              class="text-2xl
                     font-bold
                     text-[#103a32]
                     dark:text-white
                     mt-0.5"
            >
              ${card.value}
            </div>

          </div>

        </div>

        <div
          class="absolute
                 -right-7
                 -bottom-8
                 w-20
                 h-20
                 rounded-full
                 bg-emerald-500/[0.035]"
        ></div>

      </div>

    `).join('');

}


/*
 * ================================================================
 * ACADEMIC PERFORMANCE TREND
 * ================================================================
 */

function renderPerformanceTrend(
  report,
  stats
) {

  const canvas =
    document.getElementById(
      'performance-trend-chart'
    );

  if (
    !canvas ||
    !window.Chart
  ) {
    return;
  }

  const container =
    canvas.parentElement;

  const trend =
    report?.performance_trend;

  if (performanceTrendChart) {
    performanceTrendChart.destroy();
    performanceTrendChart = null;
  }

  const existingNotice =
    document.getElementById('trend-empty-state');
  if (existingNotice) {
    existingNotice.remove();
  }

  const hasHistory =
    Boolean(
      trend &&
      trend.has_history &&
      Array.isArray(trend.labels) &&
      trend.labels.length > 0
    );

  if (!hasHistory) {
    canvas.classList.add('hidden');
    const notice = document.createElement('div');
    notice.id = 'trend-empty-state';
    notice.className = 'h-full min-h-[220px] flex flex-col items-center justify-center text-center p-6';
    notice.innerHTML = `
      <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center mb-2.5">
        <i data-lucide="trending-up" class="w-5 h-5"></i>
      </div>
      <div class="text-sm font-semibold text-gray-700 dark:text-gray-200">Limited Historical Activity</div>
      <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 max-w-sm">
        Not enough completed tasks or recorded study sessions in this period to establish a historical trend. As you complete tasks and focus sessions, your progress trend will be mapped here honestly.
      </p>
    `;
    container.appendChild(notice);
    refreshLucide();
    return;
  }

  canvas.classList.remove('hidden');

  const labels =
    trend.labels;

  const completion =
    trend.completion_rates.map(
      value => safeNumber(value)
    );

  const hours =
    trend.study_hours.map(
      value => safeNumber(value)
    );

  const tasksCompleted =
    trend.tasks_completed.map(
      value => safeNumber(value)
    );

  const maxHours =
    Math.max(
      1,
      ...hours
    );

  const studyAsPercent =
    hours.map(
      value =>
        Math.round(
          (value / maxHours) * 100
        )
    );

  performanceTrendChart =
    new Chart(
      canvas,
      {
        type: 'line',

        data: {
          labels,

          datasets: [
            {
              label:
                'Completion Rate',

              data:
                completion,

              borderColor:
                '#0f9f63',

              backgroundColor:
                'rgba(15,159,99,.07)',

              pointBackgroundColor:
                '#0f9f63',

              pointBorderColor:
                '#fff',

              pointBorderWidth:
                2,

              pointRadius:
                3,

              borderWidth:
                2.25,

              tension:
                0.36,

              fill:
                true
            },

            {
              label:
                'Study Hours',

              data:
                studyAsPercent,

              borderColor:
                '#3b82f6',

              backgroundColor:
                'transparent',

              pointBackgroundColor:
                '#3b82f6',

              pointRadius:
                2.5,

              borderWidth:
                2,

              tension:
                0.36,

              fill:
                false
            },

            {
              label:
                'Tasks Completed',

              data:
                tasksCompleted,

              borderColor:
                '#f59e0b',

              backgroundColor:
                'transparent',

              pointBackgroundColor:
                '#f59e0b',

              pointRadius:
                2.5,

              borderWidth:
                2,

              tension:
                0.36,

              fill:
                false
            }
          ]
        },

        options: {
          responsive:
            true,

          maintainAspectRatio:
            false,

          interaction: {
            mode:
              'index',

            intersect:
              false
          },

          plugins: {
            legend: {
              display:
                false
            },

            tooltip: {
              backgroundColor:
                '#123b33',

              titleColor:
                '#fff',

              bodyColor:
                '#d9ebe4',

              borderColor:
                'rgba(255,255,255,.12)',

              borderWidth:
                1,

              padding:
                11,

              displayColors:
                true,

              callbacks: {
                label: context => {
                  const datasetLabel = context.dataset.label || '';
                  const idx = context.dataIndex;
                  if (datasetLabel === 'Completion Rate') {
                    return `Completion Rate: ${formatPercent(context.parsed.y)}`;
                  } else if (datasetLabel === 'Study Hours') {
                    return `Study Hours: ${hours[idx]}h`;
                  } else if (datasetLabel === 'Tasks Completed') {
                    return `Tasks Completed: ${tasksCompleted[idx]}`;
                  }
                  return `${datasetLabel}: ${context.parsed.y}`;
                }
              }
            }
          },

          scales: {
            x: {
              grid: {
                color:
                  getChartGridColor(),

                drawBorder:
                  false
              },

              ticks: {
                color:
                  getChartTextColor(),

                font: {
                  size:
                    11
                }
              }
            },

            y: {
              min:
                0,

              max:
                100,

              grid: {
                color:
                  getChartGridColor(),

                drawBorder:
                  false
              },

              ticks: {
                color:
                  getChartTextColor(),

                font: {
                  size:
                    10
                },

                callback:
                  value =>
                    `${value}%`
              }
            }
          }
        }
      }
    );

  const legend =
    document.getElementById(
      'performance-trend-legend'
    );

  if (legend) {
    const colors = [
      '#0f9f63',
      '#3b82f6',
      '#f59e0b'
    ];

    const labelsForLegend = [
      'Completion Rate',
      'Study Hours',
      'Tasks Completed'
    ];

    legend.innerHTML =
      labelsForLegend.map(
        (label, index) => `
          <span
            class="inline-flex
                   items-center
                   gap-2
                   text-[#5f7972]
                   dark:text-gray-400"
          >
            <span
              class="w-2 h-2
                     rounded-full"
              style="background:${colors[index]}"
            ></span>
            ${label}
          </span>
        `
      ).join('');
  }
}


/*
 * ================================================================
 * TASK STATUS
 * ================================================================
 */

function renderTaskStatus(
  report,
  stats
) {

  const canvas =
    document.getElementById(
      'task-status-chart'
    );

  const totalEl =
    document.getElementById(
      'task-status-total'
    );

  const legend =
    document.getElementById(
      'task-status-legend'
    );


  if (!canvas) {
    return;
  }


  const breakdown =
    report?.task_status_distribution ||
    stats?.task_breakdown ||
    {};


  const data =
    STATUS_CONFIG.map(
      item =>
        safeNumber(
          breakdown[item.key]
        )
    );


  const totalFromStats =
    data.reduce(
      (sum, value) =>
        sum + value,
      0
    );


  const fallbackTotal =
    safeNumber(
      report.tasks_created
    );


  const total =
    totalFromStats ||
    fallbackTotal;


  if (totalEl) {
    totalEl.textContent =
      total;
  }


  if (taskStatusChart) {
    taskStatusChart.destroy();
    taskStatusChart = null;
  }


  if (window.Chart) {
    taskStatusChart =
      new Chart(
        canvas,
        {

          type:
            'doughnut',

          data: {

            labels:
              STATUS_CONFIG.map(
                item => item.label
              ),

            datasets: [

              {
                data,

                backgroundColor:
                  STATUS_CONFIG.map(
                    item =>
                      item.color
                  ),

                borderWidth:
                  0,

                hoverOffset:
                  4
              }

            ]

          },

          options: {

            responsive:
              true,

            maintainAspectRatio:
              false,

            cutout:
              '70%',

            plugins: {
              legend: {
                display:
                  false
              },
              tooltip: {
                callbacks: {
                  label: context => {
                    const val = context.parsed;
                    const pct = total > 0 ? formatPercent((val / total) * 100) : '0%';
                    return ` ${context.label}: ${val} (${pct})`;
                  }
                }
              }
            }

          }

        }
      );
  }


  if (legend) {

    legend.innerHTML =
      STATUS_CONFIG.map(
        (item, index) => {

          const value =
            data[index];

          const percent =
            total > 0
              ? formatPercent(
                  (value /
                    total) *
                  100
                )
              : '0%';


          return `

            <div
              class="flex
                     items-center
                     gap-2.5"
            >

              <span
                class="w-2.5 h-2.5
                       rounded-full
                       shrink-0"
                style="background:${item.color}"
              ></span>

              <span
                class="text-[#5f7972]
                       dark:text-gray-300
                       flex-1"
              >
                ${item.label}
              </span>

              <span
                class="font-bold
                       text-[#163f36]
                       dark:text-white"
              >
                ${value}
              </span>

              <span
                class="text-[10px]
                       text-[#7c938d]
                       dark:text-gray-500
                       w-12
                       text-right"
              >
                ${percent}
              </span>

            </div>

          `;

        }
      ).join('');

  }

}


/*
 * ================================================================
 * WEEKLY STUDY TIME
 * ================================================================
 */

function renderWeeklyStudy(report) {

  const container =
    document.getElementById(
      'weekly-study-chart'
    );

  const totalEl =
    document.getElementById(
      'weekly-study-total'
    );


  if (!container) {
    return;
  }


  const activity =
    Array.isArray(
      report.weekly_activity
    )
      ? report.weekly_activity
      : [];


  const maxHours =
    Math.max(
      1,
      ...activity.map(
        item =>
          safeNumber(
            item.hours
          )
      )
    );


  const totalHours =
    activity.reduce(
      (sum, item) =>
        sum +
        safeNumber(
          item.hours
        ),
      0
    );


  if (totalEl) {

    totalEl.textContent =
      `${totalHours.toFixed(1)}h`;

  }


  if (!activity.length) {

    container.innerHTML = `

      <div
        class="h-full
               flex
               flex-col
               items-center
               justify-center
               text-center"
      >

        <i
          data-lucide="clock-off"
          class="w-7 h-7
                 text-gray-300
                 dark:text-gray-600
                 mb-2"
        ></i>

        <div
          class="text-sm
                 font-semibold
                 text-gray-600
                 dark:text-gray-300"
        >
          No study activity yet
        </div>

        <div
          class="text-xs
                 text-gray-400
                 dark:text-gray-500
                 mt-1"
        >
          Study time will appear here as
          sessions are completed.
        </div>

      </div>

    `;

    refreshLucide();

    return;
  }


  container.innerHTML = `

    <div
      class="h-full
             flex
             items-end
             justify-between
             gap-2 sm:gap-4
             pt-4
             pb-2"
    >

      ${activity.map(item => {

        const hours =
          safeNumber(
            item.hours
          );

        const height =
          Math.max(
            5,
            Math.round(
              (hours /
                maxHours) *
              175
            )
          );


        return `

          <div
            class="h-full
                   flex
                   flex-1
                   min-w-0
                   flex-col
                   justify-end
                   items-center"
          >

            <div
              class="text-[10px]
                     sm:text-xs
                     font-bold
                     text-[#4d7269]
                     dark:text-gray-300
                     mb-2"
            >
              ${hours}h
            </div>


            <div
              class="w-full
                     max-w-[54px]
                     h-[180px]
                     flex
                     items-end"
            >

              <div
                class="mini-bar
                       w-full
                       rounded-t-lg
                       bg-gradient-to-t
                       from-emerald-700
                       to-emerald-400
                       dark:from-emerald-700
                       dark:to-emerald-500
                       shadow-sm"
                style="height:${height}px"
                title="${escapeValue(
                  item.label
                )}: ${hours} hours"
              ></div>

            </div>


            <div
              class="text-[10px]
                     sm:text-xs
                     font-semibold
                     text-[#6a8580]
                     dark:text-gray-400
                     mt-2"
            >
              ${escapeValue(
                item.label
              )}
            </div>

          </div>

        `;

      }).join('')}

    </div>

  `;

}


/*
 * ================================================================
 * SUBJECT BREAKDOWN
 * ================================================================
 */

function renderSubjectBreakdown(
  coursesData,
  report
) {

  const container =
    document.getElementById(
      'subject-breakdown'
    );

  const countEl =
    document.getElementById(
      'subject-count'
    );


  if (!container) {
    return;
  }


  const courses =
    Array.isArray(
      report?.course_comparison
    ) &&
    report.course_comparison.length > 0
      ? report.course_comparison.slice(0, 6)
      : Array.isArray(
          coursesData?.courses
        )
        ? coursesData.courses.slice(0, 6)
        : [];


  if (countEl) {

    countEl.textContent =
      `${courses.length}
       ${courses.length === 1
          ? 'subject'
          : 'subjects'}`;

  }


  if (!courses.length) {

    container.innerHTML = `

      <div
        class="py-8
               text-center"
      >

        <i
          data-lucide="book-open"
          class="w-7 h-7
                 text-gray-300
                 dark:text-gray-600
                 mx-auto
                 mb-2"
        ></i>

        <p
          class="text-sm
                 font-semibold
                 text-gray-600
                 dark:text-gray-300"
        >
          No course data available
        </p>

        <p
          class="text-xs
                 text-gray-400
                 dark:text-gray-500
                 mt-1"
        >
          Add a course to see subject progress.
        </p>

      </div>

    `;

    refreshLucide();

    return;
  }


  container.innerHTML =
    courses.map(
      (course, index) => {

        const progress =
          Math.min(
            100,
            Math.max(
              0,
              safeNumber(
                course.completion_rate ??
                course.progress
              )
            )
          );


        const name =
          escapeValue(
            course.code ||
            course.name ||
            'Course'
          );


        const title =
          escapeValue(
            course.name ||
            ''
          );


        const icon =
          COURSE_ICONS[
            index %
            COURSE_ICONS.length
          ];


        return `

          <div>

            <div
              class="flex
                     items-center
                     gap-2.5
                     mb-2"
            >

              <div
                class="w-8 h-8
                       rounded-lg
                       bg-emerald-50
                       dark:bg-emerald-950/30
                       flex
                       items-center
                       justify-center"
              >

                <i
                  data-lucide="${icon}"
                  class="w-4 h-4
                         text-emerald-700
                         dark:text-emerald-400"
                ></i>

              </div>


              <div
                class="min-w-0
                       flex-1"
              >

                <div
                  class="text-xs
                         font-bold
                         text-[#264a42]
                         dark:text-gray-200
                         truncate"
                >
                  ${name}
                </div>

                <div
                  class="text-[10px]
                         text-[#728a84]
                         dark:text-gray-500
                         truncate"
                >
                  ${title}
                </div>

              </div>


              <span
                class="text-xs
                       font-bold
                       text-[#234940]
                       dark:text-white"
              >
                ${formatPercent(progress)}
              </span>

            </div>


            <div
              class="subject-progress"
            >
              <span
                style="width:${progress}%"
              ></span>
            </div>

          </div>

        `;

      }
    ).join('');


  refreshLucide();
}


/*
 * ================================================================
 * COURSE PERFORMANCE
 * ================================================================
 */

function renderCoursePerformance(
  coursesData,
  report
) {

  const container =
    document.getElementById(
      'course-performance'
    );


  if (!container) {
    return;
  }


  const courses =
    Array.isArray(
      report?.course_comparison
    ) &&
    report.course_comparison.length > 0
      ? report.course_comparison.slice(0, 5)
      : Array.isArray(
          coursesData?.courses
        )
        ? coursesData.courses.slice(0, 5)
        : [];


  if (!courses.length) {

    container.innerHTML = `

      <div
        class="py-10
               text-center"
      >

        <i
          data-lucide="book-x"
          class="w-8 h-8
                 text-gray-300
                 dark:text-gray-600
                 mx-auto
                 mb-2"
        ></i>

        <p
          class="text-sm
                 font-semibold
                 text-gray-600
                 dark:text-gray-300"
        >
          No course performance data
        </p>

        <p
          class="text-xs
                 text-gray-400
                 dark:text-gray-500
                 mt-1"
        >
          Your course progress will appear here.
        </p>

      </div>

    `;

    refreshLucide();

    return;
  }


  container.innerHTML =
    courses.map(
      (course, index) => {

        const progress =
          Math.min(
            100,
            Math.max(
              0,
              safeNumber(
                course.completion_rate ??
                course.progress
              )
            )
          );


        const courseCode =
          escapeValue(
            course.code ||
            `Course ${index + 1}`
          );


        const courseName =
          escapeValue(
            course.name ||
            ''
          );


        const completed =
          safeNumber(
            course.completed_tasks ??
            course.completed_count
          );


        const taskCount =
          safeNumber(
            course.total_tasks ??
            course.task_count
          );

        const focusHours =
          safeNumber(
            course.focus_hours ??
            course.focused_hours ??
            0
          );


        const icon =
          COURSE_ICONS[
            index %
            COURSE_ICONS.length
          ];


        let status =
          'Needs attention';


        let statusClass =
          'text-amber-600 dark:text-amber-400';


        if (progress >= 80) {

          status =
            'Strong progress';

          statusClass =
            'text-emerald-700 dark:text-emerald-400';

        } else if (progress >= 50) {

          status =
            'On track';

          statusClass =
            'text-blue-600 dark:text-blue-400';

        }


        return `

          <div
            class="flex
                   items-center
                   gap-3"
          >

            <div
              class="w-10 h-10
                     rounded-xl
                     bg-gray-50
                     dark:bg-white/[0.05]
                     flex
                     items-center
                     justify-center
                     shrink-0"
            >

              <i
                data-lucide="${icon}"
                class="w-5 h-5
                       text-emerald-700
                       dark:text-emerald-400"
              ></i>

            </div>


            <div
              class="min-w-0
                     flex-1"
            >

              <div
                class="flex
                       items-center
                       justify-between
                       gap-3
                       mb-1.5"
              >

                <div
                  class="min-w-0"
                >

                  <div
                    class="text-sm
                           font-bold
                           text-[#183f37]
                           dark:text-white
                           truncate"
                  >
                    ${courseCode}
                  </div>

                  <div
                    class="text-[11px]
                           text-[#69827c]
                           dark:text-gray-400
                           truncate"
                  >
                    ${courseName}
                  </div>

                </div>


                <div
                  class="text-sm
                         font-bold
                         text-[#183f37]
                         dark:text-white
                         shrink-0"
                >
                  ${formatPercent(progress)}
                </div>

              </div>


              <div
                class="h-2
                       bg-gray-100
                       dark:bg-white/10
                       rounded-full
                       overflow-hidden"
              >

                <div
                  class="h-full
                         rounded-full
                         bg-emerald-600
                         dark:bg-emerald-500"
                  style="width:${progress}%"
                ></div>

              </div>


              <div
                class="flex
                       items-center
                       justify-between
                       mt-1.5
                       gap-3"
              >

                <span
                  class="text-[10px]
                         ${statusClass}
                         font-semibold"
                >
                  ${status}
                </span>

                <span
                  class="text-[10px]
                         text-gray-400
                         dark:text-gray-500"
                >
                  ${completed}/${taskCount} tasks${focusHours > 0 ? ` • ${focusHours.toFixed(1)}h focus` : ''}
                </span>

              </div>

            </div>

          </div>

        `;

      }
    ).join('');


  refreshLucide();
}


/*
 * ================================================================
 * INSIGHTS
 * ================================================================
 */

function renderInsights(report) {

  const container =
    document.getElementById(
      'performance-insights'
    );

  const action =
    document.getElementById(
      'suggested-action'
    );


  if (container) {

    const insights =
      Array.isArray(
        report.insights
      )
        ? report.insights
        : [];


    container.innerHTML =
      insights.length

        ? insights.map(
            item => {

              const icon =
                INSIGHT_ICON[
                  item.icon
                ] ||
                'info';


              const color =
                INSIGHT_COLOR[
                  item.icon
                ] ||
                INSIGHT_COLOR.info;


              return `

                <div
                  class="flex
                         items-start
                         gap-3
                         p-3
                         rounded-xl
                         bg-[#fbfdfc]
                         dark:bg-white/[0.025]
                         border
                         border-[#edf3f1]
                         dark:border-white/[0.06]"
                >

                  <div
                    class="w-8 h-8
                           rounded-lg
                           bg-white
                           dark:bg-white/[0.045]
                           border
                           border-[#e8f0ed]
                           dark:border-white/[0.06]
                           flex
                           items-center
                           justify-center
                           shrink-0"
                  >

                    <i
                      data-lucide="${icon}"
                      class="w-4 h-4 ${color}"
                    ></i>

                  </div>


                  <div
                    class="text-sm
                           leading-6
                           text-[#516d66]
                           dark:text-gray-300"
                  >
                    ${escapeValue(
                      item.text || ''
                    )}
                  </div>

                </div>

              `;

            }
          ).join('')

        : `

            <div
              class="p-4
                     rounded-xl
                     bg-gray-50
                     dark:bg-white/[0.03]
                     text-sm
                     text-gray-500
                     dark:text-gray-400"
            >
              Not enough activity yet to generate insights.
            </div>

          `;

  }


  if (action) {

    action.textContent =
      report.suggested_action ||
      'No suggested action available yet.';

  }

}


/*
 * ================================================================
 * RECENT REPORTS
 * ================================================================
 */

function renderRecentReports(report) {

  const body =
    document.getElementById(
      'recent-reports-body'
    );


  if (!body) {
    return;
  }


  const rangeLabel =
    report.range_label ||
    'Current period';


  const generated =
    new Date().toLocaleString(
      [],
      {
        month:
          'short',

        day:
          'numeric',

        year:
          'numeric',

        hour:
          'numeric',

        minute:
          '2-digit'
      }
    );


  const rows = [

    [
      'Weekly Study Report',
      rangeLabel,
      generated,
      'clock-3'
    ],

    [
      'Course Performance Report',
      rangeLabel,
      generated,
      'book-open-check'
    ],

    [
      'Task Completion Report',
      rangeLabel,
      generated,
      'clipboard-check'
    ],

    [
      'Overall Progress Report',
      rangeLabel,
      generated,
      'chart-no-axes-combined'
    ]

  ];


  body.innerHTML =
    rows.map(
      row => `

        <tr
          class="report-table-row
                 border-t
                 border-[#edf3f1]
                 dark:border-white/[0.06]
                 transition-colors"
        >

          <td
            class="px-5 sm:px-6
                   py-3.5"
          >

            <div
              class="flex
                     items-center
                     gap-3"
            >

              <div
                class="w-8 h-8
                       rounded-lg
                       bg-gray-50
                       dark:bg-white/[0.05]
                       flex
                       items-center
                       justify-center"
              >

                <i
                  data-lucide="${row[3]}"
                  class="w-4 h-4
                         text-emerald-700
                         dark:text-emerald-400"
                ></i>

              </div>

              <span
                class="text-sm
                       font-semibold
                       text-[#24483f]
                       dark:text-gray-200"
              >
                ${row[0]}
              </span>

            </div>

          </td>


          <td
            class="px-5 sm:px-6
                   py-3.5
                   text-xs
                   text-[#68827c]
                   dark:text-gray-400"
          >
            ${escapeValue(row[1])}
          </td>


          <td
            class="px-5 sm:px-6
                   py-3.5
                   text-xs
                   text-[#68827c]
                   dark:text-gray-400"
          >
            ${escapeValue(row[2])}
          </td>


          <td
            class="px-5 sm:px-6
                   py-3.5"
          >

            <div
              class="flex
                     items-center
                     justify-end
                     gap-2"
            >

              <button
                type="button"
                class="report-action-btn
                       inline-flex
                       items-center
                       gap-1.5
                       text-xs
                       font-semibold
                       text-emerald-700
                       dark:text-emerald-400
                       bg-emerald-50
                       dark:bg-emerald-950/30
                       rounded-lg
                       px-3
                       py-2"
                data-report-action="view"
                data-report-name="${escapeValue(row[0])}"
              >

                <i
                  data-lucide="eye"
                  class="w-3.5 h-3.5"
                ></i>

                View

              </button>


              <button
                type="button"
                class="report-action-btn
                       w-9 h-9
                       rounded-lg
                       bg-gray-50
                       dark:bg-white/[0.05]
                       flex
                       items-center
                       justify-center
                       text-gray-500
                       dark:text-gray-300"
                data-report-action="download"
                data-report-name="${escapeValue(row[0])}"
                aria-label="Download ${escapeValue(row[0])}"
              >

                <i
                  data-lucide="download"
                  class="w-3.5 h-3.5"
                ></i>

              </button>

            </div>

          </td>

        </tr>

      `
    ).join('');


  refreshLucide();
}


/*
 * ================================================================
 * GLOBAL ERROR
 * ================================================================
 */

function renderGlobalError() {

  const label =
    document.getElementById(
      'range-label'
    );

  const stats =
    document.getElementById(
      'report-stat-rows'
    );

  const activity =
    document.getElementById(
      'weekly-study-chart'
    );

  const subjects =
    document.getElementById(
      'subject-breakdown'
    );

  const courses =
    document.getElementById(
      'course-performance'
    );

  const insights =
    document.getElementById(
      'performance-insights'
    );

  const action =
    document.getElementById(
      'suggested-action'
    );


  if (label) {

    label.textContent =
      'Unable to load report data';

  }


  if (stats) {

    stats.innerHTML = `

      <div
        class="col-span-full
               report-card
               dark:bg-[#131A18]
               dark:border-white/10
               p-5"
      >

        <div
          class="flex
                 items-center
                 justify-between
                 w-full
                 gap-3
                 text-red-600
                 dark:text-red-400"
        >

          <div class="flex items-center gap-2">
            <i
              data-lucide="alert-circle"
              class="w-5 h-5"
            ></i>

            <span
              class="text-sm
                     font-semibold"
            >
              Report data could not be loaded.
              Please check your connection and try again.
            </span>
          </div>

          <button
            type="button"
            onclick="window.loadReport ? window.loadReport() : window.location.reload()"
            class="px-3 py-1 text-xs font-semibold rounded-lg bg-red-100 hover:bg-red-200 text-red-700 dark:bg-red-950/50 dark:text-red-300 dark:hover:bg-red-900/50 transition-colors"
          >
            Retry
          </button>

        </div>

      </div>

    `;

  }


  if (activity) {

    activity.innerHTML =
      '<div class="h-full flex items-center justify-center text-sm text-gray-400">Unable to load study activity.</div>';

  }


  if (subjects) {

    subjects.innerHTML =
      '<div class="text-sm text-gray-400 py-8 text-center">Unable to load subject data.</div>';

  }


  if (courses) {

    courses.innerHTML =
      '<div class="text-sm text-gray-400 py-8 text-center">Unable to load course data.</div>';

  }


  if (insights) {

    insights.innerHTML =
      '<div class="text-sm text-gray-400">Unable to load insights.</div>';

  }


  if (action) {

    action.textContent =
      'Unavailable';

  }

}


/*
 * ================================================================
 * LOADING STATE
 * ================================================================
 */

function setReportLoading(
  isLoading
) {

  const select =
    document.getElementById(
      'range-select'
    );


  if (!select) {
    return;
  }


  select.disabled =
    isLoading;


  select.classList.toggle(
    'opacity-60',
    isLoading
  );


  select.classList.toggle(
    'cursor-wait',
    isLoading
  );

}


/*
 * ================================================================
 * RANGE CHANGE
 * ================================================================
 */

document
  .getElementById('range-select')
  ?.addEventListener(
    'change',
    loadReport
  );


/*
 * ================================================================
 * RECENT REPORT BUTTONS
 * ================================================================
 */

document.addEventListener(
  'click',
  event => {

    const button =
      event.target.closest(
        '.report-action-btn'
      );


    if (!button) {
      return;
    }


    const action =
      button.dataset.reportAction;


    const reportName =
      button.dataset.reportName ||
      'Report';


    if (action === 'view') {

      window.showToast?.(
        `${reportName} is displayed on this page.`,
        'success'
      );

    }


    if (action === 'download') {
      const rangeSelect = document.getElementById('range-select');
      const currentRange = rangeSelect ? rangeSelect.value : 'week';
      const apiBase = typeof API !== 'undefined' ? API : (window.API || '../api');
      window.location.href = `${apiBase}/reports.php?range=${encodeURIComponent(currentRange)}&export=csv`;
    }

  }
);


/*
 * ================================================================
 * EXISTING APPLICATION BOOT
 * ================================================================
 */

if (window.CURRENT_USER) {
  loadReport();
} else if (window.APP_READY && typeof window.APP_READY.then === 'function') {
  window.APP_READY.then(
    me => {
      if (me || window.CURRENT_USER) {
        loadReport();
      }
    }
  ).catch(() => {
    loadReport();
  });
} else {
  loadReport();
}

window.loadReport = loadReport;

refreshLucide();