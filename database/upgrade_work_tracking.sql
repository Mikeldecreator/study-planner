-- Run on an existing database once. Fresh installs should use schema.sql.
ALTER TABLE courses
  ADD COLUMN status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  ADD COLUMN progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN estimated_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
  ADD COLUMN completed_at DATETIME DEFAULT NULL;
ALTER TABLE schedule_events
  ADD COLUMN progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN completed_at DATETIME DEFAULT NULL;
CREATE TABLE work_timers (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
  item_type ENUM('task','course','session') NOT NULL, item_id INT NOT NULL,
  total_work_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0, base_progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('paused','running','completed') NOT NULL DEFAULT 'paused',
  started_at DATETIME DEFAULT NULL, paused_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_work_timer_item (user_id,item_type,item_id), INDEX idx_work_timer_user_status(user_id,status), INDEX idx_work_timer_user_updated(user_id,updated_at)
) ENGINE=InnoDB;
