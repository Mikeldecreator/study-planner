<?php

require_once __DIR__ . '/../includes/auth.php';

requirePageLogin();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Progress — Study Planner</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

<link rel="stylesheet" href="../assets/css/style.css">

<style>
  .surface {
    background: #ffffff;
    border: 1px solid #e4edf0;
    box-shadow: 0 2px 8px rgba(15, 61, 48, 0.035);
  }

  .dark .surface {
    background: #13191a;
    border-color: rgba(255,255,255,.09);
    box-shadow: none;
  }

  .progress-hero {
    background:
      radial-gradient(
        circle at 15% 40%,
        rgba(16,185,129,.12),
        transparent 32%
      ),
      radial-gradient(
        circle at 75% 15%,
        rgba(16,185,129,.08),
        transparent 28%
      ),
      linear-gradient(
        135deg,
        #edfdf6,
        #f8fffc 55%,
        #eefaf7
      );
  }

  .dark .progress-hero {
    background:
      radial-gradient(
        circle at 15% 40%,
        rgba(16,185,129,.10),
        transparent 32%
      ),
      linear-gradient(
        135deg,
        #10231e,
        #101918 60%,
        #122520
      );
  }

  .metric-card {
    transition:
      transform .18s ease,
      box-shadow .18s ease;
  }

  .metric-card:hover {
    transform: translateY(-2px);
    box-shadow:
      0 8px 24px rgba(15,61,48,.07);
  }

  .dark .metric-card:hover {
    box-shadow:
      0 8px 24px rgba(0,0,0,.20);
  }

  .metric-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .range-button {
    transition:
      background .15s ease,
      color .15s ease;
  }

  .range-button.active {
    background: #047857;
    color: white;
  }

  .dark .range-button.active {
    background: #059669;
    color: #06140f;
  }

  .course-row {
    transition:
      background .15s ease,
      transform .15s ease;
  }

  .course-row:hover {
    background: rgba(16,185,129,.035);
  }

  .dark .course-row:hover {
    background: rgba(255,255,255,.025);
  }

  .progress-track {
    background: #edf1f2;
  }

  .dark .progress-track {
    background: rgba(255,255,255,.08);
  }
</style>

</head>

<body
  data-page="progress"
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
            Progress
          </h1>

          <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            Track your learning progress and achieve your academic goals.
          </p>

        </div>

        <div class="relative hidden lg:block flex-1 max-w-xl mx-auto">

          <i
            data-lucide="search"
            class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"
          ></i>

          <input
            type="text"
            placeholder="Search tasks, courses, notes..."
            class="w-full bg-[#F8FBFA] dark:bg-white/[.045] border border-gray-200 dark:border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-sm outline-none"
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
              class="w-9 h-9 rounded-full bg-emerald-800 text-white text-xs font-semibold flex items-center justify-center overflow-hidden"
              data-top-avatar
            >
              <span data-user-initial>U</span>
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

      <div class="relative lg:hidden mt-3">

        <i
          data-lucide="search"
          class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"
        ></i>

        <input
          type="text"
          placeholder="Search tasks, courses, notes..."
          class="w-full bg-[#F8FBFA] dark:bg-white/[.045] border border-gray-200 dark:border-white/10 rounded-xl pl-10 pr-4 py-2.5 text-sm outline-none"
        >

      </div>

      <div
        id="bell-dropdown"
        class="hidden absolute right-4 sm:right-8 top-16 w-80 max-w-[90vw] bg-white dark:bg-[#1A1F20] border border-gray-100 dark:border-white/10 rounded-xl shadow-xl z-40 max-h-96 overflow-y-auto"
      ></div>

    </header>

    <div class="p-4 sm:p-6 xl:p-8 space-y-5">

      <!-- Hero -->
      <section
        class="progress-hero rounded-2xl border border-emerald-100 dark:border-emerald-500/10 p-5 sm:p-6 xl:p-7 overflow-hidden"
      >

        <div class="flex flex-col xl:flex-row xl:items-center gap-7">

          <div class="flex items-center gap-3">

            <div
              class="w-12 h-12 rounded-full bg-emerald-100 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center shrink-0"
            >
              <i
                data-lucide="target"
                class="w-6 h-6"
              ></i>
            </div>

            <div>

              <h2 class="text-xl sm:text-2xl font-bold text-[#073b31] dark:text-white">
                Your Academic Progress
              </h2>

              <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                You're making great progress. Keep it up!
              </p>

            </div>

          </div>

          <div class="flex-1 grid grid-cols-1 md:grid-cols-[180px_1fr] gap-6 items-center">

            <!-- Donut -->
            <div
              class="relative w-40 h-40 mx-auto"
            >

              <canvas id="overall-donut"></canvas>

              <div
                class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none"
              >

                <div
                  class="text-3xl font-bold"
                  id="overall-pct"
                >
                  –
                </div>

                <div class="text-xs text-gray-500 dark:text-gray-400">
                  Overall Progress
                </div>

              </div>

            </div>

            <!-- Progress Summary -->
            <div class="space-y-4">

              <div>

                <div class="flex items-center justify-between gap-3 mb-2">

                  <div class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                    Academic Goals
                  </div>

                  <div
                    class="text-xs font-bold text-emerald-700 dark:text-emerald-300"
                    id="overall-caption"
                  >
                    –
                  </div>

                </div>

                <div class="h-2.5 progress-track rounded-full overflow-hidden">

                  <div
                    id="overall-bar"
                    class="h-full rounded-full bg-emerald-600 transition-all duration-700"
                    style="width:0%"
                  ></div>

                </div>

              </div>

              <div class="grid grid-cols-2 gap-3">

                <a href="tasks.php" class="bg-white/80 dark:bg-white/[.04] rounded-xl border border-white/80 dark:border-white/5 p-3 hover:border-emerald-500/30 transition block group" title="Go to Tasks">

                  <div class="text-xs text-gray-400 group-hover:text-emerald-700 dark:group-hover:text-emerald-400 transition-colors">
                    Tasks
                  </div>

                  <div
                    class="font-bold text-lg mt-1"
                    id="progress-task-total"
                  >
                    –
                  </div>

                </a>

                <a href="schedule.php" class="bg-white/80 dark:bg-white/[.04] rounded-xl border border-white/80 dark:border-white/5 p-3 hover:border-emerald-500/30 transition block group" title="Go to Schedule">

                  <div class="text-xs text-gray-400 group-hover:text-emerald-700 dark:group-hover:text-emerald-400 transition-colors">
                    Study Sessions
                  </div>

                  <div
                    class="font-bold text-lg mt-1"
                    id="progress-session-total"
                  >
                    –
                  </div>

                </a>

              </div>

            </div>

          </div>

          <!-- Range Selector -->
          <div class="xl:self-start">

            <div
              class="flex items-center p-1 rounded-xl bg-white/75 dark:bg-black/10 border border-white/80 dark:border-white/5"
            >

              <button
                type="button"
                class="range-button px-3 py-2 rounded-lg text-xs font-semibold text-gray-500 dark:text-gray-300"
                data-range="week"
              >
                Week
              </button>

              <button
                type="button"
                class="range-button px-3 py-2 rounded-lg text-xs font-semibold text-gray-500 dark:text-gray-300"
                data-range="month"
              >
                Month
              </button>

              <button
                type="button"
                class="range-button px-3 py-2 rounded-lg text-xs font-semibold text-gray-500 dark:text-gray-300 active"
                data-range="semester"
              >
                Semester
              </button>

            </div>

            <select
              id="range-select"
              class="hidden"
            >
              <option value="week">
                This Week
              </option>

              <option value="month">
                This Month
              </option>

              <option
                value="semester"
                selected
              >
                This Semester
              </option>
            </select>

          </div>

        </div>

      </section>

      <!-- KPI Cards -->
      <section
        class="grid grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4"
        id="progress-stat-cards"
      >

        <div class="metric-card surface rounded-2xl p-4 flex items-center gap-3">
          <div class="metric-icon bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
            <i data-lucide="book-open" class="w-5 h-5"></i>
          </div>

          <div>
            <div class="text-xl font-bold" id="progress-courses-count">–</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Courses</div>
          </div>
        </div>

        <div class="metric-card surface rounded-2xl p-4 flex items-center gap-3">
          <div class="metric-icon bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-300">
            <i data-lucide="list-checks" class="w-5 h-5"></i>
          </div>

          <div>
            <div class="text-xl font-bold" id="progress-tasks-count">–</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Tasks</div>
          </div>
        </div>

        <div class="metric-card surface rounded-2xl p-4 flex items-center gap-3">
          <div class="metric-icon bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
            <i data-lucide="clock-3" class="w-5 h-5"></i>
          </div>

          <div>
            <div class="text-xl font-bold" id="progress-sessions-count">–</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Study Sessions</div>
          </div>
        </div>

        <div class="metric-card surface rounded-2xl p-4 flex items-center gap-3">
          <div class="metric-icon bg-purple-50 text-purple-700 dark:bg-purple-500/10 dark:text-purple-300">
            <i data-lucide="graduation-cap" class="w-5 h-5"></i>
          </div>

          <div>
            <div class="text-xl font-bold" id="progress-deadlines-count">–</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Upcoming Deadlines</div>
          </div>
        </div>

      </section>

      <!-- Foundation 5: Progress Intelligence Insights -->
      <section
        id="progress-insights-section"
        class="surface rounded-2xl p-4 sm:p-5"
      >
        <div class="flex items-center justify-between mb-3.5">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center">
              <i data-lucide="sparkles" class="w-4 h-4"></i>
            </div>
            <div>
              <h3 class="font-bold text-sm text-gray-900 dark:text-gray-100">Academic Progress Intelligence</h3>
              <p class="text-[11px] text-gray-500 dark:text-gray-400">Contextual evaluation of your current academic standing and momentum.</p>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3" id="progress-insights-grid">
          <!-- Strongest Area -->
          <div class="p-3.5 rounded-xl bg-gray-50/70 dark:bg-white/[0.02] border border-[#e8f0ed] dark:border-white/5 flex flex-col justify-between">
            <div>
              <div class="flex items-center justify-between gap-1 mb-1">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Strongest Area</span>
                <i data-lucide="award" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400"></i>
              </div>
              <div class="text-sm font-bold text-gray-900 dark:text-white truncate" id="insight-strongest-label">–</div>
            </div>
            <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 line-clamp-2" id="insight-strongest-desc">Analyzing...</div>
          </div>

          <!-- Needs Attention -->
          <div class="p-3.5 rounded-xl bg-gray-50/70 dark:bg-white/[0.02] border border-[#e8f0ed] dark:border-white/5 flex flex-col justify-between">
            <div>
              <div class="flex items-center justify-between gap-1 mb-1">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Needs Attention</span>
                <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400"></i>
              </div>
              <div class="text-sm font-bold text-gray-900 dark:text-white truncate" id="insight-attention-label">–</div>
            </div>
            <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 line-clamp-2" id="insight-attention-desc">Analyzing...</div>
          </div>

          <!-- Remaining Workload -->
          <div class="p-3.5 rounded-xl bg-gray-50/70 dark:bg-white/[0.02] border border-[#e8f0ed] dark:border-white/5 flex flex-col justify-between">
            <div>
              <div class="flex items-center justify-between gap-1 mb-1">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Workload Remaining</span>
                <i data-lucide="clock" class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400"></i>
              </div>
              <div class="text-sm font-bold text-gray-900 dark:text-white truncate" id="insight-workload-label">–</div>
            </div>
            <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 line-clamp-2" id="insight-workload-desc">Calculating effort...</div>
          </div>

          <!-- Completion Trend -->
          <div class="p-3.5 rounded-xl bg-gray-50/70 dark:bg-white/[0.02] border border-[#e8f0ed] dark:border-white/5 flex flex-col justify-between">
            <div>
              <div class="flex items-center justify-between gap-1 mb-1">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Completion Trend</span>
                <i data-lucide="trending-up" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400"></i>
              </div>
              <div class="text-sm font-bold text-gray-900 dark:text-white truncate" id="insight-trend-label">–</div>
            </div>
            <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 line-clamp-2" id="insight-trend-desc">Checking pace...</div>
          </div>

          <!-- Study Consistency -->
          <div class="p-3.5 rounded-xl bg-gray-50/70 dark:bg-white/[0.02] border border-[#e8f0ed] dark:border-white/5 flex flex-col justify-between">
            <div>
              <div class="flex items-center justify-between gap-1 mb-1">
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Study Consistency</span>
                <i data-lucide="calendar-check" class="w-3.5 h-3.5 text-purple-600 dark:text-purple-400"></i>
              </div>
              <div class="text-sm font-bold text-gray-900 dark:text-white truncate" id="insight-consistency-label">–</div>
            </div>
            <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 line-clamp-2" id="insight-consistency-desc">Evaluating sessions...</div>
          </div>
        </div>
      </section>

      <!-- Main content -->
      <section
        class="grid grid-cols-1 2xl:grid-cols-[minmax(0,1.6fr)_minmax(340px,.9fr)] gap-5 items-start"

      >

        <!-- Overview -->
        <div class="surface rounded-2xl p-5 sm:p-6">

          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">

            <div>

              <div class="flex items-center gap-2">

                <div class="w-9 h-9 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center">
                  <i
                    data-lucide="chart-no-axes-combined"
                    class="w-4 h-4"
                  ></i>
                </div>

                <div>
                  <h3 class="font-bold text-sm">
                    Progress Overview
                  </h3>

                  <p class="text-xs text-gray-400 mt-1">
                    Your progress across different areas of your academic journey.
                  </p>
                </div>

              </div>

            </div>

            <div class="flex items-center p-1 rounded-lg bg-gray-50 dark:bg-white/[.03] border border-gray-100 dark:border-white/5">

              <button
                type="button"
                class="range-button px-3 py-1.5 rounded-md text-[11px] font-semibold text-gray-500 dark:text-gray-300"
                data-range="week"
              >
                Week
              </button>

              <button
                type="button"
                class="range-button px-3 py-1.5 rounded-md text-[11px] font-semibold text-gray-500 dark:text-gray-300"
                data-range="month"
              >
                Month
              </button>

              <button
                type="button"
                class="range-button px-3 py-1.5 rounded-md text-[11px] font-semibold text-gray-500 dark:text-gray-300 active"
                data-range="semester"
              >
                Semester
              </button>

            </div>

          </div>

          <div
            class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_210px] gap-6 items-center"
          >

            <div class="h-[250px] sm:h-[300px]">
              <canvas id="progress-chart"></canvas>
            </div>

            <div class="space-y-4">

              <div class="flex items-center justify-between gap-3">

                <div class="flex items-center gap-2">
                  <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                  <span class="text-xs text-gray-600 dark:text-gray-300">
                    Overall
                  </span>
                </div>

                <strong
                  class="text-sm"
                  id="overall-legend-value"
                >
                  –
                </strong>

              </div>

              <div class="flex items-center justify-between gap-3">

                <div class="flex items-center gap-2">
                  <span class="w-3 h-3 rounded-full bg-blue-500"></span>
                  <span class="text-xs text-gray-600 dark:text-gray-300">
                    Courses
                  </span>
                </div>

                <strong
                  class="text-sm"
                  id="courses-legend-value"
                >
                  –
                </strong>

              </div>

              <div class="flex items-center justify-between gap-3">

                <div class="flex items-center gap-2">
                  <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                  <span class="text-xs text-gray-600 dark:text-gray-300">
                    Tasks
                  </span>
                </div>

                <strong
                  class="text-sm"
                  id="tasks-legend-value"
                >
                  –
                </strong>

              </div>

              <div class="flex items-center justify-between gap-3">

                <div class="flex items-center gap-2">
                  <span class="w-3 h-3 rounded-full bg-purple-500"></span>
                  <span class="text-xs text-gray-600 dark:text-gray-300">
                    Study Sessions
                  </span>
                </div>

                <strong
                  class="text-sm"
                  id="sessions-legend-value"
                >
                  –
                </strong>

              </div>

            </div>

          </div>

          <div
            class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5 pt-5 border-t border-gray-100 dark:border-white/10"
          >

            <div>

              <h4 class="font-semibold text-sm mb-3">
                Tasks
              </h4>

              <div
                class="space-y-2 text-sm"
                id="tasks-legend"
              ></div>

            </div>

            <div>

              <h4 class="font-semibold text-sm mb-3">
                Study Sessions
              </h4>

              <div
                class="space-y-2 text-sm"
                id="sessions-legend"
              ></div>

            </div>

          </div>

        </div>

 <!-- Progress by Course -->
        <div
          class="surface rounded-2xl overflow-hidden min-w-0"
        >

          <!-- Card header -->
          <div
            class="px-5 sm:px-6 py-5 border-b border-gray-100 dark:border-white/10"
          >

            <div
              class="flex items-center justify-between gap-4"
            >

              <div class="flex items-center gap-3 min-w-0">

                <div
                  class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center shrink-0"
                >
                  <i
                    data-lucide="chart-no-axes-combined"
                    class="w-5 h-5"
                  ></i>
                </div>

                <div class="min-w-0">

                  <h3
                    class="font-bold text-sm text-gray-900 dark:text-white"
                  >
                    Progress by Course
                  </h3>

                  <p
                    class="text-xs text-gray-400 dark:text-gray-500 mt-1"
                  >
                    Your progress breakdown by course.
                  </p>

                </div>

              </div>

              <a
                href="courses.php"
                class="text-xs font-semibold text-emerald-700 dark:text-emerald-300 hover:text-emerald-600 whitespace-nowrap"
              >
                View All
              </a>

            </div>

          </div>


          <!-- Course list -->
          <div
            id="course-progress-list"
            class="w-full"
          ></div>


          <!-- Footer -->
          <div
            class="px-5 sm:px-6 py-4 border-t border-gray-100 dark:border-white/10"
          >

            <a
              href="courses.php"
              class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 dark:border-white/10 py-2.5 px-4 text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition"
            >

              View detailed course analytics

              <i
                data-lucide="arrow-right"
                class="w-3.5 h-3.5"
              ></i>

            </a>

          </div>

        </div>

      </section>

      <!-- Secondary information -->
      <section
        class="grid grid-cols-1 lg:grid-cols-2 gap-5"
      >

        <div class="surface rounded-2xl p-5">

          <div class="flex items-center gap-2 mb-4">

            <div class="w-9 h-9 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300 flex items-center justify-center">
              <i
                data-lucide="list-checks"
                class="w-4 h-4"
              ></i>
            </div>

            <div>
              <h3 class="font-bold text-sm">
                Task Progress
              </h3>

              <p class="text-xs text-gray-400 mt-1">
                Breakdown of your current tasks.
              </p>
            </div>

          </div>

          <div
            id="task-progress-summary"
            class="space-y-3"
          >

            <div class="h-2 rounded-full progress-track overflow-hidden">
              <div
                id="task-progress-bar"
                class="h-full rounded-full bg-blue-600"
                style="width:0%"
              ></div>
            </div>

            <div class="grid grid-cols-3 gap-3 text-center">

              <div class="rounded-xl bg-gray-50 dark:bg-white/[.03] p-3">

                <div
                  id="task-completed-count"
                  class="font-bold"
                >
                  –
                </div>

                <div class="text-[10px] text-gray-400 mt-1">
                  Completed
                </div>

              </div>

              <div class="rounded-xl bg-gray-50 dark:bg-white/[.03] p-3">

                <div
                  id="task-pending-count"
                  class="font-bold"
                >
                  –
                </div>

                <div class="text-[10px] text-gray-400 mt-1">
                  Pending
                </div>

              </div>

              <div class="rounded-xl bg-gray-50 dark:bg-white/[.03] p-3">

                <div
                  id="task-overdue-count"
                  class="font-bold"
                >
                  –
                </div>

                <div class="text-[10px] text-gray-400 mt-1">
                  Overdue
                </div>

              </div>

            </div>

          </div>

        </div>

        <div class="surface rounded-2xl p-5">

          <div class="flex items-center gap-2 mb-4">

            <div class="w-9 h-9 rounded-lg bg-purple-50 dark:bg-purple-500/10 text-purple-700 dark:text-purple-300 flex items-center justify-center">
              <i
                data-lucide="graduation-cap"
                class="w-4 h-4"
              ></i>
            </div>

            <div>
              <h3 class="font-bold text-sm">
                Study Session Progress
              </h3>

              <p class="text-xs text-gray-400 mt-1">
                Completed versus scheduled sessions.
              </p>
            </div>

          </div>

          <div class="space-y-3">

            <div class="h-2 rounded-full progress-track overflow-hidden">

              <div
                id="session-progress-bar"
                class="h-full rounded-full bg-purple-600"
                style="width:0%"
              ></div>

            </div>

            <div class="flex items-center justify-between gap-3">

              <div>
                <div
                  id="session-completed-count"
                  class="font-bold"
                >
                  –
                </div>

                <div class="text-[10px] text-gray-400 mt-1">
                  Completed
                </div>
              </div>

              <div class="text-right">

                <div
                  id="session-scheduled-count"
                  class="font-bold"
                >
                  –
                </div>

                <div class="text-[10px] text-gray-400 mt-1">
                  Scheduled
                </div>

              </div>

            </div>

          </div>

        </div>

      </section>

    </div>

  </main>

</div>

<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/work-timer.js"></script>
<script src="../assets/js/progress.js"></script>


<script src="../assets/js/guided-tour.js"></script>
</body>
</html>