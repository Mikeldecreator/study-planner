<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = currentUserId();

// If user has completed onboarding and is not visiting with ?force=1 or ?edit=1, redirect to normal dashboard
if (empty($_GET['force']) && empty($_GET['edit']) && isOnboardingComplete($userId)) {
    header('Location: dashboard.php');
    exit;
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <title>Welcome to Study Planner — Academic Setup</title>
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <script src="../assets/js/theme-init.js"></script>
  <link rel="stylesheet" href="../assets/css/style.css">

  <style>
    :root {
      --sp-green: #008f52;
      --sp-green-dark: #006f43;
      --sp-green-light: #eafaf3;
      --sp-text: #073b35;
      --sp-muted: #5f7890;
    }
    .step-badge-active {
      background-color: #059669;
      color: #ffffff;
      border-color: #059669;
    }
    .step-badge-completed {
      background-color: #ecfdf5;
      color: #059669;
      border-color: #10b981;
    }
    .dark .step-badge-completed {
      background-color: rgba(5, 150, 105, 0.2);
      color: #34d399;
      border-color: #059669;
    }
    .step-badge-pending {
      background-color: #f3f4f6;
      color: #9ca3af;
      border-color: #e5e7eb;
    }
    .dark .step-badge-pending {
      background-color: #1f2937;
      color: #6b7280;
      border-color: #374151;
    }
  </style>
</head>
<body class="bg-[#F4F8F7] dark:bg-[#0B0B0C] text-[#082E2A] dark:text-gray-100 min-h-screen flex flex-col font-sans transition-colors">

  <!-- Top Simple Navigation -->
  <header class="bg-white/90 dark:bg-[#121716]/90 backdrop-blur-md border-b border-gray-100 dark:border-white/10 px-4 sm:px-8 py-3.5 sticky top-0 z-30 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-emerald-600 text-white flex items-center justify-center font-bold text-lg shadow-sm">
        <i data-lucide="graduation-cap" class="w-5 h-5"></i>
      </div>
      <div>
        <span class="font-bold text-base tracking-tight text-[#082E2A] dark:text-white">Study Planner</span>
        <span class="ml-2 text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">New Student Setup</span>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <button id="dark-toggle" type="button" class="p-2 rounded-xl border border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition" title="Toggle Dark Mode">
        <i data-lucide="moon" class="w-4 h-4 dark:hidden"></i>
        <i data-lucide="sun" class="w-4 h-4 hidden dark:block"></i>
      </button>
      <a href="login.php" class="text-xs font-semibold text-gray-500 hover:text-red-600 transition flex items-center gap-1">
        <i data-lucide="log-out" class="w-3.5 h-3.5"></i> Sign Out
      </a>
    </div>
  </header>

  <!-- Main Wizard Container -->
  <main class="flex-1 max-w-4xl w-full mx-auto p-3 sm:p-6 lg:p-8 flex flex-col justify-start">

    <!-- Progress Indicator -->
    <div class="mb-8">
      <div class="flex items-center justify-between overflow-x-auto pb-3 gap-2 no-scrollbar" id="step-indicators">
        <!-- Generated dynamically via JS -->
      </div>
      <div class="w-full bg-gray-200 dark:bg-gray-800 h-1.5 rounded-full overflow-hidden mt-1">
        <div id="progress-bar-fill" class="bg-emerald-600 h-full transition-all duration-300 rounded-full" style="width: 14%;"></div>
      </div>
    </div>

    <!-- Wizard Card -->
    <div class="bg-white dark:bg-[#141A18] border border-gray-200/80 dark:border-white/10 rounded-2xl shadow-sm p-4 sm:p-8 relative min-h-[460px] flex flex-col">

      <!-- Alert / Notice Toast -->
      <div id="onboarding-notice" class="hidden mb-6 p-4 rounded-xl text-sm font-medium transition flex items-center justify-between">
        <div class="flex items-center gap-2.5">
          <i id="notice-icon" data-lucide="info" class="w-5 h-5 shrink-0"></i>
          <span id="notice-text"></span>
        </div>
        <button type="button" onclick="document.getElementById('onboarding-notice').classList.add('hidden')" class="opacity-70 hover:opacity-100">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <!-- ========================================================
           STEP 1: ABOUT YOU
           ======================================================== -->
      <div id="step-pane-1" class="step-pane flex-1 flex flex-col justify-between">
        <div>
          <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 mb-3">
            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Step 1 of 7 • Personal Profile
          </div>
          <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-[#082E2A] dark:text-white">Welcome! What should we call you?</h2>
          <p class="text-sm text-[#53736D] dark:text-gray-400 mt-1.5 max-w-xl">
            Let's personalize your study planner. Please verify your full name and confirm your student account details.
          </p>

          <div class="mt-8 space-y-5 max-w-lg">
            <div>
              <label for="input-fullname" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-1.5">
                Full Name <span class="text-red-500">*</span>
              </label>
              <input type="text" id="input-fullname" class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-white/5 text-sm font-medium focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition" placeholder="e.g. Samuel Adekunle" required>
            </div>
            <div>
              <label class="block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1.5">
                Account Email
              </label>
              <div id="display-email" class="px-4 py-3 rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/[0.03] text-sm text-gray-600 dark:text-gray-300 flex items-center gap-2">
                <i data-lucide="mail" class="w-4 h-4 text-gray-400"></i>
                <span id="email-text">Loading...</span>
              </div>
            </div>
          </div>
        </div>

        <div class="pt-8 border-t border-gray-100 dark:border-white/10 flex items-center justify-end mt-8">
          <button id="btn-step1-next" type="button" class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition flex items-center gap-2 shadow-sm">
            <span>Next: Academic Studies</span>
            <i data-lucide="arrow-right" class="w-4 h-4"></i>
          </button>
        </div>
      </div>

      <!-- ========================================================
           STEP 2: STUDIES (Programme, Level, Semester, Session)
           ======================================================== -->
      <div id="step-pane-2" class="step-pane hidden flex-1 flex flex-col justify-between">
        <div>
          <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 mb-3">
            <i data-lucide="book-open" class="w-3.5 h-3.5"></i> Step 2 of 7 • Academic Programme
          </div>
          <h2 class="text-2xl font-bold tracking-tight text-[#082E2A] dark:text-white">Tell us about your current studies</h2>
          <p class="text-sm text-[#53736D] dark:text-gray-400 mt-1.5 max-w-xl">
            This helps Study Planner categorize your courses, timetable, and study periods for the current academic session.
          </p>

          <div class="mt-8 grid grid-cols-1 sm:grid-cols-2 gap-5 max-w-2xl">
            <div class="sm:col-span-2">
              <label for="input-programme" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-1.5">
                Programme / Department <span class="text-red-500">*</span>
              </label>
              <input type="text" id="input-programme" class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-white/5 text-sm font-medium focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition" placeholder="e.g. Computer Science, Mechanical Engineering" required>
            </div>

            <div>
              <label for="input-level" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-1.5">
                Level / Academic Year <span class="text-red-500">*</span>
              </label>
              <select id="input-level" class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-[#1b2220] text-sm font-medium focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition">
                <option value="Level 100">Level 100 (Freshman / 1st Year)</option>
                <option value="Level 200">Level 200 (Sophomore / 2nd Year)</option>
                <option value="Level 300">Level 300 (Junior / 3rd Year)</option>
                <option value="Level 400" selected>Level 400 (Senior / 4th Year)</option>
                <option value="Level 500">Level 500 (Final Year / 5th Year)</option>
                <option value="Postgraduate">Postgraduate / Masters / PhD</option>
              </select>
            </div>

            <div>
              <label for="input-semester" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-1.5">
                Current Semester <span class="text-red-500">*</span>
              </label>
              <select id="input-semester" class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-[#1b2220] text-sm font-medium focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition">
                <option value="First Semester" selected>First Semester</option>
                <option value="Second Semester">Second Semester</option>
                <option value="Summer / Short Term">Summer / Short Term</option>
              </select>
            </div>

            <div class="sm:col-span-2">
              <label for="input-session" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-1.5">
                Academic Session (Optional)
              </label>
              <input type="text" id="input-session" class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-white/5 text-sm font-medium focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition" placeholder="e.g. 2025/2026">
            </div>
          </div>
        </div>

        <div class="pt-8 border-t border-gray-100 dark:border-white/10 flex items-center justify-between mt-8">
          <button type="button" onclick="goToStep(1)" class="px-5 py-2.5 rounded-xl border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 font-semibold text-sm transition flex items-center gap-1.5">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
          </button>
          <button id="btn-step2-next" type="button" class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition flex items-center gap-2 shadow-sm">
            <span>Next: Courses Registration</span>
            <i data-lucide="arrow-right" class="w-4 h-4"></i>
          </button>
        </div>
      </div>

      <!-- ========================================================
           STEP 3: COURSE REGISTRATION IMPORT
           ======================================================== -->
      <div id="step-pane-3" class="step-pane hidden flex-1 flex flex-col justify-between">
        <div>
          <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 mb-3">
            <i data-lucide="files" class="w-3.5 h-3.5"></i> Step 3 of 7 • Registered Courses
          </div>
          <h2 class="text-2xl font-bold tracking-tight text-[#082E2A] dark:text-white">Import your Course Registration</h2>
          <p class="text-sm text-[#53736D] dark:text-gray-400 mt-1.5 max-w-2xl">
            Upload your registered course slip (.pdf, .docx, .txt), paste the registration text, or add courses manually. Study Planner will automatically extract and structure your courses.
          </p>

          <!-- Input Options Tabs -->
          <div class="mt-6 flex items-center gap-2 border-b border-gray-200 dark:border-white/10 pb-3">
            <button id="course-tab-file" type="button" onclick="switchCourseInputMode('file')" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 transition flex items-center gap-1.5">
              <i data-lucide="upload-cloud" class="w-3.5 h-3.5"></i> Upload Document
            </button>
            <button id="course-tab-text" type="button" onclick="switchCourseInputMode('text')" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition flex items-center gap-1.5">
              <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Paste Text
            </button>
            <button id="course-tab-manual" type="button" onclick="switchCourseInputMode('manual')" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition flex items-center gap-1.5">
              <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Manually
            </button>
          </div>

          <!-- File Upload Mode -->
          <div id="course-mode-file" class="mt-4">
            <div id="course-dropzone" class="border-2 border-dashed border-gray-300 dark:border-white/15 hover:border-emerald-500 dark:hover:border-emerald-500 rounded-2xl p-7 text-center transition cursor-pointer bg-gray-50/50 dark:bg-white/[0.02]">
              <input type="file" id="course-file-input" class="hidden" accept=".pdf,.docx,.doc,.txt">
              <div class="w-12 h-12 rounded-2xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 flex items-center justify-center mx-auto mb-3">
                <i data-lucide="file-up" class="w-6 h-6"></i>
              </div>
              <div class="text-sm font-bold text-gray-800 dark:text-gray-200">
                Click to browse or drop your course registration file here
              </div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Supports PDF, DOCX, DOC, TXT (up to 15MB)</p>
              <div id="course-filename-display" class="hidden mt-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 text-xs font-semibold">
                <i data-lucide="file-check" class="w-4 h-4"></i> <span id="course-file-name"></span>
              </div>
            </div>
            <div class="mt-3 flex justify-end">
              <button id="btn-parse-course-file" type="button" class="hidden px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs transition flex items-center gap-1.5">
                <i data-lucide="cpu" class="w-3.5 h-3.5"></i> Extract Courses
              </button>
            </div>
          </div>

          <!-- Text Paste Mode -->
          <div id="course-mode-text" class="hidden mt-4">
            <textarea id="course-text-input" rows="6" class="w-full p-4 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-white/5 text-sm font-mono focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition" placeholder="Paste your course list or table here... Example:
CSC 411 Computer Networks 3 Units
CSC 413 Distributed Systems 3 Units
CSC 415 Compiler Construction 4 Units"></textarea>
            <div class="mt-3 flex justify-end">
              <button id="btn-parse-course-text" type="button" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs transition flex items-center gap-1.5">
                <i data-lucide="cpu" class="w-3.5 h-3.5"></i> Extract from Text
              </button>
            </div>
          </div>

          <!-- Courses Table / Review List -->
          <div id="courses-review-container" class="mt-6">
            <div class="flex items-center justify-between mb-3">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-2">
                <span>Courses to Commit</span>
                <span id="courses-count-badge" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">0 courses</span>
              </div>
              <button type="button" onclick="addNewCourseRow()" class="px-2.5 py-1 rounded-lg border border-emerald-300 dark:border-emerald-700/60 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 text-xs font-semibold flex items-center gap-1">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Course
              </button>
            </div>

            <div class="border border-gray-200 dark:border-white/10 rounded-xl overflow-hidden bg-white dark:bg-[#18201E]">
              <div class="max-h-64 overflow-y-auto">
                <table class="w-full text-left text-xs">
                  <thead class="bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10 sticky top-0 text-gray-600 dark:text-gray-400 font-semibold uppercase tracking-wider">
                    <tr>
                      <th class="p-3">Course Code</th>
                      <th class="p-3">Course Title</th>
                      <th class="p-3 w-20 text-center">Units</th>
                      <th class="p-3 w-16 text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody id="courses-tbody" class="divide-y divide-gray-100 dark:divide-white/5">
                    <tr id="courses-empty-row">
                      <td colspan="4" class="p-6 text-center text-gray-400 dark:text-gray-500">
                        No courses added yet. Upload your form, paste text, or click "Add Course".
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="pt-8 border-t border-gray-100 dark:border-white/10 flex items-center justify-between mt-8">
          <button type="button" onclick="goToStep(2)" class="px-5 py-2.5 rounded-xl border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 font-semibold text-sm transition flex items-center gap-1.5">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
          </button>
          <button id="btn-step3-next" type="button" class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition flex items-center gap-2 shadow-sm">
            <span>Save Courses & Next: Timetable</span>
            <i data-lucide="arrow-right" class="w-4 h-4"></i>
          </button>
        </div>
      </div>

      <!-- ========================================================
           STEP 4: TIMETABLE IMPORT (Optional)
           ======================================================== -->
      <div id="step-pane-4" class="step-pane hidden flex-1 flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between mb-3">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
              <i data-lucide="calendar" class="w-3.5 h-3.5"></i> Step 4 of 7 • Weekly Timetable
            </div>
            <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">Optional</span>
          </div>
          <h2 class="text-2xl font-bold tracking-tight text-[#082E2A] dark:text-white">Import your Class Timetable</h2>
          <p class="text-sm text-[#53736D] dark:text-gray-400 mt-1.5 max-w-2xl">
            Upload your weekly lecture timetable so your study planner knows your class hours and can suggest study blocks around them. You can also skip this if you don't have one yet.
          </p>

          <!-- Input Options Tabs -->
          <div class="mt-6 flex items-center gap-2 border-b border-gray-200 dark:border-white/10 pb-3">
            <button id="tt-tab-file" type="button" onclick="switchTimetableInputMode('file')" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 transition flex items-center gap-1.5">
              <i data-lucide="upload-cloud" class="w-3.5 h-3.5"></i> Upload Document
            </button>
            <button id="tt-tab-text" type="button" onclick="switchTimetableInputMode('text')" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition flex items-center gap-1.5">
              <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Paste Timetable
            </button>
            <button id="tt-tab-manual" type="button" onclick="switchTimetableInputMode('manual')" class="px-3.5 py-1.5 rounded-lg text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition flex items-center gap-1.5">
              <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Class Manually
            </button>
          </div>

          <!-- File Upload Mode -->
          <div id="tt-mode-file" class="mt-4">
            <div id="tt-dropzone" class="border-2 border-dashed border-gray-300 dark:border-white/15 hover:border-emerald-500 rounded-2xl p-7 text-center transition cursor-pointer bg-gray-50/50 dark:bg-white/[0.02]">
              <input type="file" id="tt-file-input" class="hidden" accept=".pdf,.docx,.doc,.txt">
              <div class="w-12 h-12 rounded-2xl bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 flex items-center justify-center mx-auto mb-3">
                <i data-lucide="calendar" class="w-6 h-6"></i>
              </div>
              <div class="text-sm font-bold text-gray-800 dark:text-gray-200">
                Click to browse or drop your weekly timetable file here
              </div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Supports PDF, DOCX, DOC, TXT (up to 15MB)</p>
              <div id="tt-filename-display" class="hidden mt-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 text-xs font-semibold">
                <i data-lucide="file-check" class="w-4 h-4"></i> <span id="tt-file-name"></span>
              </div>
            </div>
            <div class="mt-3 flex justify-end">
              <button id="btn-parse-tt-file" type="button" class="hidden px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs transition flex items-center gap-1.5">
                <i data-lucide="cpu" class="w-3.5 h-3.5"></i> Extract Timetable
              </button>
            </div>
          </div>

          <!-- Text Paste Mode -->
          <div id="tt-mode-text" class="hidden mt-4">
            <textarea id="tt-text-input" rows="6" class="w-full p-4 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-white/5 text-sm font-mono focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition" placeholder="Paste your weekly timetable text here... Example:
Monday 09:00 - 11:00 CSC 411 Lecture (LT 2)
Wednesday 14:00 - 16:00 CSC 413 Distributed Systems Lab
Friday 10:00 - 12:00 CSC 415 Compiler Design"></textarea>
            <div class="mt-3 flex justify-end">
              <button id="btn-parse-tt-text" type="button" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs transition flex items-center gap-1.5">
                <i data-lucide="cpu" class="w-3.5 h-3.5"></i> Extract from Text
              </button>
            </div>
          </div>

          <!-- Timetable Review Table -->
          <div id="tt-review-container" class="mt-6">
            <div class="flex items-center justify-between mb-3">
              <div class="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 flex items-center gap-2">
                <span>Classes to Commit</span>
                <span id="tt-count-badge" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">0 classes</span>
              </div>
              <button type="button" onclick="addNewTimetableRow()" class="px-2.5 py-1 rounded-lg border border-emerald-300 dark:border-emerald-700/60 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 text-xs font-semibold flex items-center gap-1">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Class
              </button>
            </div>

            <div class="border border-gray-200 dark:border-white/10 rounded-xl overflow-hidden bg-white dark:bg-[#18201E]">
              <div class="max-h-64 overflow-y-auto">
                <table class="w-full text-left text-xs">
                  <thead class="bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10 sticky top-0 text-gray-600 dark:text-gray-400 font-semibold uppercase tracking-wider">
                    <tr>
                      <th class="p-3 w-28">Day</th>
                      <th class="p-3 w-28">Course</th>
                      <th class="p-3">Title / Details</th>
                      <th class="p-3 w-36">Time</th>
                      <th class="p-3 w-14 text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody id="tt-tbody" class="divide-y divide-gray-100 dark:divide-white/5">
                    <tr id="tt-empty-row">
                      <td colspan="5" class="p-6 text-center text-gray-400 dark:text-gray-500">
                        No timetable slots added yet. You can upload a timetable or skip this step.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="pt-8 border-t border-gray-100 dark:border-white/10 flex items-center justify-between mt-8">
          <button type="button" onclick="goToStep(3)" class="px-5 py-2.5 rounded-xl border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 font-semibold text-sm transition flex items-center gap-1.5">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
          </button>
          <div class="flex items-center gap-3">
            <button id="btn-step4-skip" type="button" onclick="skipStep(4)" class="px-4 py-2.5 rounded-xl text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition">
              Skip timetable for now
            </button>
            <button id="btn-step4-next" type="button" class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition flex items-center gap-2 shadow-sm">
              <span>Save & Next: Curriculum</span>
              <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- ========================================================
           STEP 5: CURRICULUM IMPORT (Optional)
           ======================================================== -->
      <div id="step-pane-5" class="step-pane hidden flex-1 flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between mb-3">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
              <i data-lucide="calendar-range" class="w-3.5 h-3.5"></i> Step 5 of 7 • Semester Curriculum
            </div>
            <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">Optional</span>
          </div>
          <h2 class="text-2xl font-bold tracking-tight text-[#082E2A] dark:text-white">Academic Calendar & Curriculum</h2>
          <p class="text-sm text-[#53736D] dark:text-gray-400 mt-1.5 max-w-2xl">
            Upload your university academic calendar or set your semester start and end dates to automatically organize teaching weeks, revision, and exam countdowns.
          </p>

          <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-5 max-w-2xl">
            <div>
              <label for="input-sem-start" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-1.5">
                Semester Start Date
              </label>
              <input type="date" id="input-sem-start" class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-[#1b2220] text-sm font-medium focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition">
            </div>
            <div>
              <label for="input-sem-end" class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-1.5">
                Semester End Date (Exams Finish)
              </label>
              <input type="date" id="input-sem-end" class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-[#1b2220] text-sm font-medium focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 outline-none transition">
            </div>
          </div>

          <!-- Document Upload Option for Curriculum -->
          <div class="mt-6 p-4 rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.02]">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-xs font-bold text-gray-800 dark:text-gray-200">Have a Semester Calendar document?</div>
                <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Upload a PDF or TXT to automatically extract all academic weeks</div>
              </div>
              <label for="curriculum-file-input" class="px-3 py-1.5 rounded-lg border border-emerald-300 dark:border-emerald-700/60 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 text-xs font-semibold cursor-pointer transition flex items-center gap-1.5">
                <i data-lucide="upload" class="w-3.5 h-3.5"></i> Browse File
              </label>
              <input type="file" id="curriculum-file-input" class="hidden" accept=".pdf,.docx,.txt">
            </div>
            <div id="curriculum-extracted-status" class="hidden mt-3 p-2.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 text-xs font-semibold"></div>
          </div>
        </div>

        <div class="pt-8 border-t border-gray-100 dark:border-white/10 flex items-center justify-between mt-8">
          <button type="button" onclick="goToStep(4)" class="px-5 py-2.5 rounded-xl border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 font-semibold text-sm transition flex items-center gap-1.5">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
          </button>
          <div class="flex items-center gap-3">
            <button id="btn-step5-skip" type="button" onclick="skipStep(5)" class="px-4 py-2.5 rounded-xl text-xs font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition">
              Skip curriculum for now
            </button>
            <button id="btn-step5-next" type="button" class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition flex items-center gap-2 shadow-sm">
              <span>Save & Next: Review</span>
              <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- ========================================================
           STEP 6: REAL DATA REVIEW
           ======================================================== -->
      <div id="step-pane-6" class="step-pane hidden flex-1 flex flex-col justify-between">
        <div>
          <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 mb-3">
            <i data-lucide="check-check" class="w-3.5 h-3.5"></i> Step 6 of 7 • Review & Confirmation
          </div>
          <h2 class="text-2xl font-bold tracking-tight text-[#082E2A] dark:text-white">Review your Academic Profile</h2>
          <p class="text-sm text-[#53736D] dark:text-gray-400 mt-1.5 max-w-xl">
            Please verify your information below. You can jump back to any step to make updates before configuring your study targets.
          </p>

          <div class="mt-6 space-y-4 max-w-3xl">

            <!-- Review Card 1: About You & Studies -->
            <div class="p-5 rounded-2xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.02]">
              <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                  <i data-lucide="user" class="w-4 h-4"></i> About You & Studies
                </div>
                <button type="button" onclick="goToStep(1)" class="text-xs font-semibold text-emerald-600 hover:underline flex items-center gap-1">
                  <i data-lucide="pencil" class="w-3 h-3"></i> Edit
                </button>
              </div>
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                <div>
                  <div class="text-gray-400 uppercase text-[10px] font-semibold">Student Name</div>
                  <div id="rev-name" class="font-bold text-gray-800 dark:text-gray-200 mt-0.5">-</div>
                </div>
                <div>
                  <div class="text-gray-400 uppercase text-[10px] font-semibold">Programme</div>
                  <div id="rev-program" class="font-bold text-gray-800 dark:text-gray-200 mt-0.5">-</div>
                </div>
                <div>
                  <div class="text-gray-400 uppercase text-[10px] font-semibold">Level</div>
                  <div id="rev-level" class="font-bold text-gray-800 dark:text-gray-200 mt-0.5">-</div>
                </div>
                <div>
                  <div class="text-gray-400 uppercase text-[10px] font-semibold">Semester</div>
                  <div id="rev-semester" class="font-bold text-gray-800 dark:text-gray-200 mt-0.5">-</div>
                </div>
              </div>
            </div>

            <!-- Review Card 2: Courses -->
            <div class="p-5 rounded-2xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.02]">
              <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                  <i data-lucide="book-open" class="w-4 h-4"></i> Registered Courses
                  <span id="rev-courses-count" class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300 text-[10px] font-bold">0 courses</span>
                </div>
                <button type="button" onclick="goToStep(3)" class="text-xs font-semibold text-emerald-600 hover:underline flex items-center gap-1">
                  <i data-lucide="pencil" class="w-3 h-3"></i> Edit Courses
                </button>
              </div>
              <div id="rev-courses-list" class="flex flex-wrap gap-2">
                <!-- Badges populated via JS -->
              </div>
            </div>

            <!-- Review Card 3: Timetable -->
            <div class="p-5 rounded-2xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.02]">
              <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                  <i data-lucide="calendar" class="w-4 h-4"></i> Weekly Schedule
                  <span id="rev-tt-count" class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300 text-[10px] font-bold">0 classes</span>
                </div>
                <button type="button" onclick="goToStep(4)" class="text-xs font-semibold text-emerald-600 hover:underline flex items-center gap-1">
                  <i data-lucide="pencil" class="w-3 h-3"></i> Edit Timetable
                </button>
              </div>
              <div id="rev-tt-summary" class="text-xs text-gray-600 dark:text-gray-300">
                <!-- Summary text populated via JS -->
              </div>
            </div>

            <!-- Review Card 4: Curriculum / Calendar -->
            <div class="p-5 rounded-2xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.02]">
              <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                  <i data-lucide="calendar-range" class="w-4 h-4"></i> Academic Calendar
                </div>
                <button type="button" onclick="goToStep(5)" class="text-xs font-semibold text-emerald-600 hover:underline flex items-center gap-1">
                  <i data-lucide="pencil" class="w-3 h-3"></i> Edit Calendar
                </button>
              </div>
              <div id="rev-curriculum-summary" class="text-xs text-gray-600 dark:text-gray-300">
                <!-- Summary text populated via JS -->
              </div>
            </div>

          </div>
        </div>

        <div class="pt-8 border-t border-gray-100 dark:border-white/10 flex items-center justify-between mt-8">
          <button type="button" onclick="goToStep(5)" class="px-5 py-2.5 rounded-xl border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 font-semibold text-sm transition flex items-center gap-1.5">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
          </button>
          <button id="btn-step6-next" type="button" onclick="goToStep(7)" class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition flex items-center gap-2 shadow-sm">
            <span>Looks Great: Set Study Goals</span>
            <i data-lucide="arrow-right" class="w-4 h-4"></i>
          </button>
        </div>
      </div>

      <!-- ========================================================
           STEP 7: FIRST-USE GOALS (Required)
           ======================================================== -->
      <div id="step-pane-7" class="step-pane hidden flex-1 flex flex-col justify-between">
        <div>
          <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 mb-3">
            <i data-lucide="target" class="w-3.5 h-3.5"></i> Step 7 of 7 • Study Habits & Targets
          </div>
          <h2 class="text-2xl font-bold tracking-tight text-[#082E2A] dark:text-white">Set your Weekly Study Goals</h2>
          <p class="text-sm text-[#53736D] dark:text-gray-400 mt-1.5 max-w-xl">
            Choose how much time you want to dedicate to independent study outside of class. Study Planner will help you stay consistent all semester.
          </p>

          <div class="mt-8 space-y-6 max-w-2xl">

            <!-- Target Hours Selector -->
            <div>
              <label class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-3">
                How many hours would you like to study each week? <span class="text-red-500">*</span>
              </label>
              <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                <button type="button" onclick="selectGoalHours(8, this)" class="goal-hour-card p-4 rounded-xl border border-gray-200 dark:border-white/10 hover:border-emerald-500 text-left transition bg-white dark:bg-white/[0.02]">
                  <div class="text-lg font-bold text-gray-900 dark:text-white">8 hours</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">~1.1 hrs / day (Light)</div>
                </button>
                <button type="button" onclick="selectGoalHours(10, this)" class="goal-hour-card p-4 rounded-xl border border-gray-200 dark:border-white/10 hover:border-emerald-500 text-left transition bg-white dark:bg-white/[0.02]">
                  <div class="text-lg font-bold text-gray-900 dark:text-white">10 hours</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">~1.4 hrs / day (Balanced)</div>
                </button>
                <button type="button" onclick="selectGoalHours(12, this)" class="goal-hour-card p-4 rounded-xl border border-gray-200 dark:border-white/10 hover:border-emerald-500 text-left transition bg-white dark:bg-white/[0.02]">
                  <div class="text-lg font-bold text-gray-900 dark:text-white">12 hours</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">~1.7 hrs / day (Focused)</div>
                </button>
                <button type="button" onclick="selectGoalHours(15, this)" class="goal-hour-card border-2 border-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 p-4 rounded-xl text-left transition">
                  <div class="flex items-center justify-between">
                    <span class="text-lg font-bold text-emerald-800 dark:text-emerald-300">15 hours</span>
                    <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-600 text-white">Recommended</span>
                  </div>
                  <div class="text-xs text-emerald-700/80 dark:text-emerald-400/80 mt-0.5">~2.1 hrs / day (Standard)</div>
                </button>
                <button type="button" onclick="selectGoalHours(20, this)" class="goal-hour-card p-4 rounded-xl border border-gray-200 dark:border-white/10 hover:border-emerald-500 text-left transition bg-white dark:bg-white/[0.02]">
                  <div class="text-lg font-bold text-gray-900 dark:text-white">20 hours</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">~2.8 hrs / day (Intensive)</div>
                </button>
                <div class="p-4 rounded-xl border border-gray-200 dark:border-white/10 flex flex-col justify-center bg-white dark:bg-white/[0.02]">
                  <label for="input-custom-goal" class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Custom Hours</label>
                  <div class="flex items-center gap-1.5">
                    <input type="number" id="input-custom-goal" min="1" max="80" step="1" placeholder="Custom" class="w-full px-2.5 py-1 rounded-lg border border-gray-300 dark:border-white/15 text-sm font-bold bg-white dark:bg-white/5 outline-none focus:border-emerald-600">
                    <span class="text-xs font-semibold text-gray-500">hrs</span>
                  </div>
                </div>
              </div>
              <input type="hidden" id="selected-goal-hours" value="15">
            </div>

            <!-- Preferred Days -->
            <div>
              <label class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-2">
                Preferred Study Days
              </label>
              <div class="flex items-center gap-2 mb-2.5">
                <button type="button" onclick="setQuickDays('weekdays')" class="px-3 py-1 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-white/5 hover:bg-gray-200 text-gray-700 dark:text-gray-300 transition">Weekdays (Mon–Fri)</button>
                <button type="button" onclick="setQuickDays('all')" class="px-3 py-1 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-white/5 hover:bg-gray-200 text-gray-700 dark:text-gray-300 transition">Every Day (Mon–Sun)</button>
                <button type="button" onclick="setQuickDays('weekends')" class="px-3 py-1 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-white/5 hover:bg-gray-200 text-gray-700 dark:text-gray-300 transition">Weekends Only</button>
              </div>
              <div class="flex flex-wrap gap-2" id="day-toggles">
                <button type="button" data-day="1" class="day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">Mon</button>
                <button type="button" data-day="2" class="day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">Tue</button>
                <button type="button" data-day="3" class="day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">Wed</button>
                <button type="button" data-day="4" class="day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">Thu</button>
                <button type="button" data-day="5" class="day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">Fri</button>
                <button type="button" data-day="6" class="day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-400">Sat</button>
                <button type="button" data-day="0" class="day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-400">Sun</button>
              </div>
            </div>

            <!-- Preferred Time -->
            <div>
              <label class="block text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300 mb-2">
                When do you focus best?
              </label>
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                <button type="button" onclick="selectStudyTime('morning', this)" class="study-time-btn border-2 border-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 p-3 rounded-xl text-center transition">
                  <div class="text-xs font-bold text-gray-900 dark:text-white">Morning</div>
                  <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">6 AM - 12 PM</div>
                </button>
                <button type="button" onclick="selectStudyTime('afternoon', this)" class="study-time-btn border border-gray-200 dark:border-white/10 p-3 rounded-xl text-center transition">
                  <div class="text-xs font-bold text-gray-900 dark:text-white">Afternoon</div>
                  <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">12 PM - 5 PM</div>
                </button>
                <button type="button" onclick="selectStudyTime('evening', this)" class="study-time-btn border border-gray-200 dark:border-white/10 p-3 rounded-xl text-center transition">
                  <div class="text-xs font-bold text-gray-900 dark:text-white">Evening</div>
                  <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">5 PM - 10 PM</div>
                </button>
                <button type="button" onclick="selectStudyTime('flexible', this)" class="study-time-btn border border-gray-200 dark:border-white/10 p-3 rounded-xl text-center transition">
                  <div class="text-xs font-bold text-gray-900 dark:text-white">Flexible</div>
                  <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">Any time</div>
                </button>
              </div>
              <input type="hidden" id="selected-study-time" value="morning">
            </div>

          </div>
        </div>

        <div class="pt-8 border-t border-gray-100 dark:border-white/10 flex items-center justify-between mt-8">
          <button type="button" onclick="goToStep(6)" class="px-5 py-2.5 rounded-xl border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 font-semibold text-sm transition flex items-center gap-1.5">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Review
          </button>
          <button id="btn-complete-onboarding" type="button" class="px-7 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm transition flex items-center gap-2 shadow-md">
            <span>Complete Setup & Go to Dashboard</span>
            <i data-lucide="check" class="w-4 h-4"></i>
          </button>
        </div>
      </div>

      <!-- ========================================================
           READY STATE / COMPLETING
           ======================================================== -->
      <div id="step-pane-ready" class="step-pane hidden flex-1 flex flex-col items-center justify-center text-center p-8">
        <div class="w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-900/50 text-emerald-600 dark:text-emerald-300 flex items-center justify-center mb-4">
          <i data-lucide="check-circle" class="w-10 h-10 animate-pulse"></i>
        </div>
        <h2 class="text-2xl font-bold text-[#082E2A] dark:text-white">You're All Set!</h2>
        <p class="text-sm text-[#53736D] dark:text-gray-400 mt-1 max-w-md">
          Setting up your academic workspace, study goals, and semester schedule... Redirecting you to your dashboard.
        </p>
        <div class="mt-6">
          <div class="inline-flex items-center gap-2 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
            <i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Loading Dashboard...
          </div>
        </div>
      </div>

    </div>

  </main>

  <footer class="mt-auto py-4 text-center text-xs text-gray-400 dark:text-gray-600 border-t border-gray-100 dark:border-white/5">
    Study Planner • Academic Onboarding & Setup
  </footer>

  <script src="../assets/js/onboarding.js"></script>
</body>
</html>
