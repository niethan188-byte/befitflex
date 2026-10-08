# Screen map

Every file, what it does, and which tables it touches.

## Public

| File | Purpose | Tables |
|---|---|---|
| `index.php` | Sign in. Verifies the password, upgrades plain-text demo passwords to bcrypt, opens the session. | users, members, trainers |
| `register.php` | Member self-enrollment. Creates the user, the member row, notification preferences, opening dues, and a privacy-consent log entry — all in one transaction. | users, members, payments, notification_preferences, activity_log |
| `privacy.php` | RA 10173 notice. | — |
| `logout.php` | Destroys the session. | activity_log |

## Admin — 13 screens

| File | Purpose | Tables |
|---|---|---|
| `dashboard.php` | Headline counts, recent payments, next sessions, membership mix, activity feed. | most |
| `members.php` | Full CRUD. Deleting removes the user row, which cascades. | users, members |
| `trainers.php` | Full CRUD as cards, with member and class counts. | users, trainers |
| `classes.php` | Weekly group schedule with live capacity bars. | classes, class_attendance |
| `sessions.php` | One-to-one sessions; notifies the member when booked. | sessions, notifications |
| `reservations.php` | Approve, complete or cancel member bookings. | reservations |
| `attendance.php` | Check members in and out; today, history, and class records. | attendance, class_attendance |
| `payments.php` | Record dues, mark paid (notifies the member), delete. | payments, notifications |
| `templates.php` | Workout template library with popularity and usage counts. | workout_templates, workout_plans |
| `gyms.php` | Branches plus their group training sessions. | gyms, training_sessions |
| `reports.php` | Revenue by month, payment mix, trainer workload, retention, top members. Printable. | payments, members, attendance, sessions |
| `activity-log.php` | Last 200 audited actions. | activity_log |
| `notifications.php` | Shared notification screen. | notifications |

## Trainer — 6 screens

| File | Purpose | Scoped by |
|---|---|---|
| `dashboard.php` | Today's sessions, confirmed bookings, roster. | trainer_id |
| `members.php` | Assigned members with visits, last seen, balance. | trainer_id |
| `workout-plans.php` | Write and edit plans; notifies the member. Refuses to write for someone else's member. | trainer_id |
| `sessions.php` | Schedule sessions, mark completed or cancelled. | trainer_id |
| `classes.php` | Classes they lead; record class attendance. | trainer_id |
| `reservations.php` | Complete or cancel bookings; notifies the member. | trainer_id |

## Member — 7 screens

| File | Purpose | Scoped by |
|---|---|---|
| `dashboard.php` | Membership, visits, balance, trainer, what's coming up. | member_id |
| `classes.php` | Browse the weekly schedule and enroll; own history. | member_id |
| `reservations.php` | Book a trainer — refuses double-booked slots; notifies the trainer. | member_id |
| `attendance.php` | Self check-in / check-out, visit stats and history. | member_id |
| `payments.php` | Dues, history, printable. | member_id |
| `profile.php` | Edit details, change password, notification preferences. | member_id |
| `notifications.php` | Shared notification screen. | user_id |

## Shared

| File | Purpose |
|---|---|
| `config/db.php` | PDO connection with a readable failure page. |
| `config/config.php` | Brand constants, branches, offers, membership pricing. |
| `includes/auth.php` | Session start, login attempt, role guard. |
| `includes/helpers.php` | `e()`, `money()`, `badge()`, CSRF, id generation, notifications, audit logging, query shorthands. |
| `includes/header.php` / `sidebar.php` / `footer.php` | Chrome and role-aware navigation. |
| `includes/notifications-page.php` | One notification screen, included by all three roles. |
| `api/check-in.php` | JSON kiosk endpoint that toggles check-in / check-out. |
