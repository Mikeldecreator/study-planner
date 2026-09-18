/* =========================================================
   STUDY PLANNER — SCHEDULE
   Courses + Tasks on Week / Day / Month
========================================================= */

const GRID_START_HOUR = 7;
const GRID_END_HOUR = 21;
const PX_PER_HOUR = 60;

const TYPE_COLORS = {
    lecture: '#2563eb',
    study: '#166534',
    exam: '#dc2626',
    other: '#7c3aed'
};

const DOW_LABELS = [
    'Sun',
    'Mon',
    'Tue',
    'Wed',
    'Thu',
    'Fri',
    'Sat'
];

let ALL_EVENTS = [];
let ALL_TASKS = [];
let COURSES_CACHE = [];

let distributionChart = null;

/* Current calendar state */

let scheduleView = 'week';

let anchorDate = new Date();

let miniCalendarDate = new Date(
    anchorDate.getFullYear(),
    anchorDate.getMonth(),
    1
);


/* =========================================================
   HELPERS
========================================================= */

function iconSvg(
    name,
    className = 'w-4 h-4'
) {
    return `
        <i
            data-lucide="${name}"
            class="${className}"
        ></i>
    `;
}


function initLucide() {

    if (
        window.lucide &&
        typeof window.lucide.createIcons === 'function'
    ) {

        window.lucide.createIcons();

    }

}


function formatDate(
    date,
    options = {}
) {

    return new Intl.DateTimeFormat(
        undefined,
        options
    ).format(date);

}


function sameDate(
    a,
    b
) {

    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );

}


function startOfWeek(
    date
) {

    const d = new Date(date);

    d.setHours(
        0,
        0,
        0,
        0
    );

    d.setDate(
        d.getDate() -
        d.getDay()
    );

    return d;

}


function escapeHtml(
    value
) {

    return String(
        value ?? ''
    )
        .replace(
            /&/g,
            '&amp;'
        )
        .replace(
            /</g,
            '&lt;'
        )
        .replace(
            />/g,
            '&gt;'
        )
        .replace(
            /"/g,
            '&quot;'
        )
        .replace(
            /'/g,
            '&#039;'
        );

}


function escapeAttribute(
    value
) {

    return escapeHtml(value);

}


function capitalize(
    value
) {

    const text =
        String(
            value ?? ''
        );

    if (!text) {
        return '';
    }

    return (
        text.charAt(0).toUpperCase() +
        text.slice(1)
    );

}


function eventDateForDay(
    dayDate,
    event
) {

    return (
        String(
            dayDate.getDay()
        ) ===
        String(
            event.day_of_week
        )
    );

}


/* =========================================================
   TASK HELPERS
========================================================= */

function taskDate(
    task
) {

    if (
        !task ||
        !task.due_at
    ) {

        return null;

    }

    const normalized =
        String(
            task.due_at
        ).replace(
            ' ',
            'T'
        );

    const date =
        new Date(
            normalized
        );

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {

        return null;

    }

    return date;

}


function taskMatchesDate(
    task,
    date
) {

    const due =
        taskDate(task);

    return (
        !!due &&
        sameDate(
            due,
            date
        )
    );

}


function taskTopPosition(
    task
) {

    const due =
        taskDate(task);

    if (!due) {
        return 0;
    }

    const minutes =
        due.getHours() * 60 +
        due.getMinutes();

    const gridStart =
        GRID_START_HOUR * 60;

    return (
        (
            minutes -
            gridStart
        ) / 60
    ) * PX_PER_HOUR;

}


function taskDuration(
    task
) {

    const hours =
        Number(
            task?.duration_hours || 0
        );

    if (
        Number.isFinite(hours) &&
        hours > 0
    ) {

        return Math.max(
            30,
            Math.min(
                120,
                hours * 60
            )
        );

    }

    return 45;

}


function formatTaskTime(
    task
) {

    const due =
        taskDate(task);

    if (!due) {
        return '';
    }

    return due.toLocaleTimeString(
        [],
        {
            hour: 'numeric',
            minute: '2-digit'
        }
    );

}


/* =========================================================
   DATE / NAVIGATION
========================================================= */

function renderDateRange() {

    const label =
        document.getElementById(
            'schedule-range-label'
        );

    const subtitle =
        document.getElementById(
            'schedule-range-subtitle'
        );

    if (!label) {
        return;
    }


    if (
        scheduleView === 'week'
    ) {

        const start =
            startOfWeek(
                anchorDate
            );

        const end =
            new Date(start);

        end.setDate(
            start.getDate() + 6
        );


        label.textContent =
            `${formatDate(
                start,
                {
                    month: 'short',
                    day: 'numeric'
                }
            )} – ${formatDate(
                end,
                {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                }
            )}`;


        if (subtitle) {

            subtitle.textContent =
                'Weekly schedule';

        }


    } else if (
        scheduleView === 'day'
    ) {

        label.textContent =
            formatDate(
                anchorDate,
                {
                    weekday: 'long',
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                }
            );


        if (subtitle) {

            subtitle.textContent =
                'Daily schedule';

        }


    } else {

        label.textContent =
            formatDate(
                anchorDate,
                {
                    month: 'long',
                    year: 'numeric'
                }
            );


        if (subtitle) {

            subtitle.textContent =
                'Monthly schedule';

        }

    }

}


function navigateSchedule(
    direction
) {

    if (
        scheduleView === 'week'
    ) {

        anchorDate =
            new Date(
                anchorDate
            );

        anchorDate.setDate(
            anchorDate.getDate() +
            direction * 7
        );


    } else if (
        scheduleView === 'day'
    ) {

        anchorDate =
            new Date(
                anchorDate
            );

        anchorDate.setDate(
            anchorDate.getDate() +
            direction
        );


    } else {

        anchorDate =
            new Date(
                anchorDate.getFullYear(),
                anchorDate.getMonth() +
                    direction,
                1
            );

    }


    miniCalendarDate =
        new Date(
            anchorDate.getFullYear(),
            anchorDate.getMonth(),
            1
        );


    renderCurrentView();

}


function goToToday() {

    anchorDate =
        new Date();

    miniCalendarDate =
        new Date(
            anchorDate.getFullYear(),
            anchorDate.getMonth(),
            1
        );

    renderCurrentView();

}


/* =========================================================
   VIEW BUTTONS
========================================================= */

function updateViewButtons() {

    document
        .querySelectorAll(
            '.schedule-view-btn'
        )
        .forEach(
            button => {

                const active =
                    button.dataset.view ===
                    scheduleView;


                button.classList.toggle(
                    'bg-emerald-700',
                    active
                );

                button.classList.toggle(
                    'text-white',
                    active
                );

                button.classList.toggle(
                    'shadow-sm',
                    active
                );

                button.classList.toggle(
                    'text-gray-500',
                    !active
                );

                button.classList.toggle(
                    'dark:text-gray-400',
                    !active
                );

            }
        );

}


/* =========================================================
   MINI CALENDAR
========================================================= */

function renderMiniCalendar() {

    const target =
        document.getElementById(
            'mini-calendar-grid'
        );

    const monthLabel =
        document.getElementById(
            'mini-calendar-month'
        );

    if (
        !target ||
        !monthLabel
    ) {
        return;
    }


    monthLabel.textContent =
        formatDate(
            miniCalendarDate,
            {
                month: 'long',
                year: 'numeric'
            }
        );


    const year =
        miniCalendarDate.getFullYear();

    const month =
        miniCalendarDate.getMonth();


    const firstDay =
        new Date(
            year,
            month,
            1
        );


    const mondayOffset =
        (
            firstDay.getDay() +
            6
        ) % 7;


    const calendarStart =
        new Date(
            year,
            month,
            1 - mondayOffset
        );


    const today =
        new Date();


    let html = '';


    for (
        let i = 0;
        i < 42;
        i++
    ) {

        const date =
            new Date(
                calendarStart
            );

        date.setDate(
            calendarStart.getDate() +
            i
        );


        const inMonth =
            date.getMonth() === month;

        const isToday =
            sameDate(
                date,
                today
            );

        const isSelected =
            sameDate(
                date,
                anchorDate
            );


        const hasEvents =
            ALL_EVENTS.some(
                event =>
                    eventDateForDay(
                        date,
                        event
                    )
            );


        const hasTasks =
            ALL_TASKS.some(
                task =>
                    taskMatchesDate(
                        task,
                        date
                    )
            );


        const hasItems =
            hasEvents ||
            hasTasks;


        let classes =
            'mini-day focus-ring cursor-pointer transition-colors';


        if (!inMonth) {
            classes += ' muted';
        }


        if (isToday) {
            classes += ' today';
        }


        if (
            date.getDay() === 0 &&
            !isToday &&
            !isSelected
        ) {

            classes += ' sun';

        }


        if (
            isSelected &&
            !isToday
        ) {

            classes += ' selected';

        }


        html += `
            <button
                type="button"
                class="${classes}"
                data-mini-date="${date.getFullYear()}-${String(
                    date.getMonth() + 1
                ).padStart(2, '0')}-${String(
                    date.getDate()
                ).padStart(2, '0')}"
                aria-label="${escapeAttribute(
                    formatDate(
                        date,
                        {
                            weekday: 'long',
                            month: 'long',
                            day: 'numeric',
                            year: 'numeric'
                        }
                    )
                )}"
            >

                <span>
                    ${date.getDate()}
                </span>

                ${
                    hasItems
                        ? `
                            <span
                                class="mini-event-dot"
                            ></span>
                        `
                        : ''
                }

            </button>
        `;

    }


    target.innerHTML =
        html;


    target
        .querySelectorAll(
            '[data-mini-date]'
        )
        .forEach(
            button => {

                button.addEventListener(
                    'click',
                    () => {

                        const [
                            year,
                            month,
                            day
                        ] =
                            button.dataset
                                .miniDate
                                .split('-')
                                .map(Number);


                        anchorDate =
                            new Date(
                                year,
                                month - 1,
                                day
                            );


                        miniCalendarDate =
                            new Date(
                                year,
                                month - 1,
                                1
                            );


                        renderCurrentView();

                    }
                );

            }
        );

}


/* =========================================================
   DAY HEADERS
========================================================= */

function updateDayHeaders() {

    const headers =
        document.querySelectorAll(
            '.day-header'
        );

    const weekStart =
        startOfWeek(
            anchorDate
        );

    const selectedDow =
        anchorDate.getDay();

    const today =
        new Date();


    headers.forEach(
        (
            header,
            index
        ) => {

            const date =
                new Date(
                    weekStart
                );

            date.setDate(
                weekStart.getDate() +
                index
            );


            const visible =
                scheduleView !== 'day' ||
                index === selectedDow;


            header.style.display =
                visible
                    ? ''
                    : 'none';


            const isToday =
                sameDate(
                    date,
                    today
                );


            header.innerHTML = `
                <div
                    class="${
                        isToday
                            ? 'text-emerald-700 dark:text-emerald-300'
                            : 'text-gray-700 dark:text-gray-200'
                    } font-semibold"
                >
                    ${DOW_LABELS[index]}
                </div>

                <div
                    class="text-[10px] mt-0.5 font-medium ${
                        isToday
                            ? 'text-emerald-700 dark:text-emerald-300'
                            : 'text-gray-400 dark:text-gray-500'
                    }"
                >
                    ${date.getDate()}
                </div>
            `;

        }
    );

}


/* =========================================================
   WEEK / DAY GRID
========================================================= */

function renderGrid() {

    const grid =
        document.getElementById(
            'week-grid'
        );

    const scroll =
        document.querySelector(
            '.schedule-scroll'
        );

    const monthCalendar =
        document.getElementById(
            'month-calendar'
        );


    if (!grid) {
        return;
    }


    updateDayHeaders();


    /* MONTH */

    if (
        scheduleView === 'month'
    ) {

        if (scroll) {

            scroll.classList.add(
                'hidden'
            );

        }


        grid.classList.add(
            'hidden'
        );


        if (monthCalendar) {

            monthCalendar.classList.remove(
                'hidden'
            );

        }


        renderMonthCalendar();

        return;

    }


    /* WEEK / DAY */

    if (scroll) {

        scroll.classList.remove(
            'hidden'
        );

    }


    if (monthCalendar) {

        monthCalendar.classList.add(
            'hidden'
        );

    }


    grid.classList.remove(
        'hidden'
    );


    const isDay =
        scheduleView === 'day';


    grid.style.gridTemplateColumns =
        isDay
            ? '54px minmax(0,1fr)'
            : '54px repeat(7,minmax(0,1fr))';


    /* Hour labels */

    const hourLabels =
        document.getElementById(
            'hour-labels'
        );


    if (hourLabels) {

        let labelsHtml = '';


        for (
            let hour = GRID_START_HOUR;
            hour <= GRID_END_HOUR;
            hour++
        ) {

            const top =
                (
                    hour -
                    GRID_START_HOUR
                ) *
                PX_PER_HOUR;


            const label =
                hour === 12
                    ? '12 PM'
                    : hour > 12
                        ? `${hour - 12} PM`
                        : `${hour} AM`;


            labelsHtml += `
                <div
                    class="absolute text-[10px] text-gray-400 dark:text-gray-500 -translate-y-1/2 whitespace-nowrap"
                    style="top:${top}px"
                >
                    ${label}
                </div>
            `;

        }


        hourLabels.innerHTML =
            labelsHtml;

    }


    const selectedDow =
        anchorDate.getDay();

    const weekStart =
        startOfWeek(
            anchorDate
        );


    /* Day columns */

    document
        .querySelectorAll(
            '.day-col'
        )
        .forEach(
            col => {

                const dow =
                    Number(
                        col.dataset.dow
                    );


                const visible =
                    !isDay ||
                    dow === selectedDow;


                col.style.display =
                    visible
                        ? ''
                        : 'none';


                if (!visible) {

                    col.innerHTML =
                        '';

                    return;

                }


                let html = '';


                /* Grid lines */

                for (
                    let hour = GRID_START_HOUR;
                    hour <= GRID_END_HOUR;
                    hour++
                ) {

                    const top =
                        (
                            hour -
                            GRID_START_HOUR
                        ) *
                        PX_PER_HOUR;


                    html += `
                        <div
                            class="absolute inset-x-0 border-t border-gray-100 dark:border-white/[.05]"
                            style="top:${top}px"
                        ></div>
                    `;


                    if (
                        hour < GRID_END_HOUR
                    ) {

                        html += `
                            <div
                                class="absolute inset-x-0 border-t border-gray-50 dark:border-white/[.025]"
                                style="top:${top + 30}px"
                            ></div>
                        `;

                    }

                }


                /* Current day background */

                const today =
                    new Date();


                if (
                    dow === today.getDay()
                ) {

                    html += `
                        <div
                            class="absolute inset-0 grid-current-day pointer-events-none"
                        ></div>
                    `;

                }


                /* Recurring schedule events */

                const dayEvents =
                    ALL_EVENTS
                        .filter(
                            event =>
                                String(
                                    event.day_of_week
                                ) ===
                                String(
                                    dow
                                )
                        )
                        .sort(
                            (a, b) =>
                                String(
                                    a.start_time
                                ).localeCompare(
                                    String(
                                        b.start_time
                                    )
                                )
                        );


                dayEvents.forEach(
                    event => {

                        html +=
                            eventBlockHtml(
                                event
                            );

                    }
                );


                /* Date for this column */

                const dayDate =
                    new Date(
                        weekStart
                    );


                dayDate.setDate(
                    weekStart.getDate() +
                    dow
                );


                /* Tasks due on this date */

                const dayTasks =
                    ALL_TASKS
                        .filter(
                            task =>
                                taskMatchesDate(
                                    task,
                                    dayDate
                                )
                        )
                        .sort(
                            (a, b) => {

                                const aDate =
                                    taskDate(a);

                                const bDate =
                                    taskDate(b);


                                if (
                                    !aDate ||
                                    !bDate
                                ) {
                                    return 0;
                                }


                                return (
                                    aDate -
                                    bDate
                                );

                            }
                        );


                dayTasks.forEach(
                    task => {

                        html +=
                            taskBlockHtml(
                                task
                            );

                    }
                );


                /* Current time line */

                const now =
                    new Date();


                if (
                    sameDate(
                        dayDate,
                        now
                    )
                ) {

                    const minutes =
                        now.getHours() * 60 +
                        now.getMinutes();


                    const gridStart =
                        GRID_START_HOUR * 60;


                    const top =
                        (
                            (
                                minutes -
                                gridStart
                            ) /
                            60
                        ) *
                        PX_PER_HOUR;


                    const maxTop =
                        (
                            GRID_END_HOUR -
                            GRID_START_HOUR
                        ) *
                        PX_PER_HOUR;


                    if (
                        top >= 0 &&
                        top <= maxTop
                    ) {

                        html += `
                            <div
                                class="today-line"
                                style="top:${top}px"
                            >

                                <span
                                    class="absolute -left-1 top-1/2 -translate-y-1/2 today-dot"
                                ></span>

                            </div>
                        `;

                    }

                }


                col.innerHTML =
                    html;

            }
        );


    /* Header column layout */

    const headerRow =
        document.querySelector(
            '.day-header'
        )?.parentElement;


    if (headerRow) {

        headerRow.style.gridTemplateColumns =
            isDay
                ? '54px minmax(0,1fr)'
                : '54px repeat(7,minmax(0,1fr))';

    }


    /* Session click */

    document
        .querySelectorAll(
            '.event-block'
        )
        .forEach(
            block => {

                block.addEventListener(
                    'click',
                    event => {

                        event.stopPropagation();

                        openEditSession(
                            block.dataset.id
                        );

                    }
                );

            }
        );


    /* Task click */

    document
        .querySelectorAll(
            '.task-block'
        )
        .forEach(
            block => {

                block.addEventListener(
                    'click',
                    event => {

                        event.stopPropagation();

                        const task =
                            ALL_TASKS.find(
                                item =>
                                    String(
                                        item.id
                                    ) ===
                                    String(
                                        block.dataset.taskId
                                    )
                            );


                        if (
                            !task
                        ) {
                            return;
                        }


                        /*
                         * Prefer your existing toast system.
                         * If none exists, send the user to
                         * the Deadlines page.
                         */

                        if (
                            window.showToast
                        ) {

                            window.showToast(
                                `${task.title} · due ${formatTaskTime(task)}`,
                                'info'
                            );

                        } else {

                            window.location.href =
                                `deadlines.php?task=${encodeURIComponent(
                                    task.id
                                )}`;

                        }

                    }
                );

            }
        );


    initLucide();

}


/* =========================================================
   EVENT BLOCK
========================================================= */

function eventBlockHtml(
    ev
) {

    const [
        startHour,
        startMinute
    ] =
        String(
            ev.start_time
        )
            .split(':')
            .map(Number);


    const [
        endHour,
        endMinute
    ] =
        String(
            ev.end_time
        )
            .split(':')
            .map(Number);


    const startMins =
        Math.max(
            0,
            (
                startHour -
                GRID_START_HOUR
            ) * 60 +
            startMinute
        );


    const endMins =
        Math.min(
            (
                GRID_END_HOUR -
                GRID_START_HOUR
            ) * 60,

            (
                endHour -
                GRID_START_HOUR
            ) * 60 +
            endMinute
        );


    const top =
        (
            startMins /
            60
        ) *
        PX_PER_HOUR;


    const height =
        Math.max(
            22,
            (
                (
                    endMins -
                    startMins
                ) /
                60
            ) *
            PX_PER_HOUR -
            2
        );


    const color =
        ev.course_color ||
        TYPE_COLORS[
            ev.event_type
        ] ||
        '#166534';


    const icon =
        ev.event_type === 'exam'
            ? 'clipboard-check'
            : ev.event_type === 'lecture'
                ? 'book-open'
                : ev.event_type === 'study'
                    ? 'graduation-cap'
                    : 'layers-3';


    const courseText =
        ev.course_code
            ? ev.course_name
                ? `${ev.course_code} — ${ev.course_name}`
                : ev.course_code
            : capitalize(
                ev.event_type ||
                'session'
            );


    const completedClass =
        ev.is_completed
            ? 'opacity-50'
            : '';


    return `
        <div
            class="event-block absolute left-1 right-1 rounded-xl px-2 py-2 overflow-hidden cursor-pointer text-white text-[10px] leading-tight ${completedClass}"
            data-id="${escapeAttribute(
                ev.id
            )}"
            style="
                top:${top}px;
                height:${height}px;
                background:${escapeAttribute(
                    color
                )}
            "
            title="${escapeAttribute(
                ev.title
            )}"
        >

            <div class="flex items-start gap-1.5">

                <div class="mt-0.5 shrink-0">
                    ${iconSvg(
                        icon,
                        'w-3.5 h-3.5'
                    )}
                </div>

                <div class="min-w-0">

                    <div class="font-bold truncate">
                        ${escapeHtml(
                            ev.title
                        )}
                    </div>

                    <div class="truncate opacity-90 mt-0.5">
                        ${escapeHtml(
                            String(
                                ev.start_time
                            ).slice(
                                0,
                                5
                            )
                        )}
                        –
                        ${escapeHtml(
                            String(
                                ev.end_time
                            ).slice(
                                0,
                                5
                            )
                        )}
                    </div>

                    <div class="truncate opacity-80 mt-0.5">
                        ${escapeHtml(
                            courseText
                        )}
                    </div>

                </div>

            </div>

        </div>
    `;

}


/* =========================================================
   TASK BLOCK
========================================================= */

function taskBlockHtml(
    task
) {

    const top =
        taskTopPosition(
            task
        );


    const height =
        Math.max(
            28,
            (
                taskDuration(
                    task
                ) / 60
            ) *
            PX_PER_HOUR -
            2
        );


    const priority =
        String(
            task.priority || ''
        ).toLowerCase();


    const color =
        task.course_color ||
        (
            priority === 'high'
                ? '#b42318'
                : priority === 'medium'
                    ? '#b7791f'
                    : '#0f766e'
        );


    const courseText =
        task.course_code
            ? task.course_name
                ? `${task.course_code} — ${task.course_name}`
                : task.course_code
            : 'No course';


    const completed =
        String(
            task.status || ''
        ).toLowerCase() ===
        'completed';


    return `
        <div
            class="task-block absolute left-1 right-1 rounded-lg border-2 border-dashed border-white/80 px-2 py-1.5 overflow-hidden cursor-pointer text-white text-[10px] leading-tight shadow-sm ${
                completed
                    ? 'opacity-50'
                    : ''
            }"
            data-task-id="${escapeAttribute(
                task.id
            )}"
            style="
                top:${Math.max(
                    0,
                    top
                )}px;
                height:${height}px;
                background:${escapeAttribute(
                    color
                )};
            "
            title="${escapeAttribute(
                task.title
            )}"
        >

            <div class="flex items-start gap-1.5">

                <div class="mt-0.5 shrink-0">

                    ${iconSvg(
                        'clipboard-check',
                        'w-3.5 h-3.5'
                    )}

                </div>

                <div class="min-w-0">

                    <div class="font-bold truncate ${
                        completed
                            ? 'line-through'
                            : ''
                    }">
                        ${escapeHtml(
                            task.title
                        )}
                    </div>

                    <div class="truncate opacity-90 mt-0.5">
                        Due
                        ${escapeHtml(
                            formatTaskTime(
                                task
                            )
                        )}
                    </div>

                    <div class="truncate opacity-80 mt-0.5">
                        ${escapeHtml(
                            courseText
                        )}
                    </div>

                </div>

            </div>

        </div>
    `;

}


/* =========================================================
   MONTH VIEW
========================================================= */

function renderMonthCalendar() {

    const target =
        document.getElementById(
            'month-calendar'
        );


    if (!target) {
        return;
    }


    const year =
        anchorDate.getFullYear();

    const month =
        anchorDate.getMonth();


    const first =
        new Date(
            year,
            month,
            1
        );


    const offset =
        (
            first.getDay() +
            6
        ) % 7;


    const start =
        new Date(
            year,
            month,
            1 - offset
        );


    const today =
        new Date();


    let html = `

        <div
            class="grid grid-cols-7 border-t border-l border-gray-100 dark:border-white/[.06] rounded-xl overflow-hidden"
        >

            ${
                [
                    'Mon',
                    'Tue',
                    'Wed',
                    'Thu',
                    'Fri',
                    'Sat',
                    'Sun'
                ]
                    .map(
                        day => `
                            <div
                                class="p-2 sm:p-3 text-[11px] font-bold text-gray-400 dark:text-gray-500 bg-gray-50/80 dark:bg-white/[.03] border-r border-b border-gray-100 dark:border-white/[.06]"
                            >
                                ${day}
                            </div>
                        `
                    )
                    .join('')
            }

    `;


    for (
        let i = 0;
        i < 42;
        i++
    ) {

        const date =
            new Date(
                start
            );


        date.setDate(
            start.getDate() +
            i
        );


        const inMonth =
            date.getMonth() ===
            month;


        const isToday =
            sameDate(
                date,
                today
            );


        const isSelected =
            sameDate(
                date,
                anchorDate
            );


        const events =
            ALL_EVENTS
                .filter(
                    event =>
                        eventDateForDay(
                            date,
                            event
                        )
                )
                .sort(
                    (a, b) =>
                        String(
                            a.start_time
                        ).localeCompare(
                            String(
                                b.start_time
                            )
                        )
                );


        const tasks =
            ALL_TASKS
                .filter(
                    task =>
                        taskMatchesDate(
                            task,
                            date
                        )
                )
                .sort(
                    (a, b) => {

                        const aDate =
                            taskDate(a);

                        const bDate =
                            taskDate(b);

                        if (
                            !aDate ||
                            !bDate
                        ) {
                            return 0;
                        }

                        return (
                            aDate -
                            bDate
                        );

                    }
                );


        html += `

            <div
                class="month-cell text-left p-2 sm:p-3 min-h-[125px] sm:min-h-[150px] border-r border-b border-gray-100 dark:border-white/[.06] ${
                    !inMonth
                        ? 'opacity-45'
                        : ''
                } ${
                    isSelected
                        ? 'bg-emerald-50/70 dark:bg-emerald-500/[.06]'
                        : 'hover:bg-gray-50 dark:hover:bg-white/[.025]'
                } transition-colors"
                data-month-date="${date.getFullYear()}-${String(
                    date.getMonth() + 1
                ).padStart(
                    2,
                    '0'
                )}-${String(
                    date.getDate()
                ).padStart(
                    2,
                    '0'
                )}"
            >

                <button
                    type="button"
                    class="w-full text-left"
                    data-month-day
                >

                    <div
                        class="flex items-center justify-between mb-2"
                    >

                        <span
                            class="w-7 h-7 rounded-full inline-flex items-center justify-center text-xs font-bold ${
                                isToday
                                    ? 'bg-emerald-700 text-white dark:bg-emerald-500 dark:text-[#07140f]'
                                    : 'text-gray-600 dark:text-gray-300'
                            }"
                        >
                            ${date.getDate()}
                        </span>

                        ${
                            isSelected
                                ? `
                                    <span class="text-[9px] font-semibold text-emerald-700 dark:text-emerald-300">
                                        Selected
                                    </span>
                                `
                                : ''
                        }

                    </div>

                </button>


                <div class="space-y-1">

                    <!-- Schedule sessions -->

                    ${events
                        .slice(
                            0,
                            3
                        )
                        .map(
                            event => {

                                const eventColor =
                                    event.course_color ||
                                    TYPE_COLORS[
                                        event.event_type
                                    ] ||
                                    '#166534';


                                return `

                                    <button
                                        type="button"
                                        class="month-event w-full text-left rounded-md px-1.5 py-1 text-[10px] text-white truncate hover:brightness-95 transition"
                                        style="background:${escapeAttribute(
                                            eventColor
                                        )}"
                                        data-month-event-id="${escapeAttribute(
                                            event.id
                                        )}"
                                        title="${escapeAttribute(
                                            event.title
                                        )}"
                                    >

                                        ${escapeHtml(
                                            event.title
                                        )}

                                        ·

                                        ${escapeHtml(
                                            String(
                                                event.start_time
                                            ).slice(
                                                0,
                                                5
                                            )
                                        )}

                                    </button>

                                `;

                            }
                        )
                        .join('')
                    }


                    <!-- Tasks -->

                    ${tasks
                        .slice(
                            0,
                            3
                        )
                        .map(
                            task => {

                                const priority =
                                    String(
                                        task.priority ||
                                        ''
                                    ).toLowerCase();


                                const taskColor =
                                    task.course_color ||
                                    (
                                        priority ===
                                        'high'
                                            ? '#b42318'
                                            : priority ===
                                              'medium'
                                                ? '#b7791f'
                                                : '#0f766e'
                                    );


                                return `

                                    <button
                                        type="button"
                                        class="month-task w-full text-left rounded-md px-1.5 py-1 text-[10px] text-white truncate border border-dashed border-white/80 hover:brightness-95 transition"
                                        style="background:${escapeAttribute(
                                            taskColor
                                        )}"
                                        data-month-task-id="${escapeAttribute(
                                            task.id
                                        )}"
                                        title="Due ${escapeAttribute(
                                            formatTaskTime(
                                                task
                                            )
                                        )}"
                                    >

                                        📋
                                        ${escapeHtml(
                                            task.title
                                        )}

                                        ·

                                        ${escapeHtml(
                                            formatTaskTime(
                                                task
                                            )
                                        )}

                                    </button>

                                `;

                            }
                        )
                        .join('')
                    }


                    ${
                        events.length > 3 &&
                        tasks.length === 0
                            ? `
                                <div class="text-[10px] text-gray-400 dark:text-gray-500 font-semibold px-1">
                                    +${events.length - 3} more
                                </div>
                            `
                            : ''
                    }


                    ${
                        tasks.length > 3
                            ? `
                                <div class="text-[10px] text-amber-700 dark:text-amber-300 font-semibold px-1">
                                    +${tasks.length - 3}
                                    more task${
                                        tasks.length - 3 === 1
                                            ? ''
                                            : 's'
                                    }
                                </div>
                            `
                            : ''
                    }

                </div>

            </div>
        `;

    }


    html += `
        </div>
    `;


    target.innerHTML =
        html;


    /* Month day click */

    target
        .querySelectorAll(
            '[data-month-date]'
        )
        .forEach(
            cell => {

                cell.addEventListener(
                    'click',
                    event => {

                        if (
                            event.target.closest(
                                '[data-month-event-id]'
                            )
                            ||
                            event.target.closest(
                                '[data-month-task-id]'
                            )
                        ) {
                            return;
                        }


                        const [
                            year,
                            month,
                            day
                        ] =
                            cell.dataset
                                .monthDate
                                .split('-')
                                .map(Number);


                        anchorDate =
                            new Date(
                                year,
                                month - 1,
                                day
                            );


                        scheduleView =
                            'day';


                        miniCalendarDate =
                            new Date(
                                year,
                                month - 1,
                                1
                            );


                        renderCurrentView();

                    }
                );

            }
        );


    /* Month session click */

    target
        .querySelectorAll(
            '[data-month-event-id]'
        )
        .forEach(
            button => {

                button.addEventListener(
                    'click',
                    event => {

                        event.stopPropagation();

                        openEditSession(
                            button.dataset
                                .monthEventId
                        );

                    }
                );

            }
        );


    /* Month task click */

    target
        .querySelectorAll(
            '[data-month-task-id]'
        )
        .forEach(
            button => {

                button.addEventListener(
                    'click',
                    event => {

                        event.stopPropagation();

                        const task =
                            ALL_TASKS.find(
                                item =>
                                    String(
                                        item.id
                                    ) ===
                                    String(
                                        button.dataset
                                            .monthTaskId
                                    )
                            );


                        if (
                            !task
                        ) {
                            return;
                        }


                        if (
                            window.showToast
                        ) {

                            window.showToast(
                                `${task.title} · due ${formatTaskTime(task)}`,
                                'info'
                            );

                        } else {

                            window.location.href =
                                `deadlines.php?task=${encodeURIComponent(
                                    task.id
                                )}`;

                        }

                    }
                );

            }
        );


    initLucide();

}


/* =========================================================
   CURRENT VIEW
========================================================= */

function renderCurrentView() {

    renderDateRange();

    updateViewButtons();

    renderMiniCalendar();

    renderGrid();


    const summary =
        document.getElementById(
            'schedule-summary'
        );


    if (!summary) {
        return;
    }


    if (
        scheduleView === 'week'
    ) {

        const weekStart =
            startOfWeek(
                anchorDate
            );


        const weekEnd =
            new Date(
                weekStart
            );

        weekEnd.setDate(
            weekStart.getDate() + 6
        );


        const weekTaskCount =
            ALL_TASKS.filter(
                task => {

                    const due =
                        taskDate(
                            task
                        );

                    if (!due) {
                        return false;
                    }

                    return (
                        due >= weekStart &&
                        due <
                            new Date(
                                weekEnd.getFullYear(),
                                weekEnd.getMonth(),
                                weekEnd.getDate() + 1
                            )
                    );

                }
            ).length;


        summary.textContent =
            `Showing 7 days · ${
                ALL_EVENTS.length
            } session${
                ALL_EVENTS.length === 1
                    ? ''
                    : 's'
            } · ${
                weekTaskCount
            } task${
                weekTaskCount === 1
                    ? ''
                    : 's'
            }`;


    } else if (
        scheduleView === 'day'
    ) {

        const dayEventCount =
            ALL_EVENTS.filter(
                event =>
                    String(
                        event.day_of_week
                    ) ===
                    String(
                        anchorDate.getDay()
                    )
            ).length;


        const dayTaskCount =
            ALL_TASKS.filter(
                task =>
                    taskMatchesDate(
                        task,
                        anchorDate
                    )
            ).length;


        summary.textContent =
            `${formatDate(
                anchorDate,
                {
                    weekday: 'long',
                    month: 'short',
                    day: 'numeric'
                }
            )} · ${
                dayEventCount
            } session${
                dayEventCount === 1
                    ? ''
                    : 's'
            } · ${
                dayTaskCount
            } task${
                dayTaskCount === 1
                    ? ''
                    : 's'
            }`;


    } else {

        const monthTasks =
            ALL_TASKS.filter(
                task => {

                    const due =
                        taskDate(
                            task
                        );

                    return (
                        due &&
                        due.getFullYear() ===
                            anchorDate.getFullYear() &&
                        due.getMonth() ===
                            anchorDate.getMonth()
                    );

                }
            ).length;


        summary.textContent =
            `${formatDate(
                anchorDate,
                {
                    month: 'long',
                    year: 'numeric'
                }
            )} · ${
                ALL_EVENTS.length
            } recurring session${
                ALL_EVENTS.length === 1
                    ? ''
                    : 's'
            } · ${
                monthTasks
            } task${
                monthTasks === 1
                    ? ''
                    : 's'
            }`;

    }


    initLucide();

}


/* =========================================================
   STATISTICS
========================================================= */

function renderStatCards(
    stats
) {

    const target =
        document.getElementById(
            'schedule-stat-cards'
        );


    if (!target) {
        return;
    }


    target.innerHTML = `

        ${statCard(
            'calendar-days',
            stats.total_sessions,
            'Total Sessions',
            'emerald',
            'Recurring schedule'
        )}

        ${statCard(
            'clock-3',
            `${stats.scheduled_hours} hrs`,
            'Scheduled Time',
            'amber',
            'Planned this week'
        )}

        ${statCard(
            'circle-check',
            stats.completed,
            'Completed',
            'green',
            'Sessions completed'
        )}

        ${statCard(
            'chart-no-axes-combined',
            `${stats.weekly_utilization}%`,
            'Weekly Utilization',
            'blue',
            'Schedule coverage'
        )}

        ${statCard(
            'target',
            stats.daily_goal_met,
            'Daily Goal Met',
            'violet',
            'Days on target'
        )}

    `;


    initLucide();

}


function statCard(
    icon,
    value,
    label,
    tone,
    helper
) {

    const tones = {

        emerald:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',

        amber:
            'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',

        green:
            'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300',

        blue:
            'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',

        violet:
            'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'

    };


    return `

        <div
            class="metric-card surface rounded-2xl p-4 sm:p-5 flex items-center gap-3.5 sm:gap-4 shadow-sm"
        >

            <div
                class="metric-icon ${tones[tone]}"
            >
                ${iconSvg(
                    icon,
                    'w-6 h-6'
                )}
            </div>

            <div class="min-w-0">

                <div
                    class="text-xl sm:text-2xl font-bold leading-tight truncate"
                >
                    ${escapeHtml(
                        value
                    )}
                </div>

                <div
                    class="text-xs font-semibold text-gray-600 dark:text-gray-300 mt-0.5 truncate"
                >
                    ${escapeHtml(
                        label
                    )}
                </div>

                <div
                    class="text-[10px] text-gray-400 dark:text-gray-500 mt-1 truncate"
                >
                    ${escapeHtml(
                        helper
                    )}
                </div>

            </div>

        </div>

    `;

}


/* =========================================================
   TODAY'S SESSIONS
========================================================= */

function renderTodaySessions() {

    const today =
        new Date();


    const todayDow =
        today.getDay();


    const todays =
        ALL_EVENTS
            .filter(
                event =>
                    String(
                        event.day_of_week
                    ) ===
                    String(
                        todayDow
                    )
            )
            .sort(
                (a, b) =>
                    String(
                        a.start_time
                    ).localeCompare(
                        String(
                            b.start_time
                        )
                    )
            );


    const todaysTasks =
        ALL_TASKS
            .filter(
                task =>
                    taskMatchesDate(
                        task,
                        today
                    )
            )
            .sort(
                (a, b) => {

                    const aDate =
                        taskDate(a);

                    const bDate =
                        taskDate(b);

                    if (
                        !aDate ||
                        !bDate
                    ) {
                        return 0;
                    }

                    return aDate - bDate;

                }
            );


    const element =
        document.getElementById(
            'today-sessions'
        );


    if (!element) {
        return;
    }


    if (
        !todays.length &&
        !todaysTasks.length
    ) {

        element.innerHTML = `

            <p
                class="text-xs text-gray-400 dark:text-gray-500 rounded-xl bg-gray-50 dark:bg-white/[.03] px-3 py-3"
            >
                Nothing scheduled today.
            </p>

        `;

        return;

    }


    let html = '';


    /* Sessions */

    todays.forEach(
        event => {

            html += `

                <label
                    class="flex items-start gap-2.5 cursor-pointer rounded-xl px-2 py-2 -mx-2 hover:bg-gray-50 dark:hover:bg-white/[.03] transition-colors"
                >

                    <input
                        type="checkbox"
                        class="mt-1 session-complete-toggle w-4 h-4 accent-emerald-600"
                        data-id="${escapeAttribute(
                            event.id
                        )}"
                        ${
                            event.is_completed
                                ? 'checked'
                                : ''
                        }
                    >

                    <div
                        class="min-w-0 ${
                            event.is_completed
                                ? 'opacity-50 line-through'
                                : ''
                        }"
                    >

                        <div
                            class="font-semibold truncate text-gray-800 dark:text-gray-100"
                        >
                            ${escapeHtml(
                                event.title
                            )}
                        </div>

                        <div
                            class="text-xs text-gray-400 dark:text-gray-500 mt-0.5"
                        >
                            ${escapeHtml(
                                event.start_time.slice(
                                    0,
                                    5
                                )
                            )}
                            –
                            ${escapeHtml(
                                event.end_time.slice(
                                    0,
                                    5
                                )
                            )}
                        </div>

                        ${
                            event.course_code
                                ? `
                                    <div class="text-[10px] text-emerald-700 dark:text-emerald-300 mt-0.5 truncate">
                                        ${escapeHtml(
                                            event.course_code
                                        )}
                                    </div>
                                `
                                : ''
                        }

                    </div>

                    ${event.is_completed ? '' : `<button type="button" class="ml-auto shrink-0 mt-0.5 px-2 py-1 rounded-lg text-[10px] font-bold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 timer-session-btn" data-work-item-type="session" data-work-item-id="${escapeAttribute(event.id)}" data-work-item-title="${escapeAttribute(event.title)}" data-work-complete="false"><span data-work-label>Start</span></button>`}

                </label>

            `;

        }
    );


    /* Tasks */

    todaysTasks.forEach(
        task => {

            html += `

                <button
                    type="button"
                    class="w-full text-left flex items-start gap-2.5 rounded-xl px-2 py-2 -mx-2 hover:bg-gray-50 dark:hover:bg-white/[.03] transition-colors"
                    data-today-task-id="${escapeAttribute(
                        task.id
                    )}"
                >

                    <span
                        class="mt-1.5 w-2 h-2 rounded-full bg-amber-500 shrink-0"
                    ></span>

                    <div class="min-w-0">

                        <div
                            class="font-semibold truncate text-gray-800 dark:text-gray-100"
                        >
                            ${escapeHtml(
                                task.title
                            )}
                        </div>

                        <div
                            class="text-xs text-amber-700 dark:text-amber-300 mt-0.5"
                        >
                            Task due
                            ${escapeHtml(
                                formatTaskTime(
                                    task
                                )
                            )}
                        </div>

                        ${
                            task.course_code
                                ? `
                                    <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5 truncate">
                                        ${escapeHtml(
                                            task.course_code
                                        )}
                                    </div>
                                `
                                : ''
                        }

                    </div>

                </button>

            `;

        }
    );


    element.innerHTML =
        html;


    /* Session completion */

    element
        .querySelectorAll(
            '.session-complete-toggle'
        )
        .forEach(
            checkbox => {

                checkbox.addEventListener(
                    'change',
                    async event => {

                        const checked =
                            event.target.checked;


                        try {

                            const response =
                                await fetch(
                                    `${API}/schedule.php`,
                                    {
                                        method: 'PUT',
                                        headers: {
                                            'Content-Type':
                                                'application/json'
                                        },
                                        body:
                                            JSON.stringify({
                                                id:
                                                    checkbox.dataset.id,

                                                is_completed:
                                                    checked,

                                                csrf_token:
                                                    window.CSRF_TOKEN
                                            })
                                    }
                                );


                            if (
                                !response.ok
                            ) {

                                event.target.checked =
                                    !checked;


                                const error =
                                    await response
                                        .json()
                                        .catch(
                                            () => ({})
                                        );


                                if (
                                    window.showToast
                                ) {

                                    window.showToast(
                                        error.error ||
                                            'Could not update this session.',
                                        'error'
                                    );

                                }


                                return;

                            }


                        } catch (
                            error
                        ) {

                            event.target.checked =
                                !checked;


                            if (
                                window.showToast
                            ) {

                                window.showToast(
                                    'Could not update this session.',
                                    'error'
                                );

                            }


                            return;

                        }


                        loadSchedule();

                    }
                );

            }
        );


    /* Task click */

    element
        .querySelectorAll(
            '[data-today-task-id]'
        )
        .forEach(
            button => {

                button.addEventListener(
                    'click',
                    () => {

                        const task =
                            ALL_TASKS.find(
                                item =>
                                    String(
                                        item.id
                                    ) ===
                                    String(
                                        button.dataset
                                            .todayTaskId
                                    )
                            );


                        if (
                            !task
                        ) {
                            return;
                        }


                        if (
                            window.showToast
                        ) {

                            window.showToast(
                                `${task.title} · due ${formatTaskTime(task)}`,
                                'info'
                            );

                        } else {

                            window.location.href =
                                `deadlines.php?task=${encodeURIComponent(
                                    task.id
                                )}`;

                        }

                    }
                );

            }
        );

}


/* =========================================================
   WEEKLY GOAL
========================================================= */

function renderGoal(
    stats
) {

    const label =
        document.getElementById(
            'goal-progress-label'
        );

    const bar =
        document.getElementById(
            'goal-progress-bar'
        );


    if (label) {

        label.textContent =
            `${stats.scheduled_hours} / ${stats.weekly_goal_hours} hrs`;

    }


    if (bar) {

        bar.style.width =
            Math.min(
                100,
                Number(
                    stats.weekly_utilization
                ) || 0
            ) +
            '%';

    }

}


/* =========================================================
   EDIT WEEKLY GOAL
========================================================= */

document
    .getElementById(
        'edit-goal-btn'
    )
    ?.addEventListener(
        'click',
        async () => {

            const current =
                document
                    .getElementById(
                        'goal-progress-label'
                    )
                    ?.textContent
                    .split('/')[1]
                    ?.trim()
                    .replace(
                        ' hrs',
                        ''
                    );


            const next =
                prompt(
                    'Set your weekly study goal (hours):',
                    current ||
                        '15'
                );


            if (
                next === null ||
                isNaN(
                    parseFloat(
                        next
                    )
                )
            ) {
                return;
            }


            try {

                await fetch(
                    `${API}/settings.php`,
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json'
                        },

                        body:
                            JSON.stringify({

                                weekly_goal_hours:
                                    parseFloat(
                                        next
                                    ),

                                csrf_token:
                                    window.CSRF_TOKEN

                            })
                    }
                );

            } catch (
                error
            ) {

                if (
                    window.showToast
                ) {

                    window.showToast(
                        'Could not update weekly goal.',
                        'error'
                    );

                }

                return;

            }


            loadSchedule();

        }
    );


/* =========================================================
   TIME DISTRIBUTION
========================================================= */

function renderDistribution(
    distribution
) {

    const safeDistribution =
        distribution &&
        typeof distribution ===
            'object'
            ? distribution
            : {};


    const labels =
        Object.keys(
            safeDistribution
        ).map(
            capitalize
        );


    const values =
        Object.values(
            safeDistribution
        ).map(
            value =>
                Math.round(
                    Number(
                        value
                    ) * 10
                ) / 10
        );


    const colors =
        Object.keys(
            safeDistribution
        ).map(
            type =>
                TYPE_COLORS[type] ||
                '#9ca3af'
        );


    if (
        distributionChart
    ) {

        distributionChart.destroy();

        distributionChart =
            null;

    }


    const legend =
        document.getElementById(
            'distribution-legend'
        );


    if (
        !labels.length
    ) {

        if (legend) {

            legend.innerHTML =
                '<p class="text-gray-400 dark:text-gray-500">No sessions yet.</p>';

        }

        return;

    }


    const canvas =
        document.getElementById(
            'distribution-chart'
        );


    if (!canvas) {
        return;
    }


    if (
        typeof Chart !==
        'undefined'
    ) {

        distributionChart =
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

                                borderWidth: 0
                            }
                        ]
                    },

                    options: {

                        cutout: '68%',

                        plugins: {
                            legend: {
                                display: false
                            }
                        },

                        maintainAspectRatio:
                            false

                    }

                }
            );

    }


    if (legend) {

        legend.innerHTML =
            labels
                .map(
                    (
                        label,
                        index
                    ) => `

                        <div
                            class="flex items-center gap-2 min-w-0"
                        >

                            <span
                                class="w-2 h-2 rounded-full shrink-0"
                                style="background:${escapeAttribute(
                                    colors[index]
                                )}"
                            ></span>

                            <span
                                class="text-gray-600 dark:text-gray-300 truncate"
                            >
                                ${escapeHtml(
                                    label
                                )}
                            </span>

                            <span
                                class="ml-auto font-semibold"
                            >
                                ${escapeHtml(
                                    values[index]
                                )}h
                            </span>

                        </div>

                    `
                )
                .join('');

    }

}


/* =========================================================
   COURSE OPTIONS
========================================================= */

function populateCourseOptions() {

    const select =
        document.getElementById(
            'session-course-select'
        );


    if (!select) {
        return;
    }


    const currentValue =
        select.value;


    select.innerHTML =
        '<option value="">No course</option>';


    select.insertAdjacentHTML(
        'beforeend',

        COURSES_CACHE
            .map(
                course => `

                    <option
                        value="${escapeAttribute(
                            course.id
                        )}"
                    >

                        ${escapeHtml(
                            course.code
                        )}

                        —

                        ${escapeHtml(
                            course.name
                        )}

                    </option>

                `
            )
            .join('')
    );


    if (
        currentValue
    ) {

        select.value =
            currentValue;

    }

}


async function loadCourseOptions() {

    try {

        const response =
            await fetch(
                `${API}/courses.php`
            );


        if (
            !response.ok
        ) {
            return;
        }


        const data =
            await response.json();


        COURSES_CACHE =
            Array.isArray(
                data.courses
            )
                ? data.courses
                : [];


        populateCourseOptions();

    } catch (
        error
    ) {

        console.error(
            'Could not load courses:',
            error
        );

    }

}


/* =========================================================
   TASK OPTIONS
========================================================= */

function populateTaskOptions() {

    const select =
        document.getElementById(
            'session-task-select'
        );


    if (!select) {
        return;
    }


    const currentValue =
        select.value;


    select.innerHTML =
        '<option value="">No linked task</option>';


    select.insertAdjacentHTML(
        'beforeend',

        ALL_TASKS
            .map(
                task => {

                    const course =
                        task.course_code
                            ? ` — ${task.course_code}`
                            : '';


                    const due =
                        formatTaskTime(
                            task
                        );


                    return `

                        <option
                            value="${escapeAttribute(
                                task.id
                            )}"
                        >

                            ${escapeHtml(
                                task.title
                            )}

                            ${escapeHtml(
                                course
                            )}

                            ${
                                due
                                    ? ` · due ${escapeHtml(
                                        due
                                    )}`
                                    : ''
                            }

                        </option>

                    `;

                }
            )
            .join('')
    );


    if (
        currentValue
    ) {

        select.value =
            currentValue;

    }

}


/* =========================================================
   LOAD SCHEDULE
========================================================= */

async function loadSchedule() {

    try {

        const response =
            await fetch(
                `${API}/schedule.php`,
                {
                    cache: 'no-store'
                }
            );


        if (
            !response.ok
        ) {

            throw new Error(
                `HTTP ${response.status}`
            );

        }


        const data =
            await response.json();


        ALL_EVENTS =
            Array.isArray(
                data.events
            )
                ? data.events
                : [];


        ALL_TASKS =
            Array.isArray(
                data.tasks
            )
                ? data.tasks
                : [];


        /*
         * Keep courses synchronized.
         */

        if (
            Array.isArray(
                data.courses
            )
        ) {

            COURSES_CACHE =
                data.courses;

        }


        populateCourseOptions();

        populateTaskOptions();


        const stats =
            data.stats || {

                total_sessions: 0,

                scheduled_hours: 0,

                completed: 0,

                weekly_utilization: 0,

                daily_goal_met: 0,

                weekly_goal_hours: 0,

                time_distribution: {}

            };


        renderStatCards(
            stats
        );


        renderCurrentView();

        renderTodaySessions();

        renderGoal(
            stats
        );

        renderDistribution(
            stats.time_distribution
        );


        initLucide();


    } catch (
        error
    ) {

        console.error(
            'Schedule load failed:',
            error
        );


        if (
            window.showToast
        ) {

            window.showToast(
                'Could not load your schedule.',
                'error'
            );

        }

    }

}


/* =========================================================
   VIEW CONTROLS
========================================================= */

document
    .querySelectorAll(
        '.schedule-view-btn'
    )
    .forEach(
        button => {

            button.addEventListener(
                'click',
                () => {

                    const newView =
                        button.dataset.view;


                    if (
                        ![
                            'week',
                            'day',
                            'month'
                        ].includes(
                            newView
                        )
                    ) {
                        return;
                    }


                    scheduleView =
                        newView;


                    renderCurrentView();

                }
            );

        }
    );


document
    .getElementById(
        'schedule-prev'
    )
    ?.addEventListener(
        'click',
        () => {

            navigateSchedule(
                -1
            );

        }
    );


document
    .getElementById(
        'schedule-next'
    )
    ?.addEventListener(
        'click',
        () => {

            navigateSchedule(
                1
            );

        }
    );


document
    .getElementById(
        'schedule-today'
    )
    ?.addEventListener(
        'click',
        () => {

            goToToday();

        }
    );


/* =========================================================
   MINI CALENDAR NAVIGATION
========================================================= */

document
    .getElementById(
        'mini-calendar-prev'
    )
    ?.addEventListener(
        'click',
        () => {

            miniCalendarDate =
                new Date(
                    miniCalendarDate.getFullYear(),
                    miniCalendarDate.getMonth() - 1,
                    1
                );


            renderMiniCalendar();

        }
    );


document
    .getElementById(
        'mini-calendar-next'
    )
    ?.addEventListener(
        'click',
        () => {

            miniCalendarDate =
                new Date(
                    miniCalendarDate.getFullYear(),
                    miniCalendarDate.getMonth() + 1,
                    1
                );


            renderMiniCalendar();

        }
    );


/* =========================================================
   ADD / EDIT SESSION MODAL
========================================================= */

const modal =
    document.getElementById(
        'session-modal'
    );


const form =
    document.getElementById(
        'session-form'
    );


function closeSessionModal() {

    if (modal) {

        modal.classList.add(
            'hidden'
        );

    }

}


document
    .getElementById(
        'open-add-session'
    )
    ?.addEventListener(
        'click',
        () => {

            if (!form || !modal) {
                return;
            }


            form.reset();

            form.id.value =
                '';


            if (
                form.task_id
            ) {

                form.task_id.value =
                    '';

            }


            if (
                document.getElementById(
                    'session-modal-title'
                )
            ) {

                document.getElementById(
                    'session-modal-title'
                ).textContent =
                    'Add Study Session';

            }


            document
                .getElementById(
                    'delete-session'
                )
                ?.classList.add(
                    'hidden'
                );


            modal.classList.remove(
                'hidden'
            );


            initLucide();

        }
    );


document
    .getElementById(
        'cancel-session'
    )
    ?.addEventListener(
        'click',
        closeSessionModal
    );


document
    .getElementById(
        'cancel-session-secondary'
    )
    ?.addEventListener(
        'click',
        closeSessionModal
    );


if (modal) {

    modal.addEventListener(
        'click',
        event => {

            if (
                event.target ===
                modal
            ) {

                closeSessionModal();

            }

        }
    );

}


/* Dashboard deep-link */

if (
    new URLSearchParams(
        window.location.search
    ).get('add') === '1'
) {

    document
        .getElementById(
            'open-add-session'
        )
        ?.click();


    window.history.replaceState(
        {},
        '',
        'schedule.php'
    );

}


/* =========================================================
   DELETE SESSION
========================================================= */

document
    .getElementById(
        'delete-session'
    )
    ?.addEventListener(
        'click',
        async () => {

            if (!form) {
                return;
            }


            const id =
                form.id.value;


            if (!id) {
                return;
            }


            if (
                !confirm(
                    'Delete this session? This cannot be undone.'
                )
            ) {

                return;

            }


            try {

                const response =
                    await fetch(
                        `${API}/schedule.php`,
                        {
                            method: 'DELETE',

                            headers: {
                                'Content-Type':
                                    'application/x-www-form-urlencoded'
                            },

                            body:
                                `id=${encodeURIComponent(
                                    id
                                )}&csrf_token=${encodeURIComponent(
                                    window.CSRF_TOKEN || ''
                                )}`
                        }
                    );


                if (
                    !response.ok
                ) {

                    const error =
                        await response
                            .json()
                            .catch(
                                () => ({})
                            );


                    throw new Error(
                        error.error ||
                            'Could not delete session.'
                    );

                }


                if (
                    window.showToast
                ) {

                    window.showToast(
                        'Session deleted',
                        'success'
                    );

                }


                closeSessionModal();

                loadSchedule();


            } catch (
                error
            ) {

                if (
                    window.showToast
                ) {

                    window.showToast(
                        error.message ||
                            'Could not delete session.',
                        'error'
                    );

                }

            }

        }
    );


/* =========================================================
   OPEN EDIT SESSION
========================================================= */

function openEditSession(
    id
) {

    if (
        !form ||
        !modal
    ) {
        return;
    }


    const event =
        ALL_EVENTS.find(
            item =>
                String(
                    item.id
                ) ===
                String(
                    id
                )
        );


    if (!event) {
        return;
    }


    form.id.value =
        event.id;


    form.title.value =
        event.title || '';


    form.course_id.value =
        event.course_id ||
        '';


    if (
        form.task_id
    ) {

        form.task_id.value =
            event.task_id ||
            '';

    }


    form.event_type.value =
        event.event_type ||
        'study';


    form.day_of_week.value =
        event.day_of_week;


    form.start_time.value =
        String(
            event.start_time ||
            ''
        ).slice(
            0,
            5
        );


    form.end_time.value =
        String(
            event.end_time ||
            ''
        ).slice(
            0,
            5
        );


    form.is_completed.checked =
        !!event.is_completed;


    document.getElementById(
        'session-modal-title'
    ).textContent =
        'Edit Study Session';


    document
        .getElementById(
            'delete-session'
        )
        ?.classList.remove(
            'hidden'
        );


    modal.classList.remove(
        'hidden'
    );


    initLucide();

}


/* =========================================================
   SAVE SESSION
========================================================= */

form?.addEventListener(
    'submit',
    async event => {

        event.preventDefault();


        const formData =
            new FormData(
                form
            );


        const payload =
            Object.fromEntries(
                formData.entries()
            );


        payload.is_completed =
            form.is_completed.checked;


        payload.csrf_token =
            window.CSRF_TOKEN;


        const isEdit =
            !!payload.id;


        /*
         * Ensure empty task/course values
         * are sent as null-like empty values.
         */

        if (
            !payload.course_id
        ) {

            payload.course_id =
                '';

        }


        if (
            !payload.task_id
        ) {

            payload.task_id =
                '';

        }


        try {

            const response =
                await fetch(
                    `${API}/schedule.php`,
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


            if (
                response.ok
            ) {

                closeSessionModal();


                if (
                    window.showToast
                ) {

                    window.showToast(
                        isEdit
                            ? 'Session updated'
                            : 'Session added',
                        'success'
                    );

                }


                await loadSchedule();
        initImportTimetableModal();
        initStudyPreferencesModal();



            } else {

                const error =
                    await response
                        .json()
                        .catch(
                            () => ({})
                        );


                const message =
                    error.error ||
                    'Could not save session.';


                if (
                    window.showToast
                ) {

                    window.showToast(
                        message,
                        'error'
                    );

                } else {

                    alert(
                        message
                    );

                }

            }


        } catch (
            error
        ) {

            console.error(
                error
            );


            if (
                window.showToast
            ) {

                window.showToast(
                    'Could not connect to the schedule server.',
                    'error'
                );

            } else {

                alert(
                    'Could not connect to the schedule server.'
                );

            }

        }

    }
);




/* =========================================================
   FREE PERIODS & STUDY PLANNER ENGINE
========================================================= */

function renderFreePeriodsSection(freePeriods, recommendations) {
    const container = document.getElementById('schedule-free-periods');
    if (!container) return;

    const recList = Array.isArray(recommendations) ? recommendations : [];
    const fpList = Array.isArray(freePeriods) ? freePeriods : [];

    if (recList.length === 0 && fpList.length === 0) {
        container.innerHTML = `
            <div class="text-center py-4 px-2 text-gray-500 dark:text-gray-400">
                <i data-lucide="smile" class="w-6 h-6 mx-auto mb-1 text-emerald-600 dark:text-emerald-400 opacity-80"></i>
                <p class="font-medium text-xs">No timetable gaps today</p>
                <p class="text-[11px] mt-0.5 opacity-80">Your classes and study blocks are balanced.</p>
            </div>
        `;
        initLucide();
        return;
    }

    let html = '';

    if (recList.length > 0) {
        html += `<div class="font-semibold text-emerald-800 dark:text-emerald-300 text-[11px] uppercase tracking-wider mb-1.5">Recommended Study Slots</div>`;
        recList.forEach(rec => {
            const courseCode = rec.course_code ? `<span class="font-semibold text-emerald-700 dark:text-emerald-400">${escapeHtml(rec.course_code)}:</span> ` : '';
            html += `
                <div class="w-full max-w-full min-w-0 box-border p-3 rounded-xl border border-emerald-200/80 dark:border-emerald-800/50 bg-emerald-50/40 dark:bg-emerald-950/20 space-y-2.5 mb-2.5">
                    <div class="min-w-0 w-full">
                        <div class="font-semibold text-gray-900 dark:text-gray-100 text-xs break-words whitespace-normal leading-snug">
                            ${courseCode}${escapeHtml(rec.task_title || rec.title || 'Study Session')}
                        </div>
                        <div class="text-[11px] text-gray-500 dark:text-gray-400 flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-1 min-w-0">
                            <span class="inline-flex items-center gap-1"><i data-lucide="clock" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0"></i> <span>${escapeHtml(rec.start_label || rec.start_time)} – ${escapeHtml(rec.end_label || rec.end_time)}</span></span>
                            <span class="text-gray-300 dark:text-gray-600">·</span>
                            <span>${escapeHtml(rec.duration_label || (rec.duration_minutes + 'm'))}</span>
                        </div>
                    </div>
                    ${rec.reason ? `
                        <div class="rounded-lg bg-emerald-100/70 dark:bg-emerald-900/40 text-emerald-900 dark:text-emerald-200 text-[11px] px-2.5 py-1.5 leading-relaxed break-words whitespace-normal min-w-0 w-full">
                            <span class="font-semibold text-emerald-800 dark:text-emerald-300">Why: </span>${escapeHtml(rec.reason)}
                        </div>
                    ` : ''}
                    <div class="flex flex-wrap items-center gap-2 pt-1 w-full min-w-0">
                        <button type="button" class="btn-accept-rec flex-1 min-w-[120px] px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-[11px] transition-colors flex items-center justify-center gap-1"
                            data-task-id="${rec.task_id || ''}"
                            data-course-id="${rec.course_id || ''}"
                            data-title="${escapeAttribute('Study: ' + (rec.task_title || rec.title || 'Study Session'))}"
                            data-start="${escapeAttribute(rec.start_time)}"
                            data-end="${escapeAttribute(rec.end_time)}">
                            <i data-lucide="calendar-plus" class="w-3 h-3 shrink-0"></i> <span>Add to My Day</span>
                        </button>
                        <a href="${rec.task_id ? `tasks.php?focus_task_id=${rec.task_id}` : (rec.course_id ? `tasks.php?course_id=${rec.course_id}` : `tasks.php`)}" class="shrink-0 min-w-[65px] px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/5 font-semibold text-[11px] text-gray-700 dark:text-gray-300 transition-colors flex items-center justify-center gap-1">
                            <i data-lucide="play" class="w-3 h-3 shrink-0"></i> <span>Study</span>
                        </a>
                    </div>
                </div>
            `;
        });
    }

    if (fpList.length > 0) {
        html += `<div class="font-semibold text-gray-600 dark:text-gray-400 text-[11px] uppercase tracking-wider mt-3 mb-1.5">Free Gaps Today</div>`;
        fpList.forEach(fp => {
            html += `
                <div class="w-full max-w-full min-w-0 box-border p-2.5 rounded-xl border border-gray-200/80 dark:border-white/10 bg-white/60 dark:bg-white/[.02] flex flex-wrap sm:flex-nowrap items-center justify-between gap-2 mb-1.5">
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-gray-800 dark:text-gray-200 text-xs break-words">
                            ${capitalize(fp.period_label || 'Free Window')}
                        </div>
                        <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                            ${escapeHtml(fp.start_label || fp.start_time)} – ${escapeHtml(fp.end_label || fp.end_time)} (${fp.duration_label || (fp.duration_minutes + 'm free')})
                        </div>
                    </div>
                    <button type="button" class="btn-fill-free-period shrink-0 px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 border border-emerald-200/60 dark:border-emerald-800/40 transition-colors"
                        data-start="${escapeAttribute(fp.start_time)}"
                        data-end="${escapeAttribute(fp.end_time)}">
                        + Session
                    </button>
                </div>
            `;
        });
    }

    container.innerHTML = html;
    initLucide();

    container.querySelectorAll('.btn-accept-rec').forEach(btn => {
        btn.addEventListener('click', async () => {
            const taskId = btn.dataset.taskId;
            const courseId = btn.dataset.courseId ? parseInt(btn.dataset.courseId, 10) : null;
            const title = btn.dataset.title;
            const startTime = btn.dataset.start;
            const endTime = btn.dataset.end;
            const dayOfWeek = new Date().getDay();

            btn.disabled = true;
            btn.innerHTML = `<i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> Adding...`;
            initLucide();

            try {
                const res = await fetch(`${API}/schedule.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'accept_recommendation',
                        task_id: taskId ? parseInt(taskId, 10) : null,
                        course_id: courseId,
                        title: title,
                        start_time: startTime,
                        end_time: endTime,
                        day_of_week: dayOfWeek,
                        csrf_token: window.CSRF_TOKEN
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    if (window.showToast) window.showToast('Study session scheduled!', 'success');
                    await loadSchedule();
        initImportTimetableModal();
        initStudyPreferencesModal();

                } else {
                    if (window.showToast) window.showToast(data.error || 'Failed to schedule session.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = `<i data-lucide="calendar-plus" class="w-3 h-3"></i> Add to My Day`;
                    initLucide();
                }
            } catch (err) {
                console.error('Accept recommendation failed:', err);
                if (window.showToast) window.showToast('Could not schedule session.', 'error');
                btn.disabled = false;
                btn.innerHTML = `<i data-lucide="calendar-plus" class="w-3 h-3"></i> Add to My Day`;
                initLucide();
            }
        });
    });

    container.querySelectorAll('.btn-fill-free-period').forEach(btn => {
        btn.addEventListener('click', () => {
            const startTime = btn.dataset.start;
            const endTime = btn.dataset.end;
            document.getElementById('open-add-session')?.click();
            const sessionForm = document.getElementById('session-form');
            if (sessionForm) {
                if (startTime) sessionForm.start_time.value = startTime;
                if (endTime) sessionForm.end_time.value = endTime;
                sessionForm.day_of_week.value = String(new Date().getDay());
            }
        });
    });
}

function initImportTimetableModal() {
    const openBtn = document.getElementById('open-import-timetable');
    const modal = document.getElementById('import-timetable-modal');
    const closeBtn = document.getElementById('close-import-timetable');
    const cancelBtn = document.getElementById('cancel-import-timetable');
    const form = document.getElementById('import-timetable-form');
    const fileInput = document.getElementById('import-timetable-file');
    const textArea = document.getElementById('import-timetable-csv');
    const errorBox = document.getElementById('import-timetable-error');

    if (!modal) return;

    function openModal() {
        if (errorBox) { errorBox.textContent = ''; errorBox.classList.add('hidden'); }
        modal.classList.remove('hidden');
        initLucide();
    }

    function closeModal() {
        modal.classList.add('hidden');
        if (form) form.reset();
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            const file = fileInput.files?.[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    if (textArea) textArea.value = e.target.result;
                };
                reader.readAsText(file);
            }
        });
    }

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const csvData = (textArea?.value || '').trim();
            if (!csvData) {
                if (errorBox) {
                    errorBox.textContent = 'Please choose a CSV file or paste timetable lines.';
                    errorBox.classList.remove('hidden');
                }
                return;
            }

            const submitBtn = document.getElementById('submit-import-timetable');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Importing...`;
                initLucide();
            }

            try {
                const res = await fetch(`${API}/schedule.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'import_csv',
                        csv: csvData,
                        csrf_token: window.CSRF_TOKEN
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    if (window.showToast) window.showToast(`Successfully imported ${data.imported_count} timetable sessions!`, 'success');
                    closeModal();
                    await loadSchedule();
        initImportTimetableModal();
        initStudyPreferencesModal();

                } else {
                    if (errorBox) {
                        errorBox.textContent = data.error || 'Failed to import timetable.';
                        errorBox.classList.remove('hidden');
                    }
                }
            } catch (err) {
                console.error('Import timetable failed:', err);
                if (errorBox) {
                    errorBox.textContent = 'Network or server error while importing timetable.';
                    errorBox.classList.remove('hidden');
                }
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = `<i data-lucide="upload" class="w-4 h-4"></i> Import Timetable`;
                    initLucide();
                }
            }
        });
    }
}

function initStudyPreferencesModal() {
    const openBtn = document.getElementById('open-study-prefs');
    const modal = document.getElementById('study-preferences-modal');
    const closeBtn = document.getElementById('close-study-prefs');
    const cancelBtn = document.getElementById('cancel-study-prefs');
    const form = document.getElementById('study-preferences-form');

    if (!modal) return;

    async function openModal() {
        modal.classList.remove('hidden');
        initLucide();

        try {
            const res = await fetch(`${API}/me.php`, { cache: 'no-store' });
            if (res.ok) {
                const me = await res.json();
                if (form) {
                    const prefTime = me.preferred_study_time || 'flexible';
                    const radio = form.querySelector(`input[name="preferred_study_time"][value="${prefTime}"]`);
                    if (radio) radio.checked = true;

                    const hoursInput = form.querySelector('#pref-weekly-hours');
                    if (hoursInput && me.weekly_goal_hours) {
                        hoursInput.value = Math.round(me.weekly_goal_hours);
                    }

                    const daysStr = String(me.preferred_study_days || '1,2,3,4,5');
                    const activeDays = daysStr.split(',').map(s => s.trim());
                    form.querySelectorAll('input[name="preferred_days"]').forEach(cb => {
                        cb.checked = activeDays.includes(cb.value);
                    });
                }
            }
        } catch (err) {
            console.error('Failed to load study preferences:', err);
        }
    }

    function closeModal() {
        modal.classList.add('hidden');
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('save-study-prefs');
            const prefTime = form.querySelector('input[name="preferred_study_time"]:checked')?.value || 'flexible';
            const weeklyHours = parseFloat(form.querySelector('#pref-weekly-hours')?.value || '15');
            const checkedDays = Array.from(form.querySelectorAll('input[name="preferred_days"]:checked')).map(cb => cb.value);

            if (checkedDays.length === 0) {
                alert('Please select at least one preferred study day.');
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving...`;
                initLucide();
            }

            try {
                const res = await fetch(`${API}/settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        preferred_study_time: prefTime,
                        weekly_goal_hours: weeklyHours,
                        preferred_study_days: checkedDays.join(','),
                        csrf_token: window.CSRF_TOKEN
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    if (window.showToast) window.showToast('Study routine preferences saved!', 'success');
                    closeModal();
                    await loadSchedule();
        initImportTimetableModal();
        initStudyPreferencesModal();

                } else {
                    alert(data.error || 'Failed to save study routine.');
                }
            } catch (err) {
                console.error('Save study preferences failed:', err);
                alert('Could not save preferences due to network error.');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = `<i data-lucide="check" class="w-4 h-4"></i> Save Routine`;
                    initLucide();
                }
            }
        });
    }
}

/* =========================================================
   INITIALIZATION
========================================================= */

window.addEventListener('work-item-updated', e => { if (e.detail?.type === 'session') loadSchedule(); });

window.APP_READY.then(
    async me => {

        if (!me) {
            return;
        }


        anchorDate =
            new Date();


        miniCalendarDate =
            new Date(
                anchorDate.getFullYear(),
                anchorDate.getMonth(),
                1
            );


        /*
         * Render immediately.
         */

        renderCurrentView();


        /*
         * Load courses first so the
         * course selector is ready.
         */

        await loadCourseOptions();


        /*
         * Load events + tasks.
         */

        await loadSchedule();
        initImportTimetableModal();
        initStudyPreferencesModal();



        initLucide();

    }
);