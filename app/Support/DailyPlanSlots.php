<?php

namespace App\Support;

/**
 * Checks for patients' usual slots in a daily session. Times are "H:i" (or
 * "H:i:s") in the clinic's timezone; a slot is a start time and a length.
 */
class DailyPlanSlots
{
    /**
     * Determine whether a slot lies inside the daily window.
     */
    public static function fitsWindow(string $windowStart, string $windowEnd, string $start, int $minutes): bool
    {
        $from = self::minutes($start);

        return $from >= self::minutes($windowStart) && $from + $minutes <= self::minutes($windowEnd);
    }

    /**
     * The keys of slots that overlap an earlier one in the list.
     *
     * @param  array<int|string, array{start: string|null, minutes: int|null}>  $slots
     * @return list<int|string>
     */
    public static function overlapping(array $slots): array
    {
        $ranges = collect($slots)
            ->filter(fn (array $slot): bool => $slot['start'] !== null && $slot['minutes'])
            ->map(fn (array $slot): array => [self::minutes((string) $slot['start']), self::minutes((string) $slot['start']) + (int) $slot['minutes']])
            ->sortBy(fn (array $range): int => $range[0]);

        $clashes = [];
        $latestEnd = null;

        foreach ($ranges as $key => [$from, $to]) {
            if ($latestEnd !== null && $from < $latestEnd) {
                $clashes[] = $key;
            }

            $latestEnd = max($latestEnd ?? $to, $to);
        }

        return $clashes;
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
