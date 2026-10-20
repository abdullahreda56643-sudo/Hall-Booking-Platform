CREATE DATABASE IF NOT EXISTS hall_platform
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hall_platform;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message VARCHAR(500) NOT NULL,
  link VARCHAR(500) NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_notifications_recipient_read_created (recipient_id, read_at, created_at),
  INDEX idx_notifications_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role VARCHAR(20) NOT NULL PRIMARY KEY,
  permissions JSON NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role, permissions) VALUES
  ('staff', JSON_OBJECT('reports.view_own', TRUE, 'reports.create', TRUE, 'reports.submit', TRUE, 'reports.delete', TRUE));

CREATE TABLE IF NOT EXISTS reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  class_name VARCHAR(100) NULL,
  beneficiaries_count INT UNSIGNED NULL,
  body TEXT NOT NULL,
  status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  admin_note TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  submitted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_reports_status_created (status, created_at),
  INDEX idx_reports_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id BIGINT UNSIGNED NOT NULL,
  type ENUM('image','video','file') NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(150) NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_media_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
  INDEX idx_report_media_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_name VARCHAR(150) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  booking_date DATE NOT NULL,
  day VARCHAR(20) NOT NULL,
  period TINYINT UNSIGNED NOT NULL,
  class_name VARCHAR(100) NOT NULL,
  status ENUM('booked','cancelled') NOT NULL DEFAULT 'booked',
  active_slot VARCHAR(40) GENERATED ALWAYS AS (
    CASE WHEN status = 'booked'
      THEN CONCAT(booking_date, '-', period)
      ELSE NULL
    END
  ) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_bookings_period CHECK (period BETWEEN 1 AND 8),
  CONSTRAINT chk_bookings_day_matches_date CHECK (
    day = CASE DAYOFWEEK(booking_date)
      WHEN 1 THEN CONVERT(UNHEX('D8A7D984D8A3D8ADD8AF') USING utf8mb4) COLLATE utf8mb4_unicode_ci
      WHEN 2 THEN CONVERT(UNHEX('D8A7D984D8A5D8ABD986D98AD986') USING utf8mb4) COLLATE utf8mb4_unicode_ci
      WHEN 3 THEN CONVERT(UNHEX('D8A7D984D8ABD984D8A7D8ABD8A7D8A1') USING utf8mb4) COLLATE utf8mb4_unicode_ci
      WHEN 4 THEN CONVERT(UNHEX('D8A7D984D8A3D8B1D8A8D8B9D8A7D8A1') USING utf8mb4) COLLATE utf8mb4_unicode_ci
      WHEN 5 THEN CONVERT(UNHEX('D8A7D984D8AED985D98AD8B3') USING utf8mb4) COLLATE utf8mb4_unicode_ci
      ELSE CONVERT(UNHEX('') USING utf8mb4) COLLATE utf8mb4_unicode_ci
    END
  ),
  CONSTRAINT chk_bookings_teacher_name CHECK (CHAR_LENGTH(TRIM(teacher_name)) > 0),
  CONSTRAINT chk_bookings_class_name CHECK (CHAR_LENGTH(TRIM(class_name)) > 0),
  UNIQUE KEY uq_bookings_active_slot (active_slot),
  INDEX idx_bookings_date_period (booking_date, period),
  INDEX idx_bookings_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hall_profile (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  section_title VARCHAR(150) NOT NULL,
  section_intro VARCHAR(500) NOT NULL DEFAULT '',
  opened_on DATE NULL,
  purpose TEXT NOT NULL,
  technologies TEXT NOT NULL,
  contributors TEXT NOT NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hall_profile (id, section_title, section_intro, purpose, technologies, contributors)
VALUES (1, 'عن قاعة التعلم الرقمية', 'تعرفي على تاريخ القاعة وأهدافها والتقنيات المستخدمة فيها.', '', '', '');

CREATE TABLE IF NOT EXISTS hall_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  image_path VARCHAR(500) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hall_evidence_order (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  actor_name VARCHAR(150) NOT NULL,
  actor_role VARCHAR(20) NULL,
  action VARCHAR(50) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_logs_created (created_at),
  INDEX idx_audit_logs_action_created (action, created_at),
  INDEX idx_audit_logs_user_created (user_id, created_at),
  INDEX idx_audit_logs_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_lookup (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (name, username, password_hash, role, is_active)
SELECT 'مدير النظام', 'admin',
       '$2y$10$.9oNrYAmbMBLbaYNyM1dKO3.vAzv1id07f0uz4xQHfhdyf.fym5vC',
       'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');
