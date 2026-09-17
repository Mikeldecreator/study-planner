<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Forgot Password — Study Planner</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="../assets/js/theme-init.js"></script>

</head>

<body class="bg-[#F7F5F0] dark:bg-[#0B0B0C] min-h-screen flex items-center justify-center px-4 transition-colors">

<div class="w-full max-w-sm">

  <div class="bg-white dark:bg-[#131315]
              rounded-xl
              shadow-sm
              border border-gray-100 dark:border-white/10
              p-8">

    <!-- BRAND -->
    <div class="flex items-center gap-2 mb-8">

      <div class="w-9 h-9 rounded-lg bg-green-800
                  flex items-center justify-center
                  text-white text-lg">
        🎓
      </div>

      <div>
        <div class="font-bold text-gray-900 dark:text-gray-100">
          STUDY PLANNER
        </div>

        <div class="text-xs text-gray-500 dark:text-gray-400">
          Academic Workload Manager
        </div>
      </div>

    </div>


    <!-- TITLE -->
    <h1 class="text-xl font-semibold
               text-gray-900 dark:text-gray-100 mb-1">

      Forgot your password?

    </h1>

    <p class="text-sm text-gray-500
              dark:text-gray-400 mb-6">

      Enter the email address associated with your account.
      We'll send you a secure password reset link.

    </p>


    <!-- ERROR -->
    <div
      id="form-error"
      class="hidden mb-4 text-sm text-red-600
             bg-red-50 dark:bg-red-500/10
             rounded-lg px-3 py-2">
    </div>


    <!-- FORM -->
    <form
      id="forgot-password-form"
      class="space-y-4">

      <div>

        <label
          for="email"
          class="text-sm text-gray-700 dark:text-gray-300">

          Email

        </label>

        <input
          id="email"
          name="email"
          type="email"
          autocomplete="email"
          required
          placeholder="you@example.com"
          class="mt-1 w-full rounded-lg
                 border border-gray-200
                 dark:border-white/10
                 dark:bg-white/5
                 px-3 py-2 text-sm
                 focus:outline-none
                 focus:ring-2 focus:ring-green-700">

      </div>


      <button
        id="forgot-submit"
        type="submit"
        class="w-full bg-green-800
               hover:bg-green-900
               text-white rounded-lg
               py-2 text-sm font-medium
               transition">

        Send reset link

      </button>

    </form>


    <!-- SUCCESS -->
    <div
      id="success-state"
      class="hidden text-center">

      <div class="w-12 h-12 mx-auto mb-4
                  rounded-full bg-green-100
                  dark:bg-green-500/10
                  flex items-center justify-center
                  text-2xl">

        ✉️

      </div>

      <h2
        class="text-lg font-semibold
               text-gray-900 dark:text-gray-100 mb-2">

        Check your email

      </h2>

      <p
        class="text-sm text-gray-500
               dark:text-gray-400">

        If an account exists with that email address,
        a password reset link has been sent.

      </p>

      <p
        class="text-xs text-gray-400
               dark:text-gray-500 mt-3">

        The reset link will expire after 60 minutes.

      </p>

    </div>


    <!-- BACK -->
    <div class="mt-6 text-center">

      <a
        href="login.php"
        class="text-sm text-green-800
               dark:text-green-400
               font-medium hover:underline">

        ← Back to sign in

      </a>

    </div>

  </div>

</div>


<script src="../assets/js/auth.js"></script>

</body>
</html>