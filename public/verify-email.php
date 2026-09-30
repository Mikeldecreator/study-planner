<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$token = trim((string) ($_GET['token'] ?? ''));
$status = 'invalid';
$message = '';
$userEmail = '';

if ($token !== '') {
    try {
        $db = getDb();
        $tokenHash = hash('sha256', $token);
        // Safe backward-compatible lookup: check hashed token or legacy plaintext token
        $stmt = $db->prepare('SELECT id, full_name, email, email_verified, email_verification_expires_at FROM users WHERE email_verification_token = ? OR email_verification_token = ? LIMIT 1');
        $stmt->execute([$tokenHash, $token]);
        $user = $stmt->fetch();

        if ($user) {
            $userEmail = (string) $user['email'];
            $isExpired = false;
            if (!empty($user['email_verification_expires_at'])) {
                $expires = strtotime((string) $user['email_verification_expires_at']);
                if ($expires !== false && $expires < time()) {
                    $isExpired = true;
                }
            }

            if ($isExpired) {
                $status = 'expired';
                $message = 'This verification link has expired. Verification links are valid for 24 hours.';
            } else {
                // Activate account
                $update = $db->prepare('UPDATE users SET email_verified = 1, email_verification_token = NULL, email_verification_expires_at = NULL WHERE id = ?');
                $update->execute([$user['id']]);

                // Redirect to login with verified notice
                header('Location: login.php?verified=1&email=' . urlencode($userEmail));
                exit;
            }
        } else {
            $status = 'invalid';
            $message = 'This verification link is invalid or has already been used.';
        }
    } catch (Throwable $e) {
        error_log('[PUBLIC VERIFY EMAIL ERROR] ' . $e->getMessage());
        $status = 'error';
        $message = 'A server error occurred while verifying your account. Please try again.';
    }
} else {
    $status = 'missing';
    $message = 'No verification token was provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Account Verification — Study Planner</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="../assets/js/theme-init.js"></script>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-[#F7F5F0] dark:bg-[#0B0B0C] min-h-screen flex items-center justify-center p-3 sm:p-4 transition-colors">

  <div class="w-full max-w-md bg-white dark:bg-[#131315] rounded-2xl shadow-xl border border-gray-100 dark:border-white/10 p-5 sm:p-8 text-center">

    <!-- BRAND ICON -->
    <div class="mx-auto w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 flex items-center justify-center text-xl sm:text-2xl mb-3.5 sm:mb-5 border border-amber-200 dark:border-amber-800/40">
      ⚠️
    </div>

    <h1 class="text-lg sm:text-xl font-bold text-gray-900 dark:text-gray-100 mb-1.5 sm:mb-2">
      <?= $status === 'expired' ? 'Verification Link Expired' : 'Invalid Verification Link' ?>
    </h1>

    <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-400 mb-4 sm:mb-6 leading-relaxed">
      <?= htmlspecialchars($message) ?>
    </p>

    <!-- RESEND FORM -->
    <form id="resend-form" class="space-y-3.5 sm:space-y-4 text-left">
      <div>
        <label for="resend-email" class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">
          Your Email Address
        </label>
        <input
          id="resend-email"
          type="email"
          name="email"
          value="<?= htmlspecialchars($userEmail) ?>"
          required
          placeholder="student@university.edu"
          class="w-full rounded-xl border border-gray-200 dark:border-white/10 dark:bg-white/5 px-3.5 py-2.5 text-base sm:text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-emerald-600"
        >
      </div>

      <div id="resend-message" class="hidden text-xs rounded-lg p-3"></div>

      <button
        id="resend-btn"
        type="submit"
        class="w-full bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl py-2.5 text-sm font-semibold transition shadow-sm"
      >
        Send New Verification Link
      </button>
    </form>

    <div class="mt-4 sm:mt-6 pt-4 sm:pt-6 border-t border-gray-100 dark:border-white/10">
      <a href="login.php" class="text-xs font-semibold text-emerald-700 dark:text-emerald-400 hover:underline">
        &larr; Back to Sign in
      </a>
    </div>

  </div>

<script>
document.getElementById('resend-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('resend-btn');
  const msg = document.getElementById('resend-message');
  const emailInput = document.getElementById('resend-email');
  const email = (emailInput?.value || '').trim();

  if (!email) return;

  btn.disabled = true;
  btn.textContent = 'Sending link...';
  msg.className = 'hidden text-xs rounded-lg p-3';

  try {
    const res = await fetch('../api/resend-verification.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ email })
    });
    const data = await res.json();
    msg.textContent = data.message || 'Verification link sent! Check your inbox.';
    msg.className = 'text-xs rounded-lg p-3 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200 border border-emerald-200 dark:border-emerald-800/40';
  } catch (err) {
    msg.textContent = 'Unable to send link. Please try again later.';
    msg.className = 'text-xs rounded-lg p-3 bg-red-50 dark:bg-red-950/40 text-red-800 dark:text-red-200 border border-red-200 dark:border-red-800/40';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Send New Verification Link';
  }
});
</script>

</body>
</html>
