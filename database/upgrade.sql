-- Non-destructive upgrade for installations created before the verification feature.
USE befitflex_gym;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'image_path'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE notifications ADD COLUMN image_path VARCHAR(500) NULL AFTER icon_color',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'video_path'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE notifications ADD COLUMN video_path VARCHAR(500) NULL AFTER image_path',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_verified_at'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER user_type',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_verification_token'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE users ADD COLUMN email_verification_token CHAR(64) NULL AFTER email_verified_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_verification_expires_at'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE users ADD COLUMN email_verification_expires_at DATETIME NULL AFTER email_verification_token',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing accounts predate email verification and are treated as verified.
UPDATE users SET email_verified_at = COALESCE(email_verified_at, created_at);

ALTER TABLE payments
    MODIFY payment_method ENUM('Cash','Card','GCash','Bank Transfer','Maya') NOT NULL;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = 'contact_number_encrypted'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE members ADD COLUMN contact_number_encrypted TEXT NULL AFTER contact_number',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE members MODIFY contact_number TEXT NOT NULL;

CREATE TABLE IF NOT EXISTS day_passes (
    pass_id VARCHAR(50) PRIMARY KEY,
    visitor_name VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    visit_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 300.00,
    payment_method ENUM('Cash','Card','GCash','Bank Transfer','Maya') NOT NULL DEFAULT 'Cash',
    pass_status ENUM('Paid','Pending','Cancelled') NOT NULL DEFAULT 'Paid',
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_day_pass_date (visit_date), INDEX idx_day_pass_status (pass_status)
) ENGINE=InnoDB;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'attendance' AND column_name = 'day_pass_id'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE attendance ADD COLUMN day_pass_id VARCHAR(50) NULL AFTER member_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE attendance MODIFY member_id VARCHAR(50) NULL;

SET @index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'attendance' AND index_name = 'idx_day_pass_id'
);
SET @sql = IF(@index_exists = 0,
    'ALTER TABLE attendance ADD INDEX idx_day_pass_id (day_pass_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE() AND table_name = 'attendance' AND constraint_name = 'attendance_day_pass_fk'
);
SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE attendance ADD CONSTRAINT attendance_day_pass_fk FOREIGN KEY (day_pass_id) REFERENCES day_passes(pass_id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;