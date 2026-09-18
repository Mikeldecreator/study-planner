<?php
require_once __DIR__ . '/../includes/auth.php';
requirePageLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Classes — Study Planner</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
  :root {
    --page: #f6f9f7;
    --surface: #ffffff;
    --surface-soft: #f8fbf9;
    --border: #e5eee9;
    --text: #103b2f;
    --muted: #70847c;
    --green: #087b55;
  }
  .dark {
    --page: #0b1110;
    --surface: #121a17;
    --surface-soft: #17211d;
    --border: rgba(255,255,255,.09);
    --text: #edf7f2;
    --muted: #9bb1a8;
    --green: #25b27d;
  }
  html, body { min-height: 100%; }
  body { background: var(--page); color: var(--text); }
  .surface { background: var(--surface); border: 1px solid var(--border); }
  .soft-surface { background: var(--surface-soft); border: 1px solid var(--border); }
  .schedule-scroll { scrollbar-width: thin; }
  .schedule-scroll::-webkit-scrollbar { height: 8px; width: 8px; }
  .schedule-scroll::-webkit-scrollbar-thumb { background: rgba(63, 105, 88, .24); border-radius: 999px; }
  .schedule-grid { min-width: 720px; }
  .grid-current-day { background: linear-gradient(180deg, rgba(27, 157, 105, .05), rgba(27, 157, 105, .02)); }
  .event-block { box-shadow: 0 2px 8px rgba(21, 77, 58, .07); border: 1px solid rgba(255,255,255,.22); }
  .event-block:hover { filter: brightness(.98); transform: translateY(-1px); box-shadow: 0 5px 12px rgba(21, 77, 58, .12); }
  .event-block { transition: transform .16s ease, box-shadow .16s ease, filter .16s ease; }
  .today-line { position:absolute; height:2px; background:#ef6969; z-index:20; left:0; right:0; pointer-events:none; }
  .today-dot { width:8px; height:8px; border-radius:999px; background:#ef6969; box-shadow:0 0 0 3px rgba(239,105,105,.12); }
  .metric-card { min-height:108px; }
  .metric-icon { width:52px; height:52px; border-radius:16px; display:flex; align-items:center; justify-content:center; flex:none; }
  .mini-day { width:28px; height:28px; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:11px; margin:auto; }
  .mini-day.muted { color:#98a7a0; }
  .mini-day.today { background:#087b55; color:#fff; font-weight:700; }
  .dark .mini-day.today { background:#20aa78; color:#07140f; }
  .mini-day.sun { color:#ef6a6a; }
  .dark .mini-day.sun { color:#ff8a8a; }
  .mini-day.selected { background:rgba(8,123,85,.10); color:#087b55; font-weight:700; }
  .dark .mini-day.selected { background:rgba(37,178,125,.12); color:#72e0b4; }
  .mini-event-dot { width:4px; height:4px; border-radius:999px; background:#087b55; position:absolute; margin-top:21px; }
  .dark .mini-event-dot { background:#3bcf96; }
  .mini-day { position:relative; }
  .month-cell { min-width:0; }
  .month-cell:focus-visible { outline:3px solid rgba(37,178,125,.28); outline-offset:-3px; }
  .mini-head { font-size:10px; color:#84958e; text-align:center; font-weight:600; }
  .focus-ring:focus-visible { outline:3px solid rgba(37,178,125,.28); outline-offset:2px; }
  .modal-backdrop { backdrop-filter: blur(4px); }
  .modal-scroll { max-height: calc(100vh - 2rem); overflow:auto; }
  @media (max-width: 767px) {
    .metric-card { min-height:92px; }
    .metric-icon { width:44px; height:44px; border-radius:14px; }
  }
</style>
</head>
<body data-page="schedule" class="transition-colors">
<div class="flex min-h-screen">
  <div id="sidebar-slot"></div>

  <main class="flex-1 min-w-0">
    <header class="sticky top-0 z-20 bg-white/95 dark:bg-[#111817]/95 backdrop-blur border-b border-gray-100 dark:border-white/10 px-4 sm:px-6 xl:px-8 py-4 flex items-center justify-between gap-4">
      <div class="flex items-center gap-3 min-w-0">
        <button id="hamburger-btn" class="lg:hidden focus-ring text-emerald-900 dark:text-emerald-300 hover:text-emerald-700 dark:hover:text-emerald-200 transition-colors" aria-label="Open menu">
          <i data-lucide="menu" class="w-5 h-5"></i>
        </button>
        <div class="flex items-center gap-3 min-w-0">
          <div class="hidden sm:flex w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 items-center justify-center">
            <i data-lucide="calendar-days" class="w-5 h-5 text-emerald-700 dark:text-emerald-300"></i>
          </div>
          <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight truncate">My Classes</h1>
            <p class="hidden sm:block text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">Your weekly class timetable and study routine.</p>
          </div>
        </div>
      </div>

      <div class="relative hidden md:block flex-1 max-w-xl mx-3 lg:mx-8">
        <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
        <input type="text" placeholder="Search sessions, courses, topics..."
          class="focus-ring w-full h-11 bg-[#f7faf8] dark:bg-white/[.04] border border-gray-200 dark:border-white/10 rounded-xl pl-10 pr-4 text-sm text-gray-800 dark:text-gray-100 placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:outline-none focus:border-emerald-400/60 transition-colors">
      </div>

      <div class="flex items-center gap-3 sm:gap-4 shrink-0">
        <a href="schedule.php" class="hidden sm:flex focus-ring w-10 h-10 rounded-xl items-center justify-center text-gray-500 dark:text-gray-300 hover:bg-emerald-50 dark:hover:bg-white/5 hover:text-emerald-700 dark:hover:text-emerald-300 transition-colors" aria-label="View calendar">
          <i data-lucide="calendar" class="w-5 h-5"></i>
        </a>
        <button id="bell-btn" class="focus-ring relative w-10 h-10 rounded-xl flex items-center justify-center text-gray-500 dark:text-gray-300 hover:bg-emerald-50 dark:hover:bg-white/5 hover:text-emerald-700 dark:hover:text-emerald-300 transition-colors" aria-label="Notifications">
          <i data-lucide="bell" class="w-5 h-5"></i><span id="bell-badge" class="hidden absolute top-1 right-1 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-4 h-4 px-1 items-center justify-center"></span>
        </button>
        <div class="hidden sm:block h-7 w-px bg-gray-200 dark:bg-white/10"></div>
        <a href="settings.php" class="hidden sm:flex focus-ring items-center gap-2.5 group" aria-label="Account settings">
          <div class="w-9 h-9 rounded-full bg-emerald-800 dark:bg-emerald-700 text-white text-xs font-semibold flex items-center justify-center ring-2 ring-emerald-100 dark:ring-emerald-500/10 overflow-hidden" data-top-avatar><span data-user-initial>U</span></div>
          <span class="text-sm font-semibold group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition-colors" data-user-name>Loading…</span>
          <i data-lucide="chevron-down" class="w-4 h-4 text-gray-400"></i>
        </a>
      </div>

      <div id="bell-dropdown" class="hidden absolute right-4 sm:right-8 top-16 w-80 max-w-[90vw] surface rounded-2xl shadow-xl z-30 max-h-96 overflow-y-auto"></div>
    </header>

    <div class="p-4 sm:p-6 xl:p-8 space-y-5 sm:space-y-6">
      <section class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
        <div>
          <div class="flex items-center gap-2 text-emerald-700 dark:text-emerald-300 text-sm font-semibold mb-1">
            <i data-lucide="calendar-check-2" class="w-4 h-4"></i>
            <span>This week</span>
          </div>
          <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">My Classes</h2>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1.5 max-w-2xl">Your recurring weekly timetable — see your classes and find free study time.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5">
          <button id="open-import-timetable" type="button" class="focus-ring btn-press border border-emerald-600/30 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-white/5 rounded-xl px-3.5 py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-colors">
            <i data-lucide="upload" class="w-4 h-4"></i> Add My Classes
          </button>
          <button id="open-study-prefs" type="button" class="focus-ring btn-press border border-emerald-600/30 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-white/5 rounded-xl px-3.5 py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-colors">
            <i data-lucide="sliders" class="w-4 h-4"></i> When I Like to Study
          </button>
          <button id="open-add-session" type="button" class="focus-ring btn-press bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500 text-white rounded-xl px-4 py-2.5 text-sm font-semibold whitespace-nowrap flex items-center justify-center gap-2 shadow-sm transition-colors">
            <i data-lucide="plus" class="w-4 h-4"></i> Add Class
          </button>
        </div>
      </section>

      <div class="grid grid-cols-2 xl:grid-cols-5 gap-3 sm:gap-4" id="schedule-stat-cards"></div>

      <div class="grid grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_320px] gap-4 xl:gap-5 items-start">
        <section class="surface rounded-2xl overflow-hidden min-w-0">
          <div class="px-4 sm:px-5 py-3.5 border-b border-gray-100 dark:border-white/10 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
              <div class="flex items-center gap-1.5">
                <button type="button" id="schedule-prev" class="focus-ring w-9 h-9 rounded-lg border border-gray-200 dark:border-white/10 flex items-center justify-center text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 hover:text-emerald-700 dark:hover:text-emerald-300 transition-colors" aria-label="Previous period">
                  <i data-lucide="chevron-left" class="w-4 h-4"></i>
                </button>
                <button type="button" id="schedule-next" class="focus-ring w-9 h-9 rounded-lg border border-gray-200 dark:border-white/10 flex items-center justify-center text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 hover:text-emerald-700 dark:hover:text-emerald-300 transition-colors" aria-label="Next period">
                  <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </button>
              </div>
              <button type="button" id="schedule-today" class="focus-ring hidden sm:inline-flex px-2.5 h-9 items-center rounded-lg border border-gray-200 dark:border-white/10 text-[11px] font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">Today</button>
              <div class="ml-1.5">
                <div id="schedule-range-label" class="text-sm sm:text-base font-bold">This week</div>
                <div id="schedule-range-subtitle" class="text-[11px] text-gray-400">Recurring timetable</div>
              </div>
            </div>
            <div id="schedule-view-switcher" class="flex items-center rounded-xl bg-gray-100 dark:bg-white/[.05] p-1 text-xs font-semibold">
              <button type="button" data-view="week" class="schedule-view-btn px-4 py-2 rounded-lg bg-emerald-700 text-white shadow-sm">Week</button>
              <button type="button" data-view="day" class="schedule-view-btn px-4 py-2 rounded-lg text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100">Day</button>
              <button type="button" data-view="month" class="schedule-view-btn px-4 py-2 rounded-lg text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100">Month</button>
            </div>
          </div>

          <div class="schedule-scroll overflow-x-auto px-3 sm:px-4 pt-2 pb-3">
            <div class="schedule-grid">
              <div class="grid grid-cols-[54px_repeat(7,minmax(0,1fr))] text-[11px] text-gray-400 mb-1">
                <div></div>
                <div class="day-header text-center font-semibold">Sun</div>
                <div class="day-header text-center font-semibold">Mon</div>
                <div class="day-header text-center font-semibold">Tue</div>
                <div class="day-header text-center font-semibold">Wed</div>
                <div class="day-header text-center font-semibold">Thu</div>
                <div class="day-header text-center font-semibold">Fri</div>
                <div class="day-header text-center font-semibold">Sat</div>
              </div>

              <div class="relative grid grid-cols-[54px_repeat(7,minmax(0,1fr))]" id="week-grid" style="height: 840px;">
                <div class="relative" id="hour-labels"></div>
                <div class="relative border-l border-gray-100 dark:border-white/[.07] day-col" data-dow="0"></div>
                <div class="relative border-l border-gray-100 dark:border-white/[.07] day-col" data-dow="1"></div>
                <div class="relative border-l border-gray-100 dark:border-white/[.07] day-col" data-dow="2"></div>
                <div class="relative border-l border-gray-100 dark:border-white/[.07] day-col" data-dow="3"></div>
                <div class="relative border-l border-gray-100 dark:border-white/[.07] day-col" data-dow="4"></div>
                <div class="relative border-l border-gray-100 dark:border-white/[.07] day-col" data-dow="5"></div>
                <div class="relative border-l border-r border-gray-100 dark:border-white/[.07] day-col" data-dow="6"></div>
              </div>
            </div>
          </div>

          <div id="month-calendar" class="hidden p-3 sm:p-4"></div>

          <div class="px-4 sm:px-5 py-3 border-t border-gray-100 dark:border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-[11px] text-gray-500 dark:text-gray-400">
            <span id="schedule-summary">Showing 7 days</span>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
              <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-600"></span> Lecture</span>
              <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-blue-500"></span> Lab</span>
              <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Project</span>
              <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-violet-500"></span> Study</span>
              <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-500"></span> Exam</span>
            </div>
          </div>
        </section>

        <aside class="space-y-4 min-w-0 w-full max-w-full">
          <section class="surface rounded-2xl p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4 gap-2">
              <div>
                <h3 class="font-bold text-sm">Mini Calendar</h3>
                <p id="mini-calendar-month" class="text-xs text-gray-400 mt-0.5">—</p>
              </div>
              <div class="flex items-center gap-1">
                <button type="button" id="mini-calendar-prev" class="focus-ring w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-emerald-700 dark:hover:text-emerald-300 hover:bg-gray-50 dark:hover:bg-white/5" aria-label="Previous month">
                  <i data-lucide="chevron-left" class="w-4 h-4"></i>
                </button>
                <button type="button" id="mini-calendar-next" class="focus-ring w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-emerald-700 dark:hover:text-emerald-300 hover:bg-gray-50 dark:hover:bg-white/5" aria-label="Next month">
                  <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </button>
              </div>
            </div>
            <div class="grid grid-cols-7 gap-y-1.5 mb-1">
              <div class="mini-head">Mo</div><div class="mini-head">Tu</div><div class="mini-head">We</div><div class="mini-head">Th</div><div class="mini-head">Fr</div><div class="mini-head">Sa</div><div class="mini-head">Su</div>
            </div>
            <div id="mini-calendar-grid" class="grid grid-cols-7 gap-y-1.5"></div>
          </section>

          <section class="surface rounded-2xl p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
              <div>
                <h3 class="font-bold text-sm">Today's Sessions</h3>
                <p class="text-xs text-gray-400 mt-0.5">Stay on top of today's plan</p>
              </div>
              <span class="w-8 h-8 rounded-full bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center">
                <i data-lucide="list-checks" class="w-4 h-4 text-emerald-700 dark:text-emerald-300"></i>
              </span>
            </div>
            <div id="today-sessions" class="space-y-3 text-sm"></div>
          </section>

          <!-- Free Periods & Recommended Study Blocks -->
          <section class="surface rounded-2xl p-4 sm:p-5 min-w-0 w-full max-w-full box-border">
            <div class="flex items-center justify-between mb-3">
              <div>
                <h3 class="font-bold text-sm flex items-center gap-1.5">
                  <i data-lucide="sparkles" class="w-4 h-4 text-emerald-700 dark:text-emerald-300"></i> Free Periods & Study Plan
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">Available windows to study today</p>
              </div>
            </div>
            <div id="schedule-free-periods" class="space-y-2.5 text-xs min-w-0 w-full max-w-full"></div>
          </section>


          <section class="surface rounded-2xl p-4 sm:p-5">
            <div class="flex items-center justify-between mb-2">
              <div>
                <h3 class="font-bold text-sm">Weekly Goal</h3>
                <p class="text-xs text-gray-400 mt-0.5">Scheduled against your target</p>
              </div>
              <button id="edit-goal-btn" class="focus-ring text-xs font-semibold text-emerald-700 dark:text-emerald-300 hover:underline">Edit Goal</button>
            </div>
            <div class="mt-4 flex items-end justify-between gap-3">
              <div>
                <div class="text-2xl font-bold tracking-tight" id="goal-progress-label">– / – hrs</div>
                <div class="text-xs text-gray-400 mt-1">weekly study target</div>
              </div>
              <i data-lucide="target" class="w-6 h-6 text-emerald-700 dark:text-emerald-300"></i>
            </div>
            <div class="h-2.5 bg-gray-100 dark:bg-white/[.06] rounded-full overflow-hidden mt-4">
              <div id="goal-progress-bar" class="h-full bg-emerald-600 dark:bg-emerald-500 rounded-full transition-all duration-500" style="width:0%"></div>
            </div>
          </section>

          <section class="surface rounded-2xl p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
              <div>
                <h3 class="font-bold text-sm">Time Distribution</h3>
                <p class="text-xs text-gray-400 mt-0.5">Where your scheduled time goes</p>
              </div>
              <i data-lucide="pie-chart" class="w-4 h-4 text-emerald-700 dark:text-emerald-300"></i>
            </div>
            <div class="flex items-center gap-4">
              <div class="relative w-28 h-28 shrink-0">
                <canvas id="distribution-chart"></canvas>
              </div>
              <div class="flex-1 min-w-0 text-xs space-y-2" id="distribution-legend"></div>
            </div>
          </section>
        </aside>
      </div>
    </div>
  </main>
</div>

<!-- Add / Edit Session Modal -->
<div id="session-modal" class="hidden fixed inset-0 bg-slate-950/45 dark:bg-black/60 modal-backdrop flex items-center justify-center z-40 p-4">
  <div class="modal-scroll bg-white dark:bg-[#141a18] rounded-2xl p-5 sm:p-6 w-full max-w-md shadow-2xl border border-gray-100 dark:border-white/10 modal-enter">
    <div class="flex items-start justify-between gap-4 mb-5">
      <div>
        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center mb-3">
          <i data-lucide="calendar-plus-2" class="w-5 h-5 text-emerald-700 dark:text-emerald-300"></i>
        </div>
        <h3 class="font-bold text-lg" id="session-modal-title">Add Class or Study Session</h3>
        <p class="text-xs text-gray-400 mt-1">Add your classes, lectures, or study sessions to your week.</p>
      </div>
      <button type="button" id="cancel-session" class="focus-ring w-9 h-9 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Close modal">
        <i data-lucide="x" class="w-4 h-4 mx-auto"></i>
      </button>
    </div>

    <form id="session-form" class="space-y-4">
      <input type="hidden" name="id">
      <div>
        <label class="text-xs font-semibold text-gray-600 dark:text-gray-300">Session title</label>
        <input required name="title" placeholder="Session title" class="focus-ring mt-1.5 w-full border border-gray-200 dark:border-white/10 bg-white dark:bg-white/[.04] rounded-xl px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:outline-none focus:border-emerald-400/60">
      </div>
      <div>
        <label class="text-xs font-semibold text-gray-600 dark:text-gray-300">Course</label>
        <select name="course_id" id="session-course-select" class="focus-ring mt-1.5 w-full border border-gray-200 dark:border-white/10 bg-white dark:bg-[#17201d] rounded-xl px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:border-emerald-400/60">
          <option value="">No course</option>
        </select>
      </div>
      <div>
        <label class="text-xs font-semibold text-gray-600 dark:text-gray-300">Task (optional)</label>
        <select name="task_id" id="session-task-select" class="focus-ring mt-1.5 w-full border border-gray-200 dark:border-white/10 bg-white dark:bg-[#17201d] rounded-xl px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:border-emerald-400/60">
          <option value="">No linked task</option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs font-semibold text-gray-600 dark:text-gray-300">Type</label>
          <select name="event_type" class="focus-ring mt-1.5 w-full border border-gray-200 dark:border-white/10 bg-white dark:bg-[#17201d] rounded-xl px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:border-emerald-400/60">
            <option value="lecture">Lecture</option>
            <option value="study" selected>Study Session</option>
            <option value="exam">Exam</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-semibold text-gray-600 dark:text-gray-300">Day</label>
          <select name="day_of_week" class="focus-ring mt-1.5 w-full border border-gray-200 dark:border-white/10 bg-white dark:bg-[#17201d] rounded-xl px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:border-emerald-400/60">
            <option value="0">Sunday</option><option value="1">Monday</option><option value="2">Tuesday</option>
            <option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option>
          </select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs font-semibold text-gray-600 dark:text-gray-300">Start time</label>
          <input required type="time" name="start_time" class="focus-ring mt-1.5 w-full border border-gray-200 dark:border-white/10 bg-white dark:bg-white/[.04] rounded-xl px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:border-emerald-400/60">
        </div>
        <div>
          <label class="text-xs font-semibold text-gray-600 dark:text-gray-300">End time</label>
          <input required type="time" name="end_time" class="focus-ring mt-1.5 w-full border border-gray-200 dark:border-white/10 bg-white dark:bg-white/[.04] rounded-xl px-3 py-2.5 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:border-emerald-400/60">
        </div>
      </div>
      <label class="flex items-center gap-2.5 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
        <input type="checkbox" name="is_completed" class="w-4 h-4 accent-emerald-600"> Mark as completed
      </label>
      <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
        <button type="button" id="delete-session" class="hidden mr-auto focus-ring px-4 py-2.5 text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg">Delete</button>
        <button type="button" id="cancel-session-secondary" class="focus-ring px-4 py-2.5 text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg">Cancel</button>
        <button class="focus-ring px-4 py-2.5 text-sm bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500 text-white rounded-lg font-semibold transition-colors">Save Session</button>
      </div>
    </form>
  </div>
</div>

<!-- Universal Timetable Import Modal (Document & Review) -->
<div id="import-timetable-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm hidden" role="dialog" aria-modal="true" aria-labelledby="import-modal-title">
  <div class="surface rounded-2xl w-full max-w-2xl p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between">
      <div>
        <h3 id="import-modal-title" class="font-bold text-gray-900 dark:text-gray-100 text-lg">Import Class Timetable</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5" id="timetable-import-subtitle">Upload your timetable document (PDF, Word DOCX, or text) or paste schedule lines.</p>
      </div>
      <button type="button" id="close-import-timetable" aria-label="Close" class="focus-ring text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">
        <i data-lucide="x" class="w-5 h-5"></i>
      </button>
    </div>

    <!-- STEP 1: UPLOAD OR PASTE -->
    <div id="timetable-import-step-upload" class="space-y-4">
      <div class="border-2 border-dashed border-emerald-200 dark:border-emerald-800/60 hover:border-emerald-500 rounded-2xl p-5 text-center bg-emerald-50/20 dark:bg-emerald-950/10 cursor-pointer transition-colors" id="timetable-dropzone">
        <input type="file" id="timetable-file-input" accept=".pdf,.docx,.doc,.txt,text/plain" class="hidden">
        <div class="mx-auto w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center text-emerald-700 dark:text-emerald-300 mb-2">
          <i data-lucide="file-up" class="w-5 h-5"></i>
        </div>
        <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">Click to choose timetable file or drag and drop</p>
        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Supports PDF, Word (.docx), or Text files (up to 15MB)</p>
        <div id="timetable-file-chosen" class="hidden mt-2 text-xs font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-100/80 dark:bg-emerald-900/30 py-1 px-2.5 rounded-lg inline-block"></div>
      </div>

      <div>
        <label class="text-xs font-semibold text-gray-600 dark:text-gray-300 flex items-center justify-between">
          <span>Or Paste Timetable Lines</span>
          <span class="text-[11px] text-gray-400 font-normal">e.g. Monday 9am-11am CSC 401 LT 2</span>
        </label>
        <textarea id="timetable-text-input" rows="4" placeholder="Monday 09:00 - 11:00 CSC 401 LT 2&#10;Wednesday 14:00 - 16:00 MTH 301 Hall B&#10;Friday 10:00 - 12:00 PHY 202 Lab 1" class="focus-ring font-mono text-xs mt-1.5 w-full border border-gray-200 dark:border-white/10 bg-white dark:bg-white/[.04] rounded-xl px-3 py-2.5 text-gray-900 dark:text-gray-100 placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:outline-none focus:border-emerald-400/60"></textarea>
      </div>

      <div id="timetable-import-error" class="hidden text-xs rounded-xl p-3 bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-900/40 space-y-2"></div>

      <div class="flex items-center justify-between pt-2 border-t border-gray-100 dark:border-white/10">
        <button type="button" id="open-manual-class-fallback" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline flex items-center gap-1">
          <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> Or Add Manually
        </button>
        <div class="flex items-center gap-2">
          <button type="button" id="cancel-import-timetable" class="focus-ring px-4 py-2 text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg">Cancel</button>
          <button type="button" id="extract-timetable-btn" class="focus-ring px-4 py-2 text-xs bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold transition-colors flex items-center gap-1.5">
            <i data-lucide="sparkles" class="w-4 h-4"></i> Extract Timetable
          </button>
        </div>
      </div>
    </div>

    <!-- STEP 2: MANDATORY INTERACTIVE REVIEW SCREEN -->
    <div id="timetable-import-step-review" class="hidden space-y-4">
      <div class="flex items-center justify-between">
        <div>
          <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400" id="timetable-review-count">0 classes ready for review</span>
          <p class="text-[11px] text-gray-500 dark:text-gray-400">Review days, times, and course codes before saving.</p>
        </div>
        <button type="button" id="add-review-timetable-btn" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:text-emerald-800 flex items-center gap-1 bg-emerald-50 dark:bg-emerald-950/30 px-2.5 py-1.5 rounded-lg border border-emerald-200 dark:border-emerald-800/40">
          <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Class
        </button>
      </div>

      <div class="max-h-72 overflow-y-auto border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto">
        <table class="w-full text-left text-xs border-collapse min-w-[550px]">
          <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300 font-semibold">
            <tr>
              <th class="p-2 w-28">Day</th>
              <th class="p-2 w-24">Start</th>
              <th class="p-2 w-24">End</th>
              <th class="p-2">Course Code / Title</th>
              <th class="p-2 w-28">Location</th>
              <th class="p-2 w-20">Status</th>
              <th class="p-2 text-right w-10"></th>
            </tr>
          </thead>
          <tbody id="timetable-review-tbody" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
        </table>
      </div>

      <div id="timetable-review-error" class="hidden text-xs rounded-lg bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 p-2.5"></div>

      <div class="flex items-center justify-between pt-2 border-t border-gray-100 dark:border-white/10">
        <button type="button" id="back-timetable-btn" class="text-xs text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white flex items-center gap-1">
          <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Upload Another
        </button>
        <div class="flex items-center gap-2">
          <button type="button" id="cancel-review-timetable" class="focus-ring px-4 py-2 text-xs text-gray-600 dark:text-gray-300">Cancel</button>
          <button type="button" id="confirm-import-timetable-btn" class="focus-ring px-5 py-2 text-xs bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold flex items-center gap-1.5 shadow-sm">
            <i data-lucide="check" class="w-3.5 h-3.5"></i> Confirm & Add Classes
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Study Preferences Modal -->
<div id="study-preferences-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm hidden" role="dialog" aria-modal="true" aria-labelledby="prefs-modal-title">
  <div class="surface rounded-2xl w-full max-w-lg p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between">
      <div>
        <h3 id="prefs-modal-title" class="font-bold text-gray-900 dark:text-gray-100 text-lg">When I Like to Study</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Help your academic assistant recommend the most effective study slots.</p>
      </div>
      <button type="button" id="close-study-prefs" aria-label="Close" class="focus-ring text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">
        <i data-lucide="x" class="w-5 h-5"></i>
      </button>
    </div>

    <form id="study-preferences-form" class="space-y-4">
      <div>
        <label class="text-xs font-semibold text-gray-700 dark:text-gray-300 block mb-2">When do you focus best?</label>
        <div class="grid grid-cols-2 gap-2.5">
          <label class="pref-time-card flex items-center gap-2.5 p-3 rounded-xl border border-gray-200 dark:border-white/10 bg-white/50 dark:bg-white/[.02] cursor-pointer hover:border-emerald-500/50 transition-colors">
            <input type="radio" name="preferred_study_time" value="morning" class="w-4 h-4 accent-emerald-600">
            <div>
              <div class="text-xs font-bold text-gray-800 dark:text-gray-200">Morning</div>
              <div class="text-[11px] text-gray-500 dark:text-gray-400">08:00 – 12:00</div>
            </div>
          </label>
          <label class="pref-time-card flex items-center gap-2.5 p-3 rounded-xl border border-gray-200 dark:border-white/10 bg-white/50 dark:bg-white/[.02] cursor-pointer hover:border-emerald-500/50 transition-colors">
            <input type="radio" name="preferred_study_time" value="afternoon" class="w-4 h-4 accent-emerald-600">
            <div>
              <div class="text-xs font-bold text-gray-800 dark:text-gray-200">Afternoon</div>
              <div class="text-[11px] text-gray-500 dark:text-gray-400">12:00 – 17:00</div>
            </div>
          </label>
          <label class="pref-time-card flex items-center gap-2.5 p-3 rounded-xl border border-gray-200 dark:border-white/10 bg-white/50 dark:bg-white/[.02] cursor-pointer hover:border-emerald-500/50 transition-colors">
            <input type="radio" name="preferred_study_time" value="evening" class="w-4 h-4 accent-emerald-600">
            <div>
              <div class="text-xs font-bold text-gray-800 dark:text-gray-200">Evening</div>
              <div class="text-[11px] text-gray-500 dark:text-gray-400">17:00 – 22:00</div>
            </div>
          </label>
          <label class="pref-time-card flex items-center gap-2.5 p-3 rounded-xl border border-gray-200 dark:border-white/10 bg-white/50 dark:bg-white/[.02] cursor-pointer hover:border-emerald-500/50 transition-colors">
            <input type="radio" name="preferred_study_time" value="flexible" class="w-4 h-4 accent-emerald-600" checked>
            <div>
              <div class="text-xs font-bold text-gray-800 dark:text-gray-200">Flexible</div>
              <div class="text-[11px] text-gray-500 dark:text-gray-400">Any open gap</div>
            </div>
          </label>
        </div>
      </div>

      <div>
        <label class="text-xs font-semibold text-gray-700 dark:text-gray-300 block mb-1.5">Weekly Target Study Hours</label>
        <div class="flex items-center gap-3">
          <input type="number" min="1" max="60" step="1" name="weekly_goal_hours" id="pref-weekly-hours" value="15" class="focus-ring w-24 border border-gray-200 dark:border-white/10 bg-white dark:bg-white/[.04] rounded-xl px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
          <span class="text-xs text-gray-500 dark:text-gray-400">hours / week recommended across courses</span>
        </div>
      </div>

      <div>
        <label class="text-xs font-semibold text-gray-700 dark:text-gray-300 block mb-1.5">Active Study Days</label>
        <div class="flex flex-wrap gap-2 text-xs">
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-white/10 cursor-pointer">
            <input type="checkbox" name="preferred_days" value="1" class="accent-emerald-600" checked> Mon
          </label>
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-white/10 cursor-pointer">
            <input type="checkbox" name="preferred_days" value="2" class="accent-emerald-600" checked> Tue
          </label>
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-white/10 cursor-pointer">
            <input type="checkbox" name="preferred_days" value="3" class="accent-emerald-600" checked> Wed
          </label>
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-white/10 cursor-pointer">
            <input type="checkbox" name="preferred_days" value="4" class="accent-emerald-600" checked> Thu
          </label>
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-white/10 cursor-pointer">
            <input type="checkbox" name="preferred_days" value="5" class="accent-emerald-600" checked> Fri
          </label>
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-white/10 cursor-pointer">
            <input type="checkbox" name="preferred_days" value="6" class="accent-emerald-600"> Sat
          </label>
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-200 dark:border-white/10 cursor-pointer">
            <input type="checkbox" name="preferred_days" value="0" class="accent-emerald-600"> Sun
          </label>
        </div>
      </div>

      <div class="flex items-center justify-end gap-2 pt-2">
        <button type="button" id="cancel-study-prefs" class="focus-ring px-4 py-2 text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg">Cancel</button>
        <button type="submit" id="save-study-prefs" class="focus-ring px-4 py-2 text-sm bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500 text-white rounded-lg font-semibold transition-colors flex items-center gap-1.5">
          <i data-lucide="check" class="w-4 h-4"></i> Save Routine
        </button>
      </div>
    </form>
  </div>
</div>

<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/work-timer.js"></script>
<script src="../assets/js/schedule.js"></script>

<script src="../assets/js/guided-tour.js"></script>
</body>
</html>
