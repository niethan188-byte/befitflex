-- ============================================================
--  BE FIT FLEX GYM — performance pass
--
--  Import AFTER befitflex_gym.sql:
--    phpMyAdmin → befitflex_gym → Import → this file
--
--  Safe to run more than once: a helper procedure checks for each index
--  before creating it, because MySQL has no CREATE INDEX IF NOT EXISTS.
-- ============================================================

USE befitflex_gym;

DROP PROCEDURE IF EXISTS add_index_if_missing;

DELIMITER $$
CREATE PROCEDURE add_index_if_missing(
    IN tbl VARCHAR(64), IN idx VARCHAR(64), IN cols VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = tbl AND index_name = idx
    ) THEN
        SET @ddl = CONCAT('CREATE INDEX ', idx, ' ON ', tbl, ' (', cols, ')');
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END$$
DELIMITER ;

-- ---------- attendance: the hottest table in the analytics layer ----------
-- Covers "visits for this member in a date window" without touching the row.
CALL add_index_if_missing('attendance', 'idx_member_date',   'member_id, attendance_date');
-- Powers the peak-hours heatmap and the average-stay calculation.
CALL add_index_if_missing('attendance', 'idx_checkin',       'check_in_time');
-- Finds who is still inside without a full scan.
CALL add_index_if_missing('attendance', 'idx_open_visits',   'check_out_time, attendance_date');

-- ---------- payments: revenue series and outstanding balances ----------
CALL add_index_if_missing('payments',   'idx_status_date',   'payment_status, payment_date');
CALL add_index_if_missing('payments',   'idx_member_status', 'member_id, payment_status');

-- ---------- members: cohort grids and roster filters ----------
CALL add_index_if_missing('members',    'idx_status_join',   'status, join_date');

-- ---------- scheduling ----------
CALL add_index_if_missing('sessions',         'idx_member_date',    'member_id, session_date');
CALL add_index_if_missing('reservations',     'idx_member_status',  'member_id, status');
CALL add_index_if_missing('class_attendance', 'idx_class_date',     'class_id, attendance_date');
CALL add_index_if_missing('class_attendance', 'idx_member_date',    'member_id, attendance_date');
CALL add_index_if_missing('workout_plans',    'idx_member_updated', 'member_id, updated_at');
CALL add_index_if_missing('notifications',    'idx_user_created',   'user_id, created_at');
CALL add_index_if_missing('activity_log',     'idx_module_created', 'module, created_at');

DROP PROCEDURE add_index_if_missing;

-- ============================================================
--  Reporting views — the joins the analytics screens repeat most.
--  A view is not a cache; it just keeps the SQL in one place and lets
--  the optimiser reuse the same plan.
-- ============================================================

CREATE OR REPLACE VIEW v_member_health AS
SELECT
    m.member_id, m.member_name, m.email, m.contact_number,
    m.membership_type, m.status, m.join_date,
    (SELECT MAX(a.attendance_date) FROM attendance a WHERE a.member_id = m.member_id)  AS last_visit,
    (SELECT COUNT(*)               FROM attendance a WHERE a.member_id = m.member_id)  AS total_visits,
    (SELECT COUNT(*) FROM attendance a WHERE a.member_id = m.member_id
       AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))                  AS visits_30d,
    (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.member_id = m.member_id
       AND p.payment_status = 'Paid')                                                  AS lifetime_paid,
    (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.member_id = m.member_id
       AND p.payment_status <> 'Paid')                                                 AS balance_due,
    TIMESTAMPDIFF(MONTH, m.join_date, COALESCE(m.out_date, CURDATE()))                 AS tenure_months
FROM members m;

CREATE OR REPLACE VIEW v_revenue_monthly AS
SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym,
       COUNT(*)                           AS payments,
       SUM(amount)                        AS collected,
       AVG(amount)                        AS avg_payment
FROM payments
WHERE payment_status = 'Paid' AND payment_date IS NOT NULL
GROUP BY ym;

CREATE OR REPLACE VIEW v_class_fill AS
SELECT c.class_id, c.class_name, c.schedule_day, c.start_time, c.max_capacity,
       COUNT(DISTINCT ca.member_id)                                        AS enrolled,
       ROUND(COUNT(DISTINCT ca.member_id) / GREATEST(c.max_capacity,1) * 100) AS fill_pct,
       SUM(ca.attendance_status = 'Present')                               AS present,
       SUM(ca.attendance_status = 'Absent')                                AS absent
FROM classes c
LEFT JOIN class_attendance ca ON ca.class_id = c.class_id
WHERE c.class_status = 'Active'
GROUP BY c.class_id;

-- Verify the pass landed:
--   SHOW INDEX FROM attendance;
--   SELECT * FROM v_member_health ORDER BY visits_30d DESC;
