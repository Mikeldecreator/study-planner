<?php

require_once __DIR__ . '/../includes/auth.php';

requirePageLogin();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports — Study Planner</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script src="../assets/js/theme-init.js"></script>
<link rel="stylesheet" href="../assets/css/style.css">

<style>
  .reports-page {
    background: #f4f8f7;
  }

  .report-card {
    background: #ffffff;
    border: 1px solid #dfeae7;
    border-radius: 16px;
    box-shadow: 0 3px 12px rgba(18, 67, 57, 0.05);
    overflow: hidden;
  }

  .report-kpi {
    position: relative;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #dfeae7;
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 3px 12px rgba(18, 67, 57, 0.045);
    transition: transform .2s ease, box-shadow .2s ease;
  }

  .report-kpi:hover {
    transform: translateY(-2px);
    box-shadow: 0 9px 20px rgba(18, 67, 57, 0.08);
  }

  html.dark .report-kpi {
    background: #131a18;
    border-color: rgba(255,255,255,.09);
    box-shadow: none;
  }

  .report-ring {
    --ring-progress: 81%;
    width: 148px;
    height: 148px;
    border-radius: 9999px;
    background: conic-gradient(
      #059669 0 var(--ring-progress),
      #f0a51a var(--ring-progress) 96%,
      #ef4444 96% 100%
    );
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .report-ring::before {
    content: '';
    width: 108px;
    height: 108px;
    border-radius: 9999px;
    background: #fff;
    position: absolute;
  }

  .report-ring > div {
    position: relative;
    z-index: 1;
    text-align: center;
  }

  html.dark .report-ring::before {
    background: #131a18;
  }

  .subject-progress {
    height: 7px;
    background: #e8f0ed;
    border-radius: 99px;
    overflow: hidden;
  }

  html.dark .subject-progress {
    background: rgba(255,255,255,.08);
  }

  .subject-progress > span {
    display: block;
    height: 100%;
    border-radius: 99px;
    background: #12a66d;
  }

  .report-table-row:hover {
    background: rgba(5, 150, 105, .035);
  }

  html.dark .report-table-row:hover {
    background: rgba(255,255,255,.03);
  }

  .mini-bar {
    transition: width .7s ease;
  }
</style>
</head>

<body
  data-page="reports"
  class="reports-page dark:bg-[#0B0B0C] text-gray-900 dark:text-gray-100 transition-colors"
>

<div class="flex min-h-screen">

  <div id="sidebar-slot"></div>

  <main class="flex-1 min-w-0">

    <!-- HEADER -->
    <header
      class="bg-white dark:bg-[#131315]
             border-b border-gray-100 dark:border-white/10
             px-4 sm:px-8 py-4
             flex items-center justify-between
             gap-3 relative"
    >

      <div class="flex items-center gap-3 shrink-0">

        <button
          id="hamburger-btn"
          class="lg:hidden
                 text-gray-500 dark:text-gray-300
                 hover:text-gray-900 dark:hover:text-white
                 transition-colors"
          aria-label="Open menu"
        >
          <i data-lucide="menu" class="w-5 h-5"></i>
        </button>

        <div class="flex items-center gap-3">

          <div
            class="w-9 h-9 rounded-lg
                   bg-emerald-50 dark:bg-emerald-500/10
                   flex items-center justify-center"
          >
            <i
              data-lucide="bar-chart-3"
              class="w-5 h-5
                     text-emerald-700
                     dark:text-emerald-400"
            ></i>
          </div>

          <div class="hidden sm:block">

            <h1 class="text-lg sm:text-xl font-semibold">
              Reports
            </h1>

            <p
              class="text-[11px]
                     text-[#6a8580]
                     dark:text-gray-400"
            >
              View detailed analytics and insights about your
              academic performance.
            </p>

          </div>

        </div>
      </div>


      <!-- SEARCH -->
      <div
        class="relative hidden md:block
               flex-1 max-w-md mx-8"
      >

        <i
          data-lucide="search"
          class="w-4 h-4 text-gray-400
                 absolute left-3.5 top-1/2
                 -translate-y-1/2
                 pointer-events-none"
        ></i>

        <input
          type="text"
          placeholder="Search tasks, courses, notes..."
          class="w-full
                 bg-gray-50 dark:bg-white/5
                 border border-gray-200 dark:border-white/10
                 rounded-lg
                 pl-10 pr-4 py-2
                 text-sm
                 text-gray-900 dark:text-white
                 placeholder:text-gray-400
                 dark:placeholder:text-gray-500
                 focus:outline-none
                 focus:ring-2
                 focus:ring-emerald-500/20"
        >

      </div>


      <!-- ACTIONS -->
      <div class="flex items-center gap-4 sm:gap-5">

        <a
          href="schedule.php"
          class="hidden sm:flex
                 text-gray-500 dark:text-gray-300
                 hover:text-gray-900 dark:hover:text-white
                 transition-colors"
          aria-label="View calendar"
        >
          <i data-lucide="calendar" class="w-5 h-5"></i>
        </a>

        <button
          id="bell-btn"
          class="relative
                 text-gray-500 dark:text-gray-300
                 hover:text-gray-900 dark:hover:text-white
                 transition-colors"
          aria-label="Notifications"
        >

          <i data-lucide="bell" class="w-5 h-5"></i>

          <span
            id="bell-badge"
            class="hidden
                   absolute -top-1 -right-1
                   bg-red-500 text-white
                   text-[10px] font-bold
                   rounded-full
                   w-4 h-4
                   items-center
                   justify-center"
          ></span>

        </button>

        <a
          href="settings.php"
          class="hidden sm:flex
                 items-center gap-2 group"
          aria-label="Account settings"
        >

          <div
            class="w-8 h-8 rounded-full
                   bg-green-800
                   text-white
                   text-xs
                   font-semibold
                   flex items-center justify-center"
            data-user-initial
          >
            U
          </div>

          <span
            class="text-sm font-medium
                   group-hover:text-green-800
                   dark:group-hover:text-green-400
                   transition-colors"
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


      <!-- NOTIFICATIONS -->
      <div
        id="bell-dropdown"
        class="hidden
               absolute
               right-4 sm:right-8
               top-16
               w-80
               max-w-[90vw]
               bg-white dark:bg-[#1A1A1D]
               border
               border-gray-100
               dark:border-white/10
               rounded-xl
               shadow-lg
               z-30
               max-h-96
               overflow-y-auto"
      ></div>

    </header>


    <!-- PAGE CONTENT -->
    <div
      class="p-4 sm:p-6 lg:p-8
             max-w-[1550px]
             mx-auto
             space-y-5"
    >


      <!-- PAGE TITLE -->
      <section
        class="flex flex-col
               sm:flex-row
               sm:items-end
               justify-between
               gap-4"
      >

        <div>

          <div
            class="text-[11px]
                   font-bold
                   uppercase
                   tracking-[0.16em]
                   text-emerald-700
                   dark:text-emerald-400
                   mb-1"
          >
            Academic analytics
          </div>

          <h2
            class="text-2xl sm:text-3xl
                   font-bold
                   tracking-tight
                   text-[#123b33]
                   dark:text-white"
          >
            Academic Performance Report
          </h2>

          <p
            class="text-sm
                   text-[#68827c]
                   dark:text-gray-400
                   mt-1"
            id="range-label"
          >
            Loading…
          </p>

        </div>


        <!-- RANGE -->
        <div class="relative shrink-0">

          <i
            data-lucide="calendar-range"
            class="w-4 h-4
                   text-gray-400
                   absolute left-3
                   top-1/2
                   -translate-y-1/2
                   pointer-events-none"
          ></i>

          <select
            id="range-select"
            class="appearance-none
                   min-w-[165px]
                   bg-white dark:bg-[#131A18]
                   border border-[#dfeae7]
                   dark:border-white/10
                   rounded-lg
                   pl-9 pr-9 py-2.5
                   text-sm
                   font-medium
                   focus:outline-none
                   focus:ring-2
                   focus:ring-emerald-500/20"
          >
            <option value="week" selected>
              This Week
            </option>

            <option value="month">
              This Month
            </option>

            <option value="semester">
              This Semester
            </option>

          </select>

          <i
            data-lucide="chevron-down"
            class="w-4 h-4
                   text-gray-400
                   absolute right-3
                   top-1/2
                   -translate-y-1/2
                   pointer-events-none"
          ></i>

        </div>

      </section>


      <!-- ======================================================
           KPI CARDS
      ======================================================= -->

      <section
        id="report-stat-rows"
        class="grid
               grid-cols-2
               md:grid-cols-3
               xl:grid-cols-5
               gap-3
               sm:gap-4"
      ></section>


      <!-- ======================================================
           PERFORMANCE TREND + TASK STATUS
      ======================================================= -->

      <section
        class="grid
               grid-cols-1
               xl:grid-cols-[minmax(0,1fr)_390px]
               gap-4"
      >

        <!-- Academic Performance Trend -->
        <div
          class="report-card
                 dark:bg-[#131A18]
                 dark:border-white/10"
        >

          <div
            class="px-5 sm:px-6 py-4
                   border-b
                   border-[#e8f0ed]
                   dark:border-white/10
                   flex items-center
                   justify-between
                   gap-4"
          >

            <div>

              <h3
                class="font-bold
                       text-lg
                       text-[#123b33]
                       dark:text-white
                       flex
                       items-center
                       gap-2"
              >

                <i
                  data-lucide="trending-up"
                  class="w-5 h-5
                         text-emerald-700
                         dark:text-emerald-400"
                ></i>

                Academic Performance Trend

              </h3>

              <p
                class="text-xs
                       text-[#6a8580]
                       dark:text-gray-400
                       mt-1"
              >
                Your performance progression across the
                current reporting period.
              </p>

            </div>

            <span
              class="hidden sm:inline-flex
                     items-center
                     gap-1.5
                     text-[11px]
                     font-semibold
                     px-2.5 py-1.5
                     rounded-full
                     bg-emerald-50
                     dark:bg-emerald-950/30
                     text-emerald-700
                     dark:text-emerald-300"
            >
              Last 7 days
            </span>

          </div>

          <div class="p-4 sm:p-5">

            <div
              class="relative
                     h-[255px]
                     sm:h-[275px]"
            >
              <canvas
                id="performance-trend-chart"
              ></canvas>
            </div>

            <div
              id="performance-trend-legend"
              class="mt-3
                     flex
                     flex-wrap
                     gap-x-5
                     gap-y-2
                     text-xs"
            ></div>

          </div>

        </div>


        <!-- Task Status -->
        <div
          class="report-card
                 dark:bg-[#131A18]
                 dark:border-white/10"
        >

          <div
            class="px-5 sm:px-6 py-4
                   border-b
                   border-[#e8f0ed]
                   dark:border-white/10"
          >

            <h3
              class="font-bold
                     text-lg
                     text-[#123b33]
                     dark:text-white
                     flex
                     items-center
                     gap-2"
            >

              <i
                data-lucide="pie-chart"
                class="w-5 h-5
                       text-emerald-700
                       dark:text-emerald-400"
              ></i>

              Task Status

            </h3>

            <p
              class="text-xs
                     text-[#6a8580]
                     dark:text-gray-400
                     mt-1"
            >
              Current distribution of your tasks.
            </p>

          </div>


          <div class="p-5 sm:p-6">

            <div
              class="flex flex-col
                     sm:flex-row
                     xl:flex-col
                     2xl:flex-row
                     items-center
                     gap-6"
            >

              <div
                class="relative
                       w-40 h-40
                       shrink-0"
              >

                <canvas
                  id="task-status-chart"
                ></canvas>

                <div
                  class="absolute
                         inset-0
                         flex
                         flex-col
                         items-center
                         justify-center
                         pointer-events-none"
                >

                  <div
                    id="task-status-total"
                    class="text-2xl
                           font-bold
                           text-[#113d34]
                           dark:text-white"
                  >
                    –
                  </div>

                  <div
                    class="text-[10px]
                           text-[#6a8580]
                           dark:text-gray-400"
                  >
                    Total Tasks
                  </div>

                </div>

              </div>

              <div
                id="task-status-legend"
                class="w-full
                       space-y-3
                       text-xs"
              ></div>

            </div>

          </div>

        </div>

      </section>


      <!-- ======================================================
           WEEKLY STUDY TIME + SUBJECT BREAKDOWN
      ======================================================= -->

      <section
        class="grid
               grid-cols-1
               lg:grid-cols-2
               gap-4"
      >

        <!-- Weekly Study Time -->
        <div
          class="report-card
                 dark:bg-[#131A18]
                 dark:border-white/10
                 p-5 sm:p-6"
        >

          <div
            class="flex
                   items-center
                   justify-between
                   gap-4
                   mb-5"
          >

            <div>

              <h3
                class="font-bold
                       text-lg
                       text-[#123b33]
                       dark:text-white
                       flex
                       items-center
                       gap-2"
              >

                <i
                  data-lucide="clock-3"
                  class="w-5 h-5
                         text-emerald-700
                         dark:text-emerald-400"
                ></i>

                Weekly Study Time

              </h3>

              <p
                class="text-xs
                       text-[#6a8580]
                       dark:text-gray-400
                       mt-1"
              >
                Hours studied across the week.
              </p>

            </div>

            <div class="text-right">

              <div
                id="weekly-study-total"
                class="text-lg
                       font-bold
                       text-[#123b33]
                       dark:text-white"
              >
                0h
              </div>

              <div
                class="text-[10px]
                       text-[#6a8580]
                       dark:text-gray-400"
              >
                Total study time
              </div>

            </div>

          </div>

          <div
            id="weekly-study-chart"
            class="h-[245px]"
          ></div>

        </div>


        <!-- Subject Breakdown -->
        <div
          class="report-card
                 dark:bg-[#131A18]
                 dark:border-white/10
                 p-5 sm:p-6"
        >

          <div
            class="flex
                   items-center
                   justify-between
                   gap-4
                   mb-5"
          >

            <div>

              <h3
                class="font-bold
                       text-lg
                       text-[#123b33]
                       dark:text-white
                       flex
                       items-center
                       gap-2"
              >

                <i
                  data-lucide="pie-chart"
                  class="w-5 h-5
                         text-violet-600
                         dark:text-violet-400"
                ></i>

                Subject Breakdown

              </h3>

              <p
                class="text-xs
                       text-[#6a8580]
                       dark:text-gray-400
                       mt-1"
              >
                Course progress by subject.
              </p>

            </div>

            <span
              id="subject-count"
              class="text-xs
                     text-[#6a8580]
                     dark:text-gray-400"
            >
              0 subjects
            </span>

          </div>

          <div
            id="subject-breakdown"
            class="space-y-4"
          ></div>

        </div>

      </section>


      <!-- ======================================================
           COURSE PERFORMANCE + INSIGHTS
      ======================================================= -->

      <section
        class="grid
               grid-cols-1
               xl:grid-cols-[minmax(0,1fr)_380px]
               gap-4"
      >

        <!-- Course Performance -->
        <div
          class="report-card
                 dark:bg-[#131A18]
                 dark:border-white/10
                 overflow-hidden"
        >

          <div
            class="px-5 sm:px-6 py-4
                   border-b
                   border-[#e8f0ed]
                   dark:border-white/10
                   flex items-center
                   justify-between
                   gap-3"
          >

            <div>

              <h3
                class="font-bold
                       text-lg
                       text-[#123b33]
                       dark:text-white
                       flex
                       items-center
                       gap-2"
              >

                <i
                  data-lucide="book-open-check"
                  class="w-5 h-5
                         text-emerald-700
                         dark:text-emerald-400"
                ></i>

                Course Performance

              </h3>

              <p
                class="text-xs
                       text-[#6a8580]
                       dark:text-gray-400
                       mt-1"
              >
                Progress across your registered courses.
              </p>

            </div>

            <a
              href="courses.php"
              class="text-xs
                     font-semibold
                     text-emerald-700
                     dark:text-emerald-400"
            >
              View All
            </a>

          </div>

          <div
            id="course-performance"
            class="p-5 sm:p-6 space-y-4"
          ></div>

        </div>


        <!-- Insights -->
        <div
          class="report-card
                 dark:bg-[#131A18]
                 dark:border-white/10
                 overflow-hidden"
        >

          <div
            class="px-5 sm:px-6 py-4
                   border-b
                   border-[#e8f0ed]
                   dark:border-white/10"
          >

            <h3
              class="font-bold
                     text-lg
                     text-[#123b33]
                     dark:text-white
                     flex
                     items-center
                     gap-2"
            >

              <i
                data-lucide="lightbulb"
                class="w-5 h-5
                       text-amber-600
                       dark:text-amber-400"
              ></i>

              Insights & Recommendations

            </h3>

            <p
              class="text-xs
                     text-[#6a8580]
                     dark:text-gray-400
                     mt-1"
            >
              Based on your current report data.
            </p>

          </div>


          <div class="p-5 sm:p-6">

            <div
              id="performance-insights"
              class="space-y-2.5"
            ></div>


            <div
              class="mt-5
                     pt-5
                     border-t
                     border-[#e8f0ed]
                     dark:border-white/10"
            >

              <div class="flex items-start gap-3">

                <div
                  class="w-9 h-9
                         shrink-0
                         rounded-lg
                         bg-emerald-50
                         dark:bg-emerald-950/30
                         flex items-center
                         justify-center"
                >

                  <i
                    data-lucide="target"
                    class="w-4 h-4
                           text-emerald-700
                           dark:text-emerald-400"
                  ></i>

                </div>

                <div>

                  <div
                    class="text-[11px]
                           uppercase
                           tracking-wider
                           font-bold
                           text-[#6b8580]
                           dark:text-gray-400"
                  >
                    Suggested action
                  </div>

                  <div
                    id="suggested-action"
                    class="text-sm
                           leading-6
                           text-[#294941]
                           dark:text-gray-200
                           mt-1"
                  >
                    –
                  </div>

                </div>

              </div>

            </div>

          </div>

        </div>

      </section>


      <!-- ======================================================
           RECENT REPORTS
      ======================================================= -->

      <section
        class="report-card
               dark:bg-[#131A18]
               dark:border-white/10
               overflow-hidden"
      >

        <div
          class="px-5 sm:px-6 py-4
                 border-b
                 border-[#e8f0ed]
                 dark:border-white/10
                 flex
                 items-center
                 justify-between
                 gap-4"
        >

          <div>

            <h3
              class="font-bold
                     text-lg
                     text-[#123b33]
                     dark:text-white
                     flex
                     items-center
                     gap-2"
            >

              <i
                data-lucide="file-bar-chart-2"
                class="w-5 h-5
                       text-violet-600
                       dark:text-violet-400"
              ></i>

              Recent Reports

            </h3>

            <p
              class="text-xs
                     text-[#6a8580]
                     dark:text-gray-400
                     mt-1"
            >
              Your available report views for the current period.
            </p>

          </div>

          <span
            class="hidden sm:inline-block
                   text-xs
                   font-semibold
                   text-emerald-700
                   dark:text-emerald-400"
          >
            View All
          </span>

        </div>


        <div class="overflow-x-auto">

          <table class="w-full min-w-[760px]">

            <thead>

              <tr
                class="text-[10px]
                       uppercase
                       tracking-[0.12em]
                       text-[#668078]
                       dark:text-gray-400
                       bg-[#fbfdfc]
                       dark:bg-white/[0.015]"
              >

                <th
                  class="text-left
                         px-5 sm:px-6
                         py-3
                         font-bold"
                >
                  Report Name
                </th>

                <th
                  class="text-left
                         px-5 sm:px-6
                         py-3
                         font-bold"
                >
                  Period
                </th>

                <th
                  class="text-left
                         px-5 sm:px-6
                         py-3
                         font-bold"
                >
                  Generated
                </th>

                <th
                  class="text-right
                         px-5 sm:px-6
                         py-3
                         font-bold"
                >
                  Actions
                </th>

              </tr>

            </thead>

            <tbody
              id="recent-reports-body"
            ></tbody>

          </table>

        </div>

      </section>

    </div>

  </main>

</div>


<!-- Existing application scripts -->
<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/work-timer.js"></script>
<script src="../assets/js/reports.js"></script>

</body>
</html>