<?php

require_once __DIR__ . '/../includes/auth.php';

requirePageLogin();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notifications — Study Planner</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>

<link rel="stylesheet" href="../assets/css/style.css">
</head>

<body
  data-page="notifications"
  class="app-page bg-[#F4F8F7] dark:bg-[#0B0B0C] text-gray-900 dark:text-gray-100 transition-colors"
>

<div class="flex min-h-screen">

  <div id="sidebar-slot"></div>

  <main class="flex-1 min-w-0">

    <!-- =========================================================
         TOP BAR
    ========================================================== -->
    <header
      class="app-topbar bg-white dark:bg-[#131A18] border-b border-[#E4EEEB] dark:border-white/10 px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between relative gap-3"
    >

      <div class="flex items-center gap-3 min-w-0">

        <button
          id="hamburger-btn"
          class="lg:hidden text-gray-500 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition-colors"
          aria-label="Open menu"
          type="button"
        >
          <i data-lucide="menu" class="w-5 h-5"></i>
        </button>

        <div class="min-w-0">
          <h1 class="text-lg sm:text-xl font-bold truncate">
            Notifications
          </h1>

          <p class="hidden sm:block text-xs text-[#6A8881] dark:text-gray-400 mt-0.5">
            Stay updated with your academic progress and important events
          </p>
        </div>

      </div>


      <!-- Search -->
      <div class="relative hidden md:block flex-1 max-w-md mx-4 lg:mx-8">

        <i
          data-lucide="search"
          class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"
        ></i>

        <input
          id="notification-search"
          type="search"
          placeholder="Search notifications..."
          aria-label="Search notifications"
          autocomplete="off"
          class="filter-control pl-10 pr-4 h-11 rounded-xl bg-[#F8FBFA] dark:bg-white/[0.04]"
        >

      </div>


      <!-- Top-right actions -->
      <div class="flex items-center gap-3 sm:gap-4 shrink-0">

        <button
          id="bell-btn"
          type="button"
          class="relative text-[#1B5549] dark:text-gray-200 hover:text-emerald-700 dark:hover:text-emerald-400 transition-colors"
          aria-label="Notifications"
          aria-expanded="false"
        >
          <i data-lucide="bell" class="w-5 h-5"></i>

          <span
            id="bell-badge"
            class="hidden absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 items-center justify-center"
          ></span>
        </button>


        <a
          href="schedule.php"
          class="hidden sm:flex text-[#1B5549] dark:text-gray-200 hover:text-emerald-700 dark:hover:text-emerald-400 transition-colors"
          aria-label="View calendar"
        >
          <i data-lucide="calendar-days" class="w-5 h-5"></i>
        </a>


        <a
          href="settings.php"
          class="hidden sm:flex items-center gap-2 pl-3 border-l border-[#E4EEEB] dark:border-white/10 group"
          aria-label="Account settings"
        >

          <span
            class="text-sm font-semibold"
            data-user-name
          >
            Loading…
          </span>

          <i
            data-lucide="chevron-down"
            class="w-4 h-4 text-gray-400"
          ></i>

          <div
            class="w-9 h-9 rounded-full bg-emerald-700 text-white flex items-center justify-center font-bold text-xs"
            data-top-avatar
            data-user-initial
          >
            U
          </div>

        </a>

      </div>


      <!-- Bell dropdown -->
      <div
        id="bell-dropdown"
        class="hidden absolute right-4 sm:right-6 top-[72px] w-80 max-w-[90vw] bg-white dark:bg-[#171D1B] border border-[#E0EBE8] dark:border-white/10 rounded-xl shadow-xl z-30 max-h-96 overflow-y-auto"
      ></div>

    </header>


    <!-- =========================================================
         PAGE CONTENT
    ========================================================== -->
    <div class="p-4 sm:p-6 lg:p-7 space-y-5 max-w-[1600px] mx-auto">


      <!-- =======================================================
           HERO
      ======================================================== -->
      <section
        class="relative overflow-hidden rounded-2xl border border-emerald-100 dark:border-emerald-900/40 bg-gradient-to-r from-[#E2F7EF] via-[#EEF9F5] to-[#DFF3E7] dark:from-[#122D24] dark:via-[#14251F] dark:to-[#12271F] px-5 sm:px-8 py-5 sm:py-6"
      >

        <div
          class="absolute -right-20 -top-24 w-80 h-56 rounded-full bg-white/25 dark:bg-white/[0.04] pointer-events-none"
        ></div>

        <div
          class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-5"
        >

          <div class="flex items-center gap-4 sm:gap-5 min-w-0">

            <div
              class="w-16 h-16 sm:w-[76px] sm:h-[76px] rounded-full bg-[#D4F1E5] dark:bg-emerald-900/50 text-[#075F49] dark:text-emerald-300 flex items-center justify-center shrink-0 border border-white/80 dark:border-white/10"
            >
              <i
                data-lucide="bell"
                class="w-8 h-8 sm:w-9 sm:h-9"
              ></i>
            </div>

            <div class="min-w-0">

              <h2 class="text-2xl sm:text-3xl font-bold">
                Notifications
              </h2>

              <p
                id="notification-summary"
                class="text-sm sm:text-base text-[#42675F] dark:text-gray-400 mt-1"
              >
                Loading your notifications…
              </p>

            </div>

          </div>


          <button
            id="mark-all-read"
            type="button"
            class="btn-press inline-flex items-center justify-center gap-2 rounded-xl border border-emerald-300 dark:border-emerald-700/60 bg-white/60 dark:bg-emerald-950/20 px-4 py-2.5 text-sm font-semibold text-emerald-800 dark:text-emerald-300 hover:bg-white dark:hover:bg-emerald-950/40 transition-colors shrink-0"
          >
            <i data-lucide="check-check" class="w-4 h-4"></i>
            Mark all as read
          </button>

        </div>

      </section>


      <!-- =======================================================
           NOTIFICATIONS + SIDEBAR
      ======================================================== -->
      <section
        class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_355px] gap-4"
      >


        <!-- =====================================================
             NOTIFICATION LIST
        ====================================================== -->
        <div
          class="bg-white dark:bg-[#131A18] rounded-xl border border-[#E0EBE8] dark:border-white/10 shadow-sm overflow-hidden"
        >

          <!-- Tabs -->
          <div
            class="border-b border-[#E7EFED] dark:border-white/10 overflow-x-auto"
          >

            <div
              id="notification-tabs"
              class="flex items-center min-w-max px-3 sm:px-4"
            >

              <button
                type="button"
                data-notification-tab="all"
                class="notification-tab is-active px-4 py-4 text-sm font-semibold border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400"
              >
                All

                <span
                  data-tab-count="all"
                  class="ml-1.5 inline-flex min-w-6 h-6 px-1.5 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-[11px]"
                >
                  0
                </span>
              </button>


              <button
                type="button"
                data-notification-tab="unread"
                class="notification-tab px-4 py-4 text-sm font-medium border-b-2 border-transparent text-[#55756D] dark:text-gray-400 hover:text-emerald-700 dark:hover:text-emerald-400"
              >
                Unread

                <span
                  data-tab-count="unread"
                  class="ml-1.5 inline-flex min-w-6 h-6 px-1.5 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10 text-[11px]"
                >
                  0
                </span>
              </button>


              <button
                type="button"
                data-notification-tab="system"
                class="notification-tab px-4 py-4 text-sm font-medium border-b-2 border-transparent text-[#55756D] dark:text-gray-400 hover:text-emerald-700 dark:hover:text-emerald-400"
              >
                System

                <span
                  data-tab-count="system"
                  class="ml-1.5 inline-flex min-w-6 h-6 px-1.5 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10 text-[11px]"
                >
                  0
                </span>
              </button>


              <button
                type="button"
                data-notification-tab="academic"
                class="notification-tab px-4 py-4 text-sm font-medium border-b-2 border-transparent text-[#55756D] dark:text-gray-400 hover:text-emerald-700 dark:hover:text-emerald-400"
              >
                Academic

                <span
                  data-tab-count="academic"
                  class="ml-1.5 inline-flex min-w-6 h-6 px-1.5 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10 text-[11px]"
                >
                  0
                </span>
              </button>


              <button
                type="button"
                data-notification-tab="reminders"
                class="notification-tab px-4 py-4 text-sm font-medium border-b-2 border-transparent text-[#55756D] dark:text-gray-400 hover:text-emerald-700 dark:hover:text-emerald-400"
              >
                Reminders

                <span
                  data-tab-count="reminders"
                  class="ml-1.5 inline-flex min-w-6 h-6 px-1.5 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10 text-[11px]"
                >
                  0
                </span>
              </button>

            </div>

          </div>


          <!-- Loading state -->
          <div
            id="notification-loading"
            class="hidden px-5 py-6 space-y-3"
            aria-hidden="true"
          >
            <div class="skeleton h-20 w-full"></div>
            <div class="skeleton h-20 w-full"></div>
            <div class="skeleton h-20 w-full"></div>
          </div>


          <!-- IMPORTANT:
               This ID is consumed by workload.js -->
          <div
            id="notification-feed"
            class="divide-y divide-[#E7EFED] dark:divide-white/10"
          ></div>

        </div>


        <!-- =====================================================
             RIGHT SIDEBAR
        ====================================================== -->
        <aside class="space-y-4">


          <!-- Filters -->
          <div
            class="bg-white dark:bg-[#131A18] rounded-xl border border-[#E0EBE8] dark:border-white/10 shadow-sm p-5"
          >

            <div
              class="flex items-center gap-3 pb-4 border-b border-[#E7EFED] dark:border-white/10"
            >

              <div
                class="w-9 h-9 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-300 flex items-center justify-center"
              >
                <i data-lucide="list-filter" class="w-5 h-5"></i>
              </div>

              <h3 class="font-bold text-base">
                Filter Notifications
              </h3>

            </div>


            <div class="pt-3 space-y-1">

              <label
                class="flex items-center justify-between gap-3 px-1 py-2.5 cursor-pointer rounded-lg hover:bg-emerald-50/50 dark:hover:bg-white/[0.03]"
              >
                <span class="flex items-center gap-2.5 text-sm">
                  <input
                    type="checkbox"
                    data-notification-filter="all"
                    checked
                    class="accent-emerald-600 w-4 h-4"
                  >
                  All Notifications
                </span>

                <span
                  data-filter-count="all"
                  class="text-xs text-[#58766F] dark:text-gray-400"
                >
                  0
                </span>
              </label>


              <label
                class="flex items-center justify-between gap-3 px-1 py-2.5 cursor-pointer rounded-lg hover:bg-emerald-50/50 dark:hover:bg-white/[0.03]"
              >
                <span class="flex items-center gap-2.5 text-sm">
                  <input
                    type="checkbox"
                    data-notification-filter="academic"
                    class="accent-emerald-600 w-4 h-4"
                  >
                  Academic
                </span>

                <span
                  data-filter-count="academic"
                  class="text-xs text-[#58766F] dark:text-gray-400"
                >
                  0
                </span>
              </label>


              <label
                class="flex items-center justify-between gap-3 px-1 py-2.5 cursor-pointer rounded-lg hover:bg-emerald-50/50 dark:hover:bg-white/[0.03]"
              >
                <span class="flex items-center gap-2.5 text-sm">
                  <input
                    type="checkbox"
                    data-notification-filter="reminders"
                    class="accent-emerald-600 w-4 h-4"
                  >
                  Reminders
                </span>

                <span
                  data-filter-count="reminders"
                  class="text-xs text-[#58766F] dark:text-gray-400"
                >
                  0
                </span>
              </label>


              <label
                class="flex items-center justify-between gap-3 px-1 py-2.5 cursor-pointer rounded-lg hover:bg-emerald-50/50 dark:hover:bg-white/[0.03]"
              >
                <span class="flex items-center gap-2.5 text-sm">
                  <input
                    type="checkbox"
                    data-notification-filter="system"
                    class="accent-emerald-600 w-4 h-4"
                  >
                  System
                </span>

                <span
                  data-filter-count="system"
                  class="text-xs text-[#58766F] dark:text-gray-400"
                >
                  0
                </span>
              </label>


              <label
                class="flex items-center justify-between gap-3 px-1 py-2.5 cursor-pointer rounded-lg hover:bg-emerald-50/50 dark:hover:bg-white/[0.03]"
              >
                <span class="flex items-center gap-2.5 text-sm">
                  <input
                    type="checkbox"
                    data-notification-filter="updates"
                    class="accent-emerald-600 w-4 h-4"
                  >
                  Updates
                </span>

                <span
                  data-filter-count="updates"
                  class="text-xs text-[#58766F] dark:text-gray-400"
                >
                  0
                </span>
              </label>

            </div>

          </div>


          <!-- Quick stats -->
          <div
            class="bg-white dark:bg-[#131A18] rounded-xl border border-[#E0EBE8] dark:border-white/10 shadow-sm p-5"
          >

            <div class="flex items-center gap-2 mb-4">

              <div
                class="w-9 h-9 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-300 flex items-center justify-center"
              >
                <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
              </div>

              <h3 class="font-bold text-base">
                Quick Stats
              </h3>

            </div>


            <div class="grid grid-cols-2 gap-3">

              <div
                class="rounded-xl bg-emerald-50 dark:bg-emerald-950/25 border border-emerald-100 dark:border-emerald-900/30 p-4"
              >
                <div
                  id="stat-total"
                  class="text-2xl font-bold text-emerald-700 dark:text-emerald-300"
                >
                  0
                </div>

                <div class="text-xs text-[#55756D] dark:text-gray-400 mt-1">
                  Total
                </div>
              </div>


              <div
                class="rounded-xl bg-red-50 dark:bg-red-950/20 border border-red-100 dark:border-red-900/30 p-4"
              >
                <div
                  id="stat-unread"
                  class="text-2xl font-bold text-red-600 dark:text-red-300"
                >
                  0
                </div>

                <div class="text-xs text-[#55756D] dark:text-gray-400 mt-1">
                  Unread
                </div>
              </div>


              <div
                class="rounded-xl bg-blue-50 dark:bg-blue-950/20 border border-blue-100 dark:border-blue-900/30 p-4"
              >
                <div
                  id="stat-academic"
                  class="text-2xl font-bold text-blue-700 dark:text-blue-300"
                >
                  0
                </div>

                <div class="text-xs text-[#55756D] dark:text-gray-400 mt-1">
                  Academic
                </div>
              </div>


              <div
                class="rounded-xl bg-orange-50 dark:bg-orange-950/20 border border-orange-100 dark:border-orange-900/30 p-4"
              >
                <div
                  id="stat-reminders"
                  class="text-2xl font-bold text-orange-600 dark:text-orange-300"
                >
                  0
                </div>

                <div class="text-xs text-[#55756D] dark:text-gray-400 mt-1">
                  Reminders
                </div>
              </div>

            </div>

          </div>


          <!-- Tip -->
          <div
            id="notification-tip"
            class="bg-gradient-to-br from-[#EEF9F5] to-[#F8FCFA] dark:from-[#142A22] dark:to-[#111B18] rounded-xl border border-emerald-100 dark:border-emerald-900/30 p-5"
          >

            <div class="flex items-start gap-3">

              <div
                class="w-10 h-10 rounded-full bg-[#D4F1E5] dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 flex items-center justify-center shrink-0"
              >
                <i data-lucide="lightbulb" class="w-5 h-5"></i>
              </div>

              <div>

                <h3 class="font-bold text-sm">
                  Stay on track!
                </h3>

                <p
                  class="text-xs text-[#58766F] dark:text-gray-400 leading-5 mt-1"
                >
                  Notifications help you stay informed about your academic
                  progress and important dates.
                </p>

              </div>

            </div>

          </div>

        </aside>

      </section>


      <!-- =======================================================
           EXISTING WORKLOAD FUNCTIONALITY
      ======================================================== -->
      <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">


        <!-- Weekly workload -->
        <div
          class="bg-white dark:bg-[#131A18] rounded-xl border border-[#E0EBE8] dark:border-white/10 shadow-sm p-5"
        >

          <div class="flex items-center justify-between mb-2">

            <div>

              <h3 class="font-bold text-sm flex items-center gap-2">
                <i
                  data-lucide="clock-3"
                  class="w-5 h-5 text-emerald-700 dark:text-emerald-400"
                ></i>
                Weekly Workload
              </h3>

              <p class="text-xs text-[#66837D] dark:text-gray-400 mt-1">
                Total estimated academic time
              </p>

            </div>

          </div>


          <div class="flex items-baseline gap-2 mb-5">

            <span
              class="text-4xl font-bold"
              id="total-hours"
            >
              –
            </span>

            <span
              class="text-xs text-[#78918B] dark:text-gray-500 tracking-wide"
            >
              HOURS
            </span>

          </div>


          <!-- Required by workload.js -->
          <div
            class="space-y-4"
            id="workload-buckets"
          ></div>

        </div>


        <!-- Workload status -->
        <div
          class="bg-white dark:bg-[#131A18] rounded-xl border border-[#E0EBE8] dark:border-white/10 shadow-sm p-5"
        >

          <h3 class="font-bold text-sm flex items-center gap-2 mb-2">

            <i
              data-lucide="activity"
              class="w-5 h-5 text-emerald-700 dark:text-emerald-400"
            ></i>

            Workload Status

          </h3>

          <p class="text-xs text-[#66837D] dark:text-gray-400 mb-2">
            Your workload by weekday.
          </p>


          <!-- Required by workload.js -->
          <div
            class="divide-y divide-[#E7EFED] dark:divide-white/10"
            id="weekday-status-list"
          ></div>

        </div>

      </section>

    </div>

  </main>

</div>


<!-- =============================================================
     SCRIPT ORDER IS IMPORTANT

     workload.js continues to own:
     - notification feed loading
     - mark-all-read API action
     - workload API loading

     notifications.js sits on top of that and only manages the
     redesigned notification UI/state/filtering/bell presentation.
============================================================= -->

<script src="../assets/js/nav.js"></script>
<script src="../assets/js/workload.js"></script>
<script src="../assets/js/notifications.js"></script>

</body>
</html>