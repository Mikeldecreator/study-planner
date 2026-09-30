<?php
// Study Planner - Unified Authentication (Sign In & Registration)
$initialMode = 'login';
$isVerified = isset($_GET['verified']) && $_GET['verified'] === '1';
$prefillEmail = trim((string) ($_GET['email'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In &bull; Study Planner</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
  .auth-tab.active {
    background-color: #087f55;
    color: #ffffff;
    box-shadow: 0 2px 8px rgba(8, 127, 85, 0.2);
  }
  .auth-tab:not(.active) {
    color: #496861;
  }
  .dark .auth-tab:not(.active) {
    color: #9db5ae;
  }
  .auth-tab:not(.active):hover {
    color: #087f55;
  }
  .dark .auth-tab:not(.active):hover {
    color: #34d399;
  }
</style>
</head>

<body class="bg-[#F4F8F6] dark:bg-[#090E0C] text-[#082E2A] dark:text-gray-100 min-h-screen flex items-center justify-center p-3 sm:p-6 lg:p-8 transition-colors" data-auth-mode="<?= htmlspecialchars($initialMode) ?>">

  <div class="w-full max-w-5xl bg-white dark:bg-[#121A17] rounded-3xl shadow-xl border border-[#E0EBE8] dark:border-white/10 overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[640px]">

    <!-- ======================================================= -->
    <!-- LEFT PANEL: ACADEMIC BRANDING & VALUE PROPOSITION -->
    <!-- ======================================================= -->
    <div class="lg:col-span-5 bg-gradient-to-br from-[#082E2A] via-[#0B4B42] to-[#041F1C] text-white p-6 sm:p-8 lg:p-10 flex flex-col justify-between relative overflow-hidden">
      <!-- Decorative Glow -->
      <div class="absolute -right-20 -top-20 w-64 h-64 rounded-full bg-emerald-500/10 blur-3xl pointer-events-none"></div>
      <div class="absolute -left-20 -bottom-20 w-64 h-64 rounded-full bg-teal-500/10 blur-3xl pointer-events-none"></div>

      <!-- Top: Brand -->
      <div class="relative z-10 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-white/10 backdrop-blur border border-white/20 flex items-center justify-center text-xl shadow-inner">
          🎓
        </div>
        <div>
          <div class="font-extrabold tracking-tight text-white text-base leading-tight">
            STUDY PLANNER
          </div>
          <div class="text-[11px] uppercase tracking-wider text-emerald-300 font-semibold leading-tight mt-0.5">
            Academic Workload Manager
          </div>
        </div>
      </div>

      <!-- Center: Academic Pitch & Feature List -->
      <div class="relative z-10 my-8 sm:my-10 space-y-6">
        <div>
          <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-semibold bg-emerald-500/20 text-emerald-200 border border-emerald-400/30 mb-3">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            University Academic Engine
          </span>
          <h2 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-white leading-snug">
            Plan your semester with confidence.
          </h2>
          <p class="text-xs sm:text-sm text-emerald-100/80 mt-2 leading-relaxed">
            The intelligent workload manager built specifically for university students. Stay ahead of deadlines, automate your study sessions, and master your timetable.
          </p>
        </div>

        <div class="space-y-3 text-xs sm:text-sm text-emerald-50/90">
          <div class="flex items-start gap-2.5">
            <div class="w-5 h-5 rounded-md bg-emerald-500/20 flex items-center justify-center text-emerald-300 shrink-0 mt-0.5">
              <i data-lucide="calendar-check" class="w-3.5 h-3.5"></i>
            </div>
            <span><strong>Smart Academic Scheduling</strong> &mdash; Automatically schedules study time based on workload</span>
          </div>

          <div class="flex items-start gap-2.5">
            <div class="w-5 h-5 rounded-md bg-emerald-500/20 flex items-center justify-center text-emerald-300 shrink-0 mt-0.5">
              <i data-lucide="book-open" class="w-3.5 h-3.5"></i>
            </div>
            <span><strong>Course Workload Tracking</strong> &mdash; Real-time progress across all registered units</span>
          </div>

          <div class="flex items-start gap-2.5">
            <div class="w-5 h-5 rounded-md bg-emerald-500/20 flex items-center justify-center text-emerald-300 shrink-0 mt-0.5">
              <i data-lucide="timer" class="w-3.5 h-3.5"></i>
            </div>
            <span><strong>Focus Timer &amp; Countdown</strong> &mdash; Live deep-work timer with time estimates</span>
          </div>

          <div class="flex items-start gap-2.5">
            <div class="w-5 h-5 rounded-md bg-emerald-500/20 flex items-center justify-center text-emerald-300 shrink-0 mt-0.5">
              <i data-lucide="bell" class="w-3.5 h-3.5"></i>
            </div>
            <span><strong>Adaptive Reminders</strong> &mdash; Intelligent notices for classes and approaching deadlines</span>
          </div>
        </div>
      </div>

      <!-- Bottom: Testimonial & Security Badge -->
      <div class="relative z-10 pt-4 border-t border-white/10 flex items-center justify-between text-[11px] text-emerald-200/70">
        <span class="flex items-center gap-1.5">
          <i data-lucide="shield-check" class="w-4 h-4 text-emerald-400"></i>
          Secure &bull; Verified Student Data
        </span>
        <span>Version 2.0</span>
      </div>
    </div>

    <!-- ======================================================= -->
    <!-- RIGHT PANEL: UNIFIED AUTH CARD (LOGIN & REGISTER TOGGLE) -->
    <!-- ======================================================= -->
    <div class="lg:col-span-7 p-6 sm:p-10 lg:p-12 flex flex-col justify-center bg-white dark:bg-[#121A17]">

      <!-- TOGGLE BAR -->
      <div class="flex items-center p-1 bg-[#EEF5F2] dark:bg-white/5 rounded-2xl mb-6 max-w-sm mx-auto w-full border border-[#D9E7E3] dark:border-white/10">
        <button
          id="tab-btn-login"
          type="button"
          class="auth-tab active flex-1 py-2.5 px-4 text-xs sm:text-sm font-bold rounded-xl transition text-center focus:outline-none"
        >
          Sign In
        </button>
        <button
          id="tab-btn-register"
          type="button"
          class="auth-tab flex-1 py-2.5 px-4 text-xs sm:text-sm font-bold rounded-xl transition text-center focus:outline-none"
        >
          Create Account
        </button>
      </div>

      <div class="max-w-md mx-auto w-full">

        <!-- HEADER TITLES (DYNAMIC BASED ON ACTIVE TAB) -->
        <div class="mb-6">
          <h1 id="auth-title" class="text-xl sm:text-2xl font-extrabold text-[#082E2A] dark:text-gray-100 tracking-tight">
            Welcome back
          </h1>
          <p id="auth-subtitle" class="text-xs sm:text-sm text-[#53736D] dark:text-gray-400 mt-1">
            Sign in to view today's academic schedule and workload.
          </p>
        </div>

        <!-- BANNERS CONTAINER -->
        <div id="auth-banners" class="space-y-3 mb-5">

          <!-- Email Verified Success Banner -->
          <?php if ($isVerified): ?>
          <div id="verified-success-banner" class="flex items-start gap-3 p-3.5 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800/50 rounded-xl text-xs sm:text-sm text-emerald-800 dark:text-emerald-200">
            <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5"></i>
            <div>
              <strong>Email verified successfully!</strong>
              <p class="text-xs text-emerald-700 dark:text-emerald-300 mt-0.5">Your account is fully activated. Please sign in below to continue.</p>
            </div>
          </div>
          <?php endif; ?>

          <!-- General Error Message -->
          <div
            id="form-error"
            class="hidden p-3.5 text-xs sm:text-sm text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800/40 rounded-xl"
          ></div>

          <!-- General Success Message -->
          <div
            id="form-success"
            class="hidden p-3.5 text-xs sm:text-sm text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/40 rounded-xl"
          ></div>

          <!-- Unverified Account Alert -->
          <div
            id="unverified-banner"
            class="hidden p-4 bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800/40 rounded-xl text-xs sm:text-sm text-amber-900 dark:text-amber-200 space-y-2"
          >
            <div class="flex items-start gap-2.5">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5"></i>
              <div>
                <strong>Email verification required</strong>
                <p class="text-xs text-amber-800 dark:text-amber-300 mt-0.5">
                  Please verify your email address to access your planner.
                </p>
              </div>
            </div>
            <div class="flex items-center gap-3 pt-1">
              <button
                id="resend-verification-btn"
                type="button"
                class="px-3.5 py-1.5 bg-amber-700 hover:bg-amber-800 text-white rounded-lg text-xs font-semibold transition shadow-sm inline-flex items-center gap-1.5"
              >
                <i data-lucide="send" class="w-3.5 h-3.5"></i> Resend Verification Email
              </button>
              <span id="resend-status" class="text-xs text-amber-700 dark:text-amber-300"></span>
            </div>
          </div>

          <!-- Registration Completed Pending Verification State -->
          <div
            id="registration-pending-card"
            class="hidden p-5 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800/50 rounded-2xl text-center space-y-3"
          >
            <div class="w-12 h-12 rounded-full bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300 mx-auto flex items-center justify-center">
              <i data-lucide="mail-check" class="w-6 h-6"></i>
            </div>
            <h3 class="text-base font-bold text-emerald-900 dark:text-emerald-100">Check your inbox</h3>
            <p class="text-xs sm:text-sm text-emerald-800 dark:text-emerald-200 leading-relaxed">
              We sent a verification link to <strong id="registered-email-display"></strong>. Click the link in the email to activate your account.
            </p>
            <div class="pt-2 flex flex-col sm:flex-row items-center justify-center gap-2">
              <button
                id="reg-resend-btn"
                type="button"
                class="w-full sm:w-auto px-4 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl text-xs font-semibold transition"
              >
                Resend Email
              </button>
              <button
                id="reg-back-to-login"
                type="button"
                class="w-full sm:w-auto px-4 py-2 bg-white dark:bg-white/10 border border-emerald-300 dark:border-emerald-700/50 text-emerald-800 dark:text-emerald-200 hover:bg-emerald-50 rounded-xl text-xs font-semibold transition"
              >
                Sign In &rarr;
              </button>
            </div>
          </div>

        </div>

        <!-- ======================================================= -->
        <!-- SIGN IN FORM -->
        <!-- ======================================================= -->
        <form id="login-form" class="space-y-4">
          <div>
            <label class="block text-xs font-bold text-[#355750] dark:text-gray-300 mb-1" for="login-email">
              Email Address
            </label>
            <div class="relative">
              <i data-lucide="mail" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
              <input
                id="login-email"
                type="email"
                name="email"
                value="<?= htmlspecialchars($prefillEmail) ?>"
                autocomplete="email"
                required
                placeholder="student@university.edu"
                class="w-full pl-10 pr-3.5 py-2.5 rounded-xl border border-[#D9E7E3] dark:border-white/10 dark:bg-white/5 text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent transition"
              >
            </div>
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-xs font-bold text-[#355750] dark:text-gray-300" for="login-password">
                Password
              </label>
              <a
                href="forgot-password.php"
                class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline"
              >
                Forgot password?
              </a>
            </div>
            <div class="relative">
              <i data-lucide="lock" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
              <input
                id="login-password"
                type="password"
                name="password"
                autocomplete="current-password"
                required
                placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                class="w-full pl-10 pr-10 py-2.5 rounded-xl border border-[#D9E7E3] dark:border-white/10 dark:bg-white/5 text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent transition"
              >
              <button
                type="button"
                data-toggle-password="login-password"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                aria-label="Toggle password visibility"
              >
                <i data-lucide="eye" class="w-4 h-4"></i>
              </button>
            </div>
          </div>

          <button
            id="login-submit"
            type="submit"
            class="w-full bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl py-3 text-sm font-bold transition shadow-sm flex items-center justify-center gap-2 mt-2"
          >
            <span>Sign In</span>
            <i data-lucide="arrow-right" class="w-4 h-4"></i>
          </button>
        </form>

        <!-- ======================================================= -->
        <!-- CREATE ACCOUNT FORM -->
        <!-- ======================================================= -->
        <form id="register-form" class="space-y-4 hidden">
          <div>
            <label class="block text-xs font-bold text-[#355750] dark:text-gray-300 mb-1" for="register-name">
              Full Name
            </label>
            <div class="relative">
              <i data-lucide="user" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
              <input
                id="register-name"
                type="text"
                name="full_name"
                autocomplete="name"
                required
                placeholder="Alex Morgan"
                class="w-full pl-10 pr-3.5 py-2.5 rounded-xl border border-[#D9E7E3] dark:border-white/10 dark:bg-white/5 text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent transition"
              >
            </div>
          </div>

          <div>
            <label class="block text-xs font-bold text-[#355750] dark:text-gray-300 mb-1" for="register-email">
              University / Personal Email
            </label>
            <div class="relative">
              <i data-lucide="mail" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
              <input
                id="register-email"
                type="email"
                name="email"
                autocomplete="email"
                required
                placeholder="student@university.edu"
                class="w-full pl-10 pr-3.5 py-2.5 rounded-xl border border-[#D9E7E3] dark:border-white/10 dark:bg-white/5 text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent transition"
              >
            </div>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">
              A real verification link will be sent to this email address.
            </p>
          </div>

          <div>
            <label class="block text-xs font-bold text-[#355750] dark:text-gray-300 mb-1" for="register-password">
              Password
            </label>
            <div class="relative">
              <i data-lucide="lock" class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
              <input
                id="register-password"
                type="password"
                name="password"
                autocomplete="new-password"
                required
                minlength="8"
                placeholder="At least 8 characters"
                class="w-full pl-10 pr-10 py-2.5 rounded-xl border border-[#D9E7E3] dark:border-white/10 dark:bg-white/5 text-sm text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:border-transparent transition"
              >
              <button
                type="button"
                data-toggle-password="register-password"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                aria-label="Toggle password visibility"
              >
                <i data-lucide="eye" class="w-4 h-4"></i>
              </button>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">
              Must be at least 8 characters long.
            </p>
          </div>

          <button
            id="register-submit"
            type="submit"
            class="w-full bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl py-3 text-sm font-bold transition shadow-sm flex items-center justify-center gap-2 mt-2"
          >
            <span>Create Account</span>
            <i data-lucide="arrow-right" class="w-4 h-4"></i>
          </button>
        </form>

        <!-- FOOTER SWITCH HELPER -->
        <p id="auth-switch-prompt" class="text-xs text-[#53736D] dark:text-gray-400 text-center mt-6">
          <span id="auth-switch-text">Don't have an account yet?</span>
          <button
            id="auth-switch-btn"
            type="button"
            class="text-emerald-700 dark:text-emerald-400 font-bold ml-1 hover:underline focus:outline-none"
          >
            Create account
          </button>
        </p>

      </div>

    </div>

  </div>

  <script src="../assets/js/auth.js"></script>
  <script>
    if (window.lucide) {
      window.lucide.createIcons();
    }
  </script>
</body>
</html>