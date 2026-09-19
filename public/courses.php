<?php
require_once __DIR__ . '/../includes/auth.php';
requirePageLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Courses — Study Planner</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body data-page="courses" class="app-page bg-[#F4F8F7] dark:bg-[#0B0B0C] text-[#082E2A] dark:text-gray-100 transition-colors">
<div class="flex min-h-screen">
  <div id="sidebar-slot"></div>

  <main class="flex-1 min-w-0">
    <header class="app-topbar bg-white/95 dark:bg-[#101615]/95 border-b border-gray-100 dark:border-white/10 px-4 sm:px-7 py-4 flex items-center gap-4 sticky top-0 z-20">
      <button id="hamburger-btn" class="lg:hidden text-[#0B4B42] dark:text-gray-200" aria-label="Open menu"><i data-lucide="menu" class="w-6 h-6"></i></button>
      <div class="min-w-0 flex-1 sm:flex-initial sm:min-w-[170px]">
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight">Courses</h1>
        <p class="hidden sm:block text-xs sm:text-sm text-[#53736D] dark:text-gray-400 mt-0.5">Manage your courses and track your academic progress.</p>
      </div>
      <div class="relative hidden md:block flex-1 max-w-lg mx-auto">
        <i data-lucide="search" class="w-5 h-5 text-[#56736E] absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none"></i>
        <input id="course-search" type="search" placeholder="Search courses, course codes, lecturers..." aria-label="Search courses"
          class="w-full h-11 bg-[#F8FAFA] dark:bg-white/5 border border-[#D8E5E2] dark:border-white/10 rounded-xl pl-12 pr-4 text-sm outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600">
      </div>
      <div class="flex items-center gap-4 ml-auto">
        <a href="schedule.php" class="hidden sm:block text-[#0A4D44] dark:text-gray-200" aria-label="View schedule"><i data-lucide="calendar-days" class="w-6 h-6"></i></a>
        <button id="bell-btn" class="relative text-[#0A4D44] dark:text-gray-200" aria-label="Notifications">
          <i data-lucide="bell" class="w-6 h-6"></i>
          <span id="bell-badge" class="hidden absolute -top-1 -right-2 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 items-center justify-center"></span>
        </button>
        <a href="settings.php" class="hidden sm:flex items-center gap-2 pl-4 border-l border-gray-100 dark:border-white/10" aria-label="Account settings">
          <span class="text-sm font-semibold" data-user-name>Loading…</span>
          <i data-lucide="chevron-down" class="w-4 h-4 text-gray-400"></i>
          <div class="w-10 h-10 rounded-full bg-emerald-700 text-white flex items-center justify-center font-bold overflow-hidden" data-top-avatar><span data-user-initial>U</span></div>
        </a>
      </div>
      <div id="bell-dropdown" class="hidden absolute right-4 top-16 w-80 max-w-[90vw] bg-white dark:bg-[#171D1B] border border-gray-100 dark:border-white/10 rounded-xl shadow-xl z-30 max-h-96 overflow-y-auto"></div>
    </header>

    <div class="courses-content p-4 sm:p-6 lg:p-7 space-y-5 max-w-[1600px] mx-auto">
      <section class="courses-hero rounded-2xl border border-emerald-100 dark:border-emerald-900/40 px-6 sm:px-8 py-5 overflow-hidden">
        <div class="flex items-center justify-between gap-6">
          <div class="flex items-center gap-5 min-w-0">
            <div class="courses-hero-icon"><i data-lucide="book-open" class="w-10 h-10"></i></div>
            <div>
              <h2 class="text-2xl sm:text-3xl font-bold">My Courses</h2>
              <p class="text-sm sm:text-base text-[#42675F] dark:text-gray-400 mt-1">Manage, track and stay on top of your academic journey.</p>
            </div>
          </div>
          <div class="courses-hero-quote hidden lg:block">
            <div>“ Small steps every day</div>
            <div>lead to big results. ”</div>
            <span></span>
          </div>
          <div class="courses-hero-art hidden xl:flex" aria-hidden="true"><span>📚</span><span>💻</span><span>🌱</span></div>
        </div>
      </section>

      <!-- Semester Academic Context Banner -->
      <section id="semester-banner" class="hidden rounded-2xl border border-emerald-200 dark:border-emerald-800/40 bg-gradient-to-r from-emerald-50/80 via-white to-teal-50/60 dark:from-[#11241f] dark:via-[#131a18] dark:to-[#10221c] p-4 sm:p-5 shadow-sm transition">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div class="flex items-start sm:items-center gap-3.5 min-w-0">
            <div class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-900/50 text-emerald-800 dark:text-emerald-300 flex items-center justify-center shrink-0">
              <i data-lucide="calendar-check" class="w-5 h-5"></i>
            </div>
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <h3 id="semester-banner-title" class="font-bold text-base text-[#082E2A] dark:text-white">Active Semester</h3>
                <span id="semester-banner-badge" class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">Week 1</span>
              </div>
              <p id="semester-banner-subtitle" class="text-xs text-[#53736D] dark:text-gray-400 mt-0.5">Loading academic calendar information...</p>
            </div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button id="banner-view-calendar-btn" type="button" class="px-3.5 py-2 text-xs font-semibold rounded-lg bg-white dark:bg-white/10 border border-emerald-300 dark:border-emerald-700/50 text-emerald-800 dark:text-emerald-200 hover:bg-emerald-50 dark:hover:bg-white/15 transition flex items-center gap-1.5">
              <i data-lucide="calendar" class="w-3.5 h-3.5"></i> View / Edit Calendar
            </button>
          </div>
        </div>
      </section>

      <section id="course-stat-cards" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3.5 sm:gap-4"></section>

      <section class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_385px] gap-4">
        <div class="courses-list-panel">
          <div class="px-5 py-4 border-b border-[#E7EFED] dark:border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h3 class="font-bold text-lg flex items-center gap-2"><i data-lucide="book-open" class="w-5 h-5 text-emerald-700"></i> My Courses</h3>
              <p class="text-xs text-[#66837D] dark:text-gray-400 mt-0.5">Your registered courses for this semester.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
              <button id="open-import-course-form" type="button" class="btn-press bg-white dark:bg-white/5 border border-emerald-300 dark:border-emerald-700/60 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 rounded-lg px-3 py-2 text-xs font-semibold flex items-center justify-center gap-1.5" title="Extract courses from course registration PDF or text">
                <i data-lucide="file-up" class="w-4 h-4"></i> Upload Course Form
              </button>
              <button id="open-curriculum-modal" type="button" class="btn-press bg-white dark:bg-white/5 border border-emerald-300 dark:border-emerald-700/60 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 rounded-lg px-3 py-2 text-xs font-semibold flex items-center justify-center gap-1.5" title="Set or view semester academic calendar">
                <i data-lucide="calendar" class="w-4 h-4"></i> Semester Calendar
              </button>
              <button id="open-add-course" type="button" class="btn-press bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg px-3.5 py-2 text-xs font-semibold flex items-center justify-center gap-1.5">
                <i data-lucide="plus" class="w-4 h-4"></i> Add Course
              </button>
            </div>
          </div>

          <div class="px-5 py-3 border-b border-[#E7EFED] dark:border-white/10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
            <div class="relative">
              <i data-lucide="search" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
              <input id="course-search-inline" type="search" placeholder="Search courses..." class="filter-control pl-9">
            </div>
            <select id="course-semester" class="filter-control"><option value="">All Semesters</option></select>
            <select id="course-department" class="filter-control"><option value="">All Departments</option></select>
            <select id="course-sort" class="filter-control">
              <option value="name">Sort by: Name (A-Z)</option>
              <option value="code">Sort by: Code (A-Z)</option>
              <option value="progress">Sort by: Progress</option>
              <option value="credits">Sort by: Credits</option>
            </select>
          </div>

          <div id="course-loading" class="px-5 py-6 grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2 gap-3">
            <div class="course-skeleton h-36"></div><div class="course-skeleton h-36"></div><div class="course-skeleton h-36"></div><div class="course-skeleton h-36"></div>
          </div>
          <div id="course-cards" class="px-5 py-5 grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2 gap-3"></div>
          <div class="px-5 py-3 border-t border-[#E7EFED] dark:border-white/10 flex items-center justify-between gap-3">
            <span id="course-count-label" class="text-xs text-[#5C7B74] dark:text-gray-400">Loading courses…</span>
            <div id="course-pagination" class="flex items-center gap-1"></div>
          </div>
        </div>

        <aside class="space-y-4">
          <div class="course-side-panel p-5">
            <div class="flex items-center justify-between mb-4">
              <div><h3 class="font-bold text-sm flex items-center gap-2"><i data-lucide="circle-gauge" class="w-5 h-5 text-emerald-700"></i> Course Progress</h3><p class="text-xs text-[#66837D] dark:text-gray-400 mt-1">Your overall course completion rate</p></div>
            </div>
            <div class="flex items-center gap-5">
              <div class="course-donut" id="course-donut"><div><strong id="course-progress-total">0%</strong><span>Completed</span></div></div>
              <div id="course-progress-legend" class="space-y-3 text-xs flex-1"></div>
            </div>
          </div>

          <div class="course-side-panel p-5">
            <div class="flex items-center justify-between mb-3"><h3 class="font-bold text-sm flex items-center gap-2"><i data-lucide="calendar-clock" class="w-5 h-5 text-emerald-700"></i> Due Soon</h3><a href="deadlines.php" class="text-xs text-emerald-700 dark:text-emerald-400 font-semibold">View All</a></div>
            <div id="course-upcoming-deadlines" class="divide-y divide-[#E7EFED] dark:divide-white/10"></div>
          </div>

          <div class="course-side-panel p-5">
            <h3 class="font-bold text-sm mb-3">Quick Actions</h3>
            <div class="grid grid-cols-2 gap-2">
              <button id="quick-add-course" class="quick-action text-emerald-800 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/30"><i data-lucide="book-open"></i> Add Course</button>
              <a href="schedule.php" class="quick-action text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-950/30"><i data-lucide="calendar-days"></i> View My Classes</a>
              <a href="progress.php" class="quick-action text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-950/30"><i data-lucide="chart-no-axes-combined"></i> View Progress</a>
              <a href="reports.php" class="quick-action text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-950/30"><i data-lucide="file-chart-column"></i> View Reports</a>
            </div>
          </div>
        </aside>
      </section>
    </div>
  </main>
</div>

<div id="course-modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-[2px] flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true" aria-labelledby="course-modal-title">
  <div class="bg-white dark:bg-[#171D1B] rounded-2xl p-6 w-full max-w-lg modal-enter shadow-2xl border border-gray-100 dark:border-white/10 max-h-[90vh] overflow-y-auto ring-1 ring-emerald-100/60 dark:ring-white/5">
    <div class="flex items-start justify-between gap-4 mb-5">
      <div><h3 class="font-bold text-xl" id="course-modal-title">Add Course</h3><p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Add or update your course information.</p></div>
      <button type="button" id="close-course-modal" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-white" aria-label="Close"><i data-lucide="x"></i></button>
    </div>
    <form id="course-form" class="space-y-4">
      <input type="hidden" name="id">
      <div class="grid grid-cols-1 sm:grid-cols-[1fr_120px] gap-3">
        <div><label class="form-label">Course code *</label><input required maxlength="20" name="code" placeholder="CSC 401" class="form-control"></div>
        <div><label class="form-label">Credits *</label><input required type="number" name="credits" min="1" max="6" step="1" value="3" class="form-control"></div>
      </div>
      <div><label class="form-label">Course title *</label><input required maxlength="150" name="name" placeholder="Web Development" class="form-control"></div>
      <div><label class="form-label">Lecturer</label><input maxlength="100" name="lecturer" placeholder="Dr. Jane Doe" class="form-control"></div>
      <div><label class="form-label">Semester</label><input maxlength="30" name="semester" id="course-semester-input" list="course-semester-datalist" placeholder="e.g. 2025/2026 Second Semester" class="form-control"><datalist id="course-semester-datalist"></datalist></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div><label class="form-label">Icon</label><input name="icon" maxlength="10" placeholder="📘" class="form-control"></div>
        <div><label class="form-label">Accent color</label><input type="color" name="color" value="#059669" class="form-control p-1.5 h-10"></div>
      </div>
      <div><label class="form-label">Grade point <span class="font-normal text-gray-400">(optional, 0–5)</span></label><input type="number" step="0.01" min="0" max="5" name="grade_point" class="form-control"></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><div><label class="form-label">Status</label><select name="status" class="form-control"><option value="pending">Pending</option><option value="in_progress">In Progress</option><option value="completed">Completed</option></select></div><div><label class="form-label">Estimated study hours</label><input type="number" step="0.5" min="0" name="estimated_hours" placeholder="e.g. 40" class="form-control"></div></div>
      <div><div class="flex items-center justify-between"><label class="form-label mb-0">Progress</label><span id="course-progress-value" class="text-xs font-bold text-emerald-700 dark:text-emerald-400">0%</span></div><input type="range" min="0" max="100" step="5" name="progress_percent" id="course-progress-range" value="0" class="w-full accent-emerald-700 mt-2"></div>
      <div id="course-form-error" class="hidden rounded-lg bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 text-sm px-3 py-2"></div>
      <div class="flex justify-end gap-2 pt-2">
        <button type="button" id="cancel-course" class="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">Cancel</button>
        <button id="save-course-btn" type="submit" class="px-5 py-2.5 text-sm bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold">Save Course</button>
      </div>
    </form>
  </div>
</div>

<!-- Course Registration Form Import Modal -->
<div id="course-import-modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-[2px] flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true" aria-labelledby="course-import-modal-title">
  <div class="bg-white dark:bg-[#171D1B] rounded-2xl p-6 w-full max-w-2xl modal-enter shadow-2xl border border-gray-100 dark:border-white/10 max-h-[90vh] overflow-y-auto ring-1 ring-emerald-100/60 dark:ring-white/5">
    <div class="flex items-start justify-between gap-4 mb-4 pb-3 border-b border-gray-100 dark:border-white/10">
      <div>
        <h3 class="font-bold text-xl text-[#082E2A] dark:text-white" id="course-import-modal-title">Import Course Registration Form</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Upload your registered courses form (PDF or document) or paste course details.</p>
      </div>
      <button type="button" id="close-course-import-modal" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-white" aria-label="Close"><i data-lucide="x"></i></button>
    </div>

    <!-- Step 1: Upload / Input -->
    <div id="course-import-step-upload" class="space-y-4">
      <div class="border-2 border-dashed border-emerald-200 dark:border-emerald-800/60 hover:border-emerald-500 rounded-2xl p-6 sm:p-8 text-center bg-emerald-50/30 dark:bg-emerald-950/10 transition cursor-pointer" id="course-dropzone">
        <input type="file" id="course-file-input" accept=".pdf,.txt,.doc,.docx" class="hidden">
        <div class="w-12 h-12 rounded-2xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 flex items-center justify-center mx-auto mb-3">
          <i data-lucide="file-up" class="w-6 h-6"></i>
        </div>
        <h4 class="font-bold text-sm text-[#082E2A] dark:text-white mb-1">Click to select or drag & drop course form</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400">Accepts university course registration PDF or text documents (up to 15MB)</p>
        <div id="course-file-chosen" class="hidden mt-3 text-xs font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-100/80 dark:bg-emerald-900/30 py-1.5 px-3 rounded-lg inline-block"></div>
      </div>

      <div class="text-center text-xs font-semibold text-gray-400">— OR —</div>

      <div>
        <label class="form-label">Paste course list text</label>
        <textarea id="course-text-input" rows="4" placeholder="e.g.&#10;CSC 401 - Database Systems (3 Units)&#10;MTH 301 - Numerical Analysis (3 Credits)&#10;PHY 202 - General Physics (2 Units)" class="form-control text-xs font-mono"></textarea>
      </div>

      <div id="course-import-error" class="hidden rounded-lg bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 text-sm px-3 py-2"></div>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button" id="cancel-course-import" class="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">Cancel</button>
        <button type="button" id="extract-course-btn" class="px-5 py-2.5 text-sm bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold flex items-center gap-1.5">
          <i data-lucide="sparkles" class="w-4 h-4"></i> Extract Courses
        </button>
      </div>
    </div>

    <!-- Step 2: Mandatory Review Screen -->
    <div id="course-import-step-review" class="hidden space-y-4">
      <div class="bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40 rounded-xl p-3.5 text-xs text-emerald-900 dark:text-emerald-200 flex items-start gap-2.5">
        <i data-lucide="info" class="w-4 h-4 shrink-0 text-emerald-600 mt-0.5"></i>
        <div>
          <strong class="font-bold">Review Extracted Courses</strong>
          <p class="mt-0.5 text-emerald-800/80 dark:text-emerald-300/80">Please check course codes, titles, and credit units. You can modify any row, remove unneeded courses, or add missing ones before saving.</p>
        </div>
      </div>

      <div class="overflow-x-auto max-h-72 border border-gray-100 dark:border-white/10 rounded-xl">
        <table class="w-full text-left text-xs border-collapse" id="course-review-table">
          <thead class="bg-gray-50 dark:bg-white/5 border-b border-gray-100 dark:border-white/10 sticky top-0">
            <tr>
              <th class="p-2.5 font-bold text-gray-600 dark:text-gray-300">Course Code</th>
              <th class="p-2.5 font-bold text-gray-600 dark:text-gray-300">Course Title</th>
              <th class="p-2.5 font-bold text-gray-600 dark:text-gray-300 w-20">Credits</th>
              <th class="p-2.5 font-bold text-gray-600 dark:text-gray-300 w-28">Status</th>
              <th class="p-2.5 text-right w-12"></th>
            </tr>
          </thead>
          <tbody id="course-review-tbody" class="divide-y divide-gray-100 dark:divide-white/5">
          </tbody>
        </table>
      </div>

      <div class="flex items-center justify-between">
        <button type="button" id="add-review-row-btn" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:text-emerald-800 flex items-center gap-1">
          <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i> + Add Missing Course
        </button>
        <span id="course-review-count" class="text-xs text-gray-500 dark:text-gray-400">0 courses found</span>
      </div>

      <div id="course-review-error" class="hidden rounded-lg bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 text-sm px-3 py-2"></div>

      <div class="flex justify-end gap-2 pt-2 border-t border-gray-100 dark:border-white/10">
        <button type="button" id="back-to-upload-course" class="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">Back</button>
        <button type="button" id="confirm-import-courses-btn" class="px-5 py-2.5 text-sm bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold flex items-center gap-1.5">
          <i data-lucide="check" class="w-4 h-4"></i> Confirm & Import Courses
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Semester Academic Calendar Modal -->
<div id="curriculum-modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-[2px] flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true" aria-labelledby="curriculum-modal-title">
  <div class="bg-white dark:bg-[#171D1B] rounded-2xl p-6 w-full max-w-3xl modal-enter shadow-2xl border border-gray-100 dark:border-white/10 max-h-[92vh] overflow-y-auto ring-1 ring-emerald-100/60 dark:ring-white/5">
    <div class="flex items-start justify-between gap-4 mb-4 pb-3 border-b border-gray-100 dark:border-white/10">
      <div>
        <h3 class="font-bold text-xl text-[#082E2A] dark:text-white" id="curriculum-modal-title">Semester Academic Calendar</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Configure your semester timeline and academic weeks to unlock semester-aware study guidance.</p>
      </div>
      <button type="button" id="close-curriculum-modal" class="p-1 text-gray-400 hover:text-gray-700 dark:hover:text-white" aria-label="Close"><i data-lucide="x"></i></button>
    </div>

    <!-- Active Semester Summary Card (if configured) -->
    <div id="curriculum-active-summary" class="hidden mb-5 p-4 rounded-xl border border-emerald-200 dark:border-emerald-800/40 bg-emerald-50/50 dark:bg-emerald-950/20">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <div class="flex items-center gap-2">
            <h4 id="curr-sum-name" class="font-bold text-sm text-[#082E2A] dark:text-white">Semester</h4>
            <span id="curr-sum-badge" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">Teaching</span>
          </div>
          <p id="curr-sum-dates" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"></p>
          <p id="curr-sum-guidance" class="text-xs font-semibold text-emerald-800 dark:text-emerald-300 mt-1.5"></p>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" id="btn-edit-existing-curriculum" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white transition">Update Calendar</button>
          <button type="button" id="btn-delete-curriculum" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-950/30 dark:text-red-300 transition">Delete</button>
        </div>
      </div>
    </div>

    <!-- Setup / Edit Form -->
    <div id="curriculum-edit-section" class="space-y-4">
      <!-- Tabs: Upload Document vs Manual Entry -->
      <div class="flex border-b border-gray-200 dark:border-white/10 gap-4 text-xs font-bold">
        <button type="button" id="curriculum-tab-upload" class="pb-2.5 border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400">Upload Calendar (PDF / Doc)</button>
        <button type="button" id="curriculum-tab-manual" class="pb-2.5 border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700">Manual Entry</button>
      </div>

      <!-- Tab 1: Upload -->
      <div id="curriculum-panel-upload" class="space-y-3">
        <div class="border-2 border-dashed border-emerald-200 dark:border-emerald-800/60 hover:border-emerald-500 rounded-2xl p-5 text-center bg-emerald-50/20 dark:bg-emerald-950/10 cursor-pointer" id="curriculum-dropzone">
          <input type="file" id="curriculum-file-input" accept=".pdf,.txt,.doc,.docx" class="hidden">
          <div class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 flex items-center justify-center mx-auto mb-2">
            <i data-lucide="file-text" class="w-5 h-5"></i>
          </div>
          <h4 class="font-bold text-xs text-[#082E2A] dark:text-white mb-1">Click to select or drag & drop Academic Calendar PDF</h4>
          <p class="text-[11px] text-gray-500 dark:text-gray-400">Extracts semester start/end dates and academic weeks automatically.</p>
          <div id="curriculum-file-chosen" class="hidden mt-2 text-xs font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-100/80 dark:bg-emerald-900/30 py-1 px-2.5 rounded-lg inline-block"></div>
        </div>
        <div class="text-center text-xs font-semibold text-gray-400">— OR —</div>
        <div>
          <label class="form-label">Paste Academic Calendar Text</label>
          <textarea id="curriculum-text-input" rows="3" placeholder="e.g.&#10;First Semester 2025/2026&#10;Week 1: Oct 6 - Lectures begin&#10;Week 8: Nov 24 - Mid-term break&#10;Week 13: Jan 5 - Revision week&#10;Week 14-15: Jan 12 - Examination period" class="form-control text-xs font-mono"></textarea>
        </div>
        <div class="flex justify-end">
          <button type="button" id="extract-curriculum-btn" class="px-4 py-2 text-xs bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold flex items-center gap-1.5">
            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Extract & Review Calendar
          </button>
        </div>
      </div>

      <!-- Tab 2: Manual Entry Preset -->
      <div id="curriculum-panel-manual" class="hidden space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="form-label">Semester Name *</label>
            <input id="manual-sem-name" placeholder="2025/2026 First Semester" class="form-control text-xs">
          </div>
          <div>
            <label class="form-label">Start Date (Monday) *</label>
            <input type="date" id="manual-sem-start" class="form-control text-xs">
          </div>
          <div>
            <label class="form-label">Number of Weeks</label>
            <input type="number" id="manual-sem-weeks" min="4" max="24" value="14" class="form-control text-xs">
          </div>
        </div>
        <div class="flex justify-end">
          <button type="button" id="generate-manual-weeks-btn" class="px-4 py-2 text-xs bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold flex items-center gap-1.5">
            <i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i> Generate Academic Weeks
          </button>
        </div>
      </div>

      <!-- Mandatory Review / Timeline Screen -->
      <div id="curriculum-review-section" class="space-y-3 pt-3 border-t border-gray-100 dark:border-white/10">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="form-label">Semester Name *</label>
            <input id="curr-review-name" required placeholder="First Semester 2025/2026" class="form-control text-xs font-semibold">
          </div>
          <div>
            <label class="form-label">Semester Start Date *</label>
            <input type="date" id="curr-review-start" required class="form-control text-xs">
          </div>
          <div>
            <label class="form-label">Semester End Date *</label>
            <input type="date" id="curr-review-end" required class="form-control text-xs">
          </div>
        </div>

        <div class="flex items-center justify-between pt-2">
          <div>
            <h4 class="font-bold text-xs text-[#082E2A] dark:text-white">Curriculum Weeks Timeline</h4>
            <p class="text-[11px] text-gray-500 dark:text-gray-400">Classify each week as Teaching, Student Week, Break, Revision, or Exam.</p>
          </div>
          <button type="button" id="add-curriculum-week-btn" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:text-emerald-800 flex items-center gap-1">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> + Add Week
          </button>
        </div>

        <div class="overflow-x-auto max-h-64 border border-gray-100 dark:border-white/10 rounded-xl">
          <table class="w-full text-left text-xs border-collapse" id="curriculum-weeks-table">
            <thead class="bg-gray-50 dark:bg-white/5 border-b border-gray-100 dark:border-white/10 sticky top-0">
              <tr>
                <th class="p-2 font-bold text-gray-600 dark:text-gray-300 w-16">Week</th>
                <th class="p-2 font-bold text-gray-600 dark:text-gray-300 w-32">Type</th>
                <th class="p-2 font-bold text-gray-600 dark:text-gray-300">Label / Description</th>
                <th class="p-2 font-bold text-gray-600 dark:text-gray-300 w-28">Start</th>
                <th class="p-2 font-bold text-gray-600 dark:text-gray-300 w-28">End</th>
                <th class="p-2 text-right w-10"></th>
              </tr>
            </thead>
            <tbody id="curriculum-weeks-tbody" class="divide-y divide-gray-100 dark:divide-white/5">
            </tbody>
          </table>
        </div>

        <div id="curriculum-error" class="hidden rounded-lg bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 text-xs px-3 py-2"></div>

        <div class="flex justify-end gap-2 pt-2 border-t border-gray-100 dark:border-white/10">
          <button type="button" id="cancel-curriculum" class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">Cancel</button>
          <button type="button" id="save-curriculum-btn" class="px-5 py-2 text-xs bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg font-semibold flex items-center gap-1.5">
            <i data-lucide="check" class="w-3.5 h-3.5"></i> Save Semester Calendar
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/work-timer.js"></script>
<script src="../assets/js/courses.js"></script>

<script src="../assets/js/guided-tour.js"></script>
</body>
</html>
