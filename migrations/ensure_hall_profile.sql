-- Run this migration after selecting the application's database in phpMyAdmin.
-- It is safe to run more than once.

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
