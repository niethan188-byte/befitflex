# Demo walkthrough

Fifteen minutes, hitting every table in the schema. Import the SQL first so you
start from the seeded state.

## 1 · Sign in as the admin (2 min)

<http://localhost/befitflex/> → click the **Admin** demo chip → **Sign in**.

The dashboard reads live from the database: four members, five
payments, two branches. Point out that the password was stored as plain text in
the seed data and has just been silently rewritten as a bcrypt hash — check the
`users` table in phpMyAdmin to show the `\$2y\$` prefix.

## 2 · Add a member (2 min)

**Members → Add member.** The ID is generated in the schema's own format —
`MEM0005` — by reading the current maximum. Save, then show the new row and the
matching `users` row.

Edit the member. Delete a test member and show that the
cascade removed their payments and attendance too.

## 3 · Money (2 min)

**Payments.** Three totals across the top. Record a payment for the new member —
selecting them auto-fills the amount from their membership type. Then find Ben's
pending August dues and click the check mark: the row flips to Paid, and a
notification lands in Ben's account.

## 4 · Operations (3 min)

**Classes** — capacity bars fill as members enroll.
**Attendance** — check a member in, then out; the duration is computed.
**Reservations** — mark one Completed.
**Reports** — revenue by month, retention and top members. Hit **Print report**
to show the stylesheet stripping the glass for paper.

## 5 · Assign a workout plan (3 min)

Open **Workout Plans → Assign plan**, select Anna, and save a schedule and plan
details. Anna receives a notification and can see the new plan from her member
account. Admins manage all workout plans directly; the application has admin
and member accounts.

## 6 · Switch to the member (3 min)

Sign in as **anna@example.com**. The dashboard shows her balance and the
notification about the workout plan.

- **Workout Plan** — review the schedule and exercise details assigned by admin.
- **My Progress** — review attendance history, visit trends and consistency.
- **Reservations** — try the same slot twice: the second attempt
  is refused, because the check is a query, not a disabled button.
- **My Attendance** — self check-in, then check out.
- **Classes** — join Morning Bootcamp and watch the capacity bar move.

## What to say at the end

The two roles share one codebase, one stylesheet and one set of helpers. Role
separation lives in `require_role()` at the top of every page and in the
`WHERE` clause of every query. Every form carries a CSRF token, every query is
a prepared statement, every echoed value is escaped, and every write is written
to `activity_log`.
