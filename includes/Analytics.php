<?php
declare(strict_types=1);

/**
 * The analytics engine.
 *
 * Two rules shape every method here:
 *
 *   1. One round trip per question. Where the old dashboard fired eight
 *      COUNT queries, snapshot() fires one with eight scalar subqueries.
 *      Where a naive cohort grid would run 6x12 queries, cohorts() runs two.
 *
 *   2. Aggregate in MySQL, shape in PHP. The database is far better at
 *      GROUP BY over an indexed column than a PHP loop over 10,000 rows;
 *      PHP is better at scoring, forecasting and formatting.
 *
 * Results are memoised per request and optionally cached to disk for a few
 * seconds, so a page that asks for the same series twice pays once.
 */
final class Analytics
{
    private static array $memo = [];

    /* ---------- caching ---------- */

    public static function remember(string $key, callable $fn, int $ttl = 60): mixed
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $file = dirname(__DIR__) . '/cache/' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.json';

        if ($ttl > 0 && is_file($file) && (time() - filemtime($file)) < $ttl) {
            $hit = json_decode((string) file_get_contents($file), true);
            if (is_array($hit)) {
                return self::$memo[$key] = $hit;
            }
        }

        $value = $fn();
        self::$memo[$key] = $value;

        if ($ttl > 0 && is_array($value)) {
            @mkdir(dirname($file), 0775, true);
            @file_put_contents($file, json_encode($value), LOCK_EX);
        }

        return $value;
    }

    public static function flush(): void
    {
        self::$memo = [];
        foreach (glob(dirname(__DIR__) . '/cache/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    /* ---------- headline snapshot: ONE query ---------- */

    /**
     * Every headline number the dashboards need, in a single round trip.
     * Replaces eight separate COUNT/SUM queries.
     */
    public static function snapshot(): array
    {
        return self::remember('snapshot', static function (): array {
            $sql = "SELECT
                (SELECT COUNT(*) FROM members)                                            AS members_total,
                (SELECT COUNT(*) FROM members WHERE status='Active')                      AS members_active,
                (SELECT COUNT(*) FROM members WHERE status='Expired')                     AS members_expired,
                (SELECT COUNT(*) FROM members WHERE join_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS members_new_30d,
                (SELECT COUNT(*) FROM classes WHERE class_status='Active')                AS classes_active,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Paid') AS revenue_total,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Paid'
                   AND payment_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01'))                 AS revenue_mtd,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Paid'
                   AND payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m-01')
                   AND payment_date <  DATE_FORMAT(CURDATE(),'%Y-%m-01'))                 AS revenue_prev_month,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status IN ('Pending','Overdue')) AS outstanding,
                (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status='Overdue') AS overdue,
                (SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE())       AS checkins_today,
                (SELECT COUNT(*) FROM attendance WHERE check_out_time IS NULL
                   AND attendance_date = CURDATE())                                       AS inside_now,
                (SELECT COUNT(*) FROM attendance WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))  AS visits_7d,
                (SELECT COUNT(*) FROM attendance WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS visits_30d,
                (SELECT COUNT(*) FROM attendance WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                   AND attendance_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY))            AS visits_prev_30d,
                (SELECT COUNT(DISTINCT member_id) FROM attendance
                   WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))         AS actives_30d,
                (SELECT COUNT(*) FROM reservations WHERE status='Confirmed'
                   AND reservation_date >= CURDATE())                                     AS bookings_upcoming,
                (SELECT COUNT(*) FROM sessions WHERE session_status='Completed')          AS sessions_done,
                (SELECT COUNT(*) FROM sessions WHERE session_status='Cancelled')          AS sessions_cancelled,
                (SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)),0)
                   FROM attendance WHERE check_out_time IS NOT NULL)                      AS avg_stay_min";

            $s = db()->query($sql)->fetch() ?: [];

            /* ---- derived measures, computed once ---- */
            $total   = max(1, (int) ($s['members_total'] ?? 0));
            $active  = (int) ($s['members_active'] ?? 0);
            $rev     = (float) ($s['revenue_total'] ?? 0);
            $mtd     = (float) ($s['revenue_mtd'] ?? 0);
            $prev    = (float) ($s['revenue_prev_month'] ?? 0);

            $s['retention_pct']   = round($active / $total * 100, 1);
            $s['churn_pct']       = round(100 - ($active / $total * 100), 1);
            $s['arpu']            = round($rev / $total, 2);
            $s['engagement_pct']  = round(((int) ($s['actives_30d'] ?? 0)) / $total * 100, 1);
            $s['visits_per_head'] = round(((int) ($s['visits_30d'] ?? 0)) / max(1, $active), 1);
            $s['revenue_mom_pct'] = $prev > 0 ? round(($mtd - $prev) / $prev * 100, 1) : null;
            $s['visits_wow_pct']  = ((int) ($s['visits_prev_30d'] ?? 0)) > 0
                ? round((((int) $s['visits_30d']) - ((int) $s['visits_prev_30d'])) / ((int) $s['visits_prev_30d']) * 100, 1)
                : null;
            $s['collection_pct']  = ($rev + (float) $s['outstanding']) > 0
                ? round($rev / ($rev + (float) $s['outstanding']) * 100, 1) : 100.0;

            /* Monthly recurring revenue: what the active book is worth per month. */
            $mrr = (float) db()->query(
                "SELECT COALESCE(SUM(CASE membership_type
                        WHEN 'Monthly'   THEN 1500
                        WHEN 'Quarterly' THEN 4200/3
                        WHEN 'Annual'    THEN 12000/12 END),0)
                   FROM members WHERE status='Active'"
            )->fetchColumn();
            $s['mrr'] = round($mrr, 2);

            /* Lifetime value: average monthly spend x average months retained. */
            $tenure = (float) db()->query(
                'SELECT COALESCE(AVG(TIMESTAMPDIFF(MONTH, join_date, COALESCE(out_date, CURDATE()))),0) FROM members'
            )->fetchColumn();
            $s['avg_tenure_months'] = round($tenure, 1);
            $s['ltv'] = round(($mrr / max(1, $active)) * max(1, $tenure), 2);

            return $s;
        }, 45);
    }

    /* ---------- revenue ---------- */

    /** Paid revenue per calendar month, oldest first, with empty months filled in. */
    public static function revenueSeries(int $months = 12): array
    {
        return self::remember('revenue_' . $months, static function () use ($months): array {
            $raw = rows(
                "SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) total, COUNT(*) n
                   FROM payments
                  WHERE payment_status='Paid' AND payment_date IS NOT NULL
                    AND payment_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL ? MONTH)
                  GROUP BY ym ORDER BY ym",
                [$months - 1]
            );
            $byMonth = [];
            foreach ($raw as $r) {
                $byMonth[$r['ym']] = ['total' => (float) $r['total'], 'n' => (int) $r['n']];
            }

            $out = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $ym = date('Y-m', strtotime("-{$i} months"));
                $out[] = [
                    'ym'    => $ym,
                    'label' => date('M', strtotime($ym . '-01')),
                    'total' => $byMonth[$ym]['total'] ?? 0.0,
                    'n'     => $byMonth[$ym]['n'] ?? 0,
                ];
            }
            return $out;
        }, 120);
    }

    /**
     * Ordinary least squares on the series index, projected forward.
     * Deliberately simple and deliberately labelled as a projection: with a
     * handful of months of data, anything fancier would be false precision.
     */
    public static function forecast(array $series, int $ahead = 3): array
    {
        $y = array_map(static fn($p) => (float) $p['total'], $series);
        $n = count($y);
        if ($n < 3) {
            return [];
        }

        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($y as $i => $v) {
            $sumX  += $i;
            $sumY  += $v;
            $sumXY += $i * $v;
            $sumXX += $i * $i;
        }
        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if (abs($denom) < 1e-9) {
            return [];
        }

        $slope     = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        /* R^2 tells the reader how much to trust the line. */
        $meanY = $sumY / $n;
        $ssTot = $ssRes = 0.0;
        foreach ($y as $i => $v) {
            $fit    = $intercept + $slope * $i;
            $ssTot += ($v - $meanY) ** 2;
            $ssRes += ($v - $fit) ** 2;
        }
        $r2 = $ssTot > 0 ? max(0.0, 1 - ($ssRes / $ssTot)) : 0.0;

        $out = [];
        for ($k = 1; $k <= $ahead; $k++) {
            $idx = $n - 1 + $k;
            $out[] = [
                'ym'    => date('Y-m', strtotime('+' . $k . ' months')),
                'label' => date('M', strtotime('+' . $k . ' months')),
                'total' => max(0.0, round($intercept + $slope * $idx, 2)),
                'n'     => 0,
            ];
        }

        return ['points' => $out, 'slope' => round($slope, 2), 'r2' => round($r2, 3)];
    }

    /* ---------- cohort retention ---------- */

    /**
     * Join-month cohorts by months-since-join, as a percentage of the cohort
     * that still showed up. Two queries total: cohort sizes, then activity.
     */
    public static function cohorts(int $months = 6): array
    {
        return self::remember('cohorts_' . $months, static function () use ($months): array {
            $sizes = rows(
                "SELECT DATE_FORMAT(join_date,'%Y-%m') cohort, COUNT(*) n
                   FROM members
                  WHERE join_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL ? MONTH)
                  GROUP BY cohort ORDER BY cohort",
                [$months - 1]
            );
            if (!$sizes) {
                return ['rows' => [], 'width' => 0];
            }

            $activity = rows(
                "SELECT DATE_FORMAT(m.join_date,'%Y-%m') cohort,
                        TIMESTAMPDIFF(MONTH, DATE_FORMAT(m.join_date,'%Y-%m-01'),
                                             DATE_FORMAT(a.attendance_date,'%Y-%m-01')) offset,
                        COUNT(DISTINCT m.member_id) n
                   FROM members m
                   JOIN attendance a ON a.member_id = m.member_id
                  WHERE m.join_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL ? MONTH)
                  GROUP BY cohort, offset
                 HAVING offset >= 0",
                [$months - 1]
            );

            $grid = [];
            foreach ($activity as $a) {
                $grid[$a['cohort']][(int) $a['offset']] = (int) $a['n'];
            }

            $width = 0;
            $out = [];
            foreach ($sizes as $s) {
                $cohort = $s['cohort'];
                $size   = max(1, (int) $s['n']);
                $age    = (int) ((strtotime(date('Y-m-01')) - strtotime($cohort . '-01')) / 2629800);
                $age    = max(0, min($months - 1, $age));
                $width  = max($width, $age + 1);

                $cells = [];
                for ($k = 0; $k <= $age; $k++) {
                    $kept    = $grid[$cohort][$k] ?? 0;
                    $cells[] = ['n' => $kept, 'pct' => round($kept / $size * 100)];
                }
                $out[] = [
                    'cohort' => $cohort,
                    'label'  => date('M Y', strtotime($cohort . '-01')),
                    'size'   => (int) $s['n'],
                    'cells'  => $cells,
                ];
            }

            return ['rows' => $out, 'width' => $width];
        }, 300);
    }

    /* ---------- churn risk ---------- */

    /**
     * A transparent additive score, not a black box. Every point added is
     * explained in the reasons array, so the front desk knows what to say
     * when they pick up the phone.
     */
    public static function churnRisk(int $limit = 20): array
    {
        return self::remember('churn_' . $limit, static function () use ($limit): array {
            $data = rows(
                "SELECT m.member_id, m.member_name, m.email, m.contact_number, m.status,
                        m.membership_type, m.join_date,
                        (SELECT MAX(a.attendance_date) FROM attendance a WHERE a.member_id = m.member_id) last_visit,
                        (SELECT COUNT(*) FROM attendance a WHERE a.member_id = m.member_id
                          AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) v30,
                        (SELECT COUNT(*) FROM attendance a WHERE a.member_id = m.member_id
                          AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                          AND a.attendance_date <  DATE_SUB(CURDATE(), INTERVAL 30 DAY)) v60,
                        (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.member_id = m.member_id
                          AND p.payment_status = 'Overdue') overdue,
                        (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.member_id = m.member_id
                          AND p.payment_status <> 'Paid') owed
                   FROM members m
                  WHERE m.status <> 'Inactive'"
            );

            $scored = [];
            foreach ($data as $m) {
                $score   = 0;
                $reasons = [];

                $days = $m['last_visit'] ? (int) floor((time() - strtotime($m['last_visit'])) / 86400) : 999;

                if ($days >= 999)      { $score += 40; $reasons[] = 'never checked in'; }
                elseif ($days > 30)    { $score += 35; $reasons[] = $days . ' days since last visit'; }
                elseif ($days > 14)    { $score += 20; $reasons[] = $days . ' days since last visit'; }
                elseif ($days > 7)     { $score += 8;  $reasons[] = 'quiet for a week'; }

                $v30 = (int) $m['v30'];
                $v60 = (int) $m['v60'];
                if ($v60 > 0 && $v30 === 0)              { $score += 25; $reasons[] = 'stopped completely this month'; }
                elseif ($v60 > 0 && $v30 < $v60 / 2)     { $score += 15; $reasons[] = 'visits halved month on month'; }

                if ((float) $m['overdue'] > 0) { $score += 20; $reasons[] = 'overdue balance'; }
                elseif ((float) $m['owed'] > 0) { $score += 8; $reasons[] = 'unsettled dues'; }

                if ($m['status'] === 'Expired')  { $score += 25; $reasons[] = 'membership expired'; }

                $tenureDays = (int) floor((time() - strtotime((string) $m['join_date'])) / 86400);
                if ($tenureDays < 60 && $v30 <= 1) { $score += 12; $reasons[] = 'new and not settling in'; }

                $score = min(100, $score);
                if ($score < 15) {
                    continue;
                }

                $scored[] = $m + [
                    'score'      => $score,
                    'band'       => $score >= 65 ? 'high' : ($score >= 35 ? 'medium' : 'low'),
                    'days_since' => $days >= 999 ? null : $days,
                    'reasons'    => $reasons,
                ];
            }

            usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($scored, 0, $limit);
        }, 90);
    }

    /* ---------- traffic ---------- */

    /** Day-of-week x hour check-in grid. One query, 7x24 cells. */
    public static function peakGrid(): array
    {
        return self::remember('peak_grid', static function (): array {
            $raw = rows(
                'SELECT WEEKDAY(check_in_time) d, HOUR(check_in_time) h, COUNT(*) n
                   FROM attendance
                  WHERE check_in_time >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                  GROUP BY d, h'
            );

            $grid = array_fill(0, 7, array_fill(5, 19, 0));
            $max = 0;
            foreach ($raw as $r) {
                $d = (int) $r['d'];
                $h = (int) $r['h'];
                if ($h < 5 || $h > 23) {
                    continue;
                }
                $grid[$d][$h] = (int) $r['n'];
                $max = max($max, (int) $r['n']);
            }

            /* Busiest single slot, for the headline line under the chart. */
            $peak = null;
            foreach ($grid as $d => $hours) {
                foreach ($hours as $h => $n) {
                    if ($n === $max && $max > 0) {
                        $peak = ['day' => $d, 'hour' => $h, 'n' => $n];
                        break 2;
                    }
                }
            }

            return ['grid' => $grid, 'max' => $max, 'peak' => $peak];
        }, 300);
    }

    /** Visits per day for the last N days, gaps filled with zero. */
    public static function visitSeries(int $days = 30): array
    {
        return self::remember('visits_' . $days, static function () use ($days): array {
            $raw = rows(
                'SELECT attendance_date d, COUNT(*) n FROM attendance
                  WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  GROUP BY d',
                [$days - 1]
            );
            $by = [];
            foreach ($raw as $r) {
                $by[$r['d']] = (int) $r['n'];
            }

            $out = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $out[] = ['d' => $d, 'label' => date('j', strtotime($d)), 'total' => $by[$d] ?? 0];
            }
            return $out;
        }, 120);
    }

    /* ---------- utilisation ---------- */

    public static function classUtilisation(): array
    {
        return self::remember('class_util', static fn(): array => rows(
            "SELECT c.class_id, c.class_name, c.schedule_day, c.start_time, c.max_capacity,
                    COUNT(DISTINCT ca.member_id) enrolled,
                    SUM(ca.attendance_status = 'Present') present,
                    SUM(ca.attendance_status = 'Absent')  absent,
                    SUM(ca.attendance_status = 'Late')    late,
                    COUNT(ca.attendance_id) records
               FROM classes c
               LEFT JOIN class_attendance ca ON ca.class_id = c.class_id
              WHERE c.class_status = 'Active'
              GROUP BY c.class_id
              ORDER BY (COUNT(DISTINCT ca.member_id) / GREATEST(c.max_capacity,1)) DESC"
        ), 180);
    }

    /* ---------- membership mix ---------- */

    public static function membershipMix(): array
    {
        return self::remember('mix', static fn(): array => rows(
            "SELECT membership_type label, COUNT(*) n,
                    SUM(status = 'Active') active
               FROM members GROUP BY membership_type ORDER BY n DESC"
        ), 180);
    }

    public static function paymentMix(): array
    {
        return self::remember('paymix', static fn(): array => rows(
            "SELECT payment_method label, COUNT(*) n, COALESCE(SUM(amount),0) total
               FROM payments WHERE payment_status = 'Paid'
              GROUP BY payment_method ORDER BY total DESC"
        ), 180);
    }

    /* ---------- per-member progress ---------- */

    public static function memberProgress(string $memberId): array
    {
        return self::remember('progress_' . $memberId, static function () use ($memberId): array {
            $head = row(
                "SELECT COUNT(*) visits,
                        COALESCE(AVG(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)),0) avg_min,
                        COALESCE(SUM(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)),0) total_min,
                        MIN(attendance_date) first_visit, MAX(attendance_date) last_visit,
                        SUM(attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) v30,
                        SUM(attendance_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                            AND attendance_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)) v60,
                        SUM(attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) v7
                   FROM attendance WHERE member_id = ?",
                [$memberId]
            ) ?? [];

            $monthlyRaw = rows(
                "SELECT DATE_FORMAT(attendance_date,'%Y-%m') ym, COUNT(*) n
                   FROM attendance
                  WHERE member_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                  GROUP BY ym",
                [$memberId]
            );
            $by = [];
            foreach ($monthlyRaw as $r) {
                $by[$r['ym']] = (int) $r['n'];
            }
            $monthly = [];
            for ($i = 11; $i >= 0; $i--) {
                $ym = date('Y-m', strtotime("-{$i} months"));
                $monthly[] = ['ym' => $ym, 'label' => date('M', strtotime($ym . '-01')), 'total' => $by[$ym] ?? 0];
            }

            /* Weekly streak: consecutive ISO weeks with at least one visit. */
            $weeks = rows(
                'SELECT DISTINCT YEARWEEK(attendance_date, 1) yw FROM attendance
                  WHERE member_id = ? ORDER BY yw DESC LIMIT 60',
                [$memberId]
            );
            $streak = 0;
            $cursor = (int) date('oW');
            foreach ($weeks as $w) {
                if ((int) $w['yw'] === $cursor) {
                    $streak++;
                    $cursor = (int) date('oW', strtotime('-' . $streak . ' weeks'));
                } else {
                    break;
                }
            }

            /* Consistency: share of the last 12 weeks with at least one visit. */
            $activeWeeks = (int) scalar(
                'SELECT COUNT(DISTINCT YEARWEEK(attendance_date,1)) FROM attendance
                  WHERE member_id = ? AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)',
                [$memberId]
            );

            $dayMix = rows(
                'SELECT WEEKDAY(check_in_time) d, COUNT(*) n FROM attendance
                  WHERE member_id = ? GROUP BY d ORDER BY n DESC',
                [$memberId]
            );

            $best = (int) scalar(
                "SELECT COALESCE(MAX(n),0) FROM (
                    SELECT COUNT(*) n FROM attendance WHERE member_id = ?
                     GROUP BY DATE_FORMAT(attendance_date,'%Y-%m')) x",
                [$memberId]
            );

            $v30 = (int) ($head['v30'] ?? 0);
            $v60 = (int) ($head['v60'] ?? 0);

            return [
                'visits'        => (int) ($head['visits'] ?? 0),
                'avg_min'       => round((float) ($head['avg_min'] ?? 0)),
                'total_hours'   => round(((float) ($head['total_min'] ?? 0)) / 60, 1),
                'first_visit'   => $head['first_visit'] ?? null,
                'last_visit'    => $head['last_visit'] ?? null,
                'v7'            => (int) ($head['v7'] ?? 0),
                'v30'           => $v30,
                'v60'           => $v60,
                'trend_pct'     => $v60 > 0 ? round(($v30 - $v60) / $v60 * 100) : null,
                'monthly'       => $monthly,
                'streak_weeks'  => $streak,
                'consistency'   => round($activeWeeks / 12 * 100),
                'best_month'    => $best,
                'favourite_day' => $dayMix ? (int) $dayMix[0]['d'] : null,
            ];
        }, 60);
    }

}
