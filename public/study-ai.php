<?php
require_once __DIR__ . '/../includes/auth.php';
requirePageLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Study AI Assistant — Study Planner</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <script src="../assets/js/theme-init.js"></script>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    /* Custom Chat Scrollbar */
    .ai-chat-scroll::-webkit-scrollbar {
      width: 6px;
    }
    .ai-chat-scroll::-webkit-scrollbar-track {
      background: transparent;
    }
    .ai-chat-scroll::-webkit-scrollbar-thumb {
      background: rgba(16, 185, 129, 0.2);
      border-radius: 9999px;
    }
    html.dark .ai-chat-scroll::-webkit-scrollbar-thumb {
      background: rgba(16, 185, 129, 0.3);
    }
    /* Typing Dots Animation */
    @keyframes pulse-dot {
      0%, 100% { opacity: 0.25; transform: scale(0.85); }
      50% { opacity: 1; transform: scale(1.15); }
    }
    .ai-dot-1 { animation: pulse-dot 1.2s infinite ease-in-out; }
    .ai-dot-2 { animation: pulse-dot 1.2s infinite ease-in-out 0.2s; }
    .ai-dot-3 { animation: pulse-dot 1.2s infinite ease-in-out 0.4s; }

    /* Formatted AI Message Typography */
    .ai-rendered-content p {
      margin-bottom: 0.75rem;
    }
    .ai-rendered-content p:last-child {
      margin-bottom: 0;
    }
    .ai-rendered-content ul {
      list-style-type: disc;
      padding-left: 1.25rem;
      margin-top: 0.5rem;
      margin-bottom: 0.75rem;
    }
    .ai-rendered-content ol {
      list-style-type: decimal;
      padding-left: 1.25rem;
      margin-top: 0.5rem;
      margin-bottom: 0.75rem;
    }
    .ai-rendered-content li {
      margin-bottom: 0.35rem;
    }
    .ai-rendered-content h2,
    .ai-rendered-content h3,
    .ai-rendered-content h4 {
      font-weight: 700;
      margin-top: 1rem;
      margin-bottom: 0.5rem;
      color: inherit;
    }
    .ai-rendered-content h3 { font-size: 1rem; }
    .ai-rendered-content h4 { font-size: 0.925rem; }
    .ai-rendered-content strong {
      font-weight: 600;
      color: inherit;
    }
    .ai-rendered-content hr {
      border: 0;
      border-top: 1px solid rgba(0, 0, 0, 0.08);
      margin: 1rem 0;
    }
    html.dark .ai-rendered-content hr {
      border-top-color: rgba(255, 255, 255, 0.1);
    }
  </style>
</head>
<body data-page="study-ai" class="app-page bg-[#F4F8F7] dark:bg-[#0B0B0C] text-[#082E2A] dark:text-gray-100 transition-colors">
<div class="flex min-h-screen">
  <!-- Sidebar slot (dynamically populated by nav.js) -->
  <div id="sidebar-slot"></div>

  <!-- Main Content Viewport -->
  <main class="flex-1 min-w-0 flex flex-col h-screen overflow-hidden">
    <!-- Topbar Header -->
    <header class="app-topbar bg-white/95 dark:bg-[#101615]/95 border-b border-gray-100 dark:border-white/10 px-4 sm:px-7 py-3.5 flex items-center justify-between gap-4 sticky top-0 z-20 shrink-0">
      <div class="flex items-center gap-3 min-w-0">
        <button id="hamburger-btn" class="lg:hidden text-[#0B4B42] dark:text-gray-200 p-1" aria-label="Open menu">
          <i data-lucide="menu" class="w-6 h-6"></i>
        </button>
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-emerald-700 to-teal-500 text-white flex items-center justify-center shadow-sm shrink-0">
            <i data-lucide="sparkles" class="w-5 h-5"></i>
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h1 class="text-lg sm:text-xl font-bold tracking-tight text-gray-900 dark:text-white leading-tight">Study AI</h1>
              <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800/40">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Active &amp; Grounded
              </span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 hidden sm:block">Your intelligent academic planning assistant</p>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-2.5 sm:gap-3 shrink-0">
        <!-- Clear conversation button -->
        <button id="clear-chat-btn" type="button" class="btn-press flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 rounded-lg border border-gray-200 dark:border-white/10 transition-colors" title="Clear current conversation">
          <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
          <span class="hidden sm:inline">New Chat</span>
        </button>

        <!-- Notification Bell -->
        <button id="bell-btn" class="relative text-[#0A4D44] dark:text-gray-200 p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 transition-colors" aria-label="Notifications">
          <i data-lucide="bell" class="w-5 h-5"></i>
          <span id="bell-badge" class="hidden absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 items-center justify-center"></span>
        </button>
        <div id="bell-dropdown" class="hidden absolute right-4 top-16 w-80 max-w-[90vw] bg-white dark:bg-[#171D1B] border border-gray-100 dark:border-white/10 rounded-xl shadow-xl z-30 max-h-96 overflow-y-auto"></div>

        <!-- User Profile Pill -->
        <a href="settings.php" class="hidden sm:flex items-center gap-2 pl-3 border-l border-gray-100 dark:border-white/10" aria-label="Account settings">
          <span class="text-xs sm:text-sm font-semibold text-gray-800 dark:text-gray-200" data-user-name>Loading…</span>
          <div class="w-8 h-8 rounded-full bg-emerald-700 text-white flex items-center justify-center text-xs font-bold" data-top-avatar data-user-initial>U</div>
        </a>
      </div>
    </header>

    <!-- Scrollable Chat Workspace -->
    <div id="ai-chat-scroll" class="flex-1 overflow-y-auto px-4 sm:px-6 lg:px-8 py-6 ai-chat-scroll space-y-6">
      <div class="max-w-4xl mx-auto w-full space-y-6">

        <!-- Welcome / Empty State -->
        <section id="ai-empty-state" class="py-4 space-y-6">
          <!-- Hero Card -->
          <div class="rounded-2xl bg-gradient-to-br from-white via-emerald-50/30 to-teal-50/20 dark:from-[#131B18] dark:via-[#101815] dark:to-[#0D1412] border border-emerald-100/80 dark:border-emerald-900/30 p-6 sm:p-8 shadow-sm">
            <div class="flex items-start gap-4">
              <div class="w-12 h-12 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shadow-md shadow-emerald-600/20 shrink-0">
                <i data-lucide="sparkles" class="w-6 h-6"></i>
              </div>
              <div class="space-y-1">
                <h2 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white">How can I help with your studies today?</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed max-w-2xl">
                  I'm your Academic Planning AI Assistant. I analyze your current courses, active tasks, upcoming deadlines, and study timetable to provide grounded guidance and actionable study advice.
                </p>
              </div>
            </div>

            <!-- Capabilities Overview -->
            <div class="mt-6 pt-5 border-t border-emerald-100 dark:border-emerald-900/40 grid grid-cols-2 sm:grid-cols-3 gap-3">
              <div class="flex items-center gap-2 text-xs text-emerald-900 dark:text-emerald-200 font-medium">
                <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                <span>Today's Priorities</span>
              </div>
              <div class="flex items-center gap-2 text-xs text-emerald-900 dark:text-emerald-200 font-medium">
                <i data-lucide="alert-triangle" class="w-4 h-4 text-amber-500 shrink-0"></i>
                <span>Overdue Task Guidance</span>
              </div>
              <div class="flex items-center gap-2 text-xs text-emerald-900 dark:text-emerald-200 font-medium">
                <i data-lucide="clock" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                <span>Upcoming Deadlines</span>
              </div>
              <div class="flex items-center gap-2 text-xs text-emerald-900 dark:text-emerald-200 font-medium">
                <i data-lucide="bar-chart-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                <span>Workload Analysis</span>
              </div>
              <div class="flex items-center gap-2 text-xs text-emerald-900 dark:text-emerald-200 font-medium">
                <i data-lucide="calendar" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                <span>Weekly Planning</span>
              </div>
              <div class="flex items-center gap-2 text-xs text-emerald-900 dark:text-emerald-200 font-medium">
                <i data-lucide="shield-check" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                <span>Grounded &amp; Read-Only</span>
              </div>
            </div>
          </div>

          <!-- Suggested Prompt Cards -->
          <div class="space-y-3">
            <div class="flex items-center gap-2 px-1">
              <i data-lucide="compass" class="w-4 h-4 text-emerald-600"></i>
              <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Suggested Questions</h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
              <!-- Prompt 1 -->
              <button type="button" data-prompt="What should I focus on today?"
                      class="suggested-prompt-card text-left p-4 rounded-xl bg-white dark:bg-[#131A18] border border-gray-200/80 dark:border-white/10 hover:border-emerald-400 dark:hover:border-emerald-600 hover:shadow-sm transition-all duration-150 group">
                <div class="flex items-center justify-between mb-1.5">
                  <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 group-hover:scale-105 transition-transform">
                    <i data-lucide="target" class="w-4 h-4"></i>
                  </span>
                  <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-gray-400 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors"></i>
                </div>
                <div class="font-semibold text-sm text-gray-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition-colors">What should I focus on today?</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Get your top priority task &amp; actionable advice.</div>
              </button>

              <!-- Prompt 2 -->
              <button type="button" data-prompt="Which course needs the most attention?"
                      class="suggested-prompt-card text-left p-4 rounded-xl bg-white dark:bg-[#131A18] border border-gray-200/80 dark:border-white/10 hover:border-emerald-400 dark:hover:border-emerald-600 hover:shadow-sm transition-all duration-150 group">
                <div class="flex items-center justify-between mb-1.5">
                  <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 group-hover:scale-105 transition-transform">
                    <i data-lucide="book-open" class="w-4 h-4"></i>
                  </span>
                  <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-gray-400 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors"></i>
                </div>
                <div class="font-semibold text-sm text-gray-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition-colors">Which course needs attention?</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Identifies course pressure, risk &amp; overdue counts.</div>
              </button>

              <!-- Prompt 3 -->
              <button type="button" data-prompt="What deadlines are coming up?"
                      class="suggested-prompt-card text-left p-4 rounded-xl bg-white dark:bg-[#131A18] border border-gray-200/80 dark:border-white/10 hover:border-emerald-400 dark:hover:border-emerald-600 hover:shadow-sm transition-all duration-150 group">
                <div class="flex items-center justify-between mb-1.5">
                  <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 group-hover:scale-105 transition-transform">
                    <i data-lucide="calendar-days" class="w-4 h-4"></i>
                  </span>
                  <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-gray-400 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors"></i>
                </div>
                <div class="font-semibold text-sm text-gray-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition-colors">What deadlines are coming up?</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Review upcoming assignments &amp; tests chronologically.</div>
              </button>

              <!-- Prompt 4 -->
              <button type="button" data-prompt="How much workload do I have remaining?"
                      class="suggested-prompt-card text-left p-4 rounded-xl bg-white dark:bg-[#131A18] border border-gray-200/80 dark:border-white/10 hover:border-emerald-400 dark:hover:border-emerald-600 hover:shadow-sm transition-all duration-150 group">
                <div class="flex items-center justify-between mb-1.5">
                  <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 group-hover:scale-105 transition-transform">
                    <i data-lucide="activity" class="w-4 h-4"></i>
                  </span>
                  <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-gray-400 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors"></i>
                </div>
                <div class="font-semibold text-sm text-gray-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition-colors">How much workload is left?</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Exact remaining hours across all your active courses.</div>
              </button>

              <!-- Prompt 5 -->
              <button type="button" data-prompt="What should I prioritize this week?"
                      class="suggested-prompt-card text-left p-4 rounded-xl bg-white dark:bg-[#131A18] border border-gray-200/80 dark:border-white/10 hover:border-emerald-400 dark:hover:border-emerald-600 hover:shadow-sm transition-all duration-150 group">
                <div class="flex items-center justify-between mb-1.5">
                  <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 group-hover:scale-105 transition-transform">
                    <i data-lucide="list-ordered" class="w-4 h-4"></i>
                  </span>
                  <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-gray-400 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors"></i>
                </div>
                <div class="font-semibold text-sm text-gray-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition-colors">What should I prioritize this week?</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Multi-day strategic study plan based on urgency.</div>
              </button>

              <!-- Prompt 6 -->
              <button type="button" data-prompt="Help me plan today's study time"
                      class="suggested-prompt-card text-left p-4 rounded-xl bg-white dark:bg-[#131A18] border border-gray-200/80 dark:border-white/10 hover:border-emerald-400 dark:hover:border-emerald-600 hover:shadow-sm transition-all duration-150 group">
                <div class="flex items-center justify-between mb-1.5">
                  <span class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-400 group-hover:scale-105 transition-transform">
                    <i data-lucide="calendar" class="w-4 h-4"></i>
                  </span>
                  <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-gray-400 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition-colors"></i>
                </div>
                <div class="font-semibold text-sm text-gray-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300 transition-colors">Plan today's study time</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Align today's schedule sessions with pending tasks.</div>
              </button>
            </div>
          </div>
        </section>

        <!-- Message List Container -->
        <div id="ai-chat-messages" class="space-y-5"></div>

        <!-- Thinking / Loading Indicator -->
        <div id="ai-loading-indicator" class="hidden flex items-start gap-3">
          <div class="w-8 h-8 rounded-xl bg-emerald-700 text-white flex items-center justify-center shrink-0 shadow-sm">
            <i data-lucide="sparkles" class="w-4 h-4"></i>
          </div>
          <div class="bg-white dark:bg-[#141C18] border border-gray-200/80 dark:border-white/10 rounded-2xl rounded-tl-sm px-4 py-3 shadow-sm text-sm text-gray-600 dark:text-gray-300 flex items-center gap-2.5">
            <span>Study AI is analyzing your academic records</span>
            <span class="inline-flex gap-1 items-center">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 ai-dot-1"></span>
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 ai-dot-2"></span>
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 ai-dot-3"></span>
            </span>
          </div>
        </div>

      </div>
    </div>

    <!-- Message Composer (Pinned at Bottom) -->
    <div class="bg-white dark:bg-[#101615] border-t border-gray-200/80 dark:border-white/10 px-4 sm:px-6 lg:px-8 py-3.5 shrink-0 z-10">
      <div class="max-w-4xl mx-auto w-full space-y-2">

        <!-- Quick Prompt Chips (Visible when conversation has started) -->
        <div id="ai-quick-chips" class="hidden flex items-center gap-1.5 overflow-x-auto pb-1 text-xs no-scrollbar">
          <span class="text-gray-400 text-[11px] shrink-0 font-medium mr-1">Quick prompts:</span>
          <button type="button" data-prompt="What should I focus on today?" class="quick-chip-btn whitespace-nowrap px-2.5 py-1 rounded-full bg-gray-100 dark:bg-white/5 hover:bg-emerald-50 dark:hover:bg-emerald-950 text-gray-700 dark:text-gray-300 hover:text-emerald-700 dark:hover:text-emerald-300 border border-gray-200/60 dark:border-white/10 transition-colors">Focus today</button>
          <button type="button" data-prompt="How many overdue tasks do I have?" class="quick-chip-btn whitespace-nowrap px-2.5 py-1 rounded-full bg-gray-100 dark:bg-white/5 hover:bg-emerald-50 dark:hover:bg-emerald-950 text-gray-700 dark:text-gray-300 hover:text-emerald-700 dark:hover:text-emerald-300 border border-gray-200/60 dark:border-white/10 transition-colors">Overdue tasks</button>
          <button type="button" data-prompt="What deadlines are coming up?" class="quick-chip-btn whitespace-nowrap px-2.5 py-1 rounded-full bg-gray-100 dark:bg-white/5 hover:bg-emerald-50 dark:hover:bg-emerald-950 text-gray-700 dark:text-gray-300 hover:text-emerald-700 dark:hover:text-emerald-300 border border-gray-200/60 dark:border-white/10 transition-colors">Upcoming deadlines</button>
          <button type="button" data-prompt="How much workload do I have remaining?" class="quick-chip-btn whitespace-nowrap px-2.5 py-1 rounded-full bg-gray-100 dark:bg-white/5 hover:bg-emerald-50 dark:hover:bg-emerald-950 text-gray-700 dark:text-gray-300 hover:text-emerald-700 dark:hover:text-emerald-300 border border-gray-200/60 dark:border-white/10 transition-colors">Remaining workload</button>
        </div>

        <!-- Composer Input Row -->
        <form id="ai-chat-form" class="relative flex items-end gap-2 bg-[#F8FAFA] dark:bg-[#141C18] border border-gray-300 dark:border-white/15 rounded-2xl p-2 focus-within:border-emerald-600 dark:focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-500/20 transition-all">
          <textarea
            id="ai-prompt-input"
            rows="1"
            maxlength="1000"
            placeholder="Ask about your courses, tasks, deadlines, or study schedule... (Enter to send, Shift+Enter for newline)"
            class="flex-1 max-h-36 min-h-[42px] bg-transparent border-0 resize-none px-2.5 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 outline-none leading-relaxed"
            aria-label="Ask Study AI"
          ></textarea>

          <div class="flex items-center gap-2 pb-1 pr-1 shrink-0">
            <span id="ai-char-counter" class="text-[11px] text-gray-400 hidden sm:inline select-none">0/1000</span>
            <button
              id="ai-send-btn"
              type="submit"
              class="w-9 h-9 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white flex items-center justify-center transition-all duration-150 disabled:opacity-40 disabled:cursor-not-allowed shadow-sm"
              aria-label="Send message"
              disabled
            >
              <i data-lucide="send" class="w-4 h-4"></i>
            </button>
          </div>
        </form>

        <!-- Advisory Notice -->
        <div class="flex items-center justify-between text-[11px] text-gray-400 dark:text-gray-500 px-1">
          <span>Study AI is grounded in your verified planner records. Read-only advisory assistant.</span>
          <span class="hidden md:inline">Shift + Enter for new line</span>
        </div>

      </div>
    </div>
  </main>
</div>

<!-- Shared scripts -->
<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/study-ai.js"></script>
<script src="../assets/js/guided-tour.js"></script>
</body>
</html>

