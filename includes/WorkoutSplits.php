<?php
declare(strict_types=1);

final class WorkoutSplits
{
    private const PROGRAMS = [
        'PPL' => [
            'name' => 'Push / Pull / Legs (PPL)',
            'description' => 'Rotate push, pull, and legs workouts in the order that fits your week.',
            'days' => [
                'Push' => ['Bench Press', 'Incline Dumbbell Press', 'Overhead Press', 'Lateral Raise', 'Triceps Pushdown'],
                'Pull' => ['Deadlift', 'Barbell Row', 'Lat Pulldown', 'Face Pull', 'Bicep Curl'],
                'Legs' => ['Squat', 'Romanian Deadlift', 'Leg Press', 'Leg Curl', 'Calf Raise'],
            ],
        ],
        'ARNOLD' => [
            'name' => 'Arnold Split',
            'description' => 'Pair chest with back, shoulders with arms, and keep a dedicated leg day.',
            'days' => [
                'Chest & Back' => ['Bench Press', 'Incline Dumbbell Press', 'Cable Fly', 'Pull-up', 'Barbell Row'],
                'Shoulders & Arms' => ['Overhead Press', 'Lateral Raise', 'Rear Delt Fly', 'Barbell Curl', 'Triceps Pushdown'],
                'Legs' => ['Squat', 'Romanian Deadlift', 'Leg Press', 'Leg Curl', 'Calf Raise'],
            ],
        ],
        'UPPER_LOWER' => [
            'name' => 'Upper / Lower',
            'description' => 'Alternate upper- and lower-body sessions with two exercise variations.',
            'days' => [
                'Upper A' => ['Bench Press', 'Barbell Row', 'Overhead Press', 'Lat Pulldown', 'Bicep Curl', 'Triceps Pushdown'],
                'Lower A' => ['Squat', 'Romanian Deadlift', 'Leg Press', 'Leg Curl', 'Calf Raise'],
                'Upper B' => ['Incline Dumbbell Press', 'Seated Cable Row', 'Dumbbell Shoulder Press', 'Face Pull', 'Hammer Curl', 'Triceps Extension'],
                'Lower B' => ['Deadlift', 'Front Squat', 'Lunge', 'Leg Curl', 'Calf Raise'],
            ],
        ],
        'FULL_BODY' => [
            'name' => 'Full Body',
            'description' => 'Choose a full-body session and rotate the A/B/C exercise menus.',
            'days' => [
                'Full Body A' => ['Squat', 'Bench Press', 'Barbell Row', 'Romanian Deadlift', 'Push-up'],
                'Full Body B' => ['Deadlift', 'Overhead Press', 'Lat Pulldown', 'Lunge', 'Bicep Curl', 'Pull-up'],
                'Full Body C' => ['Leg Press', 'Incline Dumbbell Press', 'Seated Cable Row', 'Leg Curl', 'Calf Raise'],
            ],
        ],
        'BRO_SPLIT' => [
            'name' => 'Bro Split',
            'description' => 'Use a separate workout day for each major muscle group.',
            'days' => [
                'Chest' => ['Bench Press', 'Incline Dumbbell Press', 'Dumbbell Fly', 'Push-up'],
                'Back' => ['Deadlift', 'Barbell Row', 'Lat Pulldown', 'Seated Cable Row'],
                'Shoulders' => ['Overhead Press', 'Dumbbell Shoulder Press', 'Lateral Raise', 'Rear Delt Fly'],
                'Arms' => ['Barbell Curl', 'Hammer Curl', 'Triceps Pushdown', 'Triceps Extension'],
                'Legs' => ['Squat', 'Romanian Deadlift', 'Leg Press', 'Leg Curl', 'Calf Raise'],
            ],
        ],
    ];

    public static function all(): array
    {
        return self::PROGRAMS;
    }

    public static function get(string $code): ?array
    {
        return self::PROGRAMS[$code] ?? null;
    }

    public static function exercises(string $code, string $day): array
    {
        return self::PROGRAMS[$code]['days'][$day] ?? [];
    }

    public static function containsExercise(string $code, string $day, string $exercise): bool
    {
        return in_array($exercise, self::exercises($code, $day), true);
    }
}
