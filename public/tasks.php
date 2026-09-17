<?php
require_once __DIR__ . '/../includes/auth.php';
requirePageLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tasks — Study Planner</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
/* Tasks reference UI — scoped so it cannot disturb other pages. */
.tasks-page{background:#f4f8f7;color:#082e2a}
.tasks-content{width:100%;max-width:1600px}
.tasks-topbar{min-height:88px}
.tasks-hero{position:relative;overflow:hidden;background:linear-gradient(105deg,#e3f7ef 0%,#edf9f5 58%,#dff2e7 100%)}
.tasks-hero:after{content:"";position:absolute;width:360px;height:190px;right:-70px;top:-55px;border-radius:50%;background:rgba(255,255,255,.30);pointer-events:none}
.tasks-hero>*{position:relative;z-index:1}
.tasks-hero-icon{width:58px;height:58px;border-radius:18px;background:#d8f2e6;color:#078457;display:flex;align-items:center;justify-content:center;flex:none}
.tasks-stat-card{min-height:108px;background:#fff;border:1px solid #e0ebe8;border-radius:12px;box-shadow:0 4px 14px rgba(14,73,59,.045);padding:16px;display:flex;align-items:center;gap:13px;transition:transform .18s ease,box-shadow .18s ease}
.tasks-stat-card:hover{transform:translateY(-2px);box-shadow:0 9px 22px rgba(14,73,59,.08)}
.tasks-stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;flex:none}
.tasks-card{background:#fff;border:1px solid #e0ebe8;border-radius:12px;box-shadow:0 4px 14px rgba(14,73,59,.045)}
.task-tab-btn{flex:0 0 auto;border:0;background:transparent;color:#496861;padding:9px 14px;border-radius:10px;font-size:12px;font-weight:600;white-space:nowrap;transition:.15s}
.task-tab-btn:hover{background:#f1f7f5;color:#08734e}
.task-tab-btn.active{background:#087f55;color:#fff;box-shadow:0 2px 6px rgba(8,127,85,.16)}
.task-filter-control,.task-form-control{width:100%;min-height:40px;border:1px solid #d8e5e2;background:#fff;border-radius:9px;padding:9px 11px;font-size:12px;outline:none;color:#173b35;transition:.15s}
.task-filter-control:focus,.task-form-control:focus{border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,.12)}
.task-form-label{display:block;font-size:12px;font-weight:700;color:#45665f;margin-bottom:6px}
.task-row{border-bottom:1px solid #edf2f1;transition:background .15s}
.task-row:last-child{border-bottom:0}
.task-row:hover{background:#fbfdfc}
.task-row-accent{display:block;width:3px;min-height:38px;border-radius:999px;flex:none}
.task-type-pill,.task-status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;font-size:10px;font-weight:700;padding:5px 9px;white-space:nowrap}
.task-type-pill{background:#f0eaff;color:#7047d6}
.task-status-pill{min-width:70px}
.task-action-btn{width:31px;height:31px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:#52716b;transition:.15s}
.task-action-btn:hover{background:#edf7f3;color:#087f55}
.task-action-btn.danger:hover{background:#fff1f2;color:#dc2626}
.task-empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;color:#78918b;font-size:12px}
.task-empty-state strong{font-size:13px;color:#355750}
.task-empty-small{display:flex;align-items:center;justify-content:center;gap:8px;min-height:90px;color:#78918b;font-size:12px}
.task-upcoming-item{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #edf2f1}
.task-upcoming-item:last-child{border-bottom:0}
.task-upcoming-icon{width:36px;height:36px;border-radius:11px;background:#fff6df;color:#d88a00;display:flex;align-items:center;justify-content:center;flex:none}
.task-upcoming-icon.overdue{background:#fff0f0;color:#dc2626}
.task-pagination-btn{width:32px;height:32px;border:1px solid #dce8e5;border-radius:8px;background:#fff;color:#496861;font-size:12px;display:inline-flex;align-items:center;justify-content:center}
.task-pagination-btn:hover:not(:disabled){border-color:#9ed5c0;color:#087f55}
.task-pagination-btn.active{background:#087f55;color:#fff;border-color:#087f55;font-weight:700}
.task-pagination-btn:disabled{opacity:.4;cursor:not-allowed}
.task-checkbox{width:16px;height:16px;accent-color:#078c5b}
.task-modal-panel{animation:task-modal-in .18s ease-out}
@keyframes task-modal-in{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:none}}
.dark .tasks-page{background:#0b1411;color:#e7f3ef}
.dark .tasks-hero{background:linear-gradient(105deg,#102c23 0%,#142a24 58%,#173228 100%);border-color:rgba(52,211,153,.18)}
.dark .tasks-hero-icon{background:rgba(16,185,129,.14);color:#69d6ad}
.dark .tasks-stat-card,.dark .tasks-card{background:#131a18;border-color:rgba(255,255,255,.09);box-shadow:0 4px 14px rgba(0,0,0,.12)}
.dark .task-tab-btn{color:#b8cbc5}
.dark .task-tab-btn:hover{background:rgba(255,255,255,.05);color:#79e0b6}
.dark .task-filter-control,.dark .task-form-control{background:#111916;border-color:rgba(255,255,255,.11);color:#e5f1ed}
.dark .task-filter-control option,.dark .task-form-control option{background:#131a18;color:#e5f1ed}
.dark .task-form-label{color:#b7cbc5}
.dark .task-row{border-color:rgba(255,255,255,.07)}
.dark .task-row:hover{background:rgba(255,255,255,.025)}
.dark .task-action-btn{color:#9db5ae}
.dark .task-action-btn:hover{background:rgba(16,185,129,.10);color:#79e0b6}
.dark .task-action-btn.danger:hover{background:rgba(220,38,38,.10);color:#f87171}
.dark .task-type-pill{background:rgba(124,58,237,.14);color:#c4b5fd}
.dark .task-upcoming-item{border-color:rgba(255,255,255,.07)}
.dark .task-pagination-btn{background:#151d1a;border-color:rgba(255,255,255,.1);color:#b8cbc5}
.dark .task-empty-state strong{color:#c5d8d2}
.dark .task-empty-small{color:#91aaa2}
@media(max-width:1023px){.tasks-topbar{min-height:72px}}
@media(max-width:640px){.tasks-content{padding:12px}.tasks-hero{padding:16px}.tasks-hero-icon{width:48px;height:48px;border-radius:14px}.tasks-stat-card{min-height:92px;padding:12px}.tasks-stat-icon{width:42px;height:42px}.task-tab-btn{padding:8px 11px}.task-table-wrap{overflow-x:auto}.task-modal-panel{max-height:94vh}}
/* =========================================================
   SMART TASK RECOMMENDATION
========================================================= */

.smart-task-focus{
  position:relative;
  display:flex;
  align-items:flex-start;
  gap:14px;
  padding:16px 18px;
  border:1px solid #bfe7d6;
  border-radius:14px;
  background:linear-gradient(
    135deg,
    #f0fbf6 0%,
    #ffffff 100%
  );
  box-shadow:0 5px 18px rgba(14,73,59,.05);
  overflow:hidden;
}

.smart-task-focus::after{
  content:'';
  position:absolute;
  width:180px;
  height:180px;
  right:-70px;
  top:-90px;
  border-radius:50%;
  background:rgba(16,185,129,.06);
  pointer-events:none;
}

.smart-task-focus-icon{
  width:42px;
  height:42px;
  flex:0 0 auto;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:12px;
  background:#dff6ea;
  color:#087f55;
}

.smart-task-focus-content{
  min-width:0;
  flex:1;
}

.smart-task-focus-eyebrow{
  font-size:10px;
  line-height:1;
  font-weight:800;
  letter-spacing:.12em;
  color:#087f55;
  margin-bottom:5px;
}

.smart-task-focus-title{
  font-size:16px;
  line-height:1.35;
  font-weight:800;
  color:#143e35;
}

.smart-task-focus-reason{
  margin-top:3px;
  font-size:12px;
  color:#648079;
}

.smart-task-focus-meta{
  display:flex;
  flex-wrap:wrap;
  gap:7px;
  margin-top:10px;
}

.smart-task-focus-meta span{
  display:inline-flex;
  align-items:center;
  min-height:25px;
  padding:4px 8px;
  border-radius:999px;
  background:#f1f7f5;
  border:1px solid #dcebe5;
  color:#45665f;
  font-size:10px;
  font-weight:700;
}

.dark .smart-task-focus{
  background:linear-gradient(
    135deg,
    #10251e 0%,
    #151d1a 100%
  );
  border-color:rgba(52,211,153,.18);
}

.dark .smart-task-focus-icon{
  background:rgba(16,185,129,.12);
  color:#6ee7b7;
}

.dark .smart-task-focus-title{
  color:#e8f5f0;
}

.dark .smart-task-focus-reason{
  color:#8fa8a1;
}

.dark .smart-task-focus-meta span{
  background:rgba(255,255,255,.045);
  border-color:rgba(255,255,255,.08);
  color:#a9c0b8;
}

@media(max-width:640px){

  .smart-task-focus{
    padding:14px;
    gap:11px;
  }

  .smart-task-focus-icon{
    width:38px;
    height:38px;
  }

  .smart-task-focus-title{
    font-size:14px;
  }

}
</style>
</head>
<body data-page="tasks" class="tasks-page min-h-screen transition-colors">
<div class="flex min-h-screen">
  <div id="sidebar-slot"></div>

  <main class="flex-1 min-w-0">
    <header class="tasks-topbar bg-white/95 dark:bg-[#101615]/95 border-b border-gray-100 dark:border-white/10 px-4 sm:px-7 py-4 flex items-center gap-4 sticky top-0 z-20">
      <button id="hamburger-btn" class="lg:hidden text-[#0B4B42] dark:text-gray-200 hover:text-emerald-700 transition-colors" aria-label="Open menu">
        <i data-lucide="menu" class="w-6 h-6"></i>
      </button>
      <div class="min-w-[170px]">
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight">Tasks</h1>
        <p class="hidden sm:block text-xs sm:text-sm text-[#53736D] dark:text-gray-400 mt-0.5">Organize your academic workload and stay on track.</p>
      </div>
      <div class="relative hidden md:block flex-1 max-w-lg mx-auto">
        <i data-lucide="search" class="w-5 h-5 text-[#56736E] dark:text-gray-500 absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none"></i>
        <input id="global-search" type="text" placeholder="Search tasks, courses, notes..." aria-label="Global search"
          class="w-full h-11 bg-[#F8FAFA] dark:bg-white/5 border border-[#D8E5E2] dark:border-white/10 rounded-xl pl-12 pr-4 text-sm text-[#173B35] dark:text-gray-100 outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 placeholder:text-gray-400">
      </div>
      <div class="flex items-center gap-4 ml-auto">
        <a href="schedule.php" class="hidden sm:flex text-[#0A4D44] dark:text-gray-200 hover:text-emerald-700 dark:hover:text-emerald-400" aria-label="View calendar"><i data-lucide="calendar-days" class="w-6 h-6"></i></a>
        <button id="bell-btn" class="relative text-[#0A4D44] dark:text-gray-200 hover:text-emerald-700 dark:hover:text-emerald-400" aria-label="Notifications">
          <i data-lucide="bell" class="w-6 h-6"></i><span id="bell-badge" class="hidden absolute -top-1 -right-2 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 items-center justify-center"></span>
        </button>
        <a href="settings.php" class="hidden sm:flex items-center gap-2 pl-4 border-l border-gray-100 dark:border-white/10 group" aria-label="Account settings">
          <span class="text-sm font-semibold group-hover:text-emerald-700 dark:group-hover:text-emerald-400" data-user-name>Loading…</span>
          <i data-lucide="chevron-down" class="w-4 h-4 text-gray-400"></i>
          <div class="w-10 h-10 rounded-full bg-emerald-700 text-white flex items-center justify-center font-bold overflow-hidden" data-user-initial>U</div>
        </a>
      </div>
      <div id="bell-dropdown" class="hidden absolute right-4 top-16 w-80 max-w-[90vw] bg-white dark:bg-[#171D1B] border border-gray-100 dark:border-white/10 rounded-xl shadow-xl z-30 max-h-96 overflow-y-auto"></div>
    </header>

    <div class="tasks-content p-4 sm:p-6 lg:p-7 space-y-4 sm:space-y-5 mx-auto">
      <section class="tasks-hero rounded-2xl border border-emerald-100 dark:border-emerald-900/40 px-5 sm:px-7 py-5">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
          <div class="flex items-center gap-4 min-w-0">
            <div class="tasks-hero-icon"><i data-lucide="clipboard-check" class="w-8 h-8"></i></div>
            <div class="min-w-0">
              <h2 class="text-2xl sm:text-3xl font-bold tracking-tight">My Tasks</h2>
              <p class="text-sm sm:text-base text-[#42675F] dark:text-gray-400 mt-1">Organize, prioritize and complete your academic tasks.</p>
            </div>
          </div>
          <div class="flex items-center gap-2 sm:gap-3">
            <button id="open-import-task" type="button" class="hidden sm:inline-flex items-center justify-center gap-2 bg-white/75 dark:bg-white/5 border border-[#CFE1DC] dark:border-white/10 text-[#0B5C49] dark:text-emerald-300 rounded-xl px-4 py-2.5 text-sm font-semibold hover:bg-white dark:hover:bg-white/10">
              <i data-lucide="upload" class="w-4 h-4"></i> Import Tasks
            </button>
            <button id="open-add-task" type="button" class="btn-press inline-flex items-center justify-center gap-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl px-4 py-2.5 text-sm font-semibold shadow-sm">
              <i data-lucide="plus" class="w-4 h-4"></i> Add Task
            </button>
          </div>
        </div>
      </section>

      <section id="task-stat-cards" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 sm:gap-4"></section>

      <section class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_298px] gap-4 items-start">
        <div class="min-w-0 space-y-4">
          <div id="task-tabs" class="flex gap-1 overflow-x-auto tasks-card p-1.5">
            <button data-tab="all" class="task-tab-btn">All Tasks <span data-tab-count="all"></span></button>
            <button data-tab="pending" class="task-tab-btn">Pending <span data-tab-count="pending"></span></button>
            <button data-tab="in_progress" class="task-tab-btn">In Progress <span data-tab-count="in_progress"></span></button>
            <button data-tab="completed" class="task-tab-btn">Completed <span data-tab-count="completed"></span></button>
            <button data-tab="overdue" class="task-tab-btn">Overdue <span data-tab-count="overdue"></span></button>
          </div>

          <div class="tasks-card overflow-hidden">
            <div class="px-4 sm:px-5 py-3.5 border-b border-[#E7EFED] dark:border-white/10">
              <div class="flex flex-col lg:flex-row lg:items-center gap-2.5">
                <div class="relative flex-1 min-w-0">
                  <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                  <input type="search" id="task-search" placeholder="Search tasks..." aria-label="Search tasks" class="task-filter-control pl-10">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 lg:flex gap-2">
                  <select id="filter-course" aria-label="Filter by course" class="task-filter-control min-w-[145px]"><option value="">All Courses</option></select>
                  <select id="filter-type" aria-label="Filter by type" class="task-filter-control min-w-[120px]">
                    <option value="">All Types</option><option value="assignment">Assignment</option><option value="project">Project</option><option value="test">Test</option><option value="exam">Exam</option><option value="research">Research</option><option value="lab_report">Lab Report</option><option value="study_session">Study Session</option><option value="other">Other</option>
                  </select>
                  <select id="filter-priority" aria-label="Filter by priority" class="task-filter-control min-w-[125px]">
                    <option value="">All Priorities</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="task-table-wrap overflow-x-auto">
              <table class="w-full text-sm min-w-[940px]">
                <thead class="bg-[#FBFCFC] dark:bg-white/[0.025]">
                  <tr class="text-left text-[#64807A] dark:text-gray-400 text-[11px] uppercase tracking-wide border-b border-[#E7EFED] dark:border-white/10">
                    <th class="py-3 px-3 w-10 text-center"><input id="select-all-tasks" type="checkbox" class="task-checkbox" aria-label="Select all visible tasks"></th>
                    <th class="py-3 px-3 font-semibold">Task</th><th class="py-3 px-3 font-semibold">Course</th><th class="py-3 px-3 font-semibold">Type</th><th class="py-3 px-3 font-semibold">Priority</th><th class="py-3 px-3 font-semibold">Deadline</th><th class="py-3 px-3 font-semibold">Progress</th><th class="py-3 px-3 font-semibold">Status</th><th class="py-3 px-3 font-semibold text-center">Actions</th>
                  </tr>
                </thead>
                <tbody id="task-table-body"></tbody>
              </table>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 py-3 border-t border-[#E7EFED] dark:border-white/10">
              <div id="task-results-label" class="text-[11px] text-[#64807A] dark:text-gray-400">Showing 0 tasks</div>
              <div id="task-pagination" class="flex items-center gap-1 justify-end"></div>
            </div>
          </div>
        </div>

        <aside class="space-y-4">
          <div class="tasks-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-4">
              <div class="flex items-center gap-2"><div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 flex items-center justify-center"><i data-lucide="sliders-horizontal" class="w-4 h-4"></i></div><h3 class="font-bold text-sm">Filters</h3></div>
              <button id="reset-filters" type="button" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline">Reset</button>
            </div>
            <div class="space-y-3">
              <div><label class="task-form-label">Search</label><div class="relative"><i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></i><input id="filter-search-side" type="search" placeholder="Search tasks..." class="task-filter-control pl-9"></div></div>
              <div><label class="task-form-label">Course</label><select id="filter-course-side" class="task-filter-control"><option value="">All Courses</option></select></div>
              <div><label class="task-form-label">Type</label><select id="filter-type-side" class="task-filter-control"><option value="">All Types</option><option value="assignment">Assignment</option><option value="project">Project</option><option value="test">Test</option><option value="exam">Exam</option><option value="research">Research</option><option value="lab_report">Lab Report</option><option value="study_session">Study Session</option><option value="other">Other</option></select></div>
              <div><label class="task-form-label">Priority</label><select id="filter-priority-side" class="task-filter-control"><option value="">All Priorities</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></div>
              <div><label class="task-form-label">Status</label><select id="filter-status-side" class="task-filter-control"><option value="">All Statuses</option><option value="not_started">Not Started</option><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option><option value="overdue">Overdue</option></select></div>
              <button id="apply-filters" type="button" class="w-full btn-press inline-flex items-center justify-center gap-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg py-2.5 text-sm font-semibold"><i data-lucide="filter" class="w-4 h-4"></i> Apply Filters</button>
            </div>
          </div>

          <div class="tasks-card p-4 sm:p-5">
            <div class="flex items-center gap-2 mb-4"><div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400 flex items-center justify-center"><i data-lucide="pie-chart" class="w-4 h-4"></i></div><div><h3 class="font-bold text-sm">Task Summary</h3><p class="text-[11px] text-[#78918B] dark:text-gray-500">Current workload breakdown</p></div></div>
            <div class="flex items-center gap-4"><div class="relative w-[112px] h-[112px] shrink-0"><canvas id="task-summary-chart"></canvas><div id="task-summary-total" class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none"><strong class="text-xl font-bold">0</strong><span class="text-[10px] text-[#78918B] dark:text-gray-500 mt-1">Total</span></div></div><div id="task-summary-legend" class="text-xs space-y-2 flex-1 min-w-0"></div></div>
          </div>

          <div class="tasks-card p-4 sm:p-5">
            <div class="flex items-center justify-between mb-3.5"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 flex items-center justify-center"><i data-lucide="calendar-clock" class="w-4 h-4"></i></div><h3 class="font-bold text-sm">Upcoming Deadlines</h3></div><a href="deadlines.php" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline">View All</a></div>
            <div id="task-upcoming-deadlines" class="text-sm"></div>
          </div>
        </aside>
      </section>
    </div>
  </main>
</div>

<div id="task-modal" class="hidden fixed inset-0 bg-slate-950/45 backdrop-blur-[2px] flex items-center justify-center z-30 p-4" role="dialog" aria-modal="true" aria-labelledby="task-modal-title">
  <div class="task-modal-panel bg-white dark:bg-[#151D1A] rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto border border-white/70 dark:border-white/10">
    <div class="px-6 py-5 border-b border-[#E7EFED] dark:border-white/10 flex items-center justify-between sticky top-0 bg-white/95 dark:bg-[#151D1A]/95 backdrop-blur z-10">
      <div><div class="flex items-center gap-2"><div class="w-9 h-9 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 flex items-center justify-center"><i data-lucide="square-check-big" class="w-4 h-4"></i></div><h3 class="font-bold text-lg" id="task-modal-title">Add Task</h3></div><p class="text-xs text-[#78918B] dark:text-gray-500 mt-1 ml-11">Keep your academic workload organized.</p></div>
      <button type="button" id="cancel-task" class="w-9 h-9 rounded-lg text-gray-400 hover:text-gray-700 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Close task form"><i data-lucide="x" class="w-5 h-5"></i></button>
    </div>
    <form id="task-form" class="p-6 space-y-4">
      <input type="hidden" name="id">
      <div><label class="task-form-label">Task title</label><input required name="title" maxlength="255" placeholder="e.g. Database Assignment" class="task-form-control"></div>
      <div><label class="task-form-label">Description <span class="font-normal text-gray-400">(optional)</span></label><textarea name="description" rows="3" placeholder="Add a short description..." class="task-form-control resize-none"></textarea></div>
      <div><label class="task-form-label">Course</label><select name="course_id" id="task-course-select" class="task-form-control"><option value="">No course</option></select></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div><label class="task-form-label">Type</label><select name="type" class="task-form-control"><option value="assignment">Assignment</option><option value="project">Project</option><option value="test">Test</option><option value="exam">Exam</option><option value="research">Research</option><option value="lab_report">Lab Report</option><option value="study_session">Study Session</option><option value="other">Other</option></select></div>
        <div><label class="task-form-label">Priority</label><select name="priority" class="task-form-control"><option value="low">Low priority</option><option value="medium" selected>Medium priority</option><option value="high">High priority</option></select></div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div><label class="task-form-label">Status</label><select name="status" class="task-form-control"><option value="not_started">Not Started</option><option value="pending" selected>Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select></div>
        <div><label class="task-form-label">Estimated hours</label><input type="number" step="0.5" min="0" name="duration_hours" placeholder="e.g. 2.5" class="task-form-control"></div>
      </div>
      <div><label class="task-form-label">Deadline</label><input required type="datetime-local" name="due_at" class="task-form-control"></div>
      <div class="rounded-xl bg-[#F5FAF8] dark:bg-white/[0.035] border border-[#E0EBE8] dark:border-white/10 px-4 py-3.5"><div class="flex items-center justify-between mb-2"><label class="task-form-label mb-0">Progress</label><span id="progress-value-label" class="text-xs font-bold text-emerald-700 dark:text-emerald-400">0%</span></div><input type="range" min="0" max="100" step="5" name="progress_percent" id="progress-range" value="0" class="w-full accent-emerald-700"></div>
      <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2"><button type="button" id="cancel-task-bottom" class="px-4 py-2.5 text-sm font-semibold text-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5">Cancel</button><button id="save-task-btn" type="submit" class="btn-press inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold"><i data-lucide="save" class="w-4 h-4"></i><span>Save Task</span></button></div>
    </form>
  </div>
</div>

<input id="task-import-file" type="file" accept=".csv,text/csv" class="hidden">

<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/work-timer.js"></script>
<script src="../assets/js/tasks.js"></script>
</body>
</html>
