-- Schema and data upgrade for existing installations.
USE befitflex_gym;

CREATE TABLE IF NOT EXISTS remember_tokens (
    selector CHAR(32) PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    user_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_remember_tokens_user (user_id),
    INDEX idx_remember_tokens_expires (expires_at)
) ENGINE=InnoDB;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'image_path'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE notifications ADD COLUMN image_path VARCHAR(500) NULL AFTER icon_color',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Remove the retired gym reservation feature and its notification settings.
DELETE FROM notifications
 WHERE notification_type = 'reservation' AND action_url LIKE '%reservations.php%';
UPDATE notifications SET notification_type = 'system' WHERE notification_type = 'reservation';
DROP TABLE IF EXISTS reservations;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'notification_preferences' AND column_name = 'email_reservations'
);
SET @sql = IF(@column_exists > 0,
    'ALTER TABLE notification_preferences DROP COLUMN email_reservations',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'notification_preferences' AND column_name = 'in_app_reservations'
);
SET @sql = IF(@column_exists > 0,
    'ALTER TABLE notification_preferences DROP COLUMN in_app_reservations',
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

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'notification_preferences' AND column_name = 'sms_payments'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE notification_preferences ADD COLUMN sms_payments TINYINT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'notification_preferences' AND column_name = 'sms_account'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE notification_preferences ADD COLUMN sms_account TINYINT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'notification_preferences' AND column_name = 'sms_system'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE notification_preferences ADD COLUMN sms_system TINYINT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing accounts predate email verification and are treated as verified.
UPDATE users SET email_verified_at = COALESCE(email_verified_at, created_at);

ALTER TABLE payments
    MODIFY payment_method ENUM('Cash','Card','GCash','Bank Transfer','Maya') NOT NULL;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'member_id'
);
SET @sql = IF(@column_exists > 0,
    'ALTER TABLE payments MODIFY member_id VARCHAR(50) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'customer_name'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE payments ADD COLUMN customer_name VARCHAR(255) NULL AFTER member_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE payments p
LEFT JOIN members m ON m.member_id = p.member_id
SET p.customer_name = COALESCE(p.customer_name, m.member_name)
WHERE p.customer_name IS NULL;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'maya_checkout_id'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE payments ADD COLUMN maya_checkout_id VARCHAR(100) NULL AFTER payment_date',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
    amount DECIMAL(10,2) NOT NULL DEFAULT 150.00,
    payment_method ENUM('Cash','Card','GCash','Bank Transfer','Maya') NOT NULL DEFAULT 'Cash',
    pass_status ENUM('Paid','Pending','Cancelled') NOT NULL DEFAULT 'Paid',
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_day_pass_date (visit_date), INDEX idx_day_pass_status (pass_status)
) ENGINE=InnoDB;

ALTER TABLE day_passes ALTER COLUMN amount SET DEFAULT 150.00;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'day_passes' AND column_name = 'public_token'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE day_passes ADD COLUMN public_token CHAR(64) NULL AFTER member_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'day_passes' AND column_name = 'maya_checkout_id'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE day_passes ADD COLUMN maya_checkout_id VARCHAR(100) NULL AFTER pass_status',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'day_passes' AND column_name = 'member_id'
);
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE day_passes ADD COLUMN member_id VARCHAR(50) NULL AFTER pass_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'day_passes' AND index_name = 'idx_day_pass_member_id'
);
SET @sql = IF(@index_exists = 0,
    'ALTER TABLE day_passes ADD INDEX idx_day_pass_member_id (member_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE() AND table_name = 'day_passes' AND constraint_name = 'day_passes_member_fk'
);
SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE day_passes ADD CONSTRAINT day_passes_member_fk FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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

CREATE TABLE IF NOT EXISTS member_progress (
    progress_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id VARCHAR(50) NOT NULL,
    recorded_by_user_id INT NULL,
    check_date DATE NOT NULL,
    weight_kg DECIMAL(5,2) NULL,
    body_fat_percent DECIMAL(5,2) NULL,
    waist_cm DECIMAL(5,2) NULL,
    hips_cm DECIMAL(5,2) NULL,
    notes VARCHAR(1000) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_member_progress_date (member_id, check_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS member_progress_goals (
    member_id VARCHAR(50) PRIMARY KEY,
    target_weight_kg DECIMAL(5,2) NOT NULL,
    target_date DATE NOT NULL,
    updated_by_user_id INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS member_lifting_logs (
    lifting_log_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id VARCHAR(50) NOT NULL,
    logged_by_user_id INT NOT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    logged_date DATE NOT NULL,
    sets_count TINYINT UNSIGNED NOT NULL,
    reps_per_set SMALLINT UNSIGNED NOT NULL,
    weight_kg DECIMAL(6,2) NOT NULL DEFAULT 0,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (logged_by_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_member_lifting_exercise_date (member_id, exercise_name, logged_date)
) ENGINE=InnoDB;

UPDATE member_progress
   SET notes = 'Morning check-in before training.'
 WHERE notes LIKE 'SAMPLE DEMO ONLY — %; synthetic example, not a real measurement.';

UPDATE member_lifting_logs
   SET notes = CASE
       WHEN exercise_name IN ('Pull-up', 'Push-up')
           THEN 'Controlled reps with a full range of motion.'
       ELSE 'Completed all sets with consistent form.'
   END
 WHERE notes LIKE 'SAMPLE DEMO ONLY — synthetic % training log % of %.'
    OR notes LIKE 'SAMPLE DEMO ONLY — synthetic % not a real workout.';

SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE() AND table_name = 'attendance' AND constraint_name = 'attendance_day_pass_fk'
);
SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE attendance ADD CONSTRAINT attendance_day_pass_fk FOREIGN KEY (day_pass_id) REFERENCES day_passes(pass_id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;