<?php

require_once __DIR__ . '/../includes/auth.php';

requirePageLogin();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Home — Study Planner</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="../assets/js/theme-init.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>
        /* =====================================================
           DASHBOARD TEMPLATE STYLING
        ===================================================== */

        :root {
            --sp-green: #008f52;
            --sp-green-dark: #006f43;
            --sp-green-light: #eafaf3;
            --sp-text: #073b35;
            --sp-muted: #5f7890;
        }

        body.dashboard-page {
            background: #f4f8f7;
        }

        .dashboard-main {
            min-width: 0;
        }

        .dashboard-topbar {
            min-height: 88px;
            backdrop-filter: blur(14px);
        }

        .dashboard-search {
            height: 48px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #d9e5e5;
            color: #073b35;
            transition: .2s ease;
        }

        .dashboard-search:focus {
            border-color: #10a866;
            box-shadow: 0 0 0 3px rgba(16, 168, 102, .10);
        }

        .dashboard-hero {
            position: relative;
            overflow: hidden;
            min-height: 164px;
            background:
                radial-gradient(
                    circle at 88% 55%,
                    rgba(255,255,255,.70),
                    transparent 30%
                ),
                linear-gradient(
                    135deg,
                    #e8faf2 0%,
                    #effcf7 50%,
                    #e4f8ef 100%
                );
        }

        .dashboard-hero::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 180px;
            right: -90px;
            bottom: -110px;
            border-radius: 50%;
            background: rgba(22, 166, 103, .09);
        }

        .dashboard-hero::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 120px;
            right: 120px;
            bottom: -80px;
            border-radius: 50%;
            background: rgba(22, 166, 103, .07);
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-title {
            color: #073b35;
            letter-spacing: -.02em;
        }

        .hero-subtitle {
            color: #4c6b7e;
        }

        .hero-pill {
            min-width: 215px;
            padding: 11px 16px;
            border-radius: 16px;
            background: rgba(255,255,255,.82);
            border: 1px solid rgba(255,255,255,.95);
            box-shadow: 0 8px 24px rgba(16, 89, 68, .05);
        }

        .hero-pill-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #e3f8ed;
            color: #07894e;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .hero-quote {
            color: #07553e;
            font-size: 18px;
            line-height: 1.35;
            font-weight: 700;
        }

        .hero-quote-mark {
            color: #72b99d;
        }

        .hero-illustration {
            width: 235px;
            max-width: 23vw;
            opacity: .95;
            pointer-events: none;
        }

        .dashboard-card {
            background: #ffffff;
            border: 1px solid #e2ebeb;
            border-radius: 14px;
            box-shadow: 0 5px 18px rgba(17, 71, 58, .045);
        }

        .dashboard-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 12px;
            margin-bottom: 4px;
            border-bottom: 1px solid #edf2f2;
        }

        .dashboard-card-title {
            color: #073b35;
            font-weight: 700;
        }

        .dashboard-view-link {
            color: #008f52;
            font-size: 12px;
            font-weight: 600;
        }

        .dashboard-view-link:hover {
            color: #006f43;
        }

        .stat-card {
            min-height: 114px;
            transition:
                transform .2s ease,
                box-shadow .2s ease,
                border-color .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(17, 71, 58, .08);
            border-color: #d3e7df;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-value {
            color: #073b35;
            font-size: 28px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -.03em;
        }

        .stat-label {
            color: #4e7187;
            margin-top: 7px;
            font-size: 13px;
        }

        .stat-trend {
            margin-top: 6px;
            font-size: 11px;
            color: #00995a;
        }

        .schedule-row,
        .deadline-row,
        .activity-row {
            border-bottom: 1px solid #edf2f2;
            padding-bottom: 12px;
        }

        .schedule-row:last-child,
        .deadline-row:last-child,
        .activity-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .schedule-time {
            width: 76px;
            flex-shrink: 0;
            color: #35647a;
            font-size: 12px;
            line-height: 1.45;
        }

        .schedule-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #06985a;
            margin-top: 6px;
            flex-shrink: 0;
            box-shadow: 0 0 0 4px #e4f7ee;
        }

        .schedule-line {
            width: 2px;
            background: #dcebe6;
            position: absolute;
            left: 81px;
            top: 20px;
            bottom: 20px;
        }

        .schedule-title {
            color: #073b35;
            font-size: 13px;
            font-weight: 700;
        }

        .schedule-meta {
            color: #628098;
            font-size: 11px;
            margin-top: 3px;
        }

        .event-badge {
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .deadline-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .deadline-title {
            color: #073b35;
            font-size: 13px;
            font-weight: 700;
        }

        .deadline-course {
            color: #648099;
            font-size: 11px;
            margin-top: 2px;
        }

        .priority-high {
            color: #ef4444;
        }

        .priority-medium {
            color: #f59e0b;
        }

        .priority-low {
            color: #079353;
        }

        .deadline-date {
            color: #638096;
            font-size: 11px;
        }

        .deadline-date.urgent {
            color: #ef4444;
            font-weight: 700;
        }

        .chart-container {
            position: relative;
            width: 142px;
            height: 142px;
            flex-shrink: 0;
        }

        .chart-center {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }

        .chart-center-value {
            color: #073b35;
            font-size: 22px;
            font-weight: 800;
        }

        .chart-center-label {
            color: #7690a0;
            font-size: 10px;
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .legend-name {
            color: #355b70;
        }

        .legend-value {
            color: #567287;
        }

        .dashboard-progress-track {
            height: 9px;
            border-radius: 999px;
            overflow: hidden;
            background: #eaf1f0;
        }

        .dashboard-progress-fill {
            height: 100%;
            border-radius: inherit;
            background: #07995a;
            transition: width .7s ease;
        }

        .workload-label {
            color: #315b71;
            font-size: 12px;
        }

        .workload-value {
            color: #183f50;
            font-size: 12px;
            font-weight: 700;
        }

        .workload-track {
            height: 8px;
            background: #e8eff1;
            border-radius: 999px;
            overflow: hidden;
            margin-top: 7px;
        }

        .workload-fill {
            height: 100%;
            border-radius: inherit;
            background: #07995a;
            transition: width .6s ease;
        }

        .activity-icon {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-text {
            color: #315b71;
            font-size: 12px;
            line-height: 1.4;
        }

        .activity-time {
            color: #7a91a0;
            font-size: 10px;
            margin-top: 3px;
        }

        .modal-backdrop {
            background: rgba(2, 22, 18, .52);
            backdrop-filter: blur(4px);
        }

        .modal-panel {
            background: #ffffff;
            border: 1px solid #e1e9e8;
            box-shadow: 0 25px 70px rgba(0, 0, 0, .18);
        }

        .modal-input {
            width: 100%;
            border: 1px solid #dce7e5;
            background: #ffffff;
            color: #123f4c;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            outline: none;
        }

        .modal-input:focus {
            border-color: #07995a;
            box-shadow: 0 0 0 3px rgba(7, 153, 90, .10);
        }

        .modal-input::placeholder {
            color: #94a7b0;
        }

        .dashboard-list-item {
            animation: dashboardListIn .35s ease both;
        }

        .dashboard-card-enter {
            animation: dashboardCardIn .4s ease both;
        }

        @keyframes dashboardListIn {
            from {
                opacity: 0;
                transform: translateY(7px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes dashboardCardIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* =====================================================
           DARK MODE
        ===================================================== */

        html.dark body.dashboard-page {
            background: #0b1110;
        }

        html.dark .dashboard-search {
            background: #131918;
            border-color: rgba(255,255,255,.10);
            color: #e9f7f2;
        }

        html.dark .dashboard-hero {
            background:
                radial-gradient(
                    circle at 88% 55%,
                    rgba(40,120,91,.20),
                    transparent 30%
                ),
                linear-gradient(
                    135deg,
                    #102c24,
                    #112f27
                );
            border-color: rgba(53, 170, 121, .24);
        }

        html.dark .hero-title,
        html.dark .dashboard-card-title,
        html.dark .stat-value,
        html.dark .schedule-title,
        html.dark .deadline-title,
        html.dark .chart-center-value {
            color: #e7f6f0;
        }

        html.dark .hero-subtitle,
        html.dark .stat-label,
        html.dark .schedule-meta,
        html.dark .deadline-course,
        html.dark .schedule-time,
        html.dark .legend-name,
        html.dark .legend-value,
        html.dark .workload-label,
        html.dark .activity-text {
            color: #a8c0c8;
        }

        html.dark .dashboard-card {
            background: #131918;
            border-color: rgba(255,255,255,.09);
            box-shadow: 0 8px 30px rgba(0,0,0,.20);
        }

        html.dark .dashboard-card-header,
        html.dark .schedule-row,
        html.dark .deadline-row,
        html.dark .activity-row {
            border-color: rgba(255,255,255,.08);
        }

        html.dark .schedule-dot {
            box-shadow: 0 0 0 4px rgba(7,153,90,.16);
        }

        html.dark .schedule-line {
            background: rgba(255,255,255,.12);
        }

        html.dark .dashboard-progress-track,
        html.dark .workload-track {
            background: rgba(255,255,255,.09);
        }

        html.dark .modal-panel {
            background: #171c1b;
            border-color: rgba(255,255,255,.10);
            color: #e8f5f1;
        }

        html.dark .modal-input {
            background: #101513;
            border-color: rgba(255,255,255,.11);
            color: #e8f5f1;
        }

        html.dark .modal-input::placeholder {
            color: #72858b;
        }

        html.dark .hero-pill {
            background: rgba(20,42,36,.82);
            border-color: rgba(255,255,255,.07);
        }

        html.dark .hero-quote {
            color: #9ce1c1;
        }

        .dash-smart-focus {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f0fbf6 0%, #ffffff 100%);
            border: 1px solid #bfe7d6;
            border-radius: 14px;
            padding: 16px 20px;
            box-shadow: 0 4px 14px rgba(17, 71, 58, .04);
        }

        html.dark .dash-smart-focus {
            background: linear-gradient(135deg, #10251e 0%, #151d1a 100%);
            border-color: rgba(52, 211, 153, .18);
            box-shadow: none;
        }

        .dash-priority-action-row {
            transition: background-color .15s ease, transform .15s ease;
        }

        .dash-priority-action-row:hover {
            background-color: rgba(0, 143, 82, .04);
            transform: translateX(2px);
        }

        html.dark .dash-priority-action-row:hover {
            background-color: rgba(255, 255, 255, .04);
        }

        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 767px) {

            .dashboard-topbar {
                min-height: 70px;
            }

            .dashboard-content {
                padding: 16px !important;
            }

            .dashboard-hero {
                min-height: auto;
            }

            .hero-pill {
                min-width: 0;
                flex: 1 1 100%;
            }

            .hero-quote,
            .hero-illustration {
                display: none;
            }

            .stat-card {
                min-height: 100px;
                padding: 14px !important;
            }

            .stat-icon {
                width: 46px;
                height: 46px;
                border-radius: 13px;
            }

            .stat-value {
                font-size: 23px;
            }

            .chart-container {
                width: 120px;
                height: 120px;
            }

            #breakdown-legend {
                min-width: 120px;
            }
        }

        @media (max-width: 420px) {
            .stat-card {
                flex-direction: column;
                align-items: flex-start !important;
            }

            .stat-icon {
                width: 42px;
                height: 42px;
            }
        }
    </style>
</head>

<body
    data-page="dashboard"
    class="dashboard-page text-gray-900 dark:text-gray-100 transition-colors"
>

<div class="flex min-h-screen">

    <!-- Existing navigation/sidebar injection -->
    <div id="sidebar-slot"></div>

    <main class="dashboard-main flex-1 min-w-0">

        <!-- =================================================
             TOP BAR
        ================================================= -->
        <header
            class="dashboard-topbar bg-white dark:bg-[#111615] border-b border-gray-100 dark:border-white/10 px-4 sm:px-7 flex items-center justify-between relative gap-3 z-[90]"
        >

            <div class="flex items-center gap-3 min-w-0">

                <button
                    id="hamburger-btn"
                    type="button"
                    class="lg:hidden w-9 h-9 flex items-center justify-center rounded-lg text-gray-500 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5"
                    aria-label="Open menu"
                >
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <div class="min-w-0">
                    <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-[#073b35] dark:text-white">
                        Home
                    </h1>

                    <p class="hidden sm:block text-sm text-[#638096] dark:text-gray-400">
                        Welcome back, <span id="header-first-name">Student</span>! Here's your academic overview.
                    </p>
                </div>

            </div>

            <!-- Search -->
            <div class="relative hidden lg:block flex-1 max-w-[375px] mx-8">

                <i
                    data-lucide="search"
                    class="w-5 h-5 text-[#34596b] absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none"
                ></i>

                <input
                    type="text"
                    id="dashboard-search"
                    placeholder="Search tasks, courses, notes..."
                    class="dashboard-search w-full pl-12 pr-4"
                    autocomplete="off"
                >

            </div>

            <!-- Right side -->
            <div class="flex items-center gap-3 sm:gap-5">

                <!-- Calendar comes before Notifications -->
                <a
                    href="schedule.php"
                    class="hidden sm:flex w-9 h-9 items-center justify-center rounded-lg text-[#073b35] dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5"
                    aria-label="View calendar"
                    title="Calendar"
                >
                    <i data-lucide="calendar-days" class="w-5 h-5"></i>
                </a>

                <button
                    id="bell-btn"
                    type="button"
                    class="relative w-9 h-9 flex items-center justify-center rounded-lg text-[#073b35] dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5"
                    aria-label="Notifications"
                    title="Notifications"
                >
                    <i data-lucide="bell" class="w-5 h-5"></i>

                    <span
                        id="bell-badge"
                        class="hidden absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[9px] font-bold rounded-full min-w-[17px] h-[17px] items-center justify-center px-1"
                    ></span>
                </button>

                <a
                    href="settings.php"
                    class="hidden md:flex items-center gap-2.5"
                    aria-label="Account settings"
                >

                    <div
                        class="w-9 h-9 rounded-full bg-green-800 text-white flex items-center justify-center overflow-hidden"
                        data-top-avatar
                    >
                        <span data-user-initial>U</span>
                    </div>

                    <div class="hidden xl:block">
                        <div
                            data-user-name
                            class="text-sm font-semibold text-[#073b35] dark:text-white"
                        >
                            Loading…
                        </div>
                    </div>

                    <i
                        data-lucide="chevron-down"
                        class="w-4 h-4 text-gray-400"
                    ></i>

                </a>

            </div>

        </header>

        <!-- Notification dropdown. notifications.js moves this to <body>
             and positions it against the bell button. -->
        <div
            id="bell-dropdown"
            class="hidden fixed w-80 max-w-[calc(100vw-24px)] bg-white dark:bg-[#181d1c] border border-gray-100 dark:border-white/10 rounded-xl shadow-2xl z-[9999] max-h-[calc(100vh-24px)] overflow-y-auto"
        ></div>

        <!-- Overdue alert stays in document flow instead of covering the
             dashboard hero or other dashboard cards. -->
        <div
            id="overdue-alert"
            class="hidden w-full px-4 sm:px-6 lg:px-7 pt-3"
        >
            <div class="bg-red-600 text-white rounded-xl shadow-sm px-4 py-3 text-sm">
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0" aria-hidden="true">⚠</span>
                    <div class="flex-1 min-w-0">
                        <div
                            id="overdue-alert-text"
                            class="font-semibold"
                        ></div>
                        <div class="text-red-100 text-xs mt-1">
                            Check the Due Soon page for details.
                        </div>
                    </div>
                    <button
                        id="dismiss-overdue-alert"
                        type="button"
                        class="text-white/75 hover:text-white shrink-0"
                        aria-label="Dismiss"
                    >
                        ✕
                    </button>
                </div>
            </div>
        </div>


        <!-- =================================================
             MAIN CONTENT
        ================================================= -->
        <div class="dashboard-content p-4 sm:p-6 lg:p-7 space-y-5">


            <!-- =================================================
                 HERO
            ================================================= -->
            <section class="dashboard-hero rounded-2xl border border-emerald-100 dark:border-emerald-900/40 px-5 sm:px-7 py-5">

                <div class="hero-content flex flex-col xl:flex-row xl:items-center justify-between gap-5">

                    <div class="min-w-0">

                        <h2
                            class="hero-title text-2xl sm:text-3xl font-bold"
                        >
                            <span id="greeting">Good afternoon</span>,
                            <span id="first-name">…</span>!
                            👋
                        </h2>

                        <p id="hero-subtitle" class="hero-subtitle text-sm mt-1">
                            Keep going! Your consistency today builds your success tomorrow.
                        </p>

                        <div class="mt-5 flex flex-wrap gap-3">

                            <!-- Course -->
                            <a href="courses.php" class="hero-pill flex items-center gap-3 hover:opacity-90 transition" title="View my courses">

                                <div class="hero-pill-icon">
                                    <i data-lucide="graduation-cap" class="w-5 h-5"></i>
                                </div>

                                <div>
                                    <strong
                                        id="hero-course-name"
                                        class="block text-sm text-[#073b35] dark:text-white"
                                    >
                                        My Courses
                                    </strong>

                                    <small
                                        id="hero-level"
                                        class="block text-xs text-[#648099] dark:text-gray-400 mt-0.5"
                                    >
                                        Manage your courses
                                    </small>
                                </div>

                            </a>


                            <!-- Next deadline / Due Soon -->
                            <a href="deadlines.php" class="hero-pill flex items-center gap-3 hover:opacity-90 transition" title="View due soon">

                                <div class="hero-pill-icon">
                                    <i data-lucide="calendar-check" class="w-5 h-5"></i>
                                </div>

                                <div>
                                    <strong class="block text-sm text-[#073b35] dark:text-white">
                                        Due Soon
                                    </strong>

                                    <small
                                        id="hero-next-deadline"
                                        class="block text-xs text-[#648099] dark:text-gray-400 mt-0.5"
                                    >
                                        Stay ahead of your workload
                                    </small>
                                </div>

                            </a>

                            <!-- Next class -->
                            <a href="schedule.php" id="hero-next-class-pill" class="hero-pill flex items-center gap-3 hover:opacity-90 transition" title="View my classes">

                                <div class="hero-pill-icon">
                                    <i data-lucide="clock" class="w-5 h-5"></i>
                                </div>

                                <div>
                                    <strong class="block text-sm text-[#073b35] dark:text-white">
                                        Next Class
                                    </strong>

                                    <small
                                        id="hero-next-class"
                                        class="block text-xs text-[#648099] dark:text-gray-400 mt-0.5"
                                    >
                                        No classes today
                                    </small>
                                </div>

                            </a>

                            <!-- Academic Semester Calendar -->
                            <a href="courses.php?open_calendar=1" id="hero-semester-pill" class="hero-pill flex items-center gap-3 hover:opacity-90 transition" title="View semester academic calendar">

                                <div class="hero-pill-icon">
                                    <i data-lucide="calendar-range" class="w-5 h-5"></i>
                                </div>

                                <div>
                                    <strong
                                        id="hero-semester-week"
                                        class="block text-sm text-[#073b35] dark:text-white"
                                    >
                                        Semester Calendar
                                    </strong>

                                    <small
                                        id="hero-semester-phase"
                                        class="block text-xs text-[#648099] dark:text-gray-400 mt-0.5"
                                    >
                                        Set academic dates
                                    </small>
                                </div>

                            </a>

                        </div>

                    </div>


                    <!-- Quote / visual -->
                    <div class="hidden xl:flex items-center gap-8 pr-3">

                        <div class="hero-quote max-w-[185px]">
                            <span class="hero-quote-mark">“</span>
                            Small steps every day
                            lead to big results.
                            <span class="hero-quote-mark">”</span>

                            <div class="mt-3 w-14 h-1 rounded-full bg-green-700"></div>
                        </div>

                        <div class="hero-illustration">
                            <svg
                                viewBox="0 0 260 150"
                                xmlns="http://www.w3.org/2000/svg"
                                aria-hidden="true"
                            >
                                <ellipse
                                    cx="145"
                                    cy="130"
                                    rx="105"
                                    ry="12"
                                    fill="#ccefe0"
                                />

                                <rect
                                    x="72"
                                    y="100"
                                    width="112"
                                    height="10"
                                    rx="4"
                                    fill="#087e50"
                                />

                                <rect
                                    x="83"
                                    y="89"
                                    width="95"
                                    height="10"
                                    rx="4"
                                    fill="#e29d21"
                                />

                                <rect
                                    x="94"
                                    y="78"
                                    width="80"
                                    height="10"
                                    rx="4"
                                    fill="#0b6e4f"
                                />

                                <path
                                    d="M155 33 L214 47 L198 98 L143 83 Z"
                                    fill="#173c4b"
                                />

                                <path
                                    d="M159 39 L206 50 L194 88 L149 77 Z"
                                    fill="#c7edf0"
                                />

                                <path
                                    d="M144 83 L198 98 L207 104 L155 91 Z"
                                    fill="#0c5360"
                                />

                                <rect
                                    x="216"
                                    y="57"
                                    width="26"
                                    height="57"
                                    rx="12"
                                    fill="#8ed19e"
                                />

                                <circle
                                    cx="229"
                                    cy="50"
                                    r="19"
                                    fill="#5bad78"
                                />

                                <path
                                    d="M228 20 C214 37 219 49 230 52 C244 44 245 29 228 20Z"
                                    fill="#48a66b"
                                />

                                <path
                                    d="M230 50 C238 32 248 27 257 30 C254 45 246 53 230 56Z"
                                    fill="#77bd81"
                                />

                            </svg>
                        </div>

                    </div>

                </div>

            </section>


            <!-- =================================================
                 ACADEMIC FOCUS BANNER
            ================================================= -->
            <section
                id="dashboard-smart-focus"
                class="hidden"
            ></section>

            <!-- =================================================
                 SMART STUDY RECOMMENDATIONS & SUGGESTIONS
            ================================================= -->
            <section
                id="dashboard-recommended-plan"
                class="hidden"
            ></section>

            <section
                id="dashboard-smart-suggestions"
                class="hidden"
            ></section>


            <!-- =================================================
                 STAT CARDS
            ================================================= -->
            <div
                id="stat-cards"
                class="grid grid-cols-2 xl:grid-cols-4 gap-4"
            ></div>


            <!-- =================================================
                 ROW 1
            ================================================= -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">


                <!-- Today's Schedule -->
                <section class="dashboard-card p-5">

                    <div class="dashboard-card-header">

                        <h3 class="dashboard-card-title flex items-center gap-2 text-sm">
                            <i
                                data-lucide="calendar-days"
                                class="w-4 h-4 text-green-700"
                            ></i>

                            Today's Classes & Day
                        </h3>

                        <a
                            href="schedule.php"
                            class="dashboard-view-link"
                        >
                            View Full Schedule
                        </a>

                    </div>

                    <div
                        id="today-schedule"
                        class="space-y-4 relative"
                    ></div>

                </section>


                <!-- Upcoming Deadlines -->
                <section class="dashboard-card p-5">

                    <div class="dashboard-card-header">

                        <h3 class="dashboard-card-title flex items-center gap-2 text-sm">
                            <i
                                data-lucide="calendar-clock"
                                class="w-4 h-4 text-green-700"
                            ></i>

                            Due Soon
                        </h3>

                        <a
                            href="deadlines.php"
                            class="dashboard-view-link"
                        >
                            View All
                        </a>

                    </div>

                    <div
                        id="upcoming-deadlines"
                        class="space-y-3"
                    ></div>

                </section>


                <!-- Task Breakdown -->
                <section class="dashboard-card p-5">

                    <div class="dashboard-card-header">

                        <h3 class="dashboard-card-title flex items-center gap-2 text-sm">
                            <i
                                data-lucide="pie-chart"
                                class="w-4 h-4 text-green-700"
                            ></i>

                            My Work
                        </h3>

                        <a
                            href="tasks.php"
                            class="dashboard-view-link"
                        >
                            View All
                        </a>

                    </div>


                    <div class="flex items-center gap-4">

                        <div class="chart-container">

                            <canvas id="breakdown-chart"></canvas>

                            <div class="chart-center">

                                <div
                                    id="breakdown-total"
                                    class="chart-center-value"
                                >
                                    –
                                </div>

                                <div class="chart-center-label">
                                    Total
                                </div>

                            </div>

                        </div>


                        <div
                            id="breakdown-legend"
                            class="flex-1 text-xs space-y-2"
                        ></div>

                    </div>


                    <div class="mt-5">

                        <div class="flex justify-between text-xs mb-2">

                            <span class="text-[#58768a] dark:text-gray-400">
                                Completion Rate
                            </span>

                            <strong
                                id="completion-rate-label"
                                class="text-[#073b35] dark:text-white"
                            >
                                –
                            </strong>

                        </div>

                        <div class="dashboard-progress-track">

                            <div
                                id="completion-rate-bar"
                                class="dashboard-progress-fill dashboard-progress-bar"
                                style="width:0%"
                            ></div>

                        </div>

                    </div>

                </section>

            </div>


            <!-- =================================================
                 ROW 2
            ================================================= -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">


                <!-- Weekly Progress -->
                <section class="dashboard-card p-5">

                    <div class="dashboard-card-header">

                        <h3 class="dashboard-card-title flex items-center gap-2 text-sm">
                            <i
                                data-lucide="trending-up"
                                class="w-4 h-4 text-green-700"
                            ></i>

                            Weekly Progress
                        </h3>

                        <a
                            href="progress.php"
                            class="dashboard-view-link"
                        >
                            View Details
                        </a>

                    </div>

                    <div class="h-[205px]">
                        <canvas id="weekly-chart"></canvas>
                    </div>

                </section>


                <!-- Workload Overview -->
                <section class="dashboard-card p-5">

                    <div class="dashboard-card-header">

                        <h3 class="dashboard-card-title flex items-center gap-2 text-sm">

                            <i
                                data-lucide="layout-grid"
                                class="w-4 h-4 text-green-700"
                            ></i>

                            Weekly Workload

                        </h3>

                        <select
                            id="workload-period"
                            class="text-[11px] border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 rounded-lg px-2 py-1 text-[#355b70] dark:text-gray-300 outline-none"
                        >
                            <option value="week">This Week</option>
                        </select>

                    </div>

                    <div
                        id="workload-list"
                        class="space-y-4"
                    ></div>

                    <div class="flex justify-between mt-5 pt-4 border-t border-gray-100 dark:border-white/10 text-sm font-bold text-[#073b35] dark:text-white">

                        <span>Total Workload</span>

                        <span id="workload-total">
                            – hrs
                        </span>

                    </div>

                </section>


                <!-- Recent Activity -->
                <section class="dashboard-card p-5">

                    <div class="dashboard-card-header">

                        <h3 class="dashboard-card-title flex items-center gap-2 text-sm">
                            <i
                                data-lucide="activity"
                                class="w-4 h-4 text-green-700"
                            ></i>

                            Recent Activity
                        </h3>

                        <a
                            href="notifications.php"
                            class="dashboard-view-link"
                        >
                            View All
                        </a>

                    </div>

                    <div
                        id="recent-activity"
                        class="space-y-3"
                    ></div>

                </section>

            </div>

        </div>

    </main>

</div>


<!-- =========================================================
     ADD TASK MODAL
========================================================= -->

<div
    id="add-task-modal"
    class="hidden fixed inset-0 modal-backdrop items-center justify-center z-[80] p-4"
>
    <div
        class="modal-panel rounded-2xl p-6 w-full max-w-md"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-task-title"
    >

        <div class="flex items-center justify-between mb-5">

            <div>
                <h3
                    id="add-task-title"
                    class="text-lg font-bold text-[#073b35] dark:text-white"
                >
                    Add Task
                </h3>

                <p class="text-xs text-[#708b99] dark:text-gray-400 mt-1">
                    Add a new task to your academic workload.
                </p>
            </div>

            <button
                type="button"
                id="close-add-task"
                class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5"
                aria-label="Close"
            >
                <i data-lucide="x" class="w-4 h-4 mx-auto"></i>
            </button>

        </div>


        <div
            id="add-task-error"
            class="hidden mb-4 rounded-lg bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-300 text-xs px-3 py-2"
        ></div>


        <form
            id="add-task-form"
            class="space-y-4"
        >

            <div>

                <label class="block text-xs font-semibold text-[#315b71] dark:text-gray-300 mb-1.5">
                    Task Title
                </label>

                <input
                    required
                    name="title"
                    placeholder="e.g. Database Assignment"
                    class="modal-input"
                >

            </div>


            <div>

                <label class="block text-xs font-semibold text-[#315b71] dark:text-gray-300 mb-1.5">
                    Course
                </label>

                <select
                    name="course_id"
                    id="course-select"
                    class="modal-input"
                >
                    <option value="">
                        No course
                    </option>
                </select>

            </div>


            <div class="grid grid-cols-2 gap-3">

                <div>

                    <label class="block text-xs font-semibold text-[#315b71] dark:text-gray-300 mb-1.5">
                        Type
                    </label>

                    <select
                        name="type"
                        class="modal-input"
                    >
                        <option value="assignment">Assignment</option>
                        <option value="project">Project</option>
                        <option value="test">Test</option>
                        <option value="exam">Exam</option>
                        <option value="research">Research</option>
                        <option value="lab_report">Lab Report</option>
                    </select>

                </div>


                <div>

                    <label class="block text-xs font-semibold text-[#315b71] dark:text-gray-300 mb-1.5">
                        Priority
                    </label>

                    <select
                        name="priority"
                        class="modal-input"
                    >
                        <option value="low">Low priority</option>
                        <option value="medium" selected>Medium priority</option>
                        <option value="high">High priority</option>
                    </select>

                </div>

            </div>


            <div class="grid grid-cols-2 gap-3">

                <div>

                    <label class="block text-xs font-semibold text-[#315b71] dark:text-gray-300 mb-1.5">
                        Due Date
                    </label>

                    <input
                        required
                        type="datetime-local"
                        name="due_at"
                        class="modal-input"
                    >

                </div>


                <div>

                    <label class="block text-xs font-semibold text-[#315b71] dark:text-gray-300 mb-1.5">
                        Estimated Hours
                    </label>

                    <input
                        type="number"
                        step="0.5"
                        min="0"
                        name="duration_hours"
                        placeholder="e.g. 3"
                        class="modal-input"
                    >

                </div>

            </div>


            <div class="flex justify-end gap-2 pt-2">

                <button
                    type="button"
                    id="cancel-add-task"
                    class="px-4 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    id="submit-add-task"
                    class="px-5 py-2.5 text-sm bg-green-800 hover:bg-green-900 text-white rounded-lg font-semibold"
                >
                    Add Task
                </button>

            </div>

        </form>

    </div>
</div>


<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/work-timer.js"></script>
<script src="../assets/js/dashboard.js"></script>


<script src="../assets/js/guided-tour.js"></script>
</body>
</html>