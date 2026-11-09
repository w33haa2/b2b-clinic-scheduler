<?php

namespace App\Actions\Clinics;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\DailySession;
use App\Models\DailySessionPatient;
use App\Support\ActivityLogger;
use App\Support\HolderAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and removes the days (clinic sessions) of a daily session. Days are
 * created ahead of time so they show on the calendar and in payments; the
 * rows this writes in bulk are not logged one by one (the caller logs a
 * summary instead).
 */
class ScheduleDailySessionDays
{
    /**
     * How many days ahead days are created. Later days are created by the
     * daily `clinic:schedule-daily-sessions` run, or on demand (prepaying).
     */
    public const DAYS_AHEAD = 14;

    public function __construct(
        private AddSessionPatient $addSessionPatient,
        private HolderAvailability $availability,
    ) {}

    /**
     * Create the missing days from today up to DAYS_AHEAD, or only the given
     * dates. Dates the session does not run on, or that are past, are skipped.
     *
     * @param  iterable<CarbonInterface>|null  $dates  Dates in the clinic's timezone.
     * @return Collection<int, ClinicSession> The days created.
     */
    public function handle(DailySession $dailySession, ?iterable $dates = null): Collection
    {
        $today = $dailySession->today();

        $dates = collect($dates ?? $dailySession->datesBetween($today, $today->addDays(self::DAYS_AHEAD)))
            ->map(fn (CarbonInterface $date): CarbonImmutable => CarbonImmutable::parse($date->toDateString()))
            ->filter(fn (CarbonImmutable $date): bool => $date->greaterThanOrEqualTo($today) && $dailySession->runsOn($date))
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->sortBy(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();

        if ($dates->isEmpty()) {
            return collect();
        }

        $existing = $dailySession->clinicSessions()
            ->whereDate('occurs_on', '>=', $dates->first()->toDateString())
            ->whereDate('occurs_on', '<=', $dates->last()->toDateString())
            ->get(['occurs_on'])
            ->map(fn (ClinicSession $day): string => $day->occurs_on->toDateString());

        $missing = $dates->reject(fn (CarbonImmutable $date): bool => $existing->contains($date->toDateString()));

        if ($missing->isEmpty()) {
            return collect();
        }

        $plans = $this->plans($dailySession);

        return ActivityLogger::quietly(fn (): Collection => DB::transaction(fn (): Collection => $missing->map(function (CarbonImmutable $date) use ($dailySession, $plans): ClinicSession {
            [$startsAt, $endsAt] = $dailySession->windowOn($date);

            $day = $dailySession->clinic->clinicSessions()->create([
                'clinic_user_id' => $dailySession->clinic_user_id,
                'daily_session_id' => $dailySession->id,
                'occurs_on' => $date->toDateString(),
                'name' => $dailySession->name,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status_id' => AdvanceDailySessionDays::statusFor($dailySession, $date, $endsAt),
            ]);
            $day->setRelation('dailySession', $dailySession);

            foreach ($plans as $plan) {
                $sessionMember = $this->addSessionPatient->handle($day, $plan->patient_id, $plan);
                $this->plotFromPlan($sessionMember, $plan, $day);
            }

            return $day;
        })->values()));
    }

    /**
     * Enrolled patients' plans, earliest usual slot first, so earlier slots
     * win when two plans compete for the same free time.
     *
     * @return Collection<int, DailySessionPatient>
     */
    public function plans(DailySession $dailySession): Collection
    {
        return $dailySession->dailySessionPatients()
            ->orderByRaw('start_time is null')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * Put a patient into their usual slot on a day, if it is inside the day's
     * hours and the staff member is free then; otherwise leave them unplotted
     * ("to plot"). Returns whether they were plotted.
     */
    public function plotFromPlan(ClinicSessionMember $sessionMember, DailySessionPatient $plan, ClinicSession $day): bool
    {
        $slot = $plan->usualSlotOn($day->occurs_on ?? CarbonImmutable::instance($day->starts_at), $day->dailySession->timezone());

        $fits = $slot !== null
            && $slot[0]->greaterThanOrEqualTo($day->starts_at)
            && $slot[1]->lessThanOrEqualTo($day->ends_at)
            && ! $this->availability->isBusy($day->clinic_user_id, $slot[0], $slot[1], $sessionMember->id);

        $sessionMember->update([
            'starts_at' => $fits ? $slot[0] : null,
            'ends_at' => $fits ? $slot[1] : null,
            'customised' => false,
        ]);

        return $fits;
    }

    /**
     * Apply a patient's plan to one day: their usual slot and their service
     * (while the day is unpaid). Clears the "changed by hand" mark.
     */
    public function applyPlan(ClinicSessionMember $sessionMember, DailySessionPatient $plan, ClinicSession $day): void
    {
        if ($sessionMember->paymentStatus() === PaymentStatus::Pending) {
            $sessionMember->sessionLineItems()->orderBy('id')->first()?->update([
                'line_item_id' => $plan->line_item_id,
                'unit_price' => $plan->unit_price,
            ]);
        }

        $this->plotFromPlan($sessionMember, $plan, $day);
    }

    /**
     * Delete days that have not happened yet, with their unpaid bills.
     * Refuses if any of them has a paid or refunded patient.
     *
     * @param  Collection<int, ClinicSession>  $days
     */
    public function remove(Collection $days, string $errorKey = 'daily_session'): void
    {
        $paidDays = $days->filter(fn (ClinicSession $day): bool => $day->payments()
            ->where('payment_status_id', '!=', PaymentStatus::Pending)
            ->exists());

        if ($paidDays->isNotEmpty()) {
            throw ValidationException::withMessages([
                $errorKey => __('Patients already paid for :days. Refund those payments first.', [
                    'days' => $this->describeDays($paidDays),
                ]),
            ]);
        }

        ActivityLogger::quietly(fn () => DB::transaction(function () use ($days): void {
            foreach ($days as $day) {
                foreach ($day->sessionMembers()->get() as $sessionMember) {
                    $sessionMember->sessionLineItems()->delete();
                    $sessionMember->payment()->delete();
                    $sessionMember->delete();
                }

                $day->delete();
            }
        }));
    }

    /**
     * Upcoming days that can still be changed: not started and still scheduled.
     *
     * @return Collection<int, ClinicSession>
     */
    public function upcomingDays(DailySession $dailySession): Collection
    {
        return $dailySession->clinicSessions()
            ->where('starts_at', '>', now())
            ->where('status_id', SessionStatus::Pending)
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * e.g. "Tue 4 Nov, Wed 5 Nov".
     *
     * @param  Collection<int, ClinicSession>  $days
     */
    public function describeDays(Collection $days): string
    {
        return $days->map(fn (ClinicSession $day): string => ($day->occurs_on ?? CarbonImmutable::instance($day->starts_at))->format('D j M'))->implode(', ');
    }
}
