<?php

require_once __DIR__ . '/../includes/auth.php';

requirePageLogin();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Deadlines — Study Planner</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<link rel="stylesheet" href="../assets/css/style.css">

<style>
  .surface {
    background: #ffffff;
    border: 1px solid #e7eef2;
    box-shadow: 0 2px 8px rgba(15, 61, 48, 0.035);
  }

  .dark .surface {
    background: #13191a;
    border-color: rgba(255,255,255,.09);
    box-shadow: none;
  }

  .metric-card {
    transition:
      transform .18s ease,
      box-shadow .18s ease,
      border-color .18s ease;
  }

  .metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(15,61,48,.07);
  }

  .dark .metric-card:hover {
    box-shadow: 0 8px 22px rgba(0,0,0,.18);
  }

  .metric-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .deadline-row {
    transition:
      background .15s ease,
      transform .15s ease;
  }

  .deadline-row:hover {
    background: rgba(16,185,129,.035);
  }

  .dark .deadline-row:hover {
    background: rgba(255,255,255,.025);
  }

  .priority-badge,
  .status-badge {
    white-space: nowrap;
  }

  .deadline-calendar-day {
    min-height: 38px;
    transition:
      background .15s ease,
      color .15s ease,
      transform .15s ease;
  }

  .deadline-calendar-day:hover {
    transform: translateY(-1px);
  }

  .deadline-calendar-day.is-today {
    background: #047857;
    color: white;
    box-shadow: 0 4px 12px rgba(4,120,87,.18);
  }

  .dark .deadline-calendar-day.is-today {
    background: #10b981;
    color: #06130f;
  }

  .calendar-event-dot {
    width: 5px;
    height: 5px;
    border-radius: 999px;
  }

  .search-focus:focus {
    box-shadow: 0 0 0 3px rgba(16,185,129,.12);
  }

  .deadline-filter-btn {
    transition:
      background .15s ease,
      color .15s ease,
      border-color .15s ease;
  }

  .deadline-filter-btn.active {
    background: #047857;
    color: #fff;
    border-color: #047857;
  }

  .dark .deadline-filter-btn.active {
    background: #059669;
    border-color: #059669;
    color: #07140f;
  }

  .deadline-table th {
    letter-spacing: .01em;
  }

  .countdown-on-track {
    color: #059669;
  }

  .countdown-due-soon {
    color: #d97706;
  }

  .countdown-overdue {
    color: #dc2626;
  }
</style>
</head>

<body
  data-page="deadlines"
  class="bg-[#F6FAF8] dark:bg-[#0B0F0F] text-gray-900 dark:text-gray-100 transition-colors"
>

<div class="flex min-h-screen">

  <div id="sidebar-slot"></div>

  <main class="flex-1 min-w-0">

    <!-- Header -->
    <header
      class="sticky top-0 z-20 bg-white/95 dark:bg-[#111617]/95 backdrop-blur border-b border-gray-100 dark:border-white/10 px-4 sm:px-6 xl:px-8 py-4"
    >
      <div class="flex items-center gap-3">

        <button
          id="hamburger-btn"
          class="lg:hidden w-9 h-9 rounded-lg flex items-center justify-center text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition"
          aria-label="Open menu"
        >
          <i data-lucide="menu" class="w-5 h-5"></i>
        </button>

        <div class="min-w-0">
          <h1 class="text-xl sm:text-2xl font-bold text-[#073b31] dark:text-white">
            Deadlines
          </h1>

          <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Track all your upcoming deadlines and never miss a due date.
          </p>
        </div>

        <div class="relative hidden lg:block flex-1 max-w-xl mx-auto">
          <i
            data-lucide="search"
            class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"
          ></i>

          <input
            type="text"
            id="deadline-search"
            placeholder="Search tasks, courses, notes..."
            class="search-focus w-full bg-[#F8FBFA] dark:bg-white/[.045] border border-gray-200 dark:border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-sm outline-none transition"
          >
        </div>

        <div class="flex items-center gap-2 sm:gap-3 ml-auto">

          <a
            href="schedule.php"
            class="hidden sm:flex w-9 h-9 rounded-lg items-center justify-center text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition"
            aria-label="View schedule"
          >
            <i data-lucide="calendar-days" class="w-5 h-5"></i>
          </a>

          <button
            id="bell-btn"
            class="relative w-9 h-9 rounded-lg flex items-center justify-center text-gray-500 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition"
            aria-label="Notifications"
          >
            <i data-lucide="bell" class="w-5 h-5"></i>

            <span
              id="bell-badge"
              class="hidden absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 items-center justify-center"
            ></span>
          </button>

          <a
            href="settings.php"
            class="hidden sm:flex items-center gap-2 group"
            aria-label="Account settings"
          >
            <div
              class="w-9 h-9 rounded-full bg-emerald-800 text-white text-xs font-semibold flex items-center justify-center"
              data-user-initial
            >
              U
            </div>

            <span
              class="hidden xl:block text-sm font-semibold group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition"
              data-user-name
            >
              Loading…
            </span>

            <i
              data-lucide="chevron-down"
              class="w-4 h-4 text-gray-400"
            ></i>
          </a>

        </div>

      </div>

      <!-- Mobile Search -->
      <div class="relative lg:hidden mt-3">
        <i
          data-lucide="search"
          class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"
        ></i>

        <input
          type="text"
          id="deadline-search-mobile"
          placeholder="Search tasks, courses, notes..."
          class="w-full bg-[#F8FBFA] dark:bg-white/[.045] border border-gray-200 dark:border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-sm outline-none"
        >
      </div>

      <div
        id="bell-dropdown"
        class="hidden absolute right-4 sm:right-8 top-16 w-80 max-w-[90vw] bg-white dark:bg-[#1A1F20] border border-gray-100 dark:border-white/10 rounded-xl shadow-xl z-40 max-h-96 overflow-y-auto"
      ></div>
    </header>

    <div class="p-4 sm:p-6 xl:p-8 space-y-6">

      <!-- Page intro -->
      <section class="flex flex-col xl:flex-row xl:items-end justify-between gap-4">

        <div>
          <div class="flex items-center gap-2">
            <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center">
              <i data-lucide="clock-3" class="w-5 h-5"></i>
            </div>

            <div>
              <h2 class="text-lg sm:text-xl font-bold text-[#073b31] dark:text-white">
                Upcoming Deadlines
              </h2>

              <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                Stay ahead of assignments, tests, projects, and submissions.
              </p>
            </div>
          </div>
        </div>

        <!-- Filter controls -->
        <div class="flex flex-wrap items-center gap-2">

          <button
            type="button"
            class="deadline-filter-btn active border border-gray-200 dark:border-white/10 rounded-lg px-3 py-2 text-xs font-semibold"
            data-deadline-view="all"
          >
            All
          </button>

          <button
            type="button"
            class="deadline-filter-btn border border-gray-200 dark:border-white/10 rounded-lg px-3 py-2 text-xs font-semibold text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5"
            data-deadline-view="today"
          >
            Today
          </button>

          <button
            type="button"
            class="deadline-filter-btn border border-gray-200 dark:border-white/10 rounded-lg px-3 py-2 text-xs font-semibold text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5"
            data-deadline-view="week"
          >
            Week
          </button>

          <button
            type="button"
            class="deadline-filter-btn border border-gray-200 dark:border-white/10 rounded-lg px-3 py-2 text-xs font-semibold text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5"
            data-deadline-view="month"
          >
            Month
          </button>

          <select
            id="deadline-status-filter"
            class="bg-white dark:bg-[#13191a] border border-gray-200 dark:border-white/10 rounded-lg px-3 py-2 text-xs font-semibold outline-none"
          >
            <option value="">All Status</option>
            <option value="on_track">On Track</option>
            <option value="due_soon">Due Soon</option>
            <option value="overdue">Overdue</option>
          </select>

        </div>

      </section>

      <!-- Statistics -->
      <section
        class="grid grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4"
        id="deadline-stat-cards"
      ></section>

      <!-- Main layout -->
      <section
        class="grid grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_340px] gap-5 items-start"
      >

        <!-- Main deadlines table -->
        <div class="surface rounded-2xl overflow-hidden min-w-0">

          <div class="px-4 sm:px-5 py-4 border-b border-gray-100 dark:border-white/10">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">

              <div>
                <div class="flex items-center gap-2">
                  <i
                    data-lucide="list-checks"
                    class="w-4 h-4 text-emerald-700 dark:text-emerald-300"
                  ></i>

                  <h3 class="font-bold text-sm">
                    All Deadlines
                  </h3>
                </div>

                <p
                  id="deadline-table-subtitle"
                  class="text-xs text-gray-400 dark:text-gray-500 mt-1"
                >
                  Your deadlines sorted by due date.
                </p>
              </div>

              <button
                type="button"
                id="deadline-clear-filter"
                class="text-xs font-semibold text-emerald-700 dark:text-emerald-300 hover:text-emerald-600 hidden"
              >
                Clear filters
              </button>

            </div>

          </div>

          <div class="overflow-x-auto">

            <table class="w-full text-sm min-w-[860px] deadline-table">

              <thead>
                <tr class="bg-[#F8FBFA] dark:bg-white/[.025] text-left text-[11px] text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-white/10">

                  <th class="py-3 px-4 sm:px-5 font-semibold w-10">
                    #
                  </th>

                  <th class="py-3 px-4 font-semibold">
                    Task / Assignment
                  </th>

                  <th class="py-3 px-4 font-semibold">
                    Course
                  </th>

                  <th class="py-3 px-4 font-semibold">
                    Due Date
                  </th>

                  <th class="py-3 px-4 font-semibold">
                    Time Left
                  </th>

                  <th class="py-3 px-4 font-semibold">
                    Priority
                  </th>

                  <th class="py-3 px-4 font-semibold">
                    Status
                  </th>

                </tr>
              </thead>

              <tbody
                id="deadline-table-body"
              ></tbody>

            </table>

          </div>

          <div
            id="deadline-table-footer"
            class="px-4 sm:px-5 py-3 border-t border-gray-100 dark:border-white/10 flex items-center justify-between gap-3"
          ></div>

        </div>

        <!-- Right column -->
        <aside class="space-y-5">

          <!-- Overview -->
          <div class="surface rounded-2xl p-5">

            <div class="flex items-center justify-between mb-4">

              <div class="flex items-center gap-2">
                <i
                  data-lucide="pie-chart"
                  class="w-4 h-4 text-emerald-700 dark:text-emerald-300"
                ></i>

                <h3 class="font-bold text-sm">
                  Deadline Overview
                </h3>
              </div>

              <span class="text-[10px] text-gray-400">
                Current
              </span>

            </div>

            <div class="flex items-center gap-5">

              <div class="relative w-28 h-28 shrink-0">

                <canvas
                  id="deadline-donut"
                ></canvas>

                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">

                  <div
                    class="text-2xl font-bold"
                    id="deadline-donut-total"
                  >
                    –
                  </div>

                  <div class="text-[9px] text-gray-400">
                    Total
                  </div>

                </div>

              </div>

              <div
                class="flex-1 min-w-0 space-y-3 text-xs"
                id="deadline-donut-legend"
              ></div>

            </div>

          </div>

          <!-- Upcoming -->
          <div class="surface rounded-2xl overflow-hidden">

            <div class="px-5 py-4 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">

              <div class="flex items-center gap-2">

                <i
                  data-lucide="calendar-clock"
                  class="w-4 h-4 text-emerald-700 dark:text-emerald-300"
                ></i>

                <h3 class="font-bold text-sm">
                  Upcoming Deadlines
                </h3>

              </div>

              <span class="text-xs text-emerald-700 dark:text-emerald-300 font-semibold">
                Next 7 days
              </span>

            </div>

            <div
              id="deadline-upcoming-7"
              class="divide-y divide-gray-100 dark:divide-white/10"
            ></div>

          </div>

          <!-- Calendar -->
          <div class="surface rounded-2xl p-5">

            <div class="flex items-center justify-between mb-4">

              <button
                id="cal-prev"
                type="button"
                class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-800 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/5 transition"
                aria-label="Previous month"
              >
                <i data-lucide="chevron-left" class="w-4 h-4"></i>
              </button>

              <div class="text-center">

                <h3
                  class="font-bold text-sm"
                  id="cal-title"
                >
                  Calendar View
                </h3>

                <div class="text-[10px] text-gray-400 mt-0.5">
                  Deadline calendar
                </div>

              </div>

              <button
                id="cal-next"
                type="button"
                class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-800 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/5 transition"
                aria-label="Next month"
              >
                <i data-lucide="chevron-right" class="w-4 h-4"></i>
              </button>

            </div>

            <div class="grid grid-cols-7 gap-1 text-[10px] text-center text-gray-400 dark:text-gray-500 mb-2 font-semibold">
              <div>Su</div>
              <div>Mo</div>
              <div>Tu</div>
              <div>We</div>
              <div>Th</div>
              <div>Fr</div>
              <div>Sa</div>
            </div>

            <div
              class="grid grid-cols-7 gap-1"
              id="cal-grid"
            ></div>

            <div class="flex flex-wrap gap-x-4 gap-y-2 mt-4 pt-3 border-t border-gray-100 dark:border-white/10">

              <div class="flex items-center gap-1.5 text-[10px] text-gray-500 dark:text-gray-400">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                On Track
              </div>

              <div class="flex items-center gap-1.5 text-[10px] text-gray-500 dark:text-gray-400">
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                Due Soon
              </div>

              <div class="flex items-center gap-1.5 text-[10px] text-gray-500 dark:text-gray-400">
                <span class="w-2 h-2 rounded-full bg-red-500"></span>
                Overdue
              </div>

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
<script src="../assets/js/deadlines.js"></script>

</body>
</html>