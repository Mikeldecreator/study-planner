<?php
require_once __DIR__ . '/../includes/auth.php';
requirePageLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Study Planner | Settings</title>
<?php require_once __DIR__ . '/../includes/head-meta.php'; ?>
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
<script>window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;</script>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="../assets/js/theme-init.js"></script>

<link rel="stylesheet" href="../assets/css/style.css">

<style>
  :root{
    --sp-green:#079447;
    --sp-dark:#073f32;
    --sp-muted:#5d7890;
    --sp-border:#dfeae7;
    --sp-surface:#fff;
    --sp-soft:#eefaf4
  }

  .settings-page{
    background:#f4f9f7
  }

  .settings-card{
    background:var(--sp-surface);
    border:1px solid var(--sp-border);
    box-shadow:0 6px 22px rgba(13,70,55,.045)
  }

  .settings-hero{
    background:linear-gradient(
      110deg,
      #e8faf1 0%,
      #f5fbf9 48%,
      #edf9f3 100%
    );
    border:1px solid #d7eee4
  }

  .settings-icon{
    width:46px;
    height:46px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:#e8f8f0;
    color:#078a46;
    flex:none
  }

  .settings-row{
    min-height:58px;
    border-top:1px solid #edf2f1;
    transition:.16s ease
  }

  .settings-row:hover{
    background:#fbfdfc
  }

  .settings-row:first-child{
    border-top:0
  }

  .settings-row-icon{
    width:38px;
    height:38px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:#f1f7f5;
    color:#123f35;
    flex:none
  }

  .settings-progress{
    height:8px;
    background:#d8f4e8;
    border-radius:99px;
    overflow:hidden
  }

  .settings-progress > span{
    display:block;
    height:100%;
    background:#0b9b4d;
    border-radius:inherit
  }

  .sp-toggle{
    width:46px;
    height:26px;
    border-radius:999px;
    background:#d9e5e1;
    position:relative;
    display:inline-block;
    transition:.2s;
    flex:none
  }

  .sp-toggle::after{
    content:"";
    position:absolute;
    width:20px;
    height:20px;
    left:3px;
    top:3px;
    background:#fff;
    border-radius:50%;
    box-shadow:0 1px 3px rgba(0,0,0,.2);
    transition:.2s
  }

  .sp-toggle.is-on{
    background:#0a9b4d
  }

  .sp-toggle.is-on::after{
    transform:translateX(20px)
  }

  .dark .sp-toggle{
    background:#2e3e38
  }

  .dark .sp-toggle.is-on{
    background:#059669
  }

  .settings-row-interactive{
    min-height:48px;
    padding:10px 8px;
    border-top:1px solid #edf2f1;
    transition:.16s ease
  }

  .settings-row-interactive:hover{
    background:#fbfdfc
  }

  .dark .settings-row-interactive{
    border-color:rgba(255,255,255,.07)
  }

  .dark .settings-row-interactive:hover{
    background:rgba(255,255,255,.025)
  }

  /* Prevent iOS mobile auto-zoom */
  .settings-control,
  .settings-select,
  input[type="text"],
  input[type="email"],
  input[type="password"],
  input[type="number"],
  input[type="search"],
  select {
    font-size: 16px !important;
  }
  @media (min-width: 640px) {
    .settings-control,
    .settings-select,
    input[type="text"],
    input[type="email"],
    input[type="password"],
    input[type="number"],
    input[type="search"],
    select {
      font-size: 14px !important;
    }
  }

  .settings-select{
    appearance:none;
    background:transparent;
    border:0;
    outline:0;
    font:inherit;
    color:inherit;
    cursor:pointer;
    text-align:right;
    min-width:105px
  }

  .avatar-ring{
    box-shadow:
      0 0 0 5px #fff,
      0 0 0 7px #d9efe5
  }

  .dark .avatar-ring{
    box-shadow:
      0 0 0 5px #18211e,
      0 0 0 7px #2c4c41
  }

  .avatar-fallback{
    background:linear-gradient(145deg,#ddd,#f5f5f5)
  }

  .settings-control{
    background:#fff;
    border:1px solid #dbe7e3;
    color:#12352e
  }

  .settings-modal{
    backdrop-filter:blur(5px)
  }

  .tagline-wrap{
    min-width:0;
    max-width:100%
  }

  .tagline-text{
    display:block;
    max-width:100%;
    overflow-wrap:anywhere;
    word-break:break-word
  }

  #edit-tagline{
    flex:none
  }

  #edit-tagline:disabled{
    opacity:.5;
    cursor:not-allowed
  }

  .dark .settings-page{
    background:#0d1512
  }

  .dark .settings-card{
    background:#131c19;
    border-color:rgba(255,255,255,.09);
    box-shadow:none
  }

  .dark .settings-hero{
    background:linear-gradient(
      110deg,
      #122b22,
      #14231f
    );
    border-color:rgba(62,180,125,.18)
  }

  .dark .settings-row{
    border-color:rgba(255,255,255,.07)
  }

  .dark .settings-row:hover{
    background:rgba(255,255,255,.025)
  }

  .dark .settings-row-icon{
    background:#182824;
    color:#d8f2e8
  }

  .dark .settings-icon{
    background:#173126
  }

  .dark .settings-progress{
    background:#1e3a30
  }

  .dark .settings-select{
    color:#e6f1ed
  }

  .dark .settings-muted{
    color:#9bb4ac
  }

  .dark .settings-control{
    background:#17211e;
    border-color:rgba(255,255,255,.1);
    color:#eef7f4
  }

  @media(max-width:1023px){
    .settings-hero{
      border-radius:16px
    }

    .settings-grid{
      grid-template-columns:1fr
    }

    .settings-main{
      padding-top:20px
    }
  }

  @media(max-width:640px){
    .settings-content{
      padding:12px
    }

    .settings-hero-body{
      padding:14px 16px;
      gap:12px;
    }

    .settings-icon{
      width:34px;
      height:34px;
    }

    .settings-icon svg{
      width:18px;
      height:18px;
    }

    .settings-stats{
      padding:12px;
      gap:8px 0;
    }

    .settings-stats > div{
      padding:4px 8px;
      gap:8px;
    }

    .settings-stats strong{
      font-size:16px;
    }

    .settings-stats span{
      font-size:11px;
    }

    .settings-card{
      padding:14px 16px !important;
      border-radius:14px;
    }

    .settings-row{
      min-height:48px;
      padding:10px 8px;
    }

    .settings-row-icon{
      width:32px;
      height:32px;
    }

    .settings-row-icon svg{
      width:16px;
      height:16px;
    }

    .settings-row-value{
      max-width:45%;
      text-align:right
    }

    .tagline-text{
      max-width:calc(100vw - 125px)
    }
  }
</style>
</head>

<body
  data-page="settings"
  class="settings-page text-[#082f28] dark:text-gray-100 transition-colors"
>

<div class="flex min-h-screen">

  <div id="sidebar-slot"></div>

  <main class="flex-1 min-w-0">

    <!-- HEADER -->
    <header
      class="bg-white dark:bg-[#111815]
             border-b border-[#e3ece9] dark:border-white/10
             px-4 sm:px-8 py-4
             flex items-center justify-between
             relative gap-3
             sticky top-0 z-20"
    >

      <div class="flex items-center gap-4">

        <button
          id="hamburger-btn"
          type="button"
          class="lg:hidden text-[#163f37] dark:text-gray-200"
          aria-label="Open menu"
        >
          <i data-lucide="menu" class="w-6 h-6"></i>
        </button>

        <div>
          <h1 class="text-xl sm:text-2xl font-bold tracking-tight">
            Settings
          </h1>

          <p class="text-sm text-[#607a90] dark:text-gray-400 mt-0.5">
            Manage your profile, preferences and app settings
          </p>
        </div>

      </div>

      <div
        class="relative hidden md:block flex-1 max-w-[380px] mx-6 lg:mx-12"
      >
        <i
          data-lucide="search"
          class="w-5 h-5 text-[#315d55]
                 absolute left-4 top-1/2
                 -translate-y-1/2"
        ></i>

        <input
          id="settings-search"
          type="search"
          autocomplete="off"
          placeholder="Search courses, tasks, notes..."
          class="w-full rounded-xl
                 border border-[#d9e5e2]
                 dark:border-white/10
                 bg-white dark:bg-white/5
                 pl-11 pr-4 py-3 text-sm
                 outline-none
                 focus:border-emerald-400
                 focus:ring-2
                 focus:ring-emerald-100
                 dark:focus:ring-emerald-900/30"
        >
      </div>

      <div class="flex items-center gap-4 sm:gap-5">

        <button
          id="bell-btn"
          type="button"
          class="relative text-[#123e35] dark:text-gray-200"
          aria-label="Notifications"
        >
          <i data-lucide="bell" class="w-6 h-6"></i>

          <span
            id="bell-badge"
            class="hidden absolute -top-1 -right-1
                   bg-red-600 text-white
                   text-[10px] font-bold
                   rounded-full w-4 h-4
                   items-center justify-center"
          ></span>
        </button>

        <a
          href="schedule.php"
          class="hidden sm:block text-[#123e35] dark:text-gray-200"
          aria-label="Calendar"
        >
          <i data-lucide="calendar-days" class="w-6 h-6"></i>
        </a>

        <a
          href="settings.php"
          class="hidden lg:flex items-center gap-2"
          aria-label="Account settings"
        >
          <span
            class="w-9 h-9 rounded-full overflow-hidden
                   bg-emerald-700 text-white
                   flex items-center justify-center
                   font-semibold"
            data-top-avatar
          >
            <span data-user-initial>U</span>
          </span>

          <span
            class="text-sm font-semibold"
            data-user-name
          >
            Loading…
          </span>

          <i
            data-lucide="chevron-down"
            class="w-4 h-4 text-gray-400"
          ></i>
        </a>

      </div>

      <div
        id="bell-dropdown"
        class="hidden absolute right-4 top-16
               w-80 max-w-[90vw]
               bg-white dark:bg-[#17201d]
               border border-gray-100 dark:border-white/10
               rounded-xl shadow-lg z-30
               max-h-96 overflow-y-auto"
      ></div>

    </header>


    <!-- SETTINGS CONTENT -->
    <div
      class="settings-content
             p-4 sm:p-6 lg:p-8
             max-w-[1220px] mx-auto
             space-y-4 lg:space-y-5"
    >

      <!-- HERO -->
      <section
        class="settings-hero
               rounded-2xl
               overflow-hidden"
      >

        <div
          class="settings-hero-body
                 flex items-center
                 justify-between
                 gap-6
                 px-6 sm:px-10 py-6"
        >

          <div
            class="flex items-center
                   gap-6 min-w-0"
          >

            <!-- AVATAR -->
            <div class="relative shrink-0">

              <div
                id="profile-avatar"
                class="avatar-ring
                       w-[68px] h-[68px] sm:w-[94px] sm:h-[94px]
                       rounded-full
                       overflow-hidden
                       avatar-fallback
                       flex items-center
                       justify-center
                       text-xl sm:text-3xl font-bold
                       text-emerald-800"
              >
                <span id="profile-avatar-fallback">
                  ML
                </span>
              </div>

              <button
                id="change-avatar-btn"
                type="button"
                class="absolute right-0 bottom-0
                       w-7 h-7 sm:w-8 sm:h-8 rounded-full
                       bg-emerald-600 text-white
                       grid place-items-center
                       border-2 border-white
                       shadow-sm
                       hover:bg-emerald-700"
                aria-label="Change profile image"
              >
                <i
                  data-lucide="camera"
                  class="w-3.5 h-3.5 sm:w-4 sm:h-4"
                ></i>
              </button>

              <input
                id="avatar-input"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                class="hidden"
              >

            </div>


            <!-- PROFILE INFORMATION -->
            <div class="min-w-0">

              <h2
                id="profile-name"
                class="text-xl
                       sm:text-[28px]
                       font-bold
                       truncate"
              >
                Student
              </h2>


              <div
                class="flex flex-wrap
                       items-center
                       gap-1.5 sm:gap-2
                       text-xs sm:text-sm
                       mt-1 sm:mt-2
                       text-[#0e4037]
                       dark:text-[#d6eee5]"
              >

                <i
                  data-lucide="graduation-cap"
                  class="w-3.5 h-3.5 sm:w-4 sm:h-4"
                ></i>

                <span id="profile-level">
                  Level 400
                </span>

                <span>
                  •
                </span>

                <span id="profile-program">
                  Computer Science
                </span>

              </div>


              <!-- EDITABLE TAGLINE -->
              <div
                class="tagline-wrap
                       flex items-center
                       gap-2
                       text-xs sm:text-sm
                       text-[#476c83]
                       dark:text-gray-400
                       mt-1.5 sm:mt-3"
              >

                <span
                  id="profile-tagline"
                  class="tagline-text"
                >
                  Better plans. Bigger goals.
                </span>

                <button
                  id="edit-tagline"
                  type="button"
                  class="hover:text-emerald-700
                         dark:hover:text-emerald-400
                         transition"
                  aria-label="Edit tagline"
                  title="Edit tagline"
                >
                  <i
                    data-lucide="pencil"
                    class="w-3.5 h-3.5 sm:w-4 sm:h-4"
                  ></i>
                </button>

              </div>

            </div>

          </div>


          <!-- EDIT PROFILE -->
          <button
            id="open-edit-profile"
            type="button"
            class="shrink-0
                   border border-emerald-300
                   dark:border-emerald-800
                   text-emerald-800
                   dark:text-emerald-300
                   rounded-xl
                   px-3.5 sm:px-5 py-2 sm:py-3
                   text-xs sm:text-sm font-semibold
                   hover:bg-emerald-50
                   dark:hover:bg-emerald-900/20
                   flex items-center gap-2"
          >
            <i
              data-lucide="pencil"
              class="w-3.5 h-3.5 sm:w-4 sm:h-4"
            ></i>

            Edit Profile
          </button>

        </div>


        <!-- STATS -->
        <div
          id="settings-stats"
          class="settings-stats
                 grid grid-cols-2 lg:grid-cols-4
                 bg-white/85 dark:bg-black/10
                 border-t
                 border-white/80 dark:border-white/5
                 px-6 sm:px-8 py-5"
        >

          <div
            class="flex items-center
                   gap-4
                   px-3 sm:px-5
                   border-r
                   border-[#e3ece9]
                   dark:border-white/10"
          >

            <span class="settings-icon">
              <i
                data-lucide="book-open"
                class="w-6 h-6"
              ></i>
            </span>

            <div>

              <strong
                id="stat-total-courses"
                class="block text-xl font-bold"
              >
                –
              </strong>

              <span
                class="text-sm
                       text-[#607a90]
                       dark:text-gray-400"
              >
                Total Courses
              </span>

            </div>

          </div>


          <div
            class="flex items-center
                   gap-4
                   px-3 sm:px-5
                   lg:border-r
                   border-[#e3ece9]
                   dark:border-white/10"
          >

            <span class="settings-icon">
              <i
                data-lucide="square-check-big"
                class="w-6 h-6"
              ></i>
            </span>

            <div>

              <strong
                id="stat-tasks-completed"
                class="block text-xl font-bold"
              >
                –
              </strong>

              <span
                class="text-sm
                       text-[#607a90]
                       dark:text-gray-400"
              >
                Tasks Completed
              </span>

            </div>

          </div>


          <div
            class="flex items-center
                   gap-4
                   px-3 sm:px-5
                   border-r
                   border-[#e3ece9]
                   dark:border-white/10
                   mt-4 lg:mt-0"
          >

            <span class="settings-icon">
              <i
                data-lucide="clock-3"
                class="w-6 h-6"
              ></i>
            </span>

            <div>

              <strong
                id="stat-study-hours"
                class="block text-xl font-bold"
              >
                –
              </strong>

              <span
                class="text-sm
                       text-[#607a90]
                       dark:text-gray-400"
              >
                Study Hours
              </span>

            </div>

          </div>


          <div
            class="flex items-center
                   gap-4
                   px-3 sm:px-5
                   mt-4 lg:mt-0"
          >

            <span class="settings-icon">
              <i
                data-lucide="chart-no-axes-column"
                class="w-6 h-6"
              ></i>
            </span>

            <div>

              <strong
                id="stat-completion-rate"
                class="block text-xl font-bold"
              >
                –
              </strong>

              <span
                class="text-sm
                       text-[#607a90]
                       dark:text-gray-400"
              >
                Completion Rate
              </span>

            </div>

          </div>

        </div>

      </section>


      <!-- TWO COLUMNS -->
      <div
        class="settings-grid
               grid grid-cols-1 lg:grid-cols-2
               gap-4 lg:gap-5
               items-start"
      >

        <!-- LEFT COLUMN -->
        <div class="space-y-4 lg:space-y-5">

          <!-- 1. ACCOUNT & PROFILE -->
          <section
            id="account-card"
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >
            <div class="flex items-center gap-3 mb-4">
              <span class="settings-icon">
                <i data-lucide="user-round" class="w-6 h-6"></i>
              </span>
              <div>
                <h3 class="font-bold text-lg">Account & Profile</h3>
                <p class="text-xs sm:text-sm text-[#607a90] dark:text-gray-400">
                  Your credentials and academic identity
                </p>
              </div>
            </div>

            <div>
              <button
                id="account-email-row"
                type="button"
                class="settings-row w-full flex items-center gap-3 px-1 text-left min-h-[48px]"
              >
                <span class="settings-row-icon">
                  <i data-lucide="mail" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Email Address</strong>
                  <small id="account-email-value" class="text-xs text-[#607a90] dark:text-gray-400 truncate block">
                    Loading…
                  </small>
                </span>
                <i data-lucide="chevron-right" class="w-5 h-5 text-[#456d83] dark:text-gray-500 shrink-0"></i>
              </button>

              <button
                id="account-profile-row"
                type="button"
                class="settings-row w-full flex items-center gap-3 px-1 text-left min-h-[48px]"
              >
                <span class="settings-row-icon">
                  <i data-lucide="circle-user-round" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Profile Details</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Update your full name, level and program
                  </small>
                </span>
                <i data-lucide="chevron-right" class="w-5 h-5 text-[#456d83] dark:text-gray-500 shrink-0"></i>
              </button>

              <button
                id="account-password-row"
                type="button"
                class="settings-row w-full flex items-center gap-3 px-1 text-left min-h-[48px]"
              >
                <span class="settings-row-icon">
                  <i data-lucide="lock-keyhole" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Account Password</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Change your account password securely
                  </small>
                </span>
                <i data-lucide="chevron-right" class="w-5 h-5 text-[#456d83] dark:text-gray-500 shrink-0"></i>
              </button>
            </div>

            <div class="mt-4 pt-3 border-t border-[#edf2f1] dark:border-white/10 flex items-center justify-end gap-2">
              <button
                id="open-edit-profile-card-btn"
                type="button"
                class="btn-press px-4 py-2.5 rounded-xl border border-emerald-300 dark:border-emerald-700/60 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 text-xs sm:text-sm font-semibold flex items-center gap-2"
              >
                <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Edit Profile
              </button>
            </div>
          </section>

          <!-- 2. ACADEMIC PREFERENCES -->
          <section
            id="academic-prefs-card"
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >
            <div class="flex items-center gap-3 mb-4">
              <span class="settings-icon">
                <i data-lucide="target" class="w-6 h-6"></i>
              </span>
              <div>
                <h3 class="font-bold text-lg">Academic Preferences</h3>
                <p class="text-xs sm:text-sm text-[#607a90] dark:text-gray-400">
                  Study routines, targets, and scheduling habits
                </p>
              </div>
            </div>

            <button
              id="current-goal-row"
              type="button"
              class="w-full rounded-xl border border-emerald-100 dark:border-emerald-900/40 bg-[#ecfbf4] dark:bg-emerald-900/10 p-4 text-left hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition mb-3"
            >
              <div class="flex items-start gap-3">
                <span class="settings-icon">
                  <i data-lucide="target" class="w-6 h-6"></i>
                </span>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between gap-3">
                    <strong id="goal-title" class="text-sm font-bold">Current Goal</strong>
                    <i data-lucide="chevron-right" class="w-5 h-5 text-emerald-700 dark:text-emerald-400"></i>
                  </div>
                  <p id="goal-description" class="text-xs text-[#4e7385] dark:text-gray-400 mt-1">
                    Complete 5 courses this semester
                  </p>
                  <div class="flex items-center gap-3 mt-4">
                    <div class="settings-progress flex-1">
                      <span id="goal-progress" style="width:0%"></span>
                    </div>
                    <span id="goal-count" class="text-xs font-semibold">0/0</span>
                  </div>
                </div>
              </div>
            </button>

            <div>
              <!-- PREFERRED STUDY TIME -->
              <div class="settings-row flex items-center justify-between gap-3 px-1 min-h-[48px]">
                <span class="settings-row-icon">
                  <i data-lucide="clock-3" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Preferred Study Time</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    When you focus best during the day
                  </small>
                </span>
                <span class="relative shrink-0">
                  <select
                    id="preferred-study-time"
                    class="settings-control rounded-xl px-4 py-2 pr-9 text-base sm:text-sm settings-select"
                  >
                    <option value="flexible">Flexible</option>
                    <option value="morning">Morning (6am–12pm)</option>
                    <option value="afternoon">Afternoon (12pm–6pm)</option>
                    <option value="evening">Evening (6pm–12am)</option>
                  </select>
                  <i data-lucide="chevron-down" class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                </span>
              </div>

              <!-- WEEK STARTS ON -->
              <div class="settings-row flex items-center justify-between gap-3 px-1 min-h-[48px]">
                <span class="settings-row-icon">
                  <i data-lucide="calendar-days" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Week Starts On</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    First day shown on weekly schedules
                  </small>
                </span>
                <span class="relative shrink-0">
                  <select
                    id="week-start-select"
                    class="settings-control rounded-xl px-4 py-2 pr-9 text-base sm:text-sm settings-select"
                  >
                    <option value="1">Monday</option>
                    <option value="0">Sunday</option>
                  </select>
                  <i data-lucide="chevron-down" class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                </span>
              </div>

              <!-- UPDATE GOALS -->
              <button
                id="update-goals-row"
                type="button"
                class="settings-row w-full flex items-center gap-3 px-1 text-left min-h-[48px]"
              >
                <span class="settings-row-icon">
                  <i data-lucide="pencil" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Adjust Academic Targets</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Modify target courses and weekly study hour goal
                  </small>
                </span>
                <i data-lucide="chevron-right" class="w-5 h-5 text-[#456d83] dark:text-gray-500 shrink-0"></i>
              </button>
            </div>
          </section>

          <!-- 3. SECURITY & PASSWORD -->
          <section
            id="security-card"
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >
            <div class="flex items-center gap-3 mb-4">
              <span class="settings-icon">
                <i data-lucide="shield-check" class="w-6 h-6"></i>
              </span>
              <div>
                <h3 class="font-bold text-lg">Security & Password</h3>
                <p class="text-xs sm:text-sm text-[#607a90] dark:text-gray-400">
                  Protect your account with a secure password
                </p>
              </div>
            </div>

            <div>
              <div class="settings-row flex items-center justify-between gap-3 px-1 min-h-[48px]">
                <span class="settings-row-icon">
                  <i data-lucide="key-round" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Password Status</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Password must be at least 8 characters
                  </small>
                </span>
                <button
                  id="trigger-change-password-btn"
                  type="button"
                  class="btn-press px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs sm:text-sm font-semibold shrink-0"
                >
                  Change Password
                </button>
              </div>

              <div class="settings-row flex items-center justify-between gap-3 px-1 min-h-[48px]">
                <span class="settings-row-icon">
                  <i data-lucide="laptop" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Active Session</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Signed in from current browser
                  </small>
                </span>
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                  Active
                </span>
              </div>
            </div>
          </section>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="space-y-4 lg:space-y-5">

          <!-- 4. NOTIFICATION SETTINGS -->
          <section
            id="notifications-card"
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >
            <div class="flex items-center gap-3 mb-4">
              <span class="settings-icon">
                <i data-lucide="bell-ring" class="w-6 h-6"></i>
              </span>
              <div>
                <h3 class="font-bold text-lg">Notification Settings</h3>
                <p class="text-xs sm:text-sm text-[#607a90] dark:text-gray-400">
                  Control study reminders, class notices, and deadline alerts
                </p>
              </div>
            </div>

            <div>
              <!-- MASTER TOGGLE -->
              <button
                id="pref-notifications"
                type="button"
                class="settings-row w-full flex items-center justify-between gap-3 px-1 text-left min-h-[52px]"
                aria-label="Toggle all notifications"
              >
                <span class="settings-row-icon">
                  <i data-lucide="bell" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Enable All Notifications</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Master switch for all study reminders and deadline alerts
                  </small>
                </span>
                <span
                  id="notifications-toggle"
                  class="sp-toggle shrink-0"
                  aria-hidden="true"
                ></span>
              </button>

              <!-- BROWSER PUSH -->
              <div
                class="settings-row flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-1 min-h-[48px]"
              >
                <div class="flex items-center gap-3 flex-1 min-w-0">
                  <span class="settings-row-icon">
                    <i data-lucide="monitor-cog" class="w-5 h-5"></i>
                  </span>
                  <div class="flex-1 min-w-0">
                    <strong class="block text-sm">Browser Notifications</strong>
                    <small id="browser-push-status" class="text-xs text-[#607a90] dark:text-gray-400 block truncate">
                      Checking browser permission…
                    </small>
                  </div>
                </div>
                <button
                  id="browser-push-enable"
                  type="button"
                  class="btn-press px-3.5 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold shrink-0 self-start sm:self-center"
                >
                  Enable
                </button>
              </div>

              <!-- GRANULAR CHANNELS CONTAINER -->
              <div
                id="granular-notifications-container"
                class="mt-4 pt-3 border-t border-[#edf2f1] dark:border-white/10 space-y-3 transition-opacity"
              >
                <div class="px-1 mb-2">
                  <h4 class="text-xs font-bold uppercase tracking-wider text-[#607a90] dark:text-gray-400">
                    Notification Channels
                  </h4>
                  <p class="text-[11px] text-[#8aa39b] dark:text-gray-500 mt-0.5">
                    Fine-tune specific alerts saved to your academic profile
                  </p>
                </div>

                <!-- CLASS REMINDERS GROUP -->
                <div class="rounded-xl border border-gray-100 dark:border-white/5 bg-gray-50/50 dark:bg-white/5 p-3 space-y-1">
                  <div class="text-xs font-semibold text-emerald-800 dark:text-emerald-300 flex items-center gap-1.5 mb-1.5">
                    <i data-lucide="calendar" class="w-3.5 h-3.5"></i> Class Reminders
                  </div>

                  <button
                    type="button"
                    class="notif-channel-row w-full flex items-center justify-between gap-3 py-2 px-1 text-left min-h-[44px] hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition"
                    data-notif-key="class_1h"
                  >
                    <span class="flex-1 min-w-0">
                      <strong class="block text-xs font-semibold">1 Hour Before Class</strong>
                      <small class="text-[11px] text-[#607a90] dark:text-gray-400 block">Early notice for upcoming lectures</small>
                    </span>
                    <span class="sp-toggle scale-90 shrink-0" data-toggle-for="class_1h"></span>
                  </button>

                  <button
                    type="button"
                    class="notif-channel-row w-full flex items-center justify-between gap-3 py-2 px-1 text-left min-h-[44px] hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition"
                    data-notif-key="class_30m"
                  >
                    <span class="flex-1 min-w-0">
                      <strong class="block text-xs font-semibold">30 Minutes Before Class</strong>
                      <small class="text-[11px] text-[#607a90] dark:text-gray-400 block">Gentle reminder to gather notes</small>
                    </span>
                    <span class="sp-toggle scale-90 shrink-0" data-toggle-for="class_30m"></span>
                  </button>

                  <button
                    type="button"
                    class="notif-channel-row w-full flex items-center justify-between gap-3 py-2 px-1 text-left min-h-[44px] hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition"
                    data-notif-key="class_10m"
                  >
                    <span class="flex-1 min-w-0">
                      <strong class="block text-xs font-semibold">10 Minutes Before Class</strong>
                      <small class="text-[11px] text-[#607a90] dark:text-gray-400 block">Final alert to attend lecture</small>
                    </span>
                    <span class="sp-toggle scale-90 shrink-0" data-toggle-for="class_10m"></span>
                  </button>
                </div>

                <!-- DEADLINE ALERTS GROUP -->
                <div class="rounded-xl border border-gray-100 dark:border-white/5 bg-gray-50/50 dark:bg-white/5 p-3 space-y-1">
                  <div class="text-xs font-semibold text-emerald-800 dark:text-emerald-300 flex items-center gap-1.5 mb-1.5">
                    <i data-lucide="clock" class="w-3.5 h-3.5"></i> Deadline Alerts
                  </div>

                  <button
                    type="button"
                    class="notif-channel-row w-full flex items-center justify-between gap-3 py-2 px-1 text-left min-h-[44px] hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition"
                    data-notif-key="deadline_24h"
                  >
                    <span class="flex-1 min-w-0">
                      <strong class="block text-xs font-semibold">24 Hours Before Deadline</strong>
                      <small class="text-[11px] text-[#607a90] dark:text-gray-400 block">1-day early reminder for assignments</small>
                    </span>
                    <span class="sp-toggle scale-90 shrink-0" data-toggle-for="deadline_24h"></span>
                  </button>

                  <button
                    type="button"
                    class="notif-channel-row w-full flex items-center justify-between gap-3 py-2 px-1 text-left min-h-[44px] hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition"
                    data-notif-key="deadline_2h"
                  >
                    <span class="flex-1 min-w-0">
                      <strong class="block text-xs font-semibold">2 Hours Before Deadline</strong>
                      <small class="text-[11px] text-[#607a90] dark:text-gray-400 block">Urgent reminder as deadline nears</small>
                    </span>
                    <span class="sp-toggle scale-90 shrink-0" data-toggle-for="deadline_2h"></span>
                  </button>

                  <button
                    type="button"
                    class="notif-channel-row w-full flex items-center justify-between gap-3 py-2 px-1 text-left min-h-[44px] hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition"
                    data-notif-key="deadline_overdue"
                  >
                    <span class="flex-1 min-w-0">
                      <strong class="block text-xs font-semibold">Overdue Task Notices</strong>
                      <small class="text-[11px] text-[#607a90] dark:text-gray-400 block">Notice when a deadline has passed</small>
                    </span>
                    <span class="sp-toggle scale-90 shrink-0" data-toggle-for="deadline_overdue"></span>
                  </button>
                </div>

                <!-- ACADEMIC INTELLIGENCE GROUP -->
                <div class="rounded-xl border border-gray-100 dark:border-white/5 bg-gray-50/50 dark:bg-white/5 p-3 space-y-1">
                  <div class="text-xs font-semibold text-emerald-800 dark:text-emerald-300 flex items-center gap-1.5 mb-1.5">
                    <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Academic Intelligence
                  </div>

                  <button
                    type="button"
                    class="notif-channel-row w-full flex items-center justify-between gap-3 py-2 px-1 text-left min-h-[44px] hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition"
                    data-notif-key="curriculum_alerts"
                  >
                    <span class="flex-1 min-w-0">
                      <strong class="block text-xs font-semibold">Curriculum Calendar Alerts</strong>
                      <small class="text-[11px] text-[#607a90] dark:text-gray-400 block">Notices for semester timeline changes</small>
                    </span>
                    <span class="sp-toggle scale-90 shrink-0" data-toggle-for="curriculum_alerts"></span>
                  </button>

                  <button
                    type="button"
                    class="notif-channel-row w-full flex items-center justify-between gap-3 py-2 px-1 text-left min-h-[44px] hover:bg-black/5 dark:hover:bg-white/5 rounded-lg transition"
                    data-notif-key="study_gap_suggestions"
                  >
                    <span class="flex-1 min-w-0">
                      <strong class="block text-xs font-semibold">Study Gap Suggestions</strong>
                      <small class="text-[11px] text-[#607a90] dark:text-gray-400 block">Smart tips for free time between classes</small>
                    </span>
                    <span class="sp-toggle scale-90 shrink-0" data-toggle-for="study_gap_suggestions"></span>
                  </button>
                </div>
              </div>
            </div>
          </section>

          <!-- 5. APP PREFERENCES & GUIDED TOUR -->
          <section
            id="app-preferences-card"
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >
            <div class="flex items-center gap-3 mb-4">
              <span class="settings-icon">
                <i data-lucide="sliders-horizontal" class="w-6 h-6"></i>
              </span>
              <div>
                <h3 class="font-bold text-lg">App Preferences</h3>
                <p class="text-xs sm:text-sm text-[#607a90] dark:text-gray-400">
                  Display theme, language, and guides
                </p>
              </div>
            </div>

            <div>
              <!-- DARK MODE -->
              <button
                id="pref-dark-mode"
                type="button"
                class="settings-row w-full flex items-center justify-between gap-3 px-1 text-left min-h-[48px]"
                aria-label="Toggle dark mode theme"
              >
                <span class="settings-row-icon">
                  <i data-lucide="moon" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Dark Mode</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Switch between light and dark theme
                  </small>
                </span>
                <span
                  id="dark-mode-toggle"
                  class="sp-toggle shrink-0"
                  aria-hidden="true"
                ></span>
              </button>

              <!-- LANGUAGE -->
              <div class="settings-row flex items-center justify-between gap-3 px-1 min-h-[48px]">
                <span class="settings-row-icon">
                  <i data-lucide="globe-2" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Language</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Application interface language
                  </small>
                </span>
                <span class="relative shrink-0">
                  <select
                    id="language-select"
                    class="settings-control rounded-xl px-4 py-2 pr-9 text-base sm:text-sm settings-select"
                  >
                    <option value="en">English</option>
                  </select>
                  <i data-lucide="chevron-down" class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                </span>
              </div>

              <!-- DATA BACKUP EXPORT -->
              <button
                id="export-data-row"
                type="button"
                class="settings-row w-full flex items-center justify-between gap-3 px-1 text-left min-h-[48px]"
              >
                <span class="settings-row-icon">
                  <i data-lucide="cloud-download" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Data Backup</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Export courses, tasks, and settings to JSON
                  </small>
                </span>
                <span class="inline-flex rounded-full bg-[#e9f8f1] dark:bg-emerald-900/30 px-3 py-1.5 text-xs font-semibold text-emerald-800 dark:text-emerald-300 shrink-0">
                  Export Data
                </span>
              </button>

              <!-- SMART GUIDE TOUR -->
              <div
                id="smart-guide-tour-section"
                class="settings-row flex items-center justify-between gap-3 px-1 py-3 min-h-[48px]"
              >
                <div class="flex items-center gap-3 flex-1 min-w-0">
                  <span class="settings-row-icon">
                    <i data-lucide="compass" class="w-5 h-5"></i>
                  </span>
                  <div class="flex-1 min-w-0">
                    <strong class="block text-sm">Interactive Walkthrough</strong>
                    <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                      Restart the guided tour anytime
                    </small>
                  </div>
                </div>
                <button
                  id="restart-tour-btn"
                  type="button"
                  class="btn-press inline-flex items-center justify-center gap-1.5 rounded-xl border border-emerald-300 dark:border-emerald-700/60 bg-emerald-50 dark:bg-emerald-950/30 px-3.5 py-2 text-xs font-semibold text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-950/50 transition shrink-0"
                >
                  <i data-lucide="play" class="w-3.5 h-3.5"></i> Restart Tour
                </button>
              </div>
            </div>
          </section>

          <!-- 6. DANGER ZONE & ACCOUNT ACTIONS -->
          <section
            id="danger-zone-card"
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6
                   border-red-200 dark:border-red-900/40
                   bg-red-50/10 dark:bg-red-950/10"
          >
            <div class="flex items-center gap-3 mb-4">
              <span class="w-[46px] h-[46px] rounded-full grid place-items-center bg-red-100 dark:bg-red-950/50 text-red-600 dark:text-red-400 shrink-0">
                <i data-lucide="triangle-alert" class="w-6 h-6"></i>
              </span>
              <div>
                <h3 class="font-bold text-lg text-red-700 dark:text-red-400">Account Actions & Danger Zone</h3>
                <p class="text-xs sm:text-sm text-[#607a90] dark:text-gray-400">
                  Manage local storage and session credentials
                </p>
              </div>
            </div>

            <div>
              <button
                id="clear-cache-row"
                type="button"
                class="settings-row w-full flex items-center justify-between gap-3 px-1 text-left min-h-[48px]"
              >
                <span class="w-[38px] h-[38px] rounded-full grid place-items-center bg-red-50 dark:bg-red-950/40 text-red-600 shrink-0">
                  <i data-lucide="trash-2" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Clear Local Cache</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    Free up browser storage without deleting account data
                  </small>
                </span>
                <span class="inline-flex rounded-full bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/40 px-3 py-1.5 text-xs font-semibold text-red-700 dark:text-red-300 shrink-0">
                  Clear Cache
                </span>
              </button>

              <div class="settings-row flex items-center justify-between gap-3 px-1 py-2 min-h-[48px]">
                <span class="w-[38px] h-[38px] rounded-full grid place-items-center bg-red-50 dark:bg-red-950/40 text-red-600 shrink-0">
                  <i data-lucide="log-out" class="w-5 h-5"></i>
                </span>
                <span class="flex-1 min-w-0">
                  <strong class="block text-sm">Sign Out</strong>
                  <small class="text-xs text-[#607a90] dark:text-gray-400 block">
                    End your active session on this device
                  </small>
                </span>
                <button
                  id="danger-logout-btn"
                  type="button"
                  class="btn-press inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold shrink-0 transition"
                >
                  <i data-lucide="log-out" class="w-3.5 h-3.5"></i> Sign Out
                </button>
              </div>
            </div>
          </section>

        </div>

      </div>

    </div>

  </main>

</div>


<!-- =========================================================
     PROFILE MODAL
========================================================= -->

<div
  id="profile-modal"
  class="settings-modal hidden
         fixed inset-0
         bg-black/50
         z-50
         p-4
         items-center
         justify-center"
>

  <div
    class="settings-card
           rounded-2xl
           w-full max-w-lg
           max-h-[92vh]
           overflow-y-auto
           p-6
           modal-enter"
  >

    <div
      class="flex items-center
             justify-between mb-5"
    >

      <div>

        <h3 class="font-bold text-xl">
          Edit Profile
        </h3>

        <p
          class="text-sm
                 text-[#607a90]
                 dark:text-gray-400
                 mt-1"
        >
          Update your account information.
        </p>

      </div>

      <button
        type="button"
        id="close-profile-modal"
        class="text-gray-400
               hover:text-gray-700
               dark:hover:text-white"
        aria-label="Close"
      >
        <i data-lucide="x"></i>
      </button>

    </div>


    <div
      id="profile-form-error"
      class="hidden
             mb-3
             text-sm
             text-red-700
             dark:text-red-300
             bg-red-50
             dark:bg-red-900/20
             rounded-xl
             px-4 py-3"
    ></div>


    <form
      id="profile-form"
      class="space-y-4"
    >

      <div
        class="flex items-center gap-4"
      >

        <div
          id="modal-avatar-preview"
          class="w-16 h-16
                 rounded-full
                 overflow-hidden
                 avatar-fallback
                 flex items-center
                 justify-center
                 text-xl font-bold
                 text-emerald-800"
        ></div>

        <div>

          <button
            type="button"
            id="modal-change-avatar"
            class="text-sm
                   font-semibold
                   text-emerald-700
                   dark:text-emerald-400"
          >
            Change profile photo
          </button>

          <p
            class="text-xs
                   text-gray-500
                   dark:text-gray-400
                   mt-1"
          >
            PNG, JPG or WebP · max 5MB
          </p>

        </div>

      </div>


      <div>

        <label
          class="block
                 text-xs
                 font-semibold
                 mb-1.5"
        >
          Full name
        </label>

        <input
          required
          maxlength="100"
          name="full_name"
          class="settings-control
                 w-full
                 rounded-xl
                 px-4 py-3
                 text-sm
                 outline-none
                 focus:ring-2
                 focus:ring-emerald-100
                 dark:focus:ring-emerald-900/30"
        >

      </div>


      <div>

        <label
          class="block
                 text-xs
                 font-semibold
                 mb-1.5"
        >
          Email address
        </label>

        <input
          required
          type="email"
          maxlength="150"
          name="email"
          class="settings-control
                 w-full
                 rounded-xl
                 px-4 py-3
                 text-sm
                 outline-none
                 focus:ring-2
                 focus:ring-emerald-100
                 dark:focus:ring-emerald-900/30"
        >

      </div>


      <div
        class="grid
               grid-cols-1
               sm:grid-cols-2
               gap-4"
      >

        <div>

          <label
            class="block
                   text-xs
                   font-semibold
                   mb-1.5"
          >
            Program / Course
          </label>

          <input
            maxlength="100"
            name="program"
            placeholder="Computer Science"
            class="settings-control
                   w-full
                   rounded-xl
                   px-4 py-3
                   text-sm"
          >

        </div>


        <div>

          <label
            class="block
                   text-xs
                   font-semibold
                   mb-1.5"
          >
            Level
          </label>

          <input
            maxlength="20"
            name="level"
            placeholder="Level 400"
            class="settings-control
                   w-full
                   rounded-xl
                   px-4 py-3
                   text-sm"
          >

        </div>

      </div>


      <div
        class="flex justify-end
               gap-2 pt-2"
      >

        <button
          type="button"
          id="cancel-profile"
          class="px-4 py-2.5
                 text-sm font-medium
                 text-gray-600
                 dark:text-gray-300"
        >
          Cancel
        </button>

        <button
          id="save-profile-btn"
          type="submit"
          class="px-5 py-2.5
                 text-sm
                 bg-emerald-600
                 hover:bg-emerald-700
                 text-white
                 rounded-xl
                 font-semibold"
        >
          Save Changes
        </button>

      </div>

    </form>

  </div>

</div>


<!-- =========================================================
     PASSWORD MODAL
========================================================= -->

<div
  id="password-modal"
  class="settings-modal hidden
         fixed inset-0
         bg-black/50
         z-50
         p-4
         items-center
         justify-center"
>

  <div
    class="settings-card
           rounded-2xl
           w-full max-w-md
           p-6
           modal-enter"
  >

    <div
      class="flex items-center
             justify-between mb-5"
    >

      <div>

        <h3 class="font-bold text-xl">
          Change Password
        </h3>

        <p
          class="text-sm
                 text-[#607a90]
                 dark:text-gray-400
                 mt-1"
        >
          Use a strong password you do not reuse elsewhere.
        </p>

      </div>

      <button
        type="button"
        id="close-password-modal"
        class="text-gray-400
               hover:text-gray-700
               dark:hover:text-white"
        aria-label="Close"
      >
        <i data-lucide="x"></i>
      </button>

    </div>


    <div
      id="password-form-error"
      class="hidden
             mb-3
             text-sm
             text-red-700
             dark:text-red-300
             bg-red-50
             dark:bg-red-900/20
             rounded-xl
             px-4 py-3"
    ></div>


    <form
      id="password-form"
      class="space-y-4"
    >

      <div>

        <label
          class="block
                 text-xs
                 font-semibold
                 mb-1.5"
        >
          Current password
        </label>

        <input
          required
          type="password"
          name="current_password"
          autocomplete="current-password"
          class="settings-control
                 w-full
                 rounded-xl
                 px-4 py-3
                 text-sm"
        >

      </div>


      <div>

        <label
          class="block
                 text-xs
                 font-semibold
                 mb-1.5"
        >
          New password
        </label>

        <input
          required
          minlength="8"
          type="password"
          name="new_password"
          autocomplete="new-password"
          class="settings-control
                 w-full
                 rounded-xl
                 px-4 py-3
                 text-sm"
        >

      </div>


      <div>

        <label
          class="block
                 text-xs
                 font-semibold
                 mb-1.5"
        >
          Confirm new password
        </label>

        <input
          required
          minlength="8"
          type="password"
          name="confirm_password"
          autocomplete="new-password"
          class="settings-control
                 w-full
                 rounded-xl
                 px-4 py-3
                 text-sm"
        >

      </div>


      <div
        class="flex justify-end
               gap-2"
      >

        <button
          type="button"
          id="cancel-password"
          class="px-4 py-2.5
                 text-sm
                 text-gray-600
                 dark:text-gray-300"
        >
          Cancel
        </button>

        <button
          type="submit"
          class="px-5 py-2.5
                 text-sm
                 bg-emerald-600
                 hover:bg-emerald-700
                 text-white
                 rounded-xl
                 font-semibold"
        >
          Update Password
        </button>

      </div>

    </form>

  </div>

</div>


<!-- =========================================================
     GOAL MODAL
========================================================= -->

<div
  id="goal-modal"
  class="settings-modal hidden
         fixed inset-0
         bg-black/50
         z-50
         p-4
         items-center
         justify-center"
>

  <div
    class="settings-card
           rounded-2xl
           w-full max-w-md
           p-6
           modal-enter"
  >

    <h3 class="font-bold text-xl">
      Academic Goal
    </h3>

    <p
      class="text-sm
             text-[#607a90]
             dark:text-gray-400
             mt-1 mb-5"
    >
      Choose the course target shown on this page.
    </p>


    <form
      id="goal-form"
      class="space-y-4"
    >

      <div>

        <label
          class="block
                 text-xs
                 font-semibold
                 mb-1.5"
        >
          Goal
        </label>

        <input
          id="goal-input"
          maxlength="120"
          value="Complete 5 courses this semester"
          class="settings-control
                 w-full
                 rounded-xl
                 px-4 py-3
                 text-sm"
        >

      </div>


      <div>

        <label
          class="block
                 text-xs
                 font-semibold
                 mb-1.5"
        >
          Weekly Study Goal (Hours)
        </label>

        <input
          id="goal-weekly-hours"
          type="number"
          step="0.5"
          min="1"
          max="100"
          value="15"
          class="settings-control
                 w-full
                 rounded-xl
                 px-4 py-3
                 text-sm"
        >

      </div>

      <div>

        <label
          class="block
                 text-xs
                 font-semibold
                 mb-1.5"
        >
          Target courses
        </label>

        <input
          id="goal-target"
          type="number"
          min="1"
          max="100"
          value="5"
          class="settings-control
                 w-full
                 rounded-xl
                 px-4 py-3
                 text-sm"
        >

      </div>


      <div
        class="flex justify-end
               gap-2"
      >

        <button
          type="button"
          id="cancel-goal"
          class="px-4 py-2.5
                 text-sm
                 text-gray-600
                 dark:text-gray-300"
        >
          Cancel
        </button>

        <button
          type="submit"
          class="px-5 py-2.5
                 text-sm
                 bg-emerald-600
                 hover:bg-emerald-700
                 text-white
                 rounded-xl
                 font-semibold"
        >
          Save Goal
        </button>

      </div>

    </form>

  </div>

</div>


<!-- SCRIPTS -->
<script src="../assets/js/nav.js"></script>
<script src="../assets/js/notifications.js"></script>
<script src="../assets/js/work-timer.js"></script>
<script src="../assets/js/browser-push.js"></script>
<script src="../assets/js/settings.js"></script>


<script src="../assets/js/guided-tour.js"></script>
</body>
</html>