-- Student Study Planner — Database Schema
-- Import this in phpMyAdmin (XAMPP) or via: mysql -u root -p < schema.sql

-- This project shares the existing MySQL database named `study-planner`.
-- Do NOT CREATE or DROP the database here; other users/projects depend on it.
USE `study-planner`;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    level           VARCHAR(20)  DEFAULT NULL,     -- e.g. "Level 400"
    program         VARCHAR(100) DEFAULT NULL,     -- e.g. "CS"
    avatar_path     VARCHAR(255) DEFAULT NULL,
    dark_mode       TINYINT(1)   NOT NULL DEFAULT 0,
    weekly_goal_hours DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,   -- Settings > Preferences > Notifications toggle
    week_start_day  TINYINT(1)   NOT NULL DEFAULT 1,       -- Settings > Preferences > Week Starts (0=Sunday, 1=Monday)
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- COURSES  (used to tag tasks/schedule: "CSC 405", "MTH 301" ...)
-- ============================================================
CREATE TABLE courses (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    code        VARCHAR(20)  NOT NULL,   -- "CSC 405"
    name        VARCHAR(150) NOT NULL,   -- "Database Systems"
    lecturer    VARCHAR(100) DEFAULT NULL,
    credits     TINYINT      NOT NULL DEFAULT 3,
    semester    VARCHAR(30)  DEFAULT NULL,   -- "2025/2026 Second Semester"
    icon        VARCHAR(10)  DEFAULT '📘',   -- emoji shown on the course card
    color       VARCHAR(20)  DEFAULT '#166534',
    grade_point DECIMAL(3,2) DEFAULT NULL,   -- set once a course is graded; powers the GPA tracker
    status      ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TASKS  (assignments, projects, tests, research — the core entity)
-- ============================================================
CREATE TABLE tasks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    course_id       INT DEFAULT NULL,
    title           VARCHAR(200) NOT NULL,
    description     TEXT DEFAULT NULL,
    type            ENUM('assignment','project','test','exam','research','study_session','lab_report','other')
                    NOT NULL DEFAULT 'assignment',
    priority        ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status          ENUM('not_started','pending','in_progress','completed') NOT NULL DEFAULT 'pending',
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- manual 0–100, shown as the progress bar in Tasks
    duration_hours  DECIMAL(5,2) DEFAULT 0,        -- estimated hours, powers "Workload Overview"
    due_at          DATETIME NOT NULL,
    completed_at    DATETIME DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    INDEX idx_user_due (user_id, due_at),
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB;

-- ============================================================
-- SCHEDULE EVENTS  (recurring weekly timetable — lectures, study blocks)
-- ============================================================
CREATE TABLE schedule_events (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    course_id   INT DEFAULT NULL,
    task_id     INT DEFAULT NULL,          -- link a study session to a task, optional
    title       VARCHAR(200) NOT NULL,
    event_type  ENUM('lecture','study','exam','other') NOT NULL DEFAULT 'lecture',
    day_of_week TINYINT NOT NULL,          -- 0=Sunday ... 6=Saturday
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    FOREIGN KEY (task_id)   REFERENCES tasks(id)   ON DELETE SET NULL,
    INDEX idx_user_day (user_id, day_of_week)
) ENGINE=InnoDB;

-- ============================================================
-- WORK TIMERS (persistent work tracking)
-- ============================================================
CREATE TABLE work_timers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    item_type ENUM('task','course','session') NOT NULL,
    item_id INT NOT NULL,
    total_work_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    base_progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('paused','running','completed') NOT NULL DEFAULT 'paused',
    started_at DATETIME DEFAULT NULL,
    paused_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_work_timer_item (user_id, item_type, item_id),
    INDEX idx_work_timer_user_status (user_id, status),
    INDEX idx_work_timer_user_updated (user_id, updated_at)
) ENGINE=InnoDB;

-- ============================================================
-- NOTIFICATIONS  (bell dropdown + email reminder queue)
-- ============================================================
CREATE TABLE notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    task_id     INT DEFAULT NULL,
    channel     ENUM('in_app','email') NOT NULL DEFAULT 'in_app',
    message     VARCHAR(255) NOT NULL,
    send_at     DATETIME NOT NULL,          -- when it SHOULD fire
    sent_at     DATETIME DEFAULT NULL,      -- when the cron actually sent it
    read_at     DATETIME DEFAULT NULL,      -- when the user opened/read it (in-app only)
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, read_at),
    INDEX idx_pending_send (sent_at, send_at)
) ENGINE=InnoDB;

-- ============================================================
-- ACTIVITY LOG  (powers "Recent Activity" panel)
-- ============================================================
CREATE TABLE activity_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    message     VARCHAR(255) NOT NULL,
    icon        VARCHAR(30) DEFAULT 'info',   -- 'success' | 'info' | 'warning'
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA — one demo user + a few tasks/courses so the dashboard
-- isn't empty on first run.  Password is "password123" (hashed below).
-- ============================================================
INSERT INTO users (full_name, email, password_hash, level, program)
VALUES ('Michael Lois', 'michael@example.com',
        '$2y$12$ODp2uouKOxI1gTM8tbSaH.82vEfF3Yd8OKqG.MqmNg8IVmZh9EUYG', -- placeholder, see README
        'Level 400', 'CS');

SET @demo_user_id = LAST_INSERT_ID();

INSERT INTO courses (user_id, code, name, lecturer, credits, semester, icon, color, grade_point) VALUES
(@demo_user_id, 'CSC 401', 'Web Development',      'Dr. Samuel Adeyemi', 3, '2025/2026 Second Semester', '💻', '#166534', 4.00),
(@demo_user_id, 'CSC 405', 'Database Systems',     'Dr. Faith Okoro',    3, '2025/2026 Second Semester', '🗄️', '#d97706', 3.50),
(@demo_user_id, 'MTH 301', 'Discrete Mathematics', 'Dr. John Obasi',     3, '2025/2026 Second Semester', '∑', '#166534', 4.00),
(@demo_user_id, 'PHY 203', 'Physics for Scientists','Dr. Maryam Aliyu',  4, '2025/2026 Second Semester', '⚛️', '#7c3aed', 3.00),
(@demo_user_id, 'CSC 407', 'Data Structures',      'Dr. Ibeawuchi N.',   3, '2025/2026 Second Semester', '{ }', '#2563eb', NULL),
(@demo_user_id, 'CSC 409', 'Algorithms',           'Dr. Samuel Adeyemi', 3, '2025/2026 Second Semester', '🔗', '#db2777', NULL),
(@demo_user_id, 'GST 101', 'Use of English',       'Dr. Funmi Akande',   2, '2025/2026 Second Semester', '📖', '#0d9488', 3.70),
(@demo_user_id, 'ECO 201', 'Principles of Economics','Dr. Chinedu Eze',  3, '2025/2026 Second Semester', '📊', '#ea580c', NULL);

INSERT INTO tasks (user_id, course_id, title, description, type, priority, status, progress_percent, duration_hours, due_at) VALUES
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 405'), 'Database Assignment', 'Design ER diagram and write SQL queries', 'assignment', 'high',   'in_progress', 70, 4.0,  DATE_ADD(NOW(), INTERVAL 1 DAY)),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 401'), 'Web Development Project', 'Build a responsive portfolio website', 'project', 'medium', 'in_progress', 40, 8.0,  DATE_ADD(NOW(), INTERVAL 3 DAY)),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='MTH 301'), 'Mathematics Test', 'Calculus integrals and applications', 'test', 'medium', 'pending', 0, 3.0,      DATE_ADD(NOW(), INTERVAL 5 DAY)),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 407'), 'Research Paper', 'Write 10 pages on AI in Education', 'research', 'medium', 'pending', 20, 6.0,       DATE_ADD(NOW(), INTERVAL 9 DAY)),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 409'), 'Algorithm Analysis', 'Big-O complexity write-up', 'assignment', 'medium', 'pending', 10, 5.0,  DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 401'), 'JavaScript Assignment', 'DOM manipulation and events', 'assignment', 'high', 'in_progress', 60, 3.0, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='GST 101'), 'Use of English Test', 'Grammar and comprehension', 'test', 'low', 'pending', 0,      2.0,      DATE_ADD(NOW(), INTERVAL 13 DAY)),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='PHY 203'), 'Physics Lab Report', 'Experiment 5: Motion and Forces', 'lab_report', 'low', 'pending', 10, 3.0,          DATE_ADD(NOW(), INTERVAL 11 DAY));

INSERT INTO schedule_events (user_id, course_id, title, event_type, day_of_week, start_time, end_time) VALUES
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='MTH 301'), 'Mathematics',     'lecture', 1, '08:00:00', '10:00:00'),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 401'), 'Web Development', 'lecture', 1, '10:30:00', '12:00:00'),
(@demo_user_id, NULL, 'Study Session (Algorithms)', 'study', 1, '14:00:00', '16:00:00'),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 405'), 'Database Systems', 'lecture', 2, '08:00:00', '10:00:00'),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 407'), 'Data Structures',  'lecture', 2, '11:00:00', '12:30:00'),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='MTH 301'), 'Mathematics',      'lecture', 3, '08:00:00', '10:00:00'),
(@demo_user_id, NULL, 'Study Session (Database)', 'study', 3, '14:00:00', '16:00:00'),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 407'), 'Data Structures',  'lecture', 4, '08:00:00', '10:00:00'),
(@demo_user_id, (SELECT id FROM courses WHERE user_id=@demo_user_id AND code='CSC 409'), 'Algorithms',       'lecture', 4, '10:30:00', '12:00:00');

-- ============================================================
-- MIGRATION — run this block only if you already imported this schema
-- before the Settings page was added (i.e. your `users` table doesn't
-- yet have `notifications_enabled` / `week_start_day`). Safe to skip on
-- a fresh import, since the CREATE TABLE above already includes them.
--
-- Running the plain ALTER TABLE below on a table that already has the
-- column will error with "Duplicate column name" — that's fine, it just
-- means you don't need the migration. If your MySQL/MariaDB version
-- supports `ADD COLUMN IF NOT EXISTS`, use that form instead so it's
-- always safe to (re)run:
--
--   ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_enabled TINYINT(1) NOT NULL DEFAULT 1;
--   ALTER TABLE users ADD COLUMN IF NOT EXISTS week_start_day TINYINT(1) NOT NULL DEFAULT 1;
--
-- Otherwise:
-- ALTER TABLE users ADD COLUMN notifications_enabled TINYINT(1) NOT NULL DEFAULT 1;
-- ALTER TABLE users ADD COLUMN week_start_day TINYINT(1) NOT NULL DEFAULT 1;
--
-- Note: the app itself no longer hard-crashes if this hasn't been run —
-- includes/functions.php::getUserProfileRow() falls back to safe
-- defaults — but Settings won't actually persist those two fields until
-- the column exists, so still run this if Settings changes don't stick.
