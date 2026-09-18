<?php
require_once __DIR__ . '/../includes/auth.php';
requirePageLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings — Study Planner</title>

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
      padding:16px
    }

    .settings-hero-body{
      flex-direction:column;
      align-items:flex-start
    }

    .settings-stats{
      grid-template-columns:1fr 1fr
    }

    .settings-row{
      padding:13px 12px
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
                       w-[94px] h-[94px]
                       rounded-full
                       overflow-hidden
                       avatar-fallback
                       flex items-center
                       justify-center
                       text-3xl font-bold
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
                       w-8 h-8 rounded-full
                       bg-emerald-600 text-white
                       grid place-items-center
                       border-2 border-white
                       shadow-sm
                       hover:bg-emerald-700"
                aria-label="Change profile image"
              >
                <i
                  data-lucide="camera"
                  class="w-4 h-4"
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
                class="text-2xl
                       sm:text-[28px]
                       font-bold
                       truncate"
              >
                Student
              </h2>


              <div
                class="flex flex-wrap
                       items-center
                       gap-2
                       text-sm
                       mt-2
                       text-[#0e4037]
                       dark:text-[#d6eee5]"
              >

                <i
                  data-lucide="graduation-cap"
                  class="w-4 h-4"
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
                       text-sm
                       text-[#476c83]
                       dark:text-gray-400
                       mt-3"
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
                    class="w-4 h-4"
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
                   px-5 py-3
                   text-sm font-semibold
                   hover:bg-emerald-50
                   dark:hover:bg-emerald-900/20
                   flex items-center gap-2"
          >
            <i
              data-lucide="pencil"
              class="w-4 h-4"
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

        <!-- LEFT -->
        <div
          class="space-y-4 lg:space-y-5"
        >

          <!-- ACCOUNT SETTINGS -->
          <section
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >

            <div
              class="flex items-center
                     gap-3 mb-4"
            >

              <span class="settings-icon">
                <i
                  data-lucide="user-round"
                  class="w-6 h-6"
                ></i>
              </span>

              <div>

                <h3 class="font-bold text-lg">
                  Account Settings
                </h3>

                <p
                  class="text-sm
                         settings-muted
                         text-[#607a90]
                         dark:text-gray-400"
                >
                  Manage your personal information and account details
                </p>

              </div>

            </div>


            <div>

              <button
                id="account-email-row"
                type="button"
                class="settings-row
                       w-full
                       flex items-center
                       gap-3 px-1
                       text-left"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="mail"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Email Address
                  </strong>

                  <small
                    id="account-email-value"
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Loading…
                  </small>

                </span>

                <i
                  data-lucide="chevron-right"
                  class="w-5 h-5
                         text-[#456d83]
                         dark:text-gray-500"
                ></i>

              </button>


              <button
                id="account-password-row"
                type="button"
                class="settings-row
                       w-full
                       flex items-center
                       gap-3 px-1
                       text-left"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="lock-keyhole"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Password
                  </strong>

                  <small
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Change your account password
                  </small>

                </span>

                <i
                  data-lucide="chevron-right"
                  class="w-5 h-5
                         text-[#456d83]
                         dark:text-gray-500"
                ></i>

              </button>


              <button
                id="account-profile-row"
                type="button"
                class="settings-row
                       w-full
                       flex items-center
                       gap-3 px-1
                       text-left"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="circle-user-round"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Profile Information
                  </strong>

                  <small
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Update your name, level and course
                  </small>

                </span>

                <i
                  data-lucide="chevron-right"
                  class="w-5 h-5
                         text-[#456d83]
                         dark:text-gray-500"
                ></i>

              </button>

            </div>

          </section>


          <!-- ACADEMIC GOALS -->
          <section
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >

            <div
              class="flex items-center
                     gap-3 mb-4"
            >

              <span class="settings-icon">
                <i
                  data-lucide="target"
                  class="w-6 h-6"
                ></i>
              </span>

              <div>

                <h3 class="font-bold text-lg">
                  Academic Goals
                </h3>

                <p
                  class="text-sm
                         text-[#607a90]
                         dark:text-gray-400"
                >
                  Set and manage your academic goals
                </p>

              </div>

            </div>


            <button
              id="current-goal-row"
              type="button"
              class="w-full
                     rounded-xl
                     border border-emerald-100
                     dark:border-emerald-900/40
                     bg-[#ecfbf4]
                     dark:bg-emerald-900/10
                     p-4
                     text-left
                     hover:bg-emerald-50
                     dark:hover:bg-emerald-900/20"
            >

              <div
                class="flex items-start gap-3"
              >

                <span class="settings-icon">
                  <i
                    data-lucide="target"
                    class="w-6 h-6"
                  ></i>
                </span>

                <div
                  class="flex-1 min-w-0"
                >

                  <div
                    class="flex items-center
                           justify-between gap-3"
                  >

                    <strong
                      id="goal-title"
                      class="text-sm"
                    >
                      Current Goal
                    </strong>

                    <i
                      data-lucide="chevron-right"
                      class="w-5 h-5"
                    ></i>

                  </div>

                  <p
                    id="goal-description"
                    class="text-xs
                           text-[#4e7385]
                           dark:text-gray-400
                           mt-1"
                  >
                    Complete 5 courses this semester
                  </p>

                  <div
                    class="flex items-center
                           gap-3 mt-4"
                  >

                    <div
                      class="settings-progress
                             flex-1"
                    >
                      <span
                        id="goal-progress"
                        style="width:0%"
                      ></span>
                    </div>

                    <span
                      id="goal-count"
                      class="text-xs font-semibold"
                    >
                      0/0
                    </span>

                  </div>

                </div>

              </div>

            </button>


            <button
              id="update-goals-row"
              type="button"
              class="settings-row
                     w-full
                     flex items-center
                     gap-3 px-1 mt-2
                     text-left"
            >

              <span class="settings-row-icon">
                <i
                  data-lucide="plus"
                  class="w-5 h-5"
                ></i>
              </span>

              <span class="flex-1">

                <strong class="block text-sm">
                  Update Goals
                </strong>

                <small
                  class="text-xs
                         text-[#607a90]
                         dark:text-gray-400"
                >
                  Set new goals or modify existing ones
                </small>

              </span>

              <i
                data-lucide="chevron-right"
                class="w-5 h-5"
              ></i>

            </button>

          </section>

        </div>


        <!-- RIGHT -->
        <div
          class="space-y-4 lg:space-y-5"
        >

          <!-- PREFERENCES -->
          <section
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >

            <div
              class="flex items-center
                     gap-3 mb-4"
            >

              <span class="settings-icon">
                <i
                  data-lucide="sliders-horizontal"
                  class="w-6 h-6"
                ></i>
              </span>

              <div>

                <h3 class="font-bold text-lg">
                  Preferences
                </h3>

                <p
                  class="text-sm
                         text-[#607a90]
                         dark:text-gray-400"
                >
                  Customize your app experience
                </p>

              </div>

            </div>


            <div>

              <!-- NOTIFICATIONS -->
              <button
                id="pref-notifications"
                type="button"
                class="settings-row
                       w-full
                       flex items-center
                       gap-3 px-1
                       text-left"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="bell"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Notifications
                  </strong>

                  <small
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Receive updates and reminders
                  </small>

                </span>

                <span
                  id="notifications-toggle"
                  class="sp-toggle"
                  aria-hidden="true"
                ></span>

              </button>


              <!-- BROWSER NOTIFICATIONS -->
              <div
                class="settings-row
                       flex flex-col
                       sm:flex-row
                       sm:items-center
                       gap-3 px-1"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="monitor-cog"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Browser Notifications
                  </strong>

                  <small
                    id="browser-push-status"
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Checking browser permission…
                  </small>

                </span>

                <span
                  class="flex items-center
                         gap-2 shrink-0"
                >

                  <button
                    id="browser-push-enable"
                    type="button"
                    class="px-3 py-2
                           rounded-lg
                           bg-emerald-700
                           hover:bg-emerald-800
                           text-white
                           text-xs
                           font-semibold"
                  >
                    Enable
                  </button>

                </span>

              </div>


              <!-- DARK MODE -->
              <button
                id="pref-dark-mode"
                type="button"
                class="settings-row
                       w-full
                       flex items-center
                       gap-3 px-1
                       text-left"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="moon"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Dark Mode
                  </strong>

                  <small
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Switch between light and dark theme
                  </small>

                </span>

                <span
                  id="dark-mode-toggle"
                  class="sp-toggle"
                  aria-hidden="true"
                ></span>

              </button>


              <!-- WEEK START -->
              <div
                class="settings-row
                       flex items-center
                       gap-3 px-1"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="calendar-days"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Week Starts On
                  </strong>

                  <small
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Choose the first day of your week
                  </small>

                </span>

                <span class="relative">

                  <select
                    id="week-start-select"
                    class="settings-control
                           rounded-xl
                           px-4 py-2 pr-9
                           text-sm
                           settings-select"
                  >

                    <option value="1">
                      Monday
                    </option>

                    <option value="0">
                      Sunday
                    </option>

                  </select>

                  <i
                    data-lucide="chevron-down"
                    class="pointer-events-none
                           absolute right-2
                           top-1/2
                           -translate-y-1/2
                           w-4 h-4"
                  ></i>

                </span>

              </div>


              <!-- LANGUAGE -->
              <div
                class="settings-row
                       flex items-center
                       gap-3 px-1"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="globe-2"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Language
                  </strong>

                  <small
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    App language
                  </small>

                </span>

                <span class="relative">

                  <select
                    id="language-select"
                    class="settings-control
                           rounded-xl
                           px-4 py-2 pr-9
                           text-sm
                           settings-select"
                  >

                    <option value="en">
                      English
                    </option>

                  </select>

                  <i
                    data-lucide="chevron-down"
                    class="pointer-events-none
                           absolute right-2
                           top-1/2
                           -translate-y-1/2
                           w-4 h-4"
                  ></i>

                </span>

              </div>

            </div>

          </section>


          <!-- APP SETTINGS -->
          <section
            class="settings-card
                   rounded-2xl
                   p-5 sm:p-6"
          >

            <div
              class="flex items-center
                     gap-3 mb-4"
            >

              <span class="settings-icon">
                <i
                  data-lucide="settings-2"
                  class="w-6 h-6"
                ></i>
              </span>

              <div>

                <h3 class="font-bold text-lg">
                  App Settings
                </h3>

                <p
                  class="text-sm
                         text-[#607a90]
                         dark:text-gray-400"
                >
                  Application preferences and data management
                </p>

              </div>

            </div>


            <div>

              <button
                id="export-data-row"
                type="button"
                class="settings-row
                       w-full
                       flex items-center
                       gap-3 px-1
                       text-left"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="cloud-download"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Data Backup
                  </strong>

                  <small
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Keep your data safe
                  </small>

                </span>

                <span
                  class="flex items-center gap-3"
                >

                  <span
                    class="hidden sm:inline-flex
                           rounded-full
                           bg-[#e9f8f1]
                           dark:bg-emerald-900/20
                           px-4 py-2
                           text-xs font-semibold
                           text-emerald-800
                           dark:text-emerald-300"
                  >
                    Export Data
                  </span>

                  <i
                    data-lucide="chevron-right"
                    class="w-5 h-5"
                  ></i>

                </span>

              </button>


              <button
                id="clear-cache-row"
                type="button"
                class="settings-row
                       w-full
                       flex items-center
                       gap-3 px-1
                       text-left"
              >

                <span class="settings-row-icon">
                  <i
                    data-lucide="trash-2"
                    class="w-5 h-5"
                  ></i>
                </span>

                <span class="flex-1">

                  <strong class="block text-sm">
                    Clear Cache
                  </strong>

                  <small
                    class="text-xs
                           text-[#607a90]
                           dark:text-gray-400"
                  >
                    Free up storage space
                  </small>

                </span>

                <span
                  class="flex items-center gap-3"
                >

                  <span
                    class="hidden sm:inline-flex
                           rounded-full
                           bg-[#e9f8f1]
                           dark:bg-emerald-900/20
                           px-4 py-2
                           text-xs font-semibold
                           text-emerald-800
                           dark:text-emerald-300"
                  >
                    Clear Cache
                  </span>

                  <i
                    data-lucide="chevron-right"
                    class="w-5 h-5"
                  ></i>

                </span>

              </button>

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