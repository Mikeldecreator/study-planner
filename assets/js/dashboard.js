/* =========================================================
   STUDY PLANNER — DASHBOARD
   UI redesign while preserving existing functionality
========================================================= */

/*
 * API comes from nav.js.
 * nav.js is loaded before this file.
 */

let breakdownChart = null;
let weeklyChart = null;

let overdueAlertDismissed = false;
let dashboardLoading = false;


/* =========================================================
   HELPERS
========================================================= */

function dashboardEscape(value) {
    if (typeof escapeHtml === 'function') {
        return escapeHtml(value == null ? '' : String(value));
    }

    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);

    return div.innerHTML;
}


function dashboardCap(value) {
    if (typeof capitalize === 'function') {
        return capitalize(value || '');
    }

    if (!value) return '';

    return String(value)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, char => char.toUpperCase());
}


function dashboardNumber(value, fallback = 0) {
    const number = Number(value);

    return Number.isFinite(number)
        ? number
        : fallback;
}


function dashboardFormatHours(value) {
    const hours = dashboardNumber(value);

    if (Number.isInteger(hours)) {
        return `${hours} hrs`;
    }

    return `${hours.toFixed(1)} hrs`;
}


function dashboardShowToast(message, type = 'info') {
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
    } else {
        console[type === 'error' ? 'error' : 'log'](message);
    }
}


/* =========================================================
   ANIMATIONS
========================================================= */

function animateDashboardCards() {

    document
        .querySelectorAll(
            '#stat-cards > *, #today-schedule > *, #upcoming-deadlines > *, #recent-activity > *'
        )
        .forEach((element, index) => {

            element.classList.add(
                'dashboard-card-enter'
            );

            element.style.animationDelay =
                `${index * 0.05}s`;
        });
}


function animateProgressBars() {

    document
        .querySelectorAll(
            '.dashboard-progress-bar'
        )
        .forEach(bar => {

            bar.style.transform =
                'scaleX(0)';

            bar.style.transformOrigin =
                'left center';

            requestAnimationFrame(() => {

                bar.style.transition =
                    'transform .7s ease';

                bar.style.transform =
                    'scaleX(1)';
            });
        });
}


function animateListItems() {

    document
        .querySelectorAll(
            '#today-schedule > *, #upcoming-deadlines > *, #recent-activity > *'
        )
        .forEach((element, index) => {

            element.classList.add(
                'dashboard-list-item'
            );

            element.style.animationDelay =
                `${index * 0.06}s`;
        });
}


/* =========================================================
   PRIORITIES
========================================================= */

const priorityClasses = {

    high:
        'priority-high',

    medium:
        'priority-medium',

    low:
        'priority-low'

};


/* =========================================================
   HERO
========================================================= */

function renderDashboardHero(data) {

    const firstName =
        String(data.user_name || 'Student')
            .trim()
            .split(/\s+/)[0];

    const firstNameElement =
        document.getElementById(
            'first-name'
        );

    const headerName =
        document.getElementById(
            'header-first-name'
        );

    if (firstNameElement) {
        firstNameElement.textContent =
            firstName;
    }

    if (headerName) {
        headerName.textContent =
            firstName;
    }


    const greeting =
        document.getElementById(
            'greeting'
        );

    if (greeting) {

        const greetingText =
            data.greeting ||
            'afternoon';

        greeting.textContent =
            `Good ${greetingText}`;
    }


    /*
     * These values are optional because the existing
     * dashboard API may not return them.
     */
    const courseName =
        data.course_name ||
        data.program ||
        data.course ||
        'Computer Science';

    const level =
        data.level ||
        data.level_name ||
        'Level 400';


    const heroCourse =
        document.getElementById(
            'hero-course-name'
        );

    if (heroCourse) {
        heroCourse.textContent =
            courseName;
    }


    const heroLevel =
        document.getElementById(
            'hero-level'
        );

    if (heroLevel) {
        heroLevel.textContent =
            level;
    }


    /*
     * Next deadline.
     */
    const nextDeadline =
        data.upcoming &&
        data.upcoming.length
            ? data.upcoming[0]
            : null;


    const nextDeadlineElement =
        document.getElementById(
            'hero-next-deadline'
        );


    if (nextDeadlineElement) {

        if (nextDeadline) {

            const title =
                nextDeadline.title ||
                'Upcoming task';

            const due =
                nextDeadline.due_label ||
                nextDeadline.due_at_display ||
                '';

            nextDeadlineElement.textContent =
                due
                    ? `${title} • ${due}`
                    : title;

        } else {

            nextDeadlineElement.textContent =
                'No upcoming deadlines';

        }
    }
}


/* =========================================================
   TODAY'S SCHEDULE
========================================================= */

function renderTodaySchedule(schedule) {

    const element =
        document.getElementById(
            'today-schedule'
        );

    if (!element) return;


    if (
        !Array.isArray(schedule) ||
        schedule.length === 0
    ) {

        element.innerHTML = `
            <div class="py-6 text-center">
                <div class="w-10 h-10 mx-auto mb-2 rounded-full bg-green-50 dark:bg-green-500/10 flex items-center justify-center">
                    <i data-lucide="calendar-x" class="w-5 h-5 text-green-700 dark:text-green-400"></i>
                </div>

                <p class="text-sm text-gray-400">
                    Nothing scheduled today.
                </p>
            </div>
        `;

        return;
    }


    element.innerHTML =
        schedule
            .map((event, index) => {

                const eventType =
                    dashboardCap(
                        event.event_type
                    );

                const title =
                    dashboardEscape(
                        event.title
                    );

                const course =
                    event.course_code
                        ? ` • ${dashboardEscape(event.course_code)}`
                        : '';

                const start =
                    dashboardEscape(
                        event.start_label || ''
                    );

                const end =
                    dashboardEscape(
                        event.end_label || ''
                    );


                const badgeClass =
                    String(event.event_type || '')
                        .toLowerCase()
                        .includes('study')
                        ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                        : 'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300';


                return `

                    <div class="schedule-row flex items-start gap-3 relative dashboard-list-item"
                         style="animation-delay:${index * .06}s">

                        <div class="schedule-time">

                            <div>
                                ${start}
                            </div>

                            ${
                                end
                                    ? `<div class="mt-1 opacity-80">${end}</div>`
                                    : ''
                            }

                        </div>


                        <div class="relative pt-1">

                            <div class="schedule-dot"></div>

                        </div>


                        <div class="flex-1 min-w-0">

                            <div class="schedule-title">
                                ${title}
                            </div>

                            <div class="schedule-meta">
                                ${eventType}${course}
                            </div>

                        </div>


                        <span
                            class="event-badge ${badgeClass}"
                        >
                            ${eventType}
                        </span>

                    </div>
                `;
            })
            .join('');
}


/* =========================================================
   UPCOMING DEADLINES
========================================================= */

function deadlineIcon(type, urgency) {

    if (urgency === 'overdue') {
        return {
            icon: 'alert-triangle',
            className:
                'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300'
        };
    }

    if (type === 'project') {
        return {
            icon: 'folder-kanban',
            className:
                'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300'
        };
    }

    if (
        type === 'test' ||
        type === 'exam'
    ) {
        return {
            icon: 'file-warning',
            className:
                'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300'
        };
    }

    return {
        icon: 'file-text',
        className:
            'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300'
    };
}


function renderUpcomingDeadlines(deadlines) {

    const element =
        document.getElementById(
            'upcoming-deadlines'
        );

    if (!element) return;


    if (
        !Array.isArray(deadlines) ||
        deadlines.length === 0
    ) {

        element.innerHTML = `
            <div class="py-6 text-center">
                <div class="w-10 h-10 mx-auto mb-2 rounded-full bg-green-50 dark:bg-green-500/10 flex items-center justify-center">
                    <i data-lucide="party-popper" class="w-5 h-5 text-green-700 dark:text-green-400"></i>
                </div>

                <p class="text-sm text-gray-400">
                    No upcoming deadlines.
                </p>
            </div>
        `;

        return;
    }


    element.innerHTML =
        deadlines
            .map((task, index) => {

                const priority =
                    String(
                        task.priority || ''
                    ).toLowerCase();

                const priorityClass =
                    priorityClasses[
                        priority
                    ] ||
                    'text-gray-500 dark:text-gray-400';


                const icons =
                    deadlineIcon(
                        task.type,
                        task.urgency
                    );


                const title =
                    dashboardEscape(
                        task.title
                    );

                const course =
                    dashboardEscape(
                        task.course_code || ''
                    );

                const dueLabel =
                    dashboardEscape(
                        task.due_label || ''
                    );

                const dueDisplay =
                    dashboardEscape(
                        task.due_at_display || ''
                    );

                const priorityLabel =
                    dashboardEscape(
                        task.priority_label ||
                        dashboardCap(priority) ||
                        'TASK'
                    );


                const urgent =
                    task.urgency ===
                    'overdue';


                return `

                    <div
                        class="deadline-row flex items-center gap-3 dashboard-list-item"
                        style="animation-delay:${index * .06}s"
                    >

                        <div
                            class="deadline-icon ${icons.className}"
                        >
                            <i
                                data-lucide="${icons.icon}"
                                class="w-4 h-4"
                            ></i>
                        </div>


                        <div class="flex-1 min-w-0">

                            <div
                                class="text-[10px] uppercase font-bold ${priorityClass}"
                            >
                                ${priorityLabel}
                            </div>

                            <div class="deadline-title mt-0.5 truncate">
                                ${title}
                            </div>

                            ${
                                course
                                    ? `
                                        <div class="deadline-course">
                                            ${course}
                                        </div>
                                      `
                                    : ''
                            }

                        </div>


                        <div class="text-right shrink-0">

                            <div
                                class="text-xs font-semibold ${
                                    urgent
                                        ? 'text-red-600'
                                        : 'text-amber-600 dark:text-amber-300'
                                }"
                            >
                                ${dueLabel}
                            </div>

                            <div class="deadline-date ${
                                urgent ? 'urgent' : ''
                            }">
                                ${dueDisplay}
                            </div>

                        </div>

                    </div>
                `;
            })
            .join('');
}


/* =========================================================
   RECENT ACTIVITY
========================================================= */

function activityPresentation(type) {

    switch (
        String(type || '').toLowerCase()
    ) {

        case 'success':
            return {
                icon: 'check-circle-2',
                className:
                    'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300'
            };

        case 'warning':
            return {
                icon: 'alert-triangle',
                className:
                    'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300'
            };

        default:
            return {
                icon: 'info',
                className:
                    'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300'
            };
    }
}


function renderRecentActivity(activity) {

    const element =
        document.getElementById(
            'recent-activity'
        );

    if (!element) return;


    if (
        !Array.isArray(activity) ||
        activity.length === 0
    ) {

        element.innerHTML = `
            <div class="py-6 text-center">
                <div class="w-10 h-10 mx-auto mb-2 rounded-full bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center">
                    <i data-lucide="activity" class="w-5 h-5 text-blue-600 dark:text-blue-300"></i>
                </div>

                <p class="text-xs text-gray-400">
                    No activity yet.
                </p>
            </div>
        `;

        return;
    }


    element.innerHTML =
        activity
            .map((item, index) => {

                const presentation =
                    activityPresentation(
                        item.icon
                    );

                return `

                    <div
                        class="activity-row flex items-start gap-3 dashboard-list-item"
                        style="animation-delay:${index * .06}s"
                    >

                        <div
                            class="activity-icon ${presentation.className}"
                        >
                            <i
                                data-lucide="${presentation.icon}"
                                class="w-4 h-4"
                            ></i>
                        </div>


                        <div class="flex-1 min-w-0">

                            <div class="activity-text ${
                                item.icon === 'warning'
                                    ? 'text-red-600 dark:text-red-300'
                                    : ''
                            }">
                                ${dashboardEscape(item.message)}
                            </div>

                            <div class="activity-time">
                                ${dashboardEscape(item.time_ago || '')}
                            </div>

                        </div>

                    </div>
                `;
            })
            .join('');
}


/* =========================================================
   LOAD DASHBOARD DATA
========================================================= */

async function loadDashboard() {

    try {

        const response =
            await fetch(
                `${API}/dashboard.php`,
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept:
                            'application/json'
                    }
                }
            );


        if (!response.ok) {

            if (response.status === 401) {
                return;
            }

            throw new Error(
                `Dashboard request failed (${response.status})`
            );
        }


        const data =
            await response.json();


        renderDashboardHero(data);

        renderTodaySchedule(
            data.today_schedule
        );

        renderUpcomingDeadlines(
            data.upcoming
        );

        renderRecentActivity(
            data.activity
        );


        /*
         * Populate the existing Add Task course
         * dropdown without duplicating options
         * on repeated dashboard refreshes.
         */
        const courseSelect =
            document.getElementById(
                'course-select'
            );


        if (courseSelect) {

            const currentValue =
                courseSelect.value;


            courseSelect.innerHTML =
                `<option value="">No course</option>`;


            if (
                Array.isArray(
                    data.courses
                )
            ) {

                data.courses.forEach(
                    course => {

                        const option =
                            document.createElement(
                                'option'
                            );

                        option.value =
                            course.id;

                        option.textContent =
                            `${course.code} — ${course.name}`;

                        courseSelect.appendChild(
                            option
                        );
                    }
                );
            }


            if (
                currentValue &&
                Array.from(
                    courseSelect.options
                ).some(
                    option =>
                        option.value ===
                        currentValue
                )
            ) {
                courseSelect.value =
                    currentValue;
            }
        }


        if (window.lucide) {
            window.lucide.createIcons();
        }


        animateListItems();
        animateDashboardCards();

    } catch (error) {

        console.error(
            'Dashboard loading error:',
            error
        );

        dashboardShowToast(
            'Unable to load some dashboard information.',
            'error'
        );
    }
}


/* =========================================================
   STAT CARD
========================================================= */

function statCard(
    icon,
    iconColor,
    iconBackground,
    value,
    label,
    trend,
    trendColor = 'green'
) {

    const trendClass =
        trendColor === 'red'
            ? 'text-red-600'
            : trendColor === 'amber'
                ? 'text-amber-600'
                : 'text-green-600';


    return `

        <div class="dashboard-card stat-card p-4 sm:p-5 flex items-center gap-3 sm:gap-4">

            <div
                class="stat-icon ${iconBackground} ${iconColor} dark:bg-white/10 dark:text-white"
            >
                <i
                    data-lucide="${icon}"
                    class="w-6 h-6"
                ></i>
            </div>


            <div class="min-w-0">

                <div
                    class="stat-value"
                    data-stat-value="${dashboardNumber(value)}"
                >
                    0
                </div>

                <div class="stat-label">
                    ${dashboardEscape(label)}
                </div>

                ${
                    trend
                        ? `
                            <div class="stat-trend ${trendClass}">
                                ${dashboardEscape(trend)}
                            </div>
                          `
                        : ''
                }

            </div>


            <div class="ml-auto self-start text-gray-300 dark:text-gray-600">
                <i
                    data-lucide="chevron-right"
                    class="w-4 h-4"
                ></i>
            </div>

        </div>
    `;
}


/* =========================================================
   STATS
========================================================= */

async function loadStats() {

    try {

        const response =
            await fetch(
                `${API}/stats.php`,
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept:
                            'application/json'
                    }
                }
            );


        if (!response.ok) {

            if (response.status === 401) {
                return;
            }

            throw new Error(
                `Stats request failed (${response.status})`
            );
        }


        const stats =
            await response.json();


        const totalTasks =
            dashboardNumber(
                stats.total_tasks
            );

        const dueSoon =
            dashboardNumber(
                stats.due_soon
            );

        const overdue =
            dashboardNumber(
                stats.overdue
            );

        const completionRate =
            dashboardNumber(
                stats.completion_rate
            );


        const statCards =
            document.getElementById(
                'stat-cards'
            );


        if (statCards) {

            statCards.innerHTML = `

                ${statCard(
                    'clipboard-list',
                    'text-green-700',
                    'bg-green-50',
                    totalTasks,
                    'Total Tasks',
                    '↗ Your academic workload',
                    'green'
                )}

                ${statCard(
                    'clock-3',
                    'text-amber-600',
                    'bg-amber-50',
                    dueSoon,
                    'Due Soon',
                    '↗ Upcoming deadlines',
                    'amber'
                )}

                ${statCard(
                    'alert-circle',
                    'text-red-600',
                    'bg-red-50',
                    overdue,
                    'Overdue',
                    '↘ Needs your attention',
                    'red'
                )}

                ${statCard(
                    'trending-up',
                    'text-violet-600',
                    'bg-violet-50',
                    completionRate,
                    'Overall Progress',
                    '↗ Current completion',
                    'green'
                )}
            `;
        }


        if (window.lucide) {
            window.lucide.createIcons();
        }


        /*
         * Counter animation.
         */
        document
            .querySelectorAll(
                '[data-stat-value]'
            )
            .forEach(element => {

                const value =
                    Number(
                        element.dataset.statValue
                    );


                if (
                    typeof window.animateCounter ===
                    'function'
                ) {

                    window.animateCounter(
                        element,
                        value,
                        {
                            suffix:
                                element
                                    .closest('.stat-card')
                                    ?.querySelector(
                                        '.stat-label'
                                    )
                                    ?.textContent ===
                                    'Overall Progress'
                                    ? '%'
                                    : ''
                        }
                    );

                } else {

                    element.textContent =
                        value;
                }
            });


        renderBreakdown(
            stats
        );

        renderWeeklyProgress(
            stats
        );

        renderWorkload(
            stats
        );

        renderOverdueAlert(
            overdue
        );


        animateDashboardCards();
        animateProgressBars();


    } catch (error) {

        console.error(
            'Stats loading error:',
            error
        );

        dashboardShowToast(
            'Unable to load dashboard statistics.',
            'error'
        );
    }
}


/* =========================================================
   TASK BREAKDOWN
========================================================= */

function renderBreakdown(stats) {

    const breakdown =
        stats.task_breakdown || {};


    const labels = [
        'Completed',
        'In Progress',
        'Pending',
        'Overdue',
        'Not Started'
    ];


    const values = [
        dashboardNumber(
            breakdown.completed
        ),

        dashboardNumber(
            breakdown.in_progress
        ),

        dashboardNumber(
            breakdown.pending
        ),

        dashboardNumber(
            breakdown.overdue
        ),

        dashboardNumber(
            breakdown.not_started
        )
    ];


    const colors = [
        '#078f52',
        '#f59e0b',
        '#fbbf24',
        '#ef4444',
        '#b6c4cc'
    ];


    const canvas =
        document.getElementById(
            'breakdown-chart'
        );


    if (!canvas) return;


    if (breakdownChart) {
        breakdownChart.destroy();
        breakdownChart = null;
    }


    if (
        typeof window.Chart !==
        'function'
    ) {
        return;
    }


    breakdownChart =
        new Chart(
            canvas,
            {
                type: 'doughnut',

                data: {

                    labels,

                    datasets: [
                        {
                            data: values,

                            backgroundColor:
                                colors,

                            borderWidth: 0,

                            hoverOffset: 3
                        }
                    ]
                },

                options: {

                    responsive: true,

                    maintainAspectRatio:
                        false,

                    cutout: '69%',

                    plugins: {

                        legend: {
                            display: false
                        },

                        tooltip: {
                            callbacks: {
                                label:
                                    context =>
                                        ` ${context.label}: ${context.raw}`
                            }
                        }
                    }
                }
            }
        );


    const totalElement =
        document.getElementById(
            'breakdown-total'
        );


    if (totalElement) {

        totalElement.textContent =
            dashboardNumber(
                stats.total_tasks
            );
    }


    const legend =
        document.getElementById(
            'breakdown-legend'
        );


    if (legend) {

        const total =
            values.reduce(
                (sum, value) =>
                    sum + value,
                0
            );


        legend.innerHTML =
            labels
                .map(
                    (label, index) => {

                        const percentage =
                            total > 0
                                ? Math.round(
                                    (
                                        values[index] /
                                        total
                                    ) * 100
                                )
                                : 0;


                        return `

                            <div class="flex items-center gap-2">

                                <span
                                    class="legend-dot"
                                    style="background:${colors[index]}"
                                ></span>

                                <span class="legend-name">
                                    ${label}
                                </span>

                                <span class="ml-auto legend-value">
                                    ${values[index]}
                                    (${percentage}%)
                                </span>

                            </div>
                        `;
                    }
                )
                .join('');
    }


    const completionLabel =
        document.getElementById(
            'completion-rate-label'
        );


    if (completionLabel) {

        completionLabel.textContent =
            `${dashboardNumber(
                stats.completion_rate
            )}%`;
    }


    const completionBar =
        document.getElementById(
            'completion-rate-bar'
        );


    if (completionBar) {

        completionBar.style.width =
            `${Math.max(
                0,
                Math.min(
                    100,
                    dashboardNumber(
                        stats.completion_rate
                    )
                )
            )}%`;
    }
}


/* =========================================================
   WEEKLY PROGRESS
========================================================= */

function renderWeeklyProgress(stats) {

    const canvas =
        document.getElementById(
            'weekly-chart'
        );


    if (!canvas) return;


    if (weeklyChart) {
        weeklyChart.destroy();
        weeklyChart = null;
    }


    if (
        typeof window.Chart !==
        'function'
    ) {
        return;
    }


    const weekly =
        stats.weekly_progress || {};


    const labels =
        Array.isArray(
            weekly.labels
        )
            ? weekly.labels
            : [];


    const values =
        Array.isArray(
            weekly.data
        )
            ? weekly.data.map(
                dashboardNumber
            )
            : [];


    weeklyChart =
        new Chart(
            canvas,
            {
                type: 'line',

                data: {

                    labels,

                    datasets: [
                        {
                            data: values,

                            borderColor:
                                '#078f52',

                            backgroundColor:
                                'rgba(7,143,82,.10)',

                            fill: true,

                            tension: .38,

                            pointRadius: 4,

                            pointHoverRadius: 6,

                            pointBackgroundColor:
                                '#078f52',

                            pointBorderColor:
                                '#ffffff',

                            pointBorderWidth:
                                2
                        }
                    ]
                },

                options: {

                    responsive: true,

                    maintainAspectRatio:
                        false,

                    interaction: {
                        intersect: false,
                        mode: 'index'
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
                                        `${context.raw}%`
                            }
                        }
                    },

                    scales: {

                        x: {

                            grid: {
                                display: false
                            },

                            border: {
                                display: false
                            },

                            ticks: {
                                color: '#638096',
                                font: {
                                    size: 10
                                }
                            }
                        },

                        y: {

                            min: 0,

                            max: 100,

                            grid: {
                                color:
                                    'rgba(104,130,142,.12)'
                            },

                            border: {
                                display: false
                            },

                            ticks: {

                                color: '#638096',

                                font: {
                                    size: 10
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
}


/* =========================================================
   WORKLOAD
========================================================= */

function renderWorkload(stats) {

    const element =
        document.getElementById(
            'workload-list'
        );


    if (!element) return;


    const workload =
        stats.workload || {};


    const labels = {

        assignment:
            'Assignments',

        project:
            'Projects',

        test:
            'Tests / Exams',

        exam:
            'Tests / Exams',

        research:
            'Research',

        study_session:
            'Study Sessions',

        lab_report:
            'Lab Reports',

        other:
            'Other'
    };


    const entries =
        Object.entries(
            workload
        );


    if (entries.length === 0) {

        element.innerHTML = `
            <div class="py-8 text-center text-xs text-gray-400">
                Nothing scheduled this week.
            </div>
        `;

        const total =
            document.getElementById(
                'workload-total'
            );

        if (total) {
            total.textContent =
                '0 hrs';
        }

        return;
    }


    const maxHours =
        Math.max(
            1,
            ...entries.map(
                ([, hours]) =>
                    dashboardNumber(hours)
            )
        );


    element.innerHTML =
        entries
            .map(
                ([type, hours]) => {

                    const value =
                        dashboardNumber(
                            hours
                        );


                    const width =
                        Math.min(
                            100,
                            (
                                value /
                                maxHours
                            ) * 100
                        );


                    return `

                        <div>

                            <div class="flex justify-between">

                                <span class="workload-label">
                                    ${
                                        labels[type] ||
                                        dashboardCap(type)
                                    }
                                </span>

                                <span class="workload-value">
                                    ${value} hrs
                                </span>

                            </div>

                            <div class="workload-track">

                                <div
                                    class="workload-fill"
                                    style="width:${width}%"
                                ></div>

                            </div>

                        </div>
                    `;
                }
            )
            .join('');


    const total =
        document.getElementById(
            'workload-total'
        );


    if (total) {

        total.textContent =
            `${dashboardNumber(
                stats.workload_total
            )} hrs`;
    }
}


/* =========================================================
   OVERDUE ALERT
========================================================= */

function renderOverdueAlert(
    overdueCount
) {

    const alert =
        document.getElementById(
            'overdue-alert'
        );


    if (!alert) return;


    if (
        dashboardNumber(
            overdueCount
        ) > 0 &&
        !overdueAlertDismissed
    ) {

        const text =
            document.getElementById(
                'overdue-alert-text'
            );


        if (text) {

            const count =
                dashboardNumber(
                    overdueCount
                );

            text.textContent =
                count === 1
                    ? 'You have 1 overdue task. Check penalties.'
                    : `You have ${count} overdue tasks. Check penalties.`;
        }


        alert.classList.remove(
            'hidden'
        );

        alert.classList.add(
            'block'
        );

    } else {

        alert.classList.add(
            'hidden'
        );

        alert.classList.remove(
            'block'
        );
    }
}


/* =========================================================
   ADD TASK MODAL
========================================================= */

function openAddTaskModal() {

    const modal =
        document.getElementById(
            'add-task-modal'
        );


    if (!modal) return;


    modal.classList.remove(
        'hidden'
    );

    modal.classList.add(
        'flex'
    );


    document.body.classList.add(
        'overflow-hidden'
    );


    const firstInput =
        modal.querySelector(
            'input, select'
        );


    if (firstInput) {

        setTimeout(
            () => firstInput.focus(),
            80
        );
    }


    if (window.lucide) {
        window.lucide.createIcons();
    }
}


function closeAddTaskModal() {

    const modal =
        document.getElementById(
            'add-task-modal'
        );


    if (!modal) return;


    modal.classList.add(
        'hidden'
    );

    modal.classList.remove(
        'flex'
    );


    document.body.classList.remove(
        'overflow-hidden'
    );


    const error =
        document.getElementById(
            'add-task-error'
        );


    if (error) {
        error.classList.add(
            'hidden'
        );

        error.textContent =
            '';
    }
}


function bindAddTask() {

    const modal =
        document.getElementById(
            'add-task-modal'
        );

    const openButton =
        document.getElementById(
            'open-add-task'
        );

    const cancelButton =
        document.getElementById(
            'cancel-add-task'
        );

    const closeButton =
        document.getElementById(
            'close-add-task'
        );

    const form =
        document.getElementById(
            'add-task-form'
        );


    if (
        openButton &&
        !openButton.dataset.bound
    ) {

        openButton.dataset.bound =
            'true';

        openButton.addEventListener(
            'click',
            event => {

                event.preventDefault();

                openAddTaskModal();
            }
        );
    }


    if (
        cancelButton &&
        !cancelButton.dataset.bound
    ) {

        cancelButton.dataset.bound =
            'true';

        cancelButton.addEventListener(
            'click',
            event => {

                event.preventDefault();

                closeAddTaskModal();
            }
        );
    }


    if (
        closeButton &&
        !closeButton.dataset.bound
    ) {

        closeButton.dataset.bound =
            'true';

        closeButton.addEventListener(
            'click',
            event => {

                event.preventDefault();

                closeAddTaskModal();
            }
        );
    }


    if (
        modal &&
        !modal.dataset.bound
    ) {

        modal.dataset.bound =
            'true';

        modal.addEventListener(
            'click',
            event => {

                if (
                    event.target ===
                    modal
                ) {

                    closeAddTaskModal();
                }
            }
        );
    }


    if (
        !form ||
        form.dataset.bound
    ) {
        return;
    }


    form.dataset.bound =
        'true';


    form.addEventListener(
        'submit',
        async event => {

            event.preventDefault();


            const submitButton =
                document.getElementById(
                    'submit-add-task'
                );


            const errorElement =
                document.getElementById(
                    'add-task-error'
                );


            if (errorElement) {

                errorElement.classList.add(
                    'hidden'
                );

                errorElement.textContent =
                    '';
            }


            if (submitButton) {

                submitButton.disabled =
                    true;

                submitButton.dataset.originalText =
                    submitButton.textContent;

                submitButton.textContent =
                    'Adding…';
            }


            try {

                const formData =
                    new FormData(
                        form
                    );


                const payload =
                    Object.fromEntries(
                        formData.entries()
                    );


                /*
                 * Preserve the existing CSRF
                 * behaviour.
                 */
                payload.csrf_token =
                    window.CSRF_TOKEN;


                const response =
                    await fetch(
                        `${API}/tasks.php`,
                        {
                            method:
                                'POST',

                            credentials:
                                'same-origin',

                            headers: {
                                'Content-Type':
                                    'application/json',

                                Accept:
                                    'application/json'
                            },

                            body:
                                JSON.stringify(
                                    payload
                                )
                        }
                    );


                const result =
                    await response
                        .json()
                        .catch(
                            () => ({})
                        );


                if (!response.ok) {

                    throw new Error(
                        result.error ||
                        result.message ||
                        'Could not add task.'
                    );
                }


                closeAddTaskModal();

                form.reset();


                dashboardShowToast(
                    'Task added successfully.',
                    'success'
                );


                /*
                 * Reload both dashboard and statistics
                 * so every visible number/chart is updated.
                 */
                await Promise.all([
                    loadDashboard(),
                    loadStats()
                ]);


            } catch (error) {

                console.error(
                    'Add task error:',
                    error
                );


                if (errorElement) {

                    errorElement.textContent =
                        error.message ||
                        'Could not add task.';

                    errorElement.classList.remove(
                        'hidden'
                    );
                }


                dashboardShowToast(
                    error.message ||
                    'Could not add task.',
                    'error'
                );


            } finally {

                if (submitButton) {

                    submitButton.disabled =
                        false;

                    submitButton.textContent =
                        submitButton.dataset
                            .originalText ||
                        'Add Task';
                }
            }
        }
    );
}


/* =========================================================
   OVERDUE DISMISS
========================================================= */

function bindOverdueAlert() {

    const button =
        document.getElementById(
            'dismiss-overdue-alert'
        );


    if (
        !button ||
        button.dataset.bound
    ) {
        return;
    }


    button.dataset.bound =
        'true';


    button.addEventListener(
        'click',
        () => {

            overdueAlertDismissed =
                true;


            const alert =
                document.getElementById(
                    'overdue-alert'
                );


            if (alert) {

                alert.classList.add(
                    'hidden'
                );
            }
        }
    );
}


/* =========================================================
   DASHBOARD SEARCH
========================================================= */

function bindDashboardSearch() {

    const search =
        document.getElementById(
            'dashboard-search'
        );


    if (!search) return;


    search.addEventListener(
        'keydown',
        event => {

            if (
                event.key !==
                'Enter'
            ) {
                return;
            }


            const query =
                search.value.trim();


            if (!query) return;


            /*
             * Preserve the application as a UI
             * redesign. Do not introduce a new
             * search backend.
             *
             * Send the user to Tasks where the
             * existing application search/filter
             * functionality can continue to work.
             */
            window.location.href =
                `tasks.php?search=${encodeURIComponent(query)}`;
        }
    );
}


/* =========================================================
   KEYBOARD
========================================================= */

function bindDashboardKeyboard() {

    document.addEventListener(
        'keydown',
        event => {

            if (
                event.key ===
                'Escape'
            ) {

                closeAddTaskModal();
            }
        }
    );
}


/* =========================================================
   LOAD EVERYTHING
========================================================= */

async function refreshDashboard() {

    if (dashboardLoading) {
        return;
    }


    dashboardLoading =
        true;


    try {

        await Promise.all([
            loadDashboard(),
            loadStats()
        ]);

    } finally {

        dashboardLoading =
            false;
    }
}


/* =========================================================
   BOOT
========================================================= */

function initializeDashboard() {

    bindAddTask();

    bindOverdueAlert();

    bindDashboardSearch();

    bindDashboardKeyboard();


    if (window.lucide) {
        window.lucide.createIcons();
    }


    /*
     * Existing authentication/session flow.
     * Do not replace APP_READY.
     */
    if (
        window.APP_READY &&
        typeof window.APP_READY.then ===
        'function'
    ) {

        window.APP_READY.then(
            user => {

                if (!user) {
                    return;
                }


                /*
                 * Render user information from nav.js
                 * if available.
                 */
                if (
                    user.name &&
                    typeof window.setUserDisplay ===
                    'function'
                ) {

                    window.setUserDisplay(
                        user
                    );
                }


                refreshDashboard();
            }
        ).catch(
            error => {

                console.error(
                    'Dashboard boot error:',
                    error
                );
            }
        );

    } else {

        /*
         * Fallback only for environments where
         * APP_READY is unavailable.
         */
        refreshDashboard();
    }
}


if (
    document.readyState ===
    'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        initializeDashboard
    );

} else {

    initializeDashboard();
}