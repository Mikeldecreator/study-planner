-- Safe Migration for Aiven MySQL / Cloud Database
-- Purely additive and non-destructive.

-- 1. Base User Table
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    level           VARCHAR(20)  DEFAULT NULL,
    program         VARCHAR(100) DEFAULT NULL,
    tagline         VARCHAR(255) DEFAULT NULL,
    avatar_path     VARCHAR(255) DEFAULT NULL,
    dark_mode       TINYINT(1)   NOT NULL DEFAULT 0,
    weekly_goal_hours DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
    week_start_day  TINYINT(1)   NOT NULL DEFAULT 1,
    preferred_study_time VARCHAR(20) DEFAULT 'morning',
    preferred_study_days VARCHAR(100) DEFAULT '1,2,3,4,5',
    tour_completed  TINYINT(1)   NOT NULL DEFAULT 0,
    dismissed_tips  TEXT DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Courses (parent to tasks, schedule_events)
CREATE TABLE IF NOT EXISTS courses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    lecturer        VARCHAR(100) DEFAULT NULL,
    credits         TINYINT      NOT NULL DEFAULT 3,
    semester        VARCHAR(30)  DEFAULT NULL,
    icon            VARCHAR(10)  DEFAULT '📘',
    color           VARCHAR(20)  DEFAULT '#166534',
    grade_point     DECIMAL(3,2) DEFAULT NULL,
    status          ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    completed_at    DATETIME DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Semesters (parent to curriculum_weeks, academic_events)
CREATE TABLE IF NOT EXISTS semesters (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(150) NOT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    is_current  TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Curriculum Weeks (child of semesters)
CREATE TABLE IF NOT EXISTS curriculum_weeks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    semester_id INT NOT NULL,
    user_id     INT NOT NULL,
    week_number TINYINT UNSIGNED NOT NULL,
    label       VARCHAR(100) NOT NULL,
    week_type   ENUM('teaching','student_week','revision','exam','break','other') NOT NULL DEFAULT 'teaching',
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    notes       TEXT DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Academic Events (child of semesters)
CREATE TABLE IF NOT EXISTS academic_events (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    semester_id INT NOT NULL,
    user_id     INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    event_date  DATE NOT NULL,
    event_type  VARCHAR(50) NOT NULL DEFAULT 'milestone',
    notes       TEXT DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tasks (child of users and courses; parent to schedule_events, task_work_sessions, notifications)
CREATE TABLE IF NOT EXISTS tasks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    course_id       INT DEFAULT NULL,
    title           VARCHAR(200) NOT NULL,
    description     TEXT DEFAULT NULL,
    type            ENUM('assignment','project','test','exam','research','study_session','lab_report','other')
                    NOT NULL DEFAULT 'assignment',
    priority        ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status          ENUM('not_started','pending','in_progress','completed') NOT NULL DEFAULT 'pending',
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    duration_hours  DECIMAL(5,2) DEFAULT 0,
    due_at          DATETIME NOT NULL,
    completed_at    DATETIME DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    INDEX idx_user_due (user_id, due_at),
    INDEX idx_user_status (user_id, status),
    INDEX idx_tasks_user_status_due (user_id, status, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Schedule Events (child of users, courses, tasks)
CREATE TABLE IF NOT EXISTS schedule_events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    course_id       INT DEFAULT NULL,
    task_id         INT DEFAULT NULL,
    title           VARCHAR(200) NOT NULL,
    event_type      ENUM('lecture','study','exam','other') NOT NULL DEFAULT 'lecture',
    day_of_week     TINYINT NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    is_completed    TINYINT(1) NOT NULL DEFAULT 0,
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    completed_at    DATETIME DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    FOREIGN KEY (task_id)   REFERENCES tasks(id)   ON DELETE SET NULL,
    INDEX idx_user_day (user_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Work Timers
CREATE TABLE IF NOT EXISTS work_timers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Task Work Sessions (Focus Timer)
CREATE TABLE IF NOT EXISTS task_work_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_id INT NOT NULL,
    started_at DATETIME NOT NULL,
    duration_seconds INT NOT NULL DEFAULT 0,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'completed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_task_work_user (user_id),
    INDEX idx_task_work_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    task_id     INT DEFAULT NULL,
    channel     ENUM('in_app','email','push') NOT NULL DEFAULT 'in_app',
    message     VARCHAR(255) NOT NULL,
    send_at     DATETIME NOT NULL,
    sent_at     DATETIME DEFAULT NULL,
    read_at     DATETIME DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, read_at),
    INDEX idx_pending_send (sent_at, send_at),
    INDEX idx_notif_user_channel_send (user_id, channel, read_at, send_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Activity Log
CREATE TABLE IF NOT EXISTS activity_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    message     VARCHAR(255) NOT NULL,
    icon        VARCHAR(30) DEFAULT 'info',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Password Resets
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token_hash  VARCHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Browser Push Subscriptions
CREATE TABLE IF NOT EXISTS browser_push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    content_encoding VARCHAR(50) NOT NULL DEFAULT 'aes128gcm',
    expiration_time BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_browser_push_endpoint_hash (endpoint_hash),
    KEY idx_browser_push_user_id (user_id),
    CONSTRAINT fk_browser_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Push Daily Reminders
CREATE TABLE IF NOT EXISTS push_daily_reminders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    task_id INT NOT NULL,
    reminder_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_daily_task (user_id, task_id, reminder_date),
    KEY idx_push_daily_user (user_id),
    KEY idx_push_daily_task (task_id),
    CONSTRAINT fk_push_daily_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_push_daily_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;