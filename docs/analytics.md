# Analytics and the performance pass

## What was added

| Screen | Who sees it | What it answers |
|---|---|---|
| `admin/analytics.php` | Admin | Is revenue growing, are members staying, when is the floor busy, who is about to leave, which classes and trainers are pulling their weight |
| `trainer/analytics.php` | Trainer | Which of my members are picking up, slowing down, or have gone quiet |
| `member/progress.php` | Member | Am I actually training more than last month, and when is the gym quiet |
| `api/analytics-export.php` | Admin | Any of it as CSV, including a ready-to-dial call list |

## The eleven metric sets

**Recurring revenue (MRR).** What the active book is worth per month, with quarterly
and annual memberships amortised down to a monthly figure. This is a forward-looking
number; lifetime collected revenue is a backward-looking one, and confusing the two is
the most common mistake in gym reporting.

**Revenue trend and projection.** Twelve months of collected payments with a
least-squares line projected three months ahead. The projection ships with its
R² so the reader knows how much to trust it — a low R² means the months scatter
widely and the line is a direction, not a forecast.

**Cohort retention.** Members grouped by the month they joined, tracked across
the months that followed. Each cell is the share of that intake still checking in.
A column that collapses tells you exactly when onboarding loses people, which a
single "retention: 78%" figure never can.

**Churn risk.** An additive score per member: silence since the last visit, a
halving visit trend, overdue balance, expired membership, no assigned trainer,
and a new joiner who has not settled in. Every point added appears in the reasons
column — nothing is hidden behind a model, so the front desk knows what to say
before they pick up the phone.

**Peak-hours heatmap.** Ninety days of check-ins as a day × hour grid. Pale bands
are where a new class fits; dark ones are where a second trainer is needed on shift.

**Engagement versus retention.** Retention is who has not cancelled. Engagement is
who actually trained in the last thirty days. The gap between them is the churn
that has already happened but has not yet been paid for.

**Lifetime value.** Average monthly spend multiplied by average tenure — the
ceiling on what acquiring a member is worth.

**Collection rate.** Collected as a share of everything billed. Distinct from the
outstanding total, because a large gym with a large balance may still be collecting
well.

**Class fill rate, trainer scorecard, member progress.** Utilisation and per-person
performance, each ranked so the outlier is the first thing you see.

## Why the charts are server-rendered SVG

No charting library is loaded. `includes/Chart.php` emits inline SVG, which means
there is no bundle to download, no empty canvas while JavaScript boots, and — the
reason that actually matters here — the charts survive `window.print()`. A printed
report keeps its cohort grid and its revenue line.

Six primitives: `area` (with optional dashed projection), `bars`, `donut`,
`heatmap`, `spark`, `gauge`, plus `ranked` for leaderboards and `legend`
to sit beside a donut.

## The performance pass

**Eight queries became one.** The admin dashboard used to fire eight separate
COUNT and SUM statements. `Analytics::snapshot()` fires one statement with
scalar subqueries and derives MRR, ARPU, LTV, retention, engagement and collection
rate from the single result.

**Cohorts in two queries, not seventy-two.** A six-month × twelve-column grid
built the obvious way is one query per cell. Here it is one query for cohort sizes
and one for activity, pivoted in PHP.

**Prepared statements are reused.** `stmt()` caches by SQL text for the life of
the request, so a page that runs the same shaped query per row parses it once.

**Results are cached, and writes invalidate the cache.** Each metric set is
memoised in-process and written to `cache/` with a short TTL — 45 seconds for the
snapshot, five minutes for cohorts. Any audited write calls `Analytics::flush()`,
so the numbers never lag behind what the operator just did.

**Indexes for the queries that actually run.** `database/optimize.sql` adds
fifteen composite indexes chosen from the analytics workload — `attendance
(member_id, attendance_date)` for per-member windows, `payments (payment_status,
payment_date)` for the revenue series, `reservations (trainer_id,
reservation_date, reservation_time)` for the double-booking check. It is
idempotent: a helper procedure checks for each index first, because MySQL has no
`CREATE INDEX IF NOT EXISTS`.

**Three reporting views.** `v_member_health`, `v_revenue_monthly` and
`v_class_fill` keep the repeated joins in one place.

**Responses are gzipped**, and the stylesheet and script are versioned by file
modification time, so browsers cache them until they genuinely change.

## Installing the performance pass

```
phpMyAdmin → befitflex_gym → Import → database/optimize.sql → Go
```

Safe to run repeatedly. Verify with `SHOW INDEX FROM attendance;`.

## Reading the numbers honestly

With four seeded members the cohort grid and the projection will look thin — that
is correct behaviour, not a bug. Both refuse to overstate: the forecast needs three
months of settled payments before it draws anything, and the risk board stays empty
when nobody is showing warning signs. Seed a few months of attendance and payments
before demonstrating the retention and forecast panels.
