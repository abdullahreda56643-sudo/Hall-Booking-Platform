-- Run this migration after selecting the application's database in phpMyAdmin.
-- It is safe to run more than once.

SET @migration_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reports'
          AND COLUMN_NAME = 'class_name'
    ),
    'SELECT ''Column reports.class_name already exists''',
    'ALTER TABLE reports ADD COLUMN class_name VARCHAR(100) NULL AFTER title'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reports'
          AND COLUMN_NAME = 'beneficiaries_count'
    ),
    'SELECT ''Column reports.beneficiaries_count already exists''',
    'ALTER TABLE reports ADD COLUMN beneficiaries_count INT UNSIGNED NULL AFTER class_name'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
