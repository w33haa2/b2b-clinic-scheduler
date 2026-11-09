<?php

namespace App\Actions\Clinics;

use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\DailySession;
use Carbon\CarbonInterface;

/**
 * Days of a daily session run on their own: a day is in progress (ONGOING)
 * from the start of its date in the clinic's timezone and DONE once its hours
 * are over. Cancelling a day is the only manual status change.
 */
class AdvanceDailySessionDays
{
    /**
     * The status a day should have now.
     */
    public static function statusFor(DailySession $dailySession, CarbonInterface $date, CarbonInterface $endsAt): SessionStatus
    {
        if ($endsAt->isPast()) {
            return SessionStatus::Done;
        }

        return $date->toDateString() <= $dailySession->today()->toDateString()
            ? SessionStatus::Ongoing
            : SessionStatus::Pending;
    }

    /**
     * Start the days whose date has come and finish the ones whose hours are
     * over. Returns how many days changed.
     */
    public function handle(): int
    {
        $changed = 0;

        ClinicSession::query()
            ->whereNotNull('daily_session_id')
            ->whereIn('status_id', [SessionStatus::Pending, SessionStatus::Ongoing])
            // Covers the furthest-ahead timezone; each day is checked against its clinic's date below.
            ->whereDate('occurs_on', '<=', now()->addDay()->toDateString())
            ->with('dailySession.clinic')
            ->orderBy('id')
            ->each(function (ClinicSession $day) use (&$changed): void {
                $status = self::statusFor($day->dailySession, $day->occurs_on, $day->ends_at);

                if ($status !== $day->status_id) {
                    $day->update(['status_id' => $status]);
                    $changed++;
                }
            });

        return $changed;
    }
}
