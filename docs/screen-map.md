# Screen map

## Public

| File | Purpose | Tables |
|---|---|---|
| `index.php` | Sign in and route the account to its dashboard. | users, members |
| `register.php` | Member self-enrollment, opening dues, and privacy-consent log entry. | users, members, payments, notification_preferences, activity_log |
| `privacy.php` | RA 10173 privacy notice. | — |
| `logout.php` | End the signed-in session. | — |

## Admin

| File | Purpose | Tables |
|---|---|---|
| `dashboard.php` | Headline counts, recent payments, next sessions, membership mix, and activity feed. | most |
| `analytics.php` | Revenue, retention, traffic, utilization, and churn risk. | most |
| `reports.php` | Printable revenue, payment mix, retention, and member reports. | payments, members, attendance, sessions |
| `members.php` | Create, edit, delete, filter, message, and bulk-update members. | users, members |
| `workout-plans.php` | Assign, edit, and remove workout plans for members; sends member notifications. | workout_plans, workout_templates, members, notifications |
| `templates.php` | Manage reusable workout templates. | workout_templates, workout_plans |
| `classes.php` | Manage weekly group classes and capacity. | classes, class_attendance |
| `sessions.php` | Schedule, complete, cancel, and delete member sessions. | sessions, members, notifications |
| `reservations.php` | Manage member gym-time reservations. | reservations |
| `attendance.php` | Check members in and out and view attendance records. | attendance, class_attendance |
| `payments.php` | Record dues, mark paid, and delete payments. | payments, notifications |
| `day-passes.php` | Manage day passes and related check-ins. | day_passes, attendance |
| `gyms.php` | Manage branches and group training sessions. | gyms, training_sessions |
| `announcements.php` | Send announcements to member audiences. | notifications, notification_preferences |
| `notifications.php` | Review notifications. | notifications |
| `activity-log.php` | Review recent audited actions. | activity_log |
| `backup.php` | Download a consistent MySQL snapshot. | most |
| `qr-attendance.php` | Display the member check-in QR station. | — |

## Member

| File | Purpose | Tables |
|---|---|---|
| `dashboard.php` | Membership, visits, balance, bookings, and notifications. | members, attendance, payments, sessions, reservations, notifications |
| `workout-plan.php` | View workout plans assigned by an admin. | workout_plans, workout_templates |
| `progress.php` | Review visit totals, attendance trend, streak, consistency, and visit duration. | attendance |
| `classes.php` | Browse the group schedule, enroll, and view class history. | classes, class_attendance |
| `reservations.php` | Book or cancel a gym-time reservation. | reservations |
| `attendance.php` | View attendance and check-in history. | attendance |
| `payments.php` | View dues and payment history. | payments |
| `profile.php` | Edit profile details, password, and notification preferences. | users, members, notification_preferences |
| `id-card.php` | View and print a member card. | members |
| `notifications.php` | Review notifications. | notifications |

## Shared

| File | Purpose |
|---|---|
| `config/db.php` | PDO connection with a readable failure page. |
| `includes/auth.php` | Session start, admin/member login, and role guard. |
| `includes/helpers.php` | Escaping, CSRF, ID generation, notifications, audit logging, and query helpers. |
| `includes/header.php` / `sidebar.php` / `footer.php` | Shared page chrome and admin/member navigation. |
| `includes/notifications-page.php` | Shared notification screen. |
| `api/check-in.php` | JSON kiosk check-in/check-out endpoint. |
| `api/analytics-export.php` | Admin CSV reports. |
