<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Study Planner</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="../assets/js/theme-init.js"></script>
</head>

<body class="bg-[#F7F5F0] dark:bg-[#0B0B0C] min-h-screen flex items-center justify-center transition-colors">

  <div class="w-full max-w-sm bg-white dark:bg-[#131315] rounded-xl shadow-sm border border-gray-100 dark:border-white/10 p-8">

    <!-- BRAND -->
    <div class="flex items-center gap-2 mb-8">
      <div class="w-9 h-9 rounded-lg bg-green-800 flex items-center justify-center text-white text-lg">
        🎓
      </div>

      <div>
        <div class="font-bold text-gray-900 dark:text-gray-100 leading-tight">
          STUDY PLANNER
        </div>

        <div class="text-xs text-gray-500 dark:text-gray-400 leading-tight">
          Academic Workload Manager
        </div>
      </div>
    </div>

    <!-- TITLE -->
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-1">
      Welcome back
    </h1>

    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
      Sign in to see today's workload.
    </p>

    <!-- ERROR -->
    <div
      id="form-error"
      class="hidden mb-4 text-sm text-red-600 bg-red-50 dark:bg-red-500/10 rounded-lg px-3 py-2">
    </div>

    <!-- LOGIN FORM -->
    <form id="login-form" class="space-y-4">

      <div>
        <label
          class="text-sm text-gray-700 dark:text-gray-300"
          for="email">
          Email
        </label>

        <input
          id="email"
          type="email"
          name="email"
          autocomplete="email"
          required
          class="mt-1 w-full rounded-lg border border-gray-200 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-700">
      </div>

      <div>
        <div class="flex items-center justify-between">

          <label
            class="text-sm text-gray-700 dark:text-gray-300"
            for="password">
            Password
          </label>

          <a
            href="forgot-password.php"
            class="text-xs font-medium text-green-800 dark:text-green-400 hover:underline">
            Forgot password?
          </a>

        </div>

        <input
          id="password"
          type="password"
          name="password"
          autocomplete="current-password"
          required
          class="mt-1 w-full rounded-lg border border-gray-200 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-700">
      </div>

      <button
        id="login-submit"
        type="submit"
        class="w-full bg-green-800 hover:bg-green-900 text-white rounded-lg py-2 text-sm font-medium transition">
        Sign in
      </button>

    </form>

    <!-- REGISTER -->
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-6 text-center">
      No account?

      <a
        href="register.php"
        class="text-green-800 dark:text-green-400 font-medium">
        Create one
      </a>
    </p>

  </div>

  <script src="../assets/js/auth.js"></script>

</body>
</html>