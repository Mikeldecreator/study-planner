# Student Study Planner — Setup Guide

Stack: HTML + Tailwind CSS (CDN) + vanilla JS on the frontend, PHP + MySQL on
the backend. Built to run on XAMPP for local demo/defense. Fully responsive
(mobile drawer sidebar) and supports a database-persisted dark mode.

## 1. Install

1. Copy this whole `study-planner` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`, click **Import**, choose
   `database/schema.sql`, and run it. This uses the existing shared `study-planner`
   database with all tables plus one demo user, 8 sample courses, and a
   dozen sample tasks/sessions so every page has real data to show on
   first run.
4. **Set the demo user's real password** — the placeholder hash in
   schema.sql won't work as-is. Easiest fix: go to `register.php` in the
   app and create your own account instead of using the seeded one, or
   run this in phpMyAdmin's SQL tab (replace the password):
   ```sql
   UPDATE users SET password_hash = '<paste output of PHP below>' WHERE id = 1;
   ```
   Generate a real hash with: `php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"`
5. Visit `http://localhost/study-planner/public/login.php`.

## 2. Folder structure

```
study-planner/
  config/config.php         <- DB credentials + app settings (edit this first)
  includes/                 <- db.php, auth.php, functions.php (shared PHP logic)
  api/                       <- PHP is ONLY this: pure JSON endpoints, zero HTML
    csrf.php  me.php  settings.php  register.php  login.php  logout.php
    stats.php  dashboard.php
    tasks.php  courses.php  deadlines.php  schedule.php  notifications.php
    progress.php  reports.php  workload.php
  public/                   <- HTML is ONLY this: static shells, zero PHP
    login.html  register.html
    dashboard.html  courses.html  tasks.html  deadlines.html  schedule.html
    progress.html  reports.html  notifications.html
  assets/
    css/style.css
    partials/sidebar.html    <- the ONE copy of the sidebar; injected by nav.js
    js/theme-init.js          <- runs first, applies saved dark-mode before paint
    js/nav.js                 <- injects sidebar, auth guard, dark-mode sync,
                                  mobile drawer, logout — every protected page loads this first
    js/auth.js                 <- login.html + register.html form handling
    js/dashboard.js  courses.js  tasks.js  deadlines.js  schedule.js
    js/progress.js  reports.js  workload.js  notifications.js
  cron/check_deadlines.php  <- the reminder job (see section 4)
  database/schema.sql
```

**Important**: since PHP no longer renders any page, Apache can't gate
`dashboard.html` (or any page) server-side — anyone who requests the URL
gets the file. Every protected page's very first script is `nav.js`,
which injects the shared sidebar, then calls `api/me.php` and bounces to
`login.html` if the session isn't valid. This is standard for a
static-frontend + API-backend split — it's the same pattern any
single-page app uses. Page scripts `await window.APP_READY` (a promise
`nav.js` sets up) before fetching their own data, so nothing renders
before the auth check completes.

## 3. Features by page

**Dashboard** — stat cards, today's schedule, upcoming deadlines, task
breakdown donut, weekly progress line chart, workload overview, recent
activity, and a dismissible overdue-tasks alert banner (shown whenever
`api/stats.php` reports at least one overdue task) — all live from the
database, all dark-mode aware.

**Courses** (`courses.html` / `api/courses.php`) — stat cards (courses,
tasks, due-this-week, average progress, GPA), a course card grid, a full
sortable/searchable table, and an Add/Edit modal. Per-course **progress
is computed** (`completed tasks ÷ total tasks` for that course), not
manually set — same single-source-of-truth principle as the urgency
classifier. **GPA** is a credit-weighted average of `grade_point` across
graded courses (courses with no grade entered are excluded, not counted
as 0).

**Tasks** (`tasks.html` / `api/tasks.php`) — stat cards, status tabs
(All/Pending/In Progress/Completed/Overdue), a right-hand filter panel
(course/type/priority + live search), a task summary donut, an upcoming-
deadlines mini panel, and an Add/Edit modal with a manual progress slider.
Filters and search hit the API with query params rather than filtering a
big client-side blob, so it stays correct as the task list grows.

**Deadlines** (`deadlines.html` / `api/deadlines.php`) — stat cards
(On Track / Due Soon / Overdue / Total — the exact same three buckets
`classifyTaskUrgency()` uses everywhere else, just relabeled for this
page), a searchable/filterable table with a **live countdown** ("2d 6h
18m left" / "Overdue by 1h 15m") that recomputes every 60 seconds
client-side from each task's `due_at` (no polling needed), a deadline
overview donut, a next-7-days panel, and a navigable month calendar with
color-coded dots per day.

**Schedule** (`schedule.html` / `api/schedule.php`) — a Google-Calendar-
style weekly time grid (7 AM–9 PM, Sun–Sat) with events absolutely
positioned by start/end time and colored by course (or event type if no
course is set), stat cards (sessions, scheduled hours, completed, weekly
utilization %, days meeting a "did something" goal), an editable weekly
study-hours goal with a progress bar, a time-distribution donut, and a
"Today's Sessions" panel with one-click complete-toggling.
**Design simplification, stated plainly:** `schedule_events` is a
*recurring weekly template* (`day_of_week` 0–6), not date-specific
occurrences — the same timetable repeats every week, like a real
semester schedule does. That's why there's no prev/next-week date
navigation. Extending it to specific-date one-off sessions would mean
adding a nullable `specific_date` column and generating occurrences for
a date range — a reasonable v2, left out here to keep the model simple
for a defense build.

**Notifications bell** and **dark mode** work identically on every page
above — the bell polls `api/notifications.php` every 30s, and the dark
toggle (in the sidebar) persists to `users.dark_mode` via
`api/settings.php`, so it follows the student across devices, not just
this browser's `localStorage`.

**Progress** (`progress.html` / `api/progress.php`) — an overall
completion donut with a Tasks breakdown (Completed/Pending/Overdue) and a
Study Sessions breakdown (Completed/Scheduled), a This Week/Month/Semester
range filter, and a per-course progress list with a **Good / Average /
Needs Improvement** rating. The rating thresholds (≥70% Good, ≥40%
Average, below Needs Improvement) are the exact same ones the Courses
page's progress bar color uses — one scale, reused everywhere a course's
health is shown.

**Reports** (`reports.html` / `api/reports.php`) — a This Week/Month/
Semester academic report: five stat rows (completed, created, completion
rate, overdue, study hours), a Weekly Activity bar chart (Mon–Sun, built
from completed-task hours and completed recurring session hours for each
weekday), and a **rule-based Performance Summary** — a few short, plainly-
computed observations (completion trend, deadlines approaching, which
course has the heaviest pending load) plus one suggested action. This is
straightforward server-side logic over real numbers, not an AI feature —
worth being upfront about in case that's assumed from the "Suggested
action" framing.

**Notifications** (`notifications.html` / `api/workload.php`) — the
mockup for this page turned out to be a weekly-workload view rather than
a notification list, so it's built as both: a **Recent Notifications**
feed at the top (the same data the bell dropdown shows, in full, with a
Mark All Read action) followed by the **Weekly Workload** breakdown
(Study/Assignments/Projects/Tests hours for the current week) and a
**Workload Status** row per weekday, computed as Light (&lt;2h) / Moderate
(2–4h) / Heavy (≥4h) from that day's due-task hours plus recurring
session hours — a real computed signal, not the mockup's example values.

**Settings** (`settings.php` / `api/settings.php`) — a profile summary
card (name/program/level), an Academic Statistics list (total courses,
GPA, total tasks, completion rate — reusing `api/courses.php` and
`api/stats.php` rather than duplicating those computations), and a
Preferences list: Notifications on/off, Dark Mode (reuses the same
`setDarkMode()` the sidebar toggle uses, so the two can never drift out
of sync), and Week Starts (Sunday/Monday). An Edit Profile modal updates
full name, email, program, and level via `api/settings.php`, with the
same validation style as `api/register.php` (required fields, valid
email, uniqueness check). Note: pages here moved from the original
`.html` shells to `.php` shells that call `requirePageLogin()` at the
top — a small deviation from the pure static-HTML-frontend description
below, added for server-side gating on top of the client-side check
`nav.js` already does.

## 4. Turning on real reminders (the notification cron)

XAMPP/Apache has no built-in job scheduler, so "check for tasks due soon
and notify me" needs something outside the browser to run periodically.
On Windows that's **Task Scheduler** calling the PHP CLI directly:

1. Confirm `C:\xampp\php\php.exe` exists.
2. Open **Task Scheduler → Create Task**.
3. **Trigger**: Daily, recur every 15 minutes, indefinitely.
4. **Action**: Program = `C:\xampp\php\php.exe`,
   Arguments = `"C:\xampp\htdocs\study-planner\cron\check_deadlines.php"`.
5. Make sure Apache + MySQL are running whenever you want reminders to
   fire — including during a live demo.

You can also just run it manually any time to test:
```
php cron/check_deadlines.php
```

**In-app bell** works immediately with zero setup — it polls
`api/notifications.php` every 30 seconds and needs no cron, no email, no
push infrastructure.

**Email reminders** are optional and off by default (`EMAIL_ENABLED =
false` in `config/config.php`). To turn them on:
```
composer require phpmailer/phpmailer
```
then set `EMAIL_ENABLED = true` and fill in `SMTP_*` in
`config/config.php`. For Gmail, `SMTP_PASS` must be a 16-character
**App Password**, not your normal login password.

## 5. Why the architecture looks like this

- **Frontend is only .html/.css/.js; backend is only .php.** PHP never
  outputs a single tag of HTML — every `api/*.php` file's job is to read
  the database and `echo json_encode(...)`. The two layers talk
  exclusively through `fetch()` calls and JSON.
- **The sidebar exists in exactly one file** — `assets/partials/sidebar.html`
  — and `nav.js` fetches and injects it into every page's
  `#sidebar-slot`. Five pages needing the same ~60 lines of nav markup is
  exactly the situation that produces silent drift (one page's link list
  gets out of sync with another's); injecting a shared partial closes
  that off entirely, in plain HTML/JS with no build step.
- **One place decides "overdue" vs "due soon" vs "on track"** —
  `includes/functions.php`'s `classifyTaskUrgency()`. Dashboard stats,
  the Tasks page, and the Deadlines page all call it (Deadlines just
  relabels "upcoming" to "on track" for its own vocabulary), so they
  cannot drift out of sync with each other.
- **Progress and GPA are computed, not stored redundantly.** A course's
  progress % is derived from its tasks' completion state every time
  it's requested; nothing caches a stale percentage that could go wrong.
- **Dark mode is server-persisted, not just `localStorage`.** `theme-init.js`
  applies the last-known `localStorage` value instantly (so there's no
  flash of light mode on load), then `nav.js` reconciles against the
  database value from `api/me.php` — `localStorage` is a fast first
  paint, the database is the source of truth.
- **PDO + prepared statements everywhere** — no raw string-interpolated SQL.
- **Cron is a separate PHP CLI script, not a webpage** — it never goes
  through Apache, exactly the pattern a real production cron job would use.

## Data visibility / legacy seed-data repair

The app now automatically repairs an older database where the bundled demo
records were inserted under `user_id = 1` while the real logged-in account has
a different user ID. On successful login/registration, if the current account
has no planner data, the app copies the bundled demo user's courses, tasks,
schedule events, notifications, and activity log entries to the current user
and remaps course/task foreign keys correctly.

For a fresh database import, `database/schema.sql` now uses the dynamically
created demo-user ID instead of assuming that the demo account will always be
ID 1.


## Persistent Work Timer

Tasks, courses, and recurring study sessions share one persistent work-tracking engine. Newly created tasks/courses start as Pending at 0%. Starting an item begins a saved timer; hiding the page, switching away, refreshing, navigating away, or closing the page pauses the active timer. Returning to the site lets the student resume from the saved time. Manual completion remains available, and configured estimated durations drive automatic progress and automatic completion at 100%.

For an existing database, run `database/upgrade_work_tracking.sql` once. A fresh import of `database/schema.sql` already contains the new course/session fields and `work_timers` table.
