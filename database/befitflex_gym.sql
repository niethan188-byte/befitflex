-- ============================================================
-- BE FIT FLEX GYM — LAGUNA
-- Full database: schema + demo data
--
-- XAMPP import:
--   1. Start Apache and MySQL in the XAMPP Control Panel
--   2. Open http://localhost/phpmyadmin
--   3. Click Import → Choose File → this file → Import
--
-- Tables are created in dependency order so all foreign keys resolve.
-- ============================================================
DROP DATABASE IF EXISTS befitflex_gym;
CREATE DATABASE befitflex_gym DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
USE befitflex_gym;

CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('admin','member') NOT NULL DEFAULT 'member',
    email_verified_at DATETIME NULL,
    email_verification_token CHAR(64) NULL,
    email_verification_expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    INDEX idx_email (email), INDEX idx_user_type (user_type)
) ENGINE=InnoDB;

CREATE TABLE remember_tokens (
    selector CHAR(32) PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    user_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_remember_tokens_user (user_id),
    INDEX idx_remember_tokens_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE members (
    member_id VARCHAR(50) PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    member_name VARCHAR(255) NOT NULL,
    contact_number TEXT NOT NULL,
    contact_number_encrypted TEXT NULL,
    email VARCHAR(255) NOT NULL,
    membership_type ENUM('Monthly') NOT NULL,
    join_date DATE NOT NULL,
    out_date DATE NULL,
    status ENUM('Active','Inactive','Expired') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_status (status), INDEX idx_member_name (member_name), INDEX idx_email (email)
) ENGINE=InnoDB;

CREATE TABLE member_progress (
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

CREATE TABLE member_progress_goals (
    member_id VARCHAR(50) PRIMARY KEY,
    target_weight_kg DECIMAL(5,2) NOT NULL,
    target_date DATE NOT NULL,
    updated_by_user_id INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE member_lifting_logs (
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

CREATE TABLE gyms (
    gym_id VARCHAR(50) PRIMARY KEY,
    gym_branch VARCHAR(255) NOT NULL,
    gym_name VARCHAR(255) NOT NULL,
    location TEXT, description TEXT,
    contact_number VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gym_branch (gym_branch)
) ENGINE=InnoDB;

CREATE TABLE day_passes (
    pass_id VARCHAR(50) PRIMARY KEY,
    member_id VARCHAR(50) NULL,
    public_token CHAR(64) NULL,
    visitor_name VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    visit_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 150.00,
    payment_method ENUM('Cash','Card','GCash','Bank Transfer','Maya') NOT NULL DEFAULT 'Cash',
    pass_status ENUM('Paid','Pending','Cancelled') NOT NULL DEFAULT 'Paid',
    maya_checkout_id VARCHAR(100) NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT day_passes_member_fk FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE SET NULL,
    INDEX idx_day_pass_date (visit_date), INDEX idx_day_pass_status (pass_status),
    INDEX idx_day_pass_member_id (member_id)
) ENGINE=InnoDB;

CREATE TABLE sessions (
    session_id VARCHAR(50) PRIMARY KEY,
    session_name VARCHAR(255) NOT NULL,
    session_date DATE NOT NULL,
    session_time TIME NOT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    session_plan LONGTEXT NOT NULL,
    member_id VARCHAR(50),
    session_status ENUM('Scheduled','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE SET NULL,
    INDEX idx_session_date (session_date), INDEX idx_session_status (session_status)
) ENGINE=InnoDB;

CREATE TABLE payments (
    payment_id VARCHAR(50) PRIMARY KEY,
    member_id VARCHAR(50) NULL,
    customer_name VARCHAR(255) NULL,
    session_id VARCHAR(50),
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash','Card','GCash','Bank Transfer','Maya') NOT NULL,
    payment_status ENUM('Paid','Pending','Overdue') NOT NULL DEFAULT 'Pending',
    payment_date DATE,
    maya_checkout_id VARCHAR(100) NULL,
    notes LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE SET NULL,
    INDEX idx_member_id (member_id), INDEX idx_payment_status (payment_status), INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB;

CREATE TABLE classes (
    class_id VARCHAR(50) PRIMARY KEY,
    class_name VARCHAR(255) NOT NULL,
    class_description LONGTEXT,
    schedule_day VARCHAR(20) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_capacity INT NOT NULL DEFAULT 20,
    class_status ENUM('Active','Inactive','Cancelled') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_class_status (class_status), INDEX idx_schedule_day (schedule_day)
) ENGINE=InnoDB;

CREATE TABLE class_attendance (
    attendance_id VARCHAR(50) PRIMARY KEY,
    class_id VARCHAR(50) NOT NULL,
    member_id VARCHAR(50) NOT NULL,
    enrollment_date DATE NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_status ENUM('Present','Absent','Late') NOT NULL DEFAULT 'Present',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    INDEX idx_class_id (class_id), INDEX idx_member_id (member_id), INDEX idx_attendance_date (attendance_date)
) ENGINE=InnoDB;

CREATE TABLE attendance (
    attendance_id VARCHAR(50) PRIMARY KEY,
    member_id VARCHAR(50) NULL,
    day_pass_id VARCHAR(50) NULL,
    check_in_time DATETIME NOT NULL,
    check_out_time DATETIME,
    attendance_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (day_pass_id) REFERENCES day_passes(pass_id) ON DELETE CASCADE,
    INDEX idx_member_id (member_id), INDEX idx_day_pass_id (day_pass_id), INDEX idx_attendance_date (attendance_date)
) ENGINE=InnoDB;

CREATE TABLE activity_log (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(100) NOT NULL,
    details LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id), INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE training_sessions (
    session_id INT PRIMARY KEY AUTO_INCREMENT,
    session_name VARCHAR(255) NOT NULL,
    gym_id VARCHAR(50) NOT NULL,
    session_date DATE NOT NULL,
    session_time TIME NOT NULL,
    duration INT NOT NULL COMMENT 'Duration in minutes',
    max_capacity INT NOT NULL DEFAULT 20,
    description LONGTEXT,
    status ENUM('Scheduled','Ongoing','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (gym_id) REFERENCES gyms(gym_id) ON DELETE CASCADE,
    INDEX idx_session_date (session_date), INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE training_session_attendees (
    attendee_id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT NOT NULL,
    member_id VARCHAR(50) NOT NULL,
    check_in_time DATETIME, check_out_time DATETIME,
    attendance_status ENUM('Present','Absent','Late','Cancelled') NOT NULL DEFAULT 'Present',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES training_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_member (session_id, member_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    notification_title VARCHAR(255) NOT NULL,
    notification_message LONGTEXT NOT NULL,
    notification_icon VARCHAR(50) DEFAULT 'bell',
    icon_color VARCHAR(20) DEFAULT 'primary',
    image_path VARCHAR(500) NULL,
    video_path VARCHAR(500) NULL,
    related_entity_type VARCHAR(50), related_entity_id VARCHAR(50),
    action_url VARCHAR(500),
    is_read TINYINT DEFAULT 0, read_at DATETIME NULL,
    email_sent TINYINT DEFAULT 0, email_sent_at DATETIME NULL,
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read), INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE notification_preferences (
    preference_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    email_payments TINYINT DEFAULT 1,
    email_account TINYINT DEFAULT 1, email_system TINYINT DEFAULT 1,
    sms_payments TINYINT NOT NULL DEFAULT 0,
    sms_account TINYINT NOT NULL DEFAULT 0, sms_system TINYINT NOT NULL DEFAULT 0,
    in_app_payments TINYINT DEFAULT 1,
    in_app_account TINYINT DEFAULT 1, in_app_system TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- DEMO DATA — passwords are plain text so you can sign in right away.
-- Any password changed inside the system is stored hashed.
-- ============================================================
INSERT INTO users (user_id, email, password, user_type) VALUES
(1,'admin@befitflexgym.com','admin123','admin'),
(5,'anna@example.com','member123','member'),
(6,'ben@example.com','member123','member'),
(7,'carla@example.com','member123','member'),
(8,'dan@example.com','member123','member');

UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;

INSERT INTO members (member_id,user_id,member_name,contact_number,email,membership_type,join_date,status) VALUES
('MEM0001',5,'Anna Cruz','09201234567','anna@example.com','Monthly','2026-01-15','Active'),
('MEM0002',6,'Ben Reyes','09211234567','ben@example.com','Monthly','2026-03-02','Active'),
('MEM0003',7,'Carla Diaz','09221234567','carla@example.com','Monthly','2026-05-20','Active'),
('MEM0004',8,'Dan Torres','09231234567','dan@example.com','Monthly','2025-11-11','Expired');

INSERT INTO member_lifting_logs
    (member_id,logged_by_user_id,exercise_name,logged_date,sets_count,reps_per_set,weight_kg,notes) VALUES
('MEM0001',5,'Squat','2026-07-07',3,8,35.00,'Steady 3 × 8, focusing on consistent depth.'),
('MEM0001',5,'Squat','2026-07-21',3,8,37.50,'Added a small load increase after completing all reps.'),
('MEM0001',5,'Squat','2026-08-04',3,8,37.50,'Held the load steady and kept the reps controlled.'),
('MEM0001',5,'Squat','2026-08-18',3,8,40.00,'Completed all sets with consistent depth.'),
('MEM0002',6,'Bench Press','2026-07-08',3,8,40.00,'Working on a consistent bar path.'),
('MEM0002',6,'Bench Press','2026-07-22',3,8,40.00,'Maintained the same load for smooth reps.'),
('MEM0002',6,'Bench Press','2026-08-05',3,9,42.50,'Completed an extra rep on each set.'),
('MEM0002',6,'Bench Press','2026-08-19',3,9,42.50,'Kept the load steady and reps controlled.'),
('MEM0003',7,'Deadlift','2026-07-09',3,6,60.00,'Focused on bracing and a consistent setup.'),
('MEM0003',7,'Deadlift','2026-07-23',3,6,62.50,'Added a small increase after the previous session.'),
('MEM0003',7,'Deadlift','2026-08-06',3,7,62.50,'Maintained the load and added a rep per set.'),
('MEM0003',7,'Deadlift','2026-08-20',3,7,65.00,'Completed all sets with controlled reps.'),
('MEM0004',8,'Pull-up','2026-07-10',3,4,0.00,'Controlled reps with a full range of motion.'),
('MEM0004',8,'Pull-up','2026-07-24',3,5,0.00,'Added one rep per set while keeping form steady.'),
('MEM0004',8,'Pull-up','2026-08-07',3,6,0.00,'Maintained a controlled tempo throughout.'),
('MEM0004',8,'Pull-up','2026-08-21',3,7,0.00,'Completed all reps with consistent form.');

INSERT INTO gyms (gym_id,gym_branch,gym_name,location,description,contact_number) VALUES
('GYM001','Santa Rosa','Be Fit Flex Gym — Santa Rosa','Brgy. Dita, City of Santa Rosa, Laguna','Main branch: complete free-weight area, personal training and physique coaching.','09171234567'),
('GYM002','Cabuyao','Be Fit Flex Gym — Cabuyao','Brgy. Marinig, City of Cabuyao, Laguna','Functional training and conditioning focused branch.','09181234567');

INSERT INTO classes (class_id,class_name,class_description,schedule_day,start_time,end_time,max_capacity,class_status) VALUES
('CLS0001','Morning Bootcamp','High-energy circuit training to start the day.','Monday','06:00:00','07:00:00',20,'Active'),
('CLS0002','Functional Flow','Mobility, core and movement drills for all levels.','Wednesday','07:00:00','08:00:00',15,'Active'),
('CLS0003','Fat Loss Express','45-minute conditioning intervals.','Friday','18:00:00','18:45:00',25,'Active'),
('CLS0004','Weekend Strength Lab','Technique clinic for squat, bench and deadlift.','Saturday','10:00:00','11:30:00',12,'Active');

INSERT INTO payments (payment_id,member_id,amount,payment_method,payment_status,payment_date,notes) VALUES
('PAY00001','MEM0001',995.00,'Card','Paid','2026-01-15','Monthly membership'),
('PAY00002','MEM0002',995.00,'GCash','Paid','2026-07-02','July dues'),
('PAY00003','MEM0002',995.00,'GCash','Pending',NULL,'August dues'),
('PAY00004','MEM0003',995.00,'Bank Transfer','Paid','2026-05-20','Monthly membership'),
('PAY00005','MEM0004',995.00,'Cash','Overdue','2026-06-01','Unsettled balance');

INSERT INTO attendance (attendance_id,member_id,check_in_time,check_out_time,attendance_date) VALUES
('ATT000001','MEM0001','2026-08-11 06:12:00','2026-08-11 07:40:00','2026-08-11'),
('ATT000002','MEM0002','2026-08-11 18:05:00','2026-08-11 19:15:00','2026-08-11'),
('ATT000003','MEM0003','2026-08-12 09:30:00','2026-08-12 10:35:00','2026-08-12');

INSERT INTO class_attendance (attendance_id,class_id,member_id,enrollment_date,attendance_date,attendance_status) VALUES
('CAT000001','CLS0001','MEM0001','2026-07-01','2026-08-10','Present'),
('CAT000002','CLS0003','MEM0002','2026-07-10','2026-08-07','Present'),
('CAT000003','CLS0002','MEM0003','2026-07-15','2026-08-12','Late');

INSERT INTO training_sessions (session_name,gym_id,session_date,session_time,duration,max_capacity,description,status) VALUES
('Saturday Flex Circuit','GYM001','2026-08-15','09:00:00',75,20,'Team circuit with sled pushes and loaded carries.','Scheduled'),
('Core & Conditioning','GYM002','2026-08-16','16:00:00',45,15,'Core stability plus guided conditioning.','Scheduled');

INSERT INTO sessions (session_id,session_name,session_date,session_time,day_of_week,session_plan,member_id,session_status) VALUES
('SES00001','Anna — Squat technique','2026-08-17','07:00:00','Monday','Warm-up, squat progression 5x5, accessory lunges.','MEM0001','Scheduled'),
('SES00002','Ben — Conditioning','2026-08-18','18:00:00','Tuesday','Rower intervals plus kettlebell complex.','MEM0002','Scheduled');

INSERT INTO notifications (user_id,notification_type,notification_title,notification_message,notification_icon,icon_color,action_url,priority) VALUES
(1,'system','Welcome to the admin panel','Members, payments and reports are all managed from here.','shield-halved','danger','admin/dashboard.php','normal'),
(5,'payment','Membership active','Your monthly membership payment has been received.','money-bill-wave','success','member/payments.php','normal'),
(6,'payment','Payment pending','August dues of 995.00 are still outstanding.','triangle-exclamation','warning','member/payments.php','high'),
(5,'system','Session scheduled','Your squat technique session is scheduled for August 17 at 7:00 AM.','calendar-day','info','member/dashboard.php','normal'),
(5,'system','Data Privacy Notice','Be Fit Flex Gym processes your personal data under RA 10173. Read the notice for your rights as a data subject.','user-shield','danger','privacy.php','normal');

INSERT INTO notification_preferences (user_id) VALUES (1),(5),(6),(7),(8);

-- RA 10173 — consent records captured at enrollment
INSERT INTO activity_log (user_id,action,module,details) VALUES
(5,'Privacy consent','Data Privacy','Consent given at enrollment (front desk form) on 2026-01-15'),
(6,'Privacy consent','Data Privacy','Consent given at enrollment (front desk form) on 2026-03-02'),
(7,'Privacy consent','Data Privacy','Consent given at enrollment (front desk form) on 2026-05-20');

COMMIT;