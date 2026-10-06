<?php
/**
 * CSV export for the analytics screens.
 *   /api/analytics-export.php?report=summary|revenue|churn|cohorts|classes|members
 *
 * Streams straight to the browser — no temp file, no memory spike on a
 * large roster.
 */
declare(strict_types=1);

define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';

$u = require_role('admin');
$report = preg_replace('/[^a-z]/', '', (string) ($_GET['report'] ?? 'summary'));

$name = 'befitflex-' . $report . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");            // BOM so Excel reads the peso sign correctly

$put = static function (array $row) use ($out): void {
    fputcsv($out, $row);
};

switch ($report) {

    case 'revenue':
        $put(['Month', 'Payments', 'Collected']);
        foreach (Analytics::revenueSeries(24) as $r) {
            $put([$r['ym'], $r['n'], number_format($r['total'], 2, '.', '')]);
        }
        $fc = Analytics::forecast(Analytics::revenueSeries(12), 3);
        if (!empty($fc['points'])) {
            $put([]);
            $put(['Projection', 'R2 = ' . $fc['r2'], 'Trend per month = ' . $fc['slope']]);
            foreach ($fc['points'] as $r) {
                $put([$r['ym'], 'projected', number_format($r['total'], 2, '.', '')]);
            }
        }
        break;

    case 'churn':
        $put(['Member ID', 'Name', 'Risk score', 'Band', 'Days since visit', 'Reasons', 'Phone', 'Email']);
        foreach (Analytics::churnRisk(200) as $r) {
            $put([
                $r['member_id'], $r['member_name'], $r['score'], $r['band'],
                $r['days_since'] ?? 'never', implode('; ', $r['reasons']),
                member_contact($r), $r['email'],
            ]);
        }
        break;

    case 'cohorts':
        $c = Analytics::cohorts(12);
        $head = ['Cohort', 'Size'];
        for ($k = 0; $k < $c['width']; $k++) { $head[] = 'M' . $k; }
        $put($head);
        foreach ($c['rows'] as $row) {
            $line = [$row['label'], $row['size']];
            for ($k = 0; $k < $c['width']; $k++) {
                $line[] = isset($row['cells'][$k]) ? $row['cells'][$k]['pct'] . '%' : '';
            }
            $put($line);
        }
        break;

    case 'classes':
        $put(['Class', 'Day', 'Start', 'Enrolled', 'Capacity', 'Fill %', 'Present', 'Absent', 'Late']);
        foreach (Analytics::classUtilisation() as $c) {
            $fill = (int) $c['max_capacity'] > 0 ? round((int) $c['enrolled'] / (int) $c['max_capacity'] * 100) : 0;
            $put([$c['class_name'], $c['schedule_day'], $c['start_time'],
                  $c['enrolled'], $c['max_capacity'], $fill, (int) $c['present'], (int) $c['absent'], (int) $c['late']]);
        }
        break;

    case 'members':
        $put(['Member ID', 'Name', 'Email', 'Phone', 'Plan', 'Status', 'Joined',
              'Total visits', 'Visits 30d', 'Last visit', 'Lifetime paid', 'Balance due', 'Tenure (months)']);
        foreach (rows('SELECT * FROM v_member_health ORDER BY member_id') as $m) {
            $put([$m['member_id'], $m['member_name'], $m['email'], member_contact($m),
                  $m['membership_type'], $m['status'], $m['join_date'],
                  $m['total_visits'], $m['visits_30d'], $m['last_visit'] ?? '',
                  number_format((float) $m['lifetime_paid'], 2, '.', ''),
                  number_format((float) $m['balance_due'], 2, '.', ''), $m['tenure_months']]);
        }
        break;

    default:
        $s = Analytics::snapshot();
        $put(['Be Fit Flex Gym — analytics summary', date('d M Y H:i')]);
        $put([]);
        $put(['Metric', 'Value']);
        foreach ([
            'Total members'            => $s['members_total'],
            'Active members'           => $s['members_active'],
            'Expired members'          => $s['members_expired'],
            'New members (30d)'        => $s['members_new_30d'],
            'Retention %'              => $s['retention_pct'],
            'Engagement % (30d)'       => $s['engagement_pct'],
            'Monthly recurring revenue'=> $s['mrr'],
            'Collected this month'     => $s['revenue_mtd'],
            'Collected all time'       => $s['revenue_total'],
            'Outstanding'              => $s['outstanding'],
            'Overdue'                  => $s['overdue'],
            'Collection rate %'        => $s['collection_pct'],
            'ARPU'                     => $s['arpu'],
            'Lifetime value'           => $s['ltv'],
            'Average tenure (months)'  => $s['avg_tenure_months'],
            'Visits (30d)'             => $s['visits_30d'],
            'Visits per active member' => $s['visits_per_head'],
            'Average stay (minutes)'   => round((float) $s['avg_stay_min']),
            'Active classes'           => $s['classes_active'],
        ] as $k => $v) {
            $put([$k, $v]);
        }
        break;
}

fclose($out);
