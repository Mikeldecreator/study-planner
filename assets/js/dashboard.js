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


    const heroSubtitle =
        document.getElementById(
            'hero-subtitle'
        );

    if (heroSubtitle) {
        if (data.context_summary && data.context_summary.message) {
            heroSubtitle.textContent =
                data.context_summary.message;
        } else if (data.academic_state === 'overdue') {
            heroSubtitle.textContent =
                'You have overdue tasks that need urgent attention.';
        } else if (data.academic_state === 'urgent') {
            heroSubtitle.textContent =
                'Critical deadlines are approaching soon. Prioritize your key tasks today.';
        } else if (data.academic_state === 'all_completed') {
            heroSubtitle.textContent =
                "All caught up! Excellent work staying ahead of your academic commitments.";
        } else {
            heroSubtitle.textContent =
                'Keep going! Your consistency today builds your success tomorrow.';
        }
    }


    /*
     * These values are optional because the existing
     * dashboard API may not return them.
     */
    let courseName =
        data.course_name ||
        data.program ||
        data.course;

    let level =
        data.level ||
        data.level_name;

    if (!courseName) {
        if (Array.isArray(data.active_courses_summary) && data.active_courses_summary.length > 0) {
            const count = data.active_courses_summary.length;
            courseName = `${count} Course${count > 1 ? 's' : ''} Enrolled`;
            level = `${data.context_summary && data.context_summary.active_tasks ? data.context_summary.active_tasks : 0} active work items`;
        } else if (Array.isArray(data.courses) && data.courses.length > 0) {
            const count = data.courses.length;
            courseName = `${count} Course${count > 1 ? 's' : ''} Enrolled`;
            level = 'View your courses';
        } else {
            courseName = 'No Courses Yet';
            level = 'Click to add courses';
        }
    } else if (!level) {
        level = 'Enrolled';
    }

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
     * Next deadline / academic alert pill.
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
        if (data.academic_state === 'overdue' && data.context_summary && data.context_summary.overdue_tasks > 0) {
            const count = data.context_summary.overdue_tasks;
            nextDeadlineElement.innerHTML =
                `<span class="text-red-600 dark:text-red-400 font-bold">⚠ ${count} overdue task${count > 1 ? 's' : ''}</span>`;
        } else if (data.todays_focus) {
            const tf = data.todays_focus;
            const due = tf.due_label || tf.due_at_display || '';
            nextDeadlineElement.textContent =
                due ? `${tf.title} (${due})` : tf.title;
        } else if (nextDeadline) {
            const title =
                nextDeadline.title ||
                'Upcoming task';
            const due =
                nextDeadline.due_label ||
                nextDeadline.due_at_display ||
                '';
            nextDeadlineElement.textContent =
                due ? `${title} (${due})` : title;
        } else if (data.academic_state === 'all_completed') {
            nextDeadlineElement.textContent =
                'All tasks completed';
        } else {
            nextDeadlineElement.textContent =
                'No upcoming deadlines';
        }
    }

    const nextClassElement =
        document.getElementById(
            'hero-next-class'
        );

    if (nextClassElement) {
        if (data.next_class) {
            const nc = data.next_class;
            const code = nc.course_code || nc.title || 'Class';
            const time = nc.start_label || '';
            nextClassElement.textContent =
                time ? `${code} at ${time}` : code;
        } else {
            nextClassElement.textContent =
                'No classes today';
        }
    }

    /*
     * Semester Academic Context pill
     */
    const semesterWeekEl = document.getElementById('hero-semester-week');
    const semesterPhaseEl = document.getElementById('hero-semester-phase');
    const semesterPill = document.getElementById('hero-semester-pill');
    if (semesterWeekEl && semesterPhaseEl) {
        const sCtx = data.semester_context;
        if (sCtx && sCtx.has_semester) {
            semesterWeekEl.textContent = sCtx.current_week_number
                ? `Week ${sCtx.current_week_number} of ${sCtx.total_weeks}`
                : (sCtx.semester_name || 'Academic Calendar');
            semesterPhaseEl.textContent = sCtx.current_phase || 'Active';
            if (semesterPill) {
                semesterPill.title = `${sCtx.semester_name} • ${sCtx.student_guidance || ''}`;
            }
        } else {
            semesterWeekEl.textContent = 'Semester Calendar';
            semesterPhaseEl.textContent = 'Set academic dates';
            if (semesterPill) {
                semesterPill.title = 'Set up your semester academic calendar';
            }
        }
    }
}


/* =========================================================
   DASHBOARD SMART FOCUS
========================================================= */

function renderDashboardSmartFocus(input) {
    const container =
        document.getElementById(
            'dashboard-smart-focus'
        );
    const onboardingContainer =
        document.getElementById(
            'dashboard-onboarding'
        );

    if (!container && !onboardingContainer) return;

    let todaysFocus = null;
    let priorityActions = [];
    let academicState = 'on_track';
    let contextSummary = null;

    if (input && typeof input === 'object' && !Array.isArray(input)) {
        todaysFocus = input.todays_focus || null;
        priorityActions = Array.isArray(input.priority_actions) ? input.priority_actions : [];
        academicState = input.academic_state || (todaysFocus ? 'on_track' : 'empty');
        contextSummary = input.context_summary || null;
    } else if (Array.isArray(input)) {
        const activeTasks = input.filter(
            t => String(t.status) !== 'completed' && Number(t.progress_percent || 0) < 100
        );
        if (!activeTasks.length) {
            academicState = input.length > 0 ? 'all_completed' : 'empty';
        } else {
            todaysFocus = activeTasks[0];
            priorityActions = activeTasks.slice(1, 4);
        }
    }

    // State 1: No tasks in system
    if (academicState === 'empty' && !todaysFocus) {
        const target = onboardingContainer || container;
        target.classList.remove('hidden');
        if (onboardingContainer && container && container !== onboardingContainer) {
            container.innerHTML = '';
            container.classList.add('hidden');
        }
        target.innerHTML = `
            <div class="dash-smart-focus p-5 sm:p-6">
                <div class="flex items-start gap-3.5 min-w-0 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 flex items-center justify-center shrink-0 mt-0.5">
                        <i data-lucide="sparkles" class="w-5 h-5"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[10px] uppercase font-extrabold tracking-wider text-emerald-700 dark:text-emerald-400 mb-1">
                            Getting Started • University Student Portal
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-[#073b35] dark:text-white">
                            Set up your semester in 3 simple steps
                        </h3>
                        <p class="text-xs text-[#52756d] dark:text-gray-400 mt-1">
                            We'll guide your coursework, deadlines, and study rhythm all semester long.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5 mt-4">
                    <!-- Step 1: Add Courses -->
                    <div class="p-4 rounded-xl border border-emerald-200/80 dark:border-white/10 bg-white dark:bg-white/[0.03] flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="w-6 h-6 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 font-bold text-xs flex items-center justify-center">1</span>
                                <i data-lucide="book-open" class="w-4 h-4 text-emerald-600"></i>
                            </div>
                            <div class="text-sm font-bold text-[#073b35] dark:text-white">Add Courses</div>
                            <p class="text-xs text-[#628098] dark:text-gray-400 mt-1">Add the courses and subjects you are enrolled in this semester.</p>
                        </div>
                        <div class="mt-4 pt-3 border-t border-gray-100 dark:border-white/10 flex flex-col gap-1.5">
                            <a href="courses.php?open_form=1" class="px-2.5 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100 text-xs font-semibold flex items-center justify-between">
                                <span>Upload Course Form</span>
                                <i data-lucide="file-up" class="w-3.5 h-3.5"></i>
                            </a>
                            <a href="courses.php" class="px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-xs font-semibold flex items-center justify-between">
                                <span>Add Manually</span>
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Step 2: Add Classes -->
                    <div class="p-4 rounded-xl border border-emerald-200/80 dark:border-white/10 bg-white dark:bg-white/[0.03] flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="w-6 h-6 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 font-bold text-xs flex items-center justify-center">2</span>
                                <i data-lucide="calendar-days" class="w-4 h-4 text-emerald-600"></i>
                            </div>
                            <div class="text-sm font-bold text-[#073b35] dark:text-white">Add Classes</div>
                            <p class="text-xs text-[#628098] dark:text-gray-400 mt-1">Set up your weekly timetable or import your class schedule.</p>
                        </div>
                        <div class="mt-4 pt-3 border-t border-gray-100 dark:border-white/10 flex flex-col gap-1.5">
                            <a href="schedule.php?import=1" class="px-2.5 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100 text-xs font-semibold flex items-center justify-between">
                                <span>Import Timetable</span>
                                <i data-lucide="file-up" class="w-3.5 h-3.5"></i>
                            </a>
                            <a href="schedule.php" class="px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-xs font-semibold flex items-center justify-between">
                                <span>Add Class</span>
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Step 3: Add Work -->
                    <div class="p-4 rounded-xl border border-emerald-200/80 dark:border-white/10 bg-white dark:bg-white/[0.03] flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="w-6 h-6 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 font-bold text-xs flex items-center justify-center">3</span>
                                <i data-lucide="check-square" class="w-4 h-4 text-emerald-600"></i>
                            </div>
                            <div class="text-sm font-bold text-[#073b35] dark:text-white">Add Work</div>
                            <p class="text-xs text-[#628098] dark:text-gray-400 mt-1">Create upcoming assignments, projects, and study sessions.</p>
                        </div>
                        <div class="mt-4 pt-3 border-t border-gray-100 dark:border-white/10 flex flex-col gap-1.5">
                            <button type="button" id="open-add-task-focus" class="px-2.5 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100 text-xs font-semibold flex items-center justify-between">
                                <span>Add New Task</span>
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            </button>
                            <a href="tasks.php" class="px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-xs font-semibold flex items-center justify-between">
                                <span>View My Work</span>
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-emerald-200/60 dark:border-white/10 flex items-center justify-between">
                    <span class="text-xs text-[#52756d] dark:text-gray-400">Ready to explore?</span>
                    <a href="#today-schedule" id="see-my-day-btn" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold shadow-sm transition">
                        <span>See My Day</span>
                        <i data-lucide="arrow-down" class="w-3.5 h-3.5"></i>
                    </a>
                </div>
            </div>
        `;

        const addBtn = document.getElementById('open-add-task-focus');
        if (addBtn && typeof openAddTaskModal === 'function') {
            addBtn.addEventListener('click', (e) => {
                e.preventDefault();
                openAddTaskModal();
            });
        }
        document.getElementById('see-my-day-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('today-schedule')?.scrollIntoView({ behavior: 'smooth' });
        });
        if (window.lucide) window.lucide.createIcons();
        return;
    }

    // State 2: All tasks completed
    if (academicState === 'all_completed' && !todaysFocus) {
        if (onboardingContainer) {
            onboardingContainer.innerHTML = '';
            onboardingContainer.classList.add('hidden');
        }
        container.classList.remove('hidden');
        container.innerHTML = `
            <div class="dash-smart-focus flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-3.5 min-w-0 flex-1">
                    <div class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 flex items-center justify-center shrink-0 mt-0.5">
                        <i data-lucide="trophy" class="w-5 h-5"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[10px] uppercase font-extrabold tracking-wider text-emerald-700 dark:text-emerald-400 mb-1">
                            Academic Status • All Caught Up
                        </div>
                        <h3 class="text-base font-bold text-[#073b35] dark:text-white">
                            Outstanding work! All active tasks are completed.
                        </h3>
                        <p class="text-xs text-[#52756d] dark:text-gray-400 mt-1">
                            You're completely up to date with your coursework. Use today to review past topics, work ahead, or relax.
                        </p>
                    </div>
                </div>
                <div class="shrink-0 flex items-center gap-2 self-start sm:self-center">
                    <a
                        href="schedule.php"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold shadow-sm transition"
                    >
                        <i data-lucide="calendar" class="w-3.5 h-3.5"></i> View Schedule
                    </a>
                </div>
            </div>
        `;
        if (window.lucide) window.lucide.createIcons();
        return;
    }

    if (onboardingContainer) {
        onboardingContainer.innerHTML = '';
        onboardingContainer.classList.add('hidden');
    }

    // State 3: Active Task Focus
    if (!todaysFocus) {
        container.innerHTML = '';
        container.classList.add('hidden');
        return;
    }

    const remainingHours = Number(todaysFocus.remaining_hours) || 0;
    const remainingText = remainingHours > 0 ? `~${remainingHours} hrs left` : 'Under 1 hr left';
    const dueDisplay = dashboardEscape(todaysFocus.due_label || todaysFocus.due_at_display || 'Upcoming');
    const reason = dashboardEscape(todaysFocus.priority_reason || 'Recommended as your top study priority based on deadline timing and required prep.');
    const action = dashboardEscape(todaysFocus.recommended_action || 'Start a focused study session to make solid progress.');
    const isOverdue = (todaysFocus.urgency === 'overdue' || Number(todaysFocus.hours_remaining) < 0);
    const timeProgress = Math.round(Number(todaysFocus.system_progress !== undefined ? todaysFocus.system_progress : (todaysFocus.time_progress_percent !== undefined ? todaysFocus.time_progress_percent : todaysFocus.progress_percent || 0)));
    const recStudyText = remainingHours >= 1 ? '1 hour recommended' : (remainingHours > 0 ? `${Math.round(remainingHours * 60)}m recommended` : '30m review recommended');

    const courseInfo = todaysFocus.course_code
        ? `<span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-800/40 text-emerald-800 dark:text-emerald-200">${dashboardEscape(todaysFocus.course_code)}${todaysFocus.course_name ? ` • ${dashboardEscape(todaysFocus.course_name)}` : ''}</span>`
        : '';

    const focusCardHtml = `
        <div class="dash-smart-focus flex flex-col justify-between h-full">
            <div>
                <div class="flex items-start gap-3.5 min-w-0">
                    <div class="w-10 h-10 rounded-xl ${isOverdue ? 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300' : 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300'} flex items-center justify-center shrink-0 mt-0.5">
                        <i data-lucide="${isOverdue ? 'alert-triangle' : 'target'}" class="w-5 h-5"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <span class="text-[10px] uppercase font-extrabold tracking-wider ${isOverdue ? 'text-red-700 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'}">
                                ${isOverdue ? 'PAST DUE • NEEDS ATTENTION' : "STUDY THIS FIRST • HIGH PRIORITY"}
                            </span>
                            ${courseInfo}
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-[#073b35] dark:text-white truncate">
                            ${dashboardEscape(todaysFocus.title)}
                        </h3>
                        <p class="text-xs text-[#52756d] dark:text-gray-300 mt-1 line-clamp-2">
                            ${reason}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-emerald-100/80 dark:border-white/5">
                    <span class="inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full ${isOverdue ? 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/20 dark:text-red-300 dark:border-red-500/30' : 'bg-white dark:bg-white/5 border-emerald-200 dark:border-white/10 text-[#073b35] dark:text-gray-300'} border">
                        <i data-lucide="calendar" class="w-3 h-3 ${isOverdue ? 'text-red-600' : 'text-emerald-600'}"></i> ${dueDisplay}
                    </span>
                    <span class="inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-white dark:bg-white/5 border border-emerald-200 dark:border-white/10 text-[#073b35] dark:text-gray-300">
                        <i data-lucide="clock" class="w-3 h-3 text-emerald-600"></i> ${remainingText}
                    </span>
                    <span class="inline-flex items-center gap-1 text-[11px] font-medium px-2 py-0.5 rounded-full bg-white dark:bg-white/5 border border-emerald-200 dark:border-white/10 text-[#073b35] dark:text-gray-300">
                        <i data-lucide="pie-chart" class="w-3 h-3 text-emerald-600"></i> ${timeProgress}% progress
                    </span>
                    <span class="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300 border border-emerald-200/80 dark:border-white/10">
                        <i data-lucide="sparkles" class="w-3 h-3"></i> ${recStudyText}
                    </span>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-4 pt-3 border-t border-emerald-100/80 dark:border-white/5">
                <div class="flex items-center gap-1.5 text-xs text-[#07553e] dark:text-emerald-300 font-medium">
                    <i data-lucide="arrow-right-circle" class="w-4 h-4 shrink-0 text-emerald-600 dark:text-emerald-400"></i>
                    <span>${action}</span>
                </div>
                <div class="flex items-center gap-2">
                    <a
                        href="tasks.php?task_id=${todaysFocus.id}"
                        class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-xl border border-emerald-700/30 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-white/5 text-xs font-semibold transition shrink-0"
                    >
                        View in My Work
                    </a>
                    <a
                        href="tasks.php?focus_task_id=${todaysFocus.id}"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold shadow-sm transition shrink-0"
                    >
                        Start Studying <i data-lucide="timer" class="w-3.5 h-3.5"></i>
                    </a>
                </div>
            </div>
        </div>
    `;

    let priorityCardHtml = '';
    if (priorityActions.length > 0) {
        priorityCardHtml = `
            <div class="dashboard-card p-4 sm:p-5 flex flex-col justify-between h-full">
                <div>
                    <div class="dashboard-card-header pb-2.5 mb-2">
                        <h3 class="dashboard-card-title flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-[#073b35] dark:text-white">
                            <i data-lucide="list-checks" class="w-4 h-4 text-emerald-700 dark:text-emerald-400"></i>
                            Priority Work
                        </h3>
                        <a href="tasks.php" class="dashboard-view-link text-xs">
                            View All (${contextSummary && contextSummary.active_tasks ? contextSummary.active_tasks : priorityActions.length + 1})
                        </a>
                    </div>
                    <div class="space-y-2.5">
                        ${priorityActions.map(actionItem => {
                            const itemDue = dashboardEscape(actionItem.due_label || '');
                            const itemHours = Number(actionItem.remaining_hours) || 0;
                            const itemOverdue = (actionItem.urgency === 'overdue' || Number(actionItem.hours_remaining) < 0);
                            const itemRisk = actionItem.task_risk === 'critical' || actionItem.task_risk === 'high';

                            return `
                                <div class="dash-priority-action-row block p-2.5 rounded-xl border border-gray-100 dark:border-white/5 bg-gray-50/50 dark:bg-white/[0.02]">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="flex items-center gap-1.5 min-w-0">
                                            <span class="w-2 h-2 rounded-full ${itemOverdue ? 'bg-red-500' : itemRisk ? 'bg-amber-500' : 'bg-emerald-500'} shrink-0"></span>
                                            ${actionItem.course_code ? `<span class="text-[10px] font-bold text-[#4e7187] dark:text-gray-400">${dashboardEscape(actionItem.course_code)}</span>` : ''}
                                        </div>
                                        <span class="text-[10px] font-semibold ${itemOverdue ? 'text-red-600 dark:text-red-400 font-bold' : 'text-amber-600 dark:text-amber-300'} shrink-0">
                                            ${itemDue}
                                        </span>
                                    </div>
                                    <a href="tasks.php?task_id=${actionItem.id}" class="text-xs font-bold text-[#073b35] dark:text-white truncate block hover:text-emerald-700 dark:hover:text-emerald-400" title="View task details">
                                        ${dashboardEscape(actionItem.title)}
                                    </a>
                                    <div class="flex items-center justify-between text-[10px] text-[#628098] dark:text-gray-400 mt-1">
                                        <span>${itemHours > 0 ? `~${itemHours} hrs left` : '<1h left'}</span>
                                        <a href="tasks.php?focus_task_id=${actionItem.id}" class="text-emerald-700 dark:text-emerald-400 font-semibold hover:underline">Start Studying →</a>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
                ${contextSummary && contextSummary.remaining_workload_hours > 0 ? `
                    <div class="mt-3 pt-2.5 border-t border-gray-100 dark:border-white/10 text-[11px] text-[#58768a] dark:text-gray-400 flex items-center justify-between">
                        <span>Total study needed:</span>
                        <strong class="text-[#073b35] dark:text-white font-bold">~${contextSummary.remaining_workload_hours} hrs</strong>
                    </div>
                ` : ''}
            </div>
        `;
    }

    container.classList.remove('hidden');
    if (priorityActions.length > 0) {
        container.innerHTML = `
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 items-stretch">
                <div class="xl:col-span-2">
                    ${focusCardHtml}
                </div>
                <div>
                    ${priorityCardHtml}
                </div>
            </div>
        `;
    } else {
        container.innerHTML = focusCardHtml;
    }

    if (window.lucide) {
        window.lucide.createIcons();
    }
}

/**
 * Render Smart Recommended Study Plan
 * Displays recommended study sessions generated based on free periods and deadlines.
 */
function renderDashboardRecommendedPlan(plan) {
    const container = document.getElementById('dashboard-recommended-plan');
    if (!container) return;

    if (!Array.isArray(plan) || plan.length === 0) {
        // If student has courses or tasks, show guiding message; otherwise hide so setup guide is primary
        const hasWorkOrCourses = document.querySelector('#hero-course-name')?.textContent !== 'No Courses Yet';
        if (hasWorkOrCourses) {
            container.classList.remove('hidden');
            container.innerHTML = `
                <div class="dashboard-card p-4 sm:p-5 border-emerald-200/80 dark:border-emerald-800/30">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 flex items-center justify-center shrink-0">
                            <i data-lucide="sparkles" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-[#073b35] dark:text-white">
                                What to Study
                            </h3>
                            <p class="text-xs text-[#52756d] dark:text-gray-400 mt-0.5">
                                We'll suggest study time when we know your classes and work.
                            </p>
                        </div>
                    </div>
                </div>
            `;
            if (window.lucide) window.lucide.createIcons();
            return;
        }
        container.innerHTML = '';
        container.classList.add('hidden');
        return;
    }

    container.classList.remove('hidden');
    container.innerHTML = `
        <div class="dashboard-card p-5 border-emerald-200/80 dark:border-emerald-800/30">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-gray-100 dark:border-white/10 mb-4">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300 flex items-center justify-center shrink-0">
                        <i data-lucide="sparkles" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-[#073b35] dark:text-white flex items-center gap-2">
                            What to Study
                        </h3>
                        <p class="text-xs text-[#52756d] dark:text-gray-400 mt-0.5">
                            Suggested study sessions based on your classes and upcoming work.
                        </p>
                    </div>
                </div>
                <a href="schedule.php" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline shrink-0">
                    View My Classes →
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3.5">
                ${plan.map(item => `
                    <div
                        id="rec-card-${item.id}"
                        class="p-4 rounded-xl border border-gray-100 dark:border-white/5 bg-gray-50/50 dark:bg-white/[0.02] flex flex-col justify-between gap-3 transition"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-1.5">
                                <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 dark:text-emerald-400">
                                    <i data-lucide="clock" class="w-3.5 h-3.5"></i> ${item.start_label} – ${item.end_label}
                                </span>
                                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                                    ${item.duration_label}
                                </span>
                            </div>

                            <div class="text-sm font-bold text-[#073b35] dark:text-white mb-1 truncate">
                                ${item.course_code ? `<span class="text-xs font-extrabold text-emerald-700 dark:text-emerald-300 mr-1.5">[${item.course_code}]</span>` : ''}${dashboardEscape(item.title)}
                            </div>

                            <p class="text-xs text-[#5c7a72] dark:text-gray-400 line-clamp-2">
                                ${dashboardEscape(item.reason)}
                            </p>
                        </div>

                        <div class="flex items-center gap-2 pt-2.5 border-t border-gray-100 dark:border-white/5">
                            <button
                                type="button"
                                class="accept-rec-btn inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold shadow-sm transition"
                                data-rec-id="${item.id}"
                                data-task-id="${item.task_id}"
                                data-course-id="${item.course_id || ''}"
                                data-title="${dashboardEscape(item.title)}"
                                data-day="${item.day_of_week}"
                                data-start="${item.start_time}"
                                data-end="${item.end_time}"
                            >
                                <i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i> Add to My Day
                            </button>

                            <a
                                href="${item.task_id ? `tasks.php?focus_task_id=${item.task_id}` : (item.course_id ? `tasks.php?focus=1&course_id=${item.course_id}` : `tasks.php?focus=1`)}"
                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-emerald-700/30 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-white/5 text-xs font-semibold transition"
                            >
                                Start Studying <i data-lucide="timer" class="w-3 h-3"></i>
                            </a>

                            <button
                                type="button"
                                class="skip-rec-btn ml-auto text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 px-1"
                                data-rec-id="${item.id}"
                            >
                                Skip
                            </button>
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;

    if (window.lucide) {
        window.lucide.createIcons();
    }
}

/**
 * Render Smart Academic Suggestions
 */
function renderDashboardSmartSuggestions(suggestions) {
    const container = document.getElementById('dashboard-smart-suggestions');
    if (!container) return;

    if (!Array.isArray(suggestions) || suggestions.length === 0) {
        container.innerHTML = '';
        container.classList.add('hidden');
        return;
    }

    container.classList.remove('hidden');
    container.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
            ${suggestions.map(sug => `
                <div class="dashboard-card p-4 flex items-start gap-3.5 border-emerald-100 dark:border-white/5">
                    <div class="w-9 h-9 rounded-xl ${sug.type === 'urgent' ? 'bg-red-50 dark:bg-red-500/15 text-red-600 dark:text-red-400' : 'bg-emerald-50 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'} flex items-center justify-center shrink-0 mt-0.5">
                        <i data-lucide="${sug.icon || 'sparkles'}" class="w-4 h-4"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-bold text-[#073b35] dark:text-white">
                            ${dashboardEscape(sug.title)}
                        </div>
                        <p class="text-xs text-[#52756d] dark:text-gray-400 mt-0.5">
                            ${dashboardEscape(sug.message)}
                        </p>
                    </div>
                    ${sug.action_url ? `
                        <a
                            href="${sug.action_url}"
                            class="shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border border-emerald-700/30 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-white/5 transition"
                        >
                            ${dashboardEscape(sug.action_label || 'View')} →
                        </a>
                    ` : ''}
                </div>
            `).join('')}
        </div>
    `;

    if (window.lucide) {
        window.lucide.createIcons();
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
                <div class="w-10 h-10 mx-auto mb-2 rounded-full bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center">
                    <i data-lucide="calendar-plus" class="w-5 h-5 text-emerald-700 dark:text-emerald-400"></i>
                </div>

                <p class="text-sm font-semibold text-[#073b35] dark:text-white">
                    No classes or study scheduled today
                </p>

                <p class="text-xs text-[#628098] dark:text-gray-400 mt-1 max-w-xs mx-auto">
                    Add your classes or study time to keep your day organized.
                </p>

                <a
                    href="schedule.php"
                    class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-300 mt-3"
                >
                    <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Add to My Day
                </a>
            </div>
        `;

        if (window.lucide) {
            window.lucide.createIcons();
        }

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

                const isStudy =
                    Boolean(event.is_study_session || String(event.event_type || '').toLowerCase() === 'study');

                const isExam =
                    String(event.event_type || '').toLowerCase() === 'exam';

                const isLecture =
                    String(event.event_type || '').toLowerCase() === 'lecture';

                const sessionIcon = isStudy
                    ? 'book-open'
                    : (isExam ? 'file-warning' : (isLecture ? 'graduation-cap' : 'clock'));

                const typeColor = isStudy
                    ? 'text-amber-600 dark:text-amber-400'
                    : (isExam ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400');

                const timelineStatus = event.timeline_status || (event.is_completed ? 'completed' : 'upcoming');
                let statusBadge = '';
                let dotClass = 'bg-[#06985a] shadow-[0_0_0_4px_#e4f7ee] dark:shadow-[0_0_0_4px_rgba(7,153,90,.16)]';

                if (timelineStatus === 'completed') {
                    statusBadge = `
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-500/20">
                            <i data-lucide="check" class="w-2.5 h-2.5"></i> Done
                        </span>
                    `;
                    dotClass = 'bg-emerald-600 shadow-[0_0_0_4px_#d1fae5] dark:shadow-[0_0_0_4px_rgba(16,185,129,.2)]';
                } else if (timelineStatus === 'in_progress') {
                    statusBadge = `
                        <span class="inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-500/25 dark:text-emerald-200 border border-emerald-300 dark:border-emerald-400/40">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> In Progress
                        </span>
                    `;
                    dotClass = 'bg-emerald-500 shadow-[0_0_0_5px_#a7f3d0] dark:shadow-[0_0_0_5px_rgba(16,185,129,.35)] animate-pulse';
                } else if (timelineStatus === 'missed') {
                    statusBadge = `
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300 border border-rose-200/70 dark:border-rose-500/20">
                            Missed
                        </span>
                    `;
                    dotClass = 'bg-rose-500 shadow-[0_0_0_4px_#ffe4e6] dark:shadow-[0_0_0_4px_rgba(244,63,94,.2)]';
                } else {
                    statusBadge = `
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-50 text-slate-600 dark:bg-white/5 dark:text-gray-300 border border-slate-200/70 dark:border-white/10">
                            Upcoming
                        </span>
                    `;
                    dotClass = 'bg-slate-400 dark:bg-gray-500 shadow-[0_0_0_4px_#f1f5f9] dark:shadow-[0_0_0_4px_rgba(255,255,255,.08)]';
                }

                return `
                    <div class="schedule-row flex items-start gap-3 relative dashboard-list-item"
                         style="animation-delay:${index * .06}s">

                        <div class="schedule-time pt-0.5">
                            <div class="font-semibold text-xs text-[#123f4c] dark:text-gray-200">
                                ${start}
                            </div>
                            ${
                                end
                                    ? `<div class="text-[11px] text-[#628098] dark:text-gray-400 mt-0.5">${end}</div>`
                                    : ''
                            }
                        </div>

                        <div class="relative pt-1.5">
                            <div class="w-2.5 h-2.5 rounded-full shrink-0 ${dotClass}"></div>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="schedule-title flex items-center gap-1.5">
                                <span class="truncate">${title}</span>
                            </div>

                            <div class="schedule-meta flex items-center gap-1.5 flex-wrap mt-0.5">
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium ${typeColor}">
                                    <i data-lucide="${sessionIcon}" class="w-3 h-3"></i>
                                    ${eventType}
                                </span>
                                ${course ? `<span class="text-[11px] text-[#628098] dark:text-gray-400">${course}</span>` : ''}
                            </div>
                        </div>

                        <div class="shrink-0 pt-0.5">
                            ${statusBadge}
                        </div>

                    </div>
                `;
            })
            .join('');

    if (window.lucide) {
        window.lucide.createIcons();
    }
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
                <div class="w-10 h-10 mx-auto mb-2 rounded-full bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center">
                    <i data-lucide="calendar-check" class="w-5 h-5 text-emerald-700 dark:text-emerald-400"></i>
                </div>

                <p class="text-sm font-semibold text-[#073b35] dark:text-white">
                    No work due soon.
                </p>

                <p class="text-xs text-[#628098] dark:text-gray-400 mt-1 max-w-xs mx-auto">
                    You're all caught up! Add your next assignment or exam when ready.
                </p>

                <a
                    href="tasks.php"
                    class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-300 mt-3"
                >
                    <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> + Add Work
                </a>
            </div>
        `;

        if (window.lucide) {
            window.lucide.createIcons();
        }

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
                                class="text-[10px] uppercase font-bold ${priorityClass} flex items-center gap-1.5 flex-wrap"
                            >
                                <span>${priorityLabel}</span>
                                ${Number(task.smart_priority_score) > 0 ? `<span class="text-emerald-700 dark:text-emerald-400">Smart: ${dashboardEscape(task.smart_priority_label || '')} (${Math.round(task.smart_priority_score)})</span>` : ''}
                                ${task.task_risk && task.task_risk !== 'low' ? `<span class="${task.task_risk === 'critical' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'}">${dashboardEscape(task.risk_label || task.task_risk)} Risk</span>` : ''}
                            </div>

                            <div class="deadline-title mt-0.5 truncate">
                                ${title}
                            </div>

                            ${
                                course
                                    ? `
                                        <div class="deadline-course truncate">
                                            ${course}
                                            ${task.remaining_hours !== undefined && task.remaining_hours !== null && Number(task.remaining_hours) > 0 ? ` • ${task.remaining_hours}h left` : ''}
                                        </div>
                                      `
                                    : (task.remaining_hours !== undefined && task.remaining_hours !== null && Number(task.remaining_hours) > 0
                                        ? `<div class="deadline-course">${task.remaining_hours}h left</div>`
                                        : '')
                            }

                            ${
                                task.recommended_action
                                    ? `
                                        <div class="text-[11px] text-emerald-700 dark:text-emerald-400 font-medium truncate mt-0.5 flex items-center gap-1" title="${dashboardEscape(task.priority_reason || task.recommended_action)}">
                                            <i data-lucide="sparkles" class="w-3 h-3 shrink-0 text-emerald-600 dark:text-emerald-400"></i>
                                            ${dashboardEscape(task.recommended_action)}
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

                            ${
                                task.deadline_pressure === 'urgent' || task.deadline_pressure === 'critical'
                                    ? `
                                        <div class="mt-1">
                                            <span class="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded ${task.deadline_pressure === 'critical' ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300'}">
                                                <i data-lucide="flame" class="w-2.5 h-2.5"></i> ${dashboardEscape(task.deadline_pressure.toUpperCase())}
                                            </span>
                                        </div>
                                      `
                                    : ''
                            }

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
                <div class="w-10 h-10 mx-auto mb-2 rounded-full bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center">
                    <i data-lucide="bell" class="w-5 h-5 text-emerald-600 dark:text-emerald-300"></i>
                </div>

                <p class="text-xs text-gray-400">
                    No recent activity yet. Your study updates will appear here.
                </p>
            </div>
        `;

        if (window.lucide) {
            window.lucide.createIcons();
        }

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

        renderDashboardSmartFocus(
            data
        );

        renderDashboardRecommendedPlan(
            data.recommended_study_plan
        );

        renderDashboardSmartSuggestions(
            data.smart_suggestions
        );

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
    trendColor = 'green',
    href = null
) {

    const trendClass =
        trendColor === 'red'
            ? 'text-red-600'
            : trendColor === 'amber'
                ? 'text-amber-600'
                : 'text-green-600';

    const content = `
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
    `;

    return href
        ? `
        <a href="${href}" class="dashboard-card stat-card p-4 sm:p-5 flex items-center gap-3 sm:gap-4 hover-lift block group" title="Go to ${dashboardEscape(label)}">
            ${content}
        </a>`
        : `
        <div class="dashboard-card stat-card p-4 sm:p-5 flex items-center gap-3 sm:gap-4">
            ${content}
        </div>`;
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
                    'My Work',
                    '↗ Your active work',
                    'green',
                    'tasks.php'
                )}

                ${statCard(
                    'clock-3',
                    'text-amber-600',
                    'bg-amber-50',
                    dueSoon,
                    'Due Soon',
                    '↗ Upcoming deadlines',
                    'amber',
                    'deadlines.php?filter=due_soon'
                )}

                ${statCard(
                    'alert-circle',
                    'text-red-600',
                    'bg-red-50',
                    overdue,
                    'Overdue',
                    '↘ Needs your attention',
                    'red',
                    'deadlines.php?filter=overdue'
                )}

                ${statCard(
                    'trending-up',
                    'text-violet-600',
                    'bg-violet-50',
                    completionRate,
                    'Overall Progress',
                    '↗ Current completion',
                    'green',
                    'progress.php'
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

        await Promise.allSettled([
            loadStats(),
            loadDashboard()
        ]);

    } finally {

        dashboardLoading =
            false;
    }
}


/* =========================================================
   RECOMMENDED STUDY PLAN ACTIONS
========================================================= */

function bindStudyRecommendations() {
    document.addEventListener('click', async function(e) {
        const acceptBtn = e.target.closest('.accept-rec-btn');
        if (acceptBtn) {
            e.preventDefault();
            acceptBtn.disabled = true;
            acceptBtn.innerHTML = `<span class="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>`;
            try {
                const res = await fetch(`${API}/schedule.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: 'accept_recommendation',
                        csrf_token: window.CSRF_TOKEN,
                        title: acceptBtn.dataset.title,
                        task_id: acceptBtn.dataset.taskId,
                        course_id: acceptBtn.dataset.courseId,
                        day_of_week: Number(acceptBtn.dataset.day),
                        start_time: acceptBtn.dataset.start,
                        end_time: acceptBtn.dataset.end
                    })
                });
                const resData = await res.json();
                if (res.ok && resData.ok) {
                    acceptBtn.className = 'inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300 text-xs font-semibold';
                    acceptBtn.innerHTML = `✓ Scheduled`;
                    dashboardShowToast('Study session added to your schedule!', 'info');
                    setTimeout(() => refreshDashboard(), 1200);
                } else {
                    acceptBtn.disabled = false;
                    acceptBtn.innerHTML = `<i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i> Accept`;
                    dashboardShowToast(resData.error || 'Could not add session', 'error');
                    if (window.lucide) window.lucide.createIcons();
                }
            } catch (err) {
                acceptBtn.disabled = false;
                acceptBtn.innerHTML = `<i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i> Accept`;
                dashboardShowToast('Network error', 'error');
                if (window.lucide) window.lucide.createIcons();
            }
            return;
        }

        const skipBtn = e.target.closest('.skip-rec-btn');
        if (skipBtn) {
            e.preventDefault();
            const recId = skipBtn.dataset.recId;
            const card = document.getElementById(`rec-card-${recId}`);
            if (card) {
                card.style.opacity = '0';
                card.style.transform = 'scale(0.95)';
                card.style.transition = 'opacity .25s ease, transform .25s ease';
                setTimeout(() => {
                    card.remove();
                    const container = document.getElementById('dashboard-recommended-plan');
                    if (container && !container.querySelectorAll('[id^="rec-card-"]').length) {
                        container.classList.add('hidden');
                    }
                }, 250);
            }
        }
    });
}


/* =========================================================
   BOOT
========================================================= */

function initializeDashboard() {

    bindAddTask();

    bindOverdueAlert();

    bindDashboardSearch();

    bindDashboardKeyboard();

    bindStudyRecommendations();


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