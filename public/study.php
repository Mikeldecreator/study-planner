<?php
require_once __DIR__ . '/../includes/auth.php';
requirePageLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Study — Study Planner</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
/* Scoped Study Page Styles */
.study-page { background: #f4f8f7; color: #082e2a; }
.dark .study-page { background: #0b1411; color: #e7f3ef; }
.study-card {
  background: #ffffff;
  border: 1px solid #e0ebe8;
  border-radius: 14px;
  box-shadow: 0 4px 14px rgba(14,73,59,.04);
}
.dark .study-card {
  background: #131a18;
  border-color: rgba(255,255,255,.09);
  box-shadow: 0 4px 14px rgba(0,0,0,.15);
}
.focus-timer-card {
  position: relative;
  overflow: hidden;
  transition: border-color .2s ease, box-shadow .2s ease;
}
.focus-timer-card.is-running {
  border-color: #34d399;
  box-shadow: 0 4px 18px rgba(5,150,105,.12);
}
.focus-timer-card.is-paused {
  border-color: #fbbf24;
  box-shadow: 0 4px 18px rgba(217,119,6,.10);
}
.dark .focus-timer-card.is-running {
  border-color: rgba(52,211,153,.35);
  box-shadow: 0 4px 18px rgba(16,185,129,.14);
}
.dark .focus-timer-card.is-paused {
  border-color: rgba(251,191,36,.35);
  box-shadow: 0 4px 18px rgba(245,158,11,.14);
}
</style>
</head>
<body data-page="study" class="study-page min-h-screen transition-colors">
<div class="flex min-h-screen">
  <div id="sidebar-slot"></div>

  <main class="flex-1 min-w-0">
    <!-- Topbar -->
    <header class="bg-white/95 dark:bg-[#101615]/95 border-b border-gray-100 dark:border-white/10 px-4 sm:px-7 py-3.5 flex items-center justify-between gap-4 sticky top-0 z-20">
      <div class="flex items-center gap-3">
        <button id="hamburger-btn" class="lg:hidden text-[#0B4B42] dark:text-gray-200 p-1" aria-label="Open menu">
          <i data-lucide="menu" class="w-6 h-6"></i>
        </button>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-emerald-700 to-teal-600 text-white flex items-center justify-center shadow-sm shrink-0">
            <i data-lucide="target" class="w-5 h-5"></i>
          </div>
          <div>
            <h1 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white leading-tight">Study Focus</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 hidden sm:block">What should I study now? Plan and execute focused learning sessions.</p>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <a href="tasks.php" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg border border-gray-200 dark:border-white/10 transition-colors">
          <i data-lucide="check-square" class="w-3.5 h-3.5"></i>
          <span>My Work</span>
        </a>
        <button id="bell-btn" class="relative text-[#0A4D44] dark:text-gray-200 p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 transition-colors" aria-label="Notifications">
          <i data-lucide="bell" class="w-5 h-5"></i>
          <span id="bell-badge" class="hidden absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 items-center justify-center"></span>
        </button>
        <div id="bell-dropdown" class="hidden absolute right-4 top-16 w-80 max-w-[90vw] bg-white dark:bg-[#171D1B] border border-gray-100 dark:border-white/10 rounded-xl shadow-xl z-30 max-h-96 overflow-y-auto"></div>
        <a href="settings.php" class="hidden sm:flex items-center gap-2 pl-3 border-l border-gray-100 dark:border-white/10" aria-label="Account settings">
          <span class="text-xs sm:text-sm font-semibold text-gray-800 dark:text-gray-200" data-user-name>Loading…</span>
          <div class="w-8 h-8 rounded-full bg-emerald-700 text-white flex items-center justify-center text-xs font-bold" data-top-avatar data-user-initial>U</div>
        </a>
      </div>
    </header>

    <div class="p-4 sm:p-6 lg:p-7 space-y-5 max-w-[1550px] mx-auto">
      
      <!-- HERO: "What should I study now?" / Today's Study Focus -->
      <section id="todays-focus-section" class="study-card p-5 sm:p-6 bg-gradient-to-br from-[#e8f7f0] via-[#f2faf6] to-[#ffffff] dark:from-[#10271f] dark:via-[#131f1b] dark:to-[#121816] border-emerald-200 dark:border-emerald-800/40 relative overflow-hidden">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-5 relative z-10">
          <div class="space-y-2 min-w-0 max-w-2xl">
            <div class="flex items-center gap-2">
              <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-extrabold uppercase tracking-wider bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300">
                <i data-lucide="sparkles" class="w-3 h-3"></i>
                Today's Recommended Study Focus
              </span>
              <span id="todays-focus-risk" class="hidden text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"></span>
            </div>
            <h2 id="todays-focus-title" class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white leading-tight">
              Loading recommendations…
            </h2>
            <p id="todays-focus-reason" class="text-xs sm:text-sm text-[#456860] dark:text-gray-300 leading-relaxed">
              Evaluating course workloads and upcoming deadlines...
            </p>
            <div id="todays-focus-meta" class="flex flex-wrap gap-2 pt-1 text-xs"></div>
          </div>
          <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 shrink-0">
            <button id="focus-now-btn" type="button" class="btn-press inline-flex items-center justify-center gap-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl px-5 py-3 text-sm font-bold shadow-md shadow-emerald-700/20 transition-all">
              <i data-lucide="play" class="w-4 h-4 fill-white"></i>
              <span>Start Focus Session</span>
            </button>
            <a href="tasks.php" class="inline-flex items-center justify-center gap-1.5 px-4 py-3 rounded-xl border border-emerald-200 dark:border-white/10 text-xs font-semibold text-emerald-800 dark:text-emerald-300 hover:bg-white/60 dark:hover:bg-white/5 transition-colors">
              <span>View All Tasks</span>
              <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
          </div>
        </div>
      </section>

      <!-- Main Study Workspace Grid -->
      <section class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_360px] gap-5 items-start">
        
        <!-- Left Content Column -->
        <div class="min-w-0 space-y-5">
          
          <!-- Quick Metric Highlights -->
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
            <!-- Weekly Goal Progress -->
            <div class="study-card p-4 flex flex-col justify-between">
              <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Weekly Goal</span>
                <i data-lucide="trophy" class="w-4 h-4 text-emerald-600 dark:text-emerald-400"></i>
              </div>
              <div>
                <div class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white" id="weekly-goal-val">–</div>
                <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5" id="weekly-goal-sub">–</div>
              </div>
              <div class="w-full h-1.5 bg-gray-100 dark:bg-white/10 rounded-full overflow-hidden mt-3">
                <div id="weekly-goal-bar" class="h-full bg-emerald-600 rounded-full transition-all duration-500" style="width: 0%"></div>
              </div>
            </div>

            <!-- Focused Today -->
            <div class="study-card p-4 flex flex-col justify-between">
              <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Focused Today</span>
                <i data-lucide="clock" class="w-4 h-4 text-blue-600 dark:text-blue-400"></i>
              </div>
              <div>
                <div class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white" id="today-focus-val">0m</div>
                <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Recorded study time today</div>
              </div>
              <div class="text-[10px] font-bold text-emerald-700 dark:text-emerald-400 mt-3 flex items-center gap-1">
                <i data-lucide="check-circle" class="w-3 h-3"></i>
                <span id="today-sessions-count">0 sessions</span>
              </div>
            </div>

            <!-- Course Needing Attention -->
            <div class="study-card p-4 col-span-2 sm:col-span-1 flex flex-col justify-between">
              <div class="flex items-center justify-between mb-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Needs Attention</span>
                <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-500"></i>
              </div>
              <div>
                <div class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white truncate" id="course-attention-code">–</div>
                <div class="text-[11px] text-gray-500 dark:text-gray-400 truncate mt-0.5" id="course-attention-msg">Evaluating…</div>
              </div>
              <div class="mt-3 text-[10px] font-semibold text-amber-700 dark:text-amber-400" id="course-attention-pill">
                High Priority
              </div>
            </div>
          </div>

          <!-- Recently Studied Work -->
          <div class="study-card overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
              <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 flex items-center justify-center">
                  <i data-lucide="history" class="w-4 h-4"></i>
                </div>
                <div>
                  <h3 class="font-bold text-sm text-gray-900 dark:text-white leading-tight">Recently Studied Work</h3>
                  <p class="text-[11px] text-gray-500 dark:text-gray-400">Historical study sessions and factual time recorded</p>
                </div>
              </div>
              <a href="reports.php" class="text-xs font-bold text-emerald-700 dark:text-emerald-400 hover:underline flex items-center gap-1">
                Reports <i data-lucide="arrow-right" class="w-3 h-3"></i>
              </a>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm min-w-[500px]">
                <thead class="bg-gray-50/50 dark:bg-white/[0.02] text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-white/10 text-left">
                  <tr>
                    <th class="py-2.5 px-4 font-semibold">Task</th>
                    <th class="py-2.5 px-4 font-semibold">Course</th>
                    <th class="py-2.5 px-4 font-semibold">Time Spent</th>
                    <th class="py-2.5 px-4 font-semibold">Date</th>
                  </tr>
                </thead>
                <tbody id="recent-sessions-body">
                  <tr>
                    <td colspan="4" class="py-6 text-center text-xs text-gray-400">Loading study sessions…</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Upcoming Study Sessions & Timetable Blocks -->
          <div class="study-card p-5">
            <div class="flex items-center justify-between mb-4">
              <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400 flex items-center justify-center">
                  <i data-lucide="calendar-days" class="w-4 h-4"></i>
                </div>
                <div>
                  <h3 class="font-bold text-sm text-gray-900 dark:text-white leading-tight">Upcoming Scheduled Sessions &amp; Classes</h3>
                  <p class="text-[11px] text-gray-500 dark:text-gray-400">From your calendar and timetable</p>
                </div>
              </div>
              <a href="schedule.php" class="text-xs font-bold text-blue-700 dark:text-blue-400 hover:underline flex items-center gap-1">
                Timetable <i data-lucide="arrow-right" class="w-3 h-3"></i>
              </a>
            </div>
            <div id="upcoming-sessions-list" class="space-y-2.5">
              <div class="text-xs text-gray-400 py-4 text-center">Loading upcoming sessions…</div>
            </div>
          </div>

        </div>

        <!-- Right Aside: Layer 1 Integrated Focus Timer -->
        <aside class="space-y-4">
          <div id="focus-timer-card" class="study-card focus-timer-card p-4 sm:p-5" aria-labelledby="focus-timer-heading">
            <div class="flex items-center justify-between mb-3.5">
              <div class="flex items-center gap-2.5">
                <div id="timer-icon-wrap" class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 flex items-center justify-center transition-all">
                  <i data-lucide="timer" class="w-4 h-4"></i>
                </div>
                <div>
                  <h3 id="focus-timer-heading" class="font-bold text-sm leading-tight text-gray-900 dark:text-white">Focus Timer</h3>
                  <p id="timer-status-hint" class="text-[10px] text-gray-500 dark:text-gray-400">Track focused study time</p>
                </div>
              </div>
              <span id="timer-status-badge" class="inline-flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300">
                <span id="timer-status-dot" class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                <span id="timer-status-text">Idle</span>
              </span>
            </div>

            <!-- Task Selector -->
            <div class="space-y-1 mb-3">
              <div class="flex items-center justify-between">
                <label for="timer-task-select" class="block font-bold text-[11px] text-gray-700 dark:text-gray-300 mb-0">Task to Focus</label>
                <span id="timer-task-count-hint" class="text-[10px] text-gray-500 dark:text-gray-400"></span>
              </div>
              <select id="timer-task-select" class="w-full min-h-[38px] border border-gray-200 dark:border-white/10 bg-white dark:bg-[#111916] rounded-lg px-3 py-2 text-xs text-gray-800 dark:text-gray-100 outline-none focus:ring-2 focus:ring-emerald-500/20" aria-label="Select task to focus on">
                <option value="">— Select a task to focus —</option>
              </select>
            </div>

            <!-- Active / Selected Task Details -->
            <div id="timer-task-meta" class="hidden rounded-xl bg-gray-50 dark:bg-white/[0.035] border border-gray-200 dark:border-white/10 p-3 space-y-2 mb-3">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-1.5 mb-1 flex-wrap">
                    <span id="timer-course-badge" class="inline-flex items-center text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">General</span>
                    <span id="timer-task-priority" class="text-[10px] font-semibold text-gray-500 dark:text-gray-400"></span>
                  </div>
                  <h4 id="timer-task-title" class="text-xs font-bold text-gray-900 dark:text-gray-100 truncate" title="">Select a task</h4>
                </div>
              </div>

              <!-- Time Stats & Progress -->
              <div class="pt-2 border-t border-gray-200 dark:border-white/10 space-y-1.5">
                <div class="flex items-center justify-between text-[11px]">
                  <span class="text-gray-500 dark:text-gray-400">Total Focused:</span>
                  <strong id="timer-task-focused" class="text-emerald-700 dark:text-emerald-400 font-bold">0m</strong>
                </div>
                <div class="flex items-center justify-between text-[11px]">
                  <span class="text-gray-500 dark:text-gray-400">Estimated:</span>
                  <span id="timer-task-estimated" class="text-gray-700 dark:text-gray-300 font-semibold">—</span>
                </div>
                <div id="timer-progress-wrap" class="pt-1">
                  <div class="flex items-center justify-between text-[10px] text-gray-500 dark:text-gray-400 mb-1">
                    <span>Time Progress</span>
                    <span id="timer-progress-pct" class="font-bold text-emerald-700 dark:text-emerald-400">0%</span>
                  </div>
                  <div class="w-full h-1.5 bg-gray-200 dark:bg-white/10 rounded-full overflow-hidden">
                    <div id="timer-progress-bar" class="h-full bg-emerald-600 rounded-full transition-all duration-500" style="width: 0%"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Digital Clock Display -->
            <div class="text-center py-4 my-2 rounded-xl bg-gray-50 dark:bg-white/[0.025] border border-gray-200/80 dark:border-white/5">
              <div id="timer-display" class="font-mono text-3xl sm:text-4xl font-black tracking-wider text-gray-900 dark:text-white select-none">
                00:00:00
              </div>
              <div id="timer-clock-hint" class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">
                Focus Session
              </div>
            </div>

            <!-- Controls -->
            <div class="space-y-2 mt-3">
              <div class="grid grid-cols-2 gap-2">
                <button id="timer-btn-start" type="button" class="btn-press py-2.5 px-3 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-sm transition-all">
                  <i data-lucide="play" class="w-3.5 h-3.5 fill-white"></i>
                  <span>Start Focus</span>
                </button>
                <button id="timer-btn-pause" type="button" class="btn-press hidden py-2.5 px-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-sm transition-all">
                  <i data-lucide="pause" class="w-3.5 h-3.5 fill-white"></i>
                  <span>Pause</span>
                </button>
                <button id="timer-btn-resume" type="button" class="btn-press hidden py-2.5 px-3 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-sm transition-all">
                  <i data-lucide="play" class="w-3.5 h-3.5 fill-white"></i>
                  <span>Resume</span>
                </button>
                <button id="timer-btn-stop" type="button" class="btn-press py-2.5 px-3 rounded-xl bg-gray-200 hover:bg-gray-300 dark:bg-white/10 dark:hover:bg-white/15 text-gray-800 dark:text-gray-200 font-bold text-xs flex items-center justify-center gap-1.5 transition-all opacity-50 cursor-not-allowed" disabled>
                  <i data-lucide="square" class="w-3.5 h-3.5 fill-current"></i>
                  <span>Stop &amp; Save</span>
                </button>
              </div>
            </div>

            <!-- Strict Completion Rule Note -->
            <div class="mt-3 p-2.5 rounded-lg bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-100 dark:border-emerald-800/30 text-[10px] text-emerald-800 dark:text-emerald-300 leading-relaxed">
              <i data-lucide="info" class="w-3 h-3 inline mr-1 text-emerald-600 dark:text-emerald-400"></i>
              Focused study time is recorded accurately. Tasks auto-complete when focused time reaches 100% of estimated workload.
            </div>
          </div>
        </aside>

      </section>

    </div>
  </main>
</div>

<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/work-timer.js"></script>
<script src="../assets/js/study.js"></script>
</body>
</html>
