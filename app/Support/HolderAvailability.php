<?php

namespace App\Support;

use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * When a staff member is busy or free. Busy time is their regular sessions
 * (not cancelled) plus every patient plotted into one of their daily
 * sessions' days. A daily session's window itself does not count as busy.
 */
class HolderAvailability
{
    /**
     * Busy intervals of a member between two instants, merged and sorted.
     *
     * @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function busy(int $clinicUserId, CarbonInterface $from, CarbonInterface $to, ?int $exceptSessionMemberId = null, ?int $exceptDayId = null): Collection
    {
        $sessions = ClinicSession::query()
            ->where('clinic_user_id', $clinicUserId)
            ->overlapping($from, $to)
            ->get(['starts_at', 'ends_at']);

        $slots = ClinicSessionMember::query()
            ->plottedOverlapping($clinicUserId, $from, $to)
            ->when($exceptSessionMemberId, fn ($query, int $id) => $query->whereKeyNot($id))
            ->when($exceptDayId, fn ($query, int $id) => $query->where('clinic_session_id', '!=', $id))
            ->get(['starts_at', 'ends_at']);

        $intervals = $sessions->concat($slots)
            ->map(fn (ClinicSession|ClinicSessionMember $model): array => [
                'starts_at' => CarbonImmutable::instance($model->starts_at),
                'ends_at' => CarbonImmutable::instance($model->ends_at),
            ])
            ->sortBy(fn (array $interval): int => $interval['starts_at']->getTimestamp())
            ->values();

        return $intervals->reduce(function (Collection $merged, array $interval): Collection {
            $last = $merged->pop();

            if ($last === null) {
                return $merged->push($interval);
            }

            if ($interval['starts_at']->lessThanOrEqualTo($last['ends_at'])) {
                return $merged->push([
                    'starts_at' => $last['starts_at'],
                    'ends_at' => $interval['ends_at']->max($last['ends_at']),
                ]);
            }

            return $merged->push($last)->push($interval);
        }, collect());
    }

    /**
     * The free intervals inside a daily session's day, for plotting patients.
     *
     * @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function vacancies(ClinicSession $day): Collection
    {
        $windowStart = CarbonImmutable::instance($day->starts_at);
        $windowEnd = CarbonImmutable::instance($day->ends_at);
        $cursor = $windowStart;
        $free = collect();

        foreach ($this->busy($day->clinic_user_id, $windowStart, $windowEnd) as $interval) {
            if ($interval['starts_at']->greaterThan($cursor)) {
                $free->push(['starts_at' => $cursor, 'ends_at' => $interval['starts_at']->min($windowEnd)]);
            }

            $cursor = $cursor->max($interval['ends_at']);
        }

        if ($cursor->lessThan($windowEnd)) {
            $free->push(['starts_at' => $cursor, 'ends_at' => $windowEnd]);
        }

        return $free;
    }

    /**
     * Time inside a daily session's day taken by other things (the holder's
     * sessions and patients plotted into their other daily sessions), not
     * by this day's own patients.
     *
     * @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function blockedElsewhere(ClinicSession $day): Collection
    {
        return $this->busy($day->clinic_user_id, $day->starts_at, $day->ends_at, exceptDayId: $day->id);
    }

    /**
     * Determine whether the member is busy at any point in the range.
     */
    public function isBusy(int $clinicUserId, CarbonInterface $startsAt, CarbonInterface $endsAt, ?int $exceptSessionMemberId = null): bool
    {
        return $this->busy($clinicUserId, $startsAt, $endsAt, $exceptSessionMemberId)->isNotEmpty();
    }
}
