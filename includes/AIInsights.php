<?php
declare(strict_types=1);

/**
 * Explainable, privacy-preserving decision support built from local metrics.
 * This deliberately does not send member data to an external model.
 */
final class AIInsights
{
    public static function generate(array $snapshot, array $risk, array $classes): array
    {
        $insights = [];
        $highRisk = array_values(array_filter($risk, static fn(array $item): bool => (int) $item['score'] >= 60));
        if ($highRisk) {
            $insights[] = [
                'tone' => 'bad', 'icon' => 'user-clock', 'title' => count($highRisk) . ' member' . (count($highRisk) === 1 ? '' : 's') . ' need a personal check-in',
                'detail' => 'Prioritize the highest-risk member first. Use the reason shown in the churn table instead of sending a generic message.',
                'action' => 'Open churn call list', 'href' => '../api/analytics-export.php?report=churn',
            ];
        }

        if ((float) ($snapshot['outstanding'] ?? 0) > 0) {
            $insights[] = [
                'tone' => 'warn', 'icon' => 'wallet', 'title' => 'Follow up on ' . html_entity_decode(money((float) ($snapshot['outstanding'] ?? 0)), ENT_QUOTES, 'UTF-8') . ' outstanding',
                'detail' => 'Start with overdue balances, then send payment reminders to pending accounts.',
                'action' => 'Review payments', 'href' => 'payments.php?status=Overdue',
            ];
        }

        $underfilled = array_values(array_filter($classes, static function (array $item): bool {
            return (int) $item['max_capacity'] > 0
                && ((int) $item['enrolled'] / (int) $item['max_capacity']) < 0.3;
        }));
        if ($underfilled) {
            $insights[] = [
                'tone' => 'info', 'icon' => 'calendar-xmark', 'title' => count($underfilled) . ' class slot' . (count($underfilled) === 1 ? '' : 's') . ' is underfilled',
                'detail' => 'Consider promoting, consolidating, or rescheduling the lowest-attendance slot.',
                'action' => 'Review classes', 'href' => 'classes.php',
            ];
        }

        if (!$insights) {
            $insights[] = [
                'tone' => 'ok', 'icon' => 'sparkles', 'title' => 'No urgent operating signals',
                'detail' => 'The current membership, payment, and class metrics do not produce a priority action.',
                'action' => 'Refresh analytics', 'href' => 'analytics.php',
            ];
        }
        return $insights;
    }
}