<?php
// Centralized PWA, Favicon, and Mobile Head Metadata for Study Planner
$pushScriptSrc = (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '/public/'))
    ? '../assets/js/browser-push.js'
    : '/assets/js/browser-push.js';
?>
<!-- Favicons & App Icons -->
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
<link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">

<!-- Web App Manifest -->
<link rel="manifest" href="/manifest.json">

<!-- Mobile & Theme Meta Tags -->
<meta name="theme-color" content="#0F2C1E">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Study Planner">
<meta name="application-name" content="Study Planner">
<meta name="mobile-web-app-capable" content="yes">

<!-- Push Notification & Service Worker Lifecycle -->
<script defer src="<?= htmlspecialchars($pushScriptSrc, ENT_QUOTES, 'UTF-8') ?>"></script>
