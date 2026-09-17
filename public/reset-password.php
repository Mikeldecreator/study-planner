<?php

$token = trim($_GET['token'] ?? '');

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Reset Password — Study Planner</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="../assets/js/theme-init.js"></script>

</head>

<body class="bg-[#F7F5F0] dark:bg-[#0B0B0C]
             min-h-screen
             flex items-center justify-center
             px-4 transition-colors">

<div class="w-full max-w-sm">

  <div class="bg-white dark:bg-[#131315]
              rounded-xl
              shadow-sm
              border border-gray-100
              dark:border-white/10
              p-8">


    <!-- BRAND -->

    <div class="flex items-center gap-2 mb-8">

      <div
        class="w-9 h-9 rounded-lg
               bg-green-800
               flex items-center justify-center
               text-white text-lg">

        🎓

      </div>

      <div>

        <div
          class="font-bold
                 text-gray-900
                 dark:text-gray-100">

          STUDY PLANNER

        </div>

        <div
          class="text-xs
                 text-gray-500
                 dark:text-gray-400">

          Academic Workload Manager

        </div>

      </div>

    </div>


    <!-- RESET FORM -->

    <div id="reset-form-container">

      <h1
        class="text-xl font-semibold
               text-gray-900
               dark:text-gray-100 mb-1">

        Reset your password

      </h1>

      <p
        class="text-sm text-gray-500
               dark:text-gray-400 mb-6">

        Create a new password for your account.

      </p>


      <!-- ERROR -->

      <div
        id="form-error"
        class="hidden mb-4 text-sm
               text-red-600
               bg-red-50
               dark:bg-red-500/10
               rounded-lg px-3 py-2">
      </div>


      <form
        id="reset-password-form"
        class="space-y-4">

        <!-- TOKEN -->

        <input
          type="hidden"
          name="token"
          value="<?= htmlspecialchars(
              $token,
              ENT_QUOTES,
              'UTF-8'
          ) ?>">


        <!-- NEW PASSWORD -->

        <div>

          <label
            for="new-password"
            class="text-sm
                   text-gray-700
                   dark:text-gray-300">

            New password

          </label>

          <input
            id="new-password"
            name="password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            placeholder="Enter new password"
            class="mt-1 w-full rounded-lg
                   border border-gray-200
                   dark:border-white/10
                   dark:bg-white/5
                   px-3 py-2 text-sm
                   focus:outline-none
                   focus:ring-2
                   focus:ring-green-700">

          <div
            id="password-strength"
            class="text-xs mt-1">
          </div>

        </div>


        <!-- CONFIRM PASSWORD -->

        <div>

          <label
            for="confirm-password"
            class="text-sm
                   text-gray-700
                   dark:text-gray-300">

            Confirm password

          </label>

          <input
            id="confirm-password"
            name="confirm_password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            placeholder="Confirm new password"
            class="mt-1 w-full rounded-lg
                   border border-gray-200
                   dark:border-white/10
                   dark:bg-white/5
                   px-3 py-2 text-sm
                   focus:outline-none
                   focus:ring-2
                   focus:ring-green-700">

          <div
            id="password-match"
            class="text-xs mt-1">
          </div>

        </div>


        <!-- BUTTON -->

        <button
          id="reset-submit"
          type="submit"
          class="w-full bg-green-800
                 hover:bg-green-900
                 text-white rounded-lg
                 py-2 text-sm
                 font-medium transition">

          Reset password

        </button>

      </form>

    </div>


    <!-- SUCCESS -->

    <div
      id="reset-success"
      class="hidden text-center">

      <div
        class="w-12 h-12 mx-auto mb-4
               rounded-full
               bg-green-100
               dark:bg-green-500/10
               flex items-center
               justify-center
               text-2xl">

        ✓

      </div>

      <h2
        class="text-lg font-semibold
               text-gray-900
               dark:text-gray-100 mb-2">

        Password reset successfully

      </h2>

      <p
        class="text-sm
               text-gray-500
               dark:text-gray-400 mb-6">

        Your password has been changed.
        You can now sign in using your new password.

      </p>

      <a
        href="login.php"
        class="block w-full
               bg-green-800
               hover:bg-green-900
               text-white
               rounded-lg
               py-2
               text-sm
               font-medium">

        Go to sign in

      </a>

    </div>

  </div>

</div>


<script src="../assets/js/auth.js"></script>

</body>
</html>