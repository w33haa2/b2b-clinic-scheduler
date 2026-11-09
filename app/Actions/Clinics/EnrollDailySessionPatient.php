<?php

namespace App\Actions\Clinics;

use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\DailySession;
use App\Models\DailySessionPatient;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class EnrollDailySessionPatient
{
    public function __construct(
        private AddSessionPatient $addSessionPatient,
        private ScheduleDailySessionDays $scheduleDays,
    ) {}

    /**
     * Enrol a patient with their plan: they join every scheduled day that has
     * not ended (and every later day), billed their service and put into
     * their usual slot where the staff member is free.
     *
     * @param  array<string, mixed>  $plan  patient_id, line_item_id?, unit_price?, start_time?, duration_minutes?
     */
    public function handle(DailySession $dailySession, array $plan): DailySessionPatient
    {
        return DB::transaction(function () use ($dailySession, $plan): DailySessionPatient {
            $enrollment = $dailySession->dailySessionPatients()->create($dailySession->planAttributes($plan));

            $days = $dailySession->clinicSessions()
                ->where('ends_at', '>', now())
                ->whereIn('status_id', [SessionStatus::Pending, SessionStatus::Ongoing])
                ->whereDoesntHave('sessionMembers', fn ($query) => $query->where('patient_id', $enrollment->patient_id))
                ->orderBy('starts_at')
                ->get();

            ActivityLogger::quietly(fn () => $days->each(function (ClinicSession $day) use ($dailySession, $enrollment): void {
                $day->setRelation('dailySession', $dailySession);
                $sessionMember = $this->addSessionPatient->handle($day, $enrollment->patient_id, $enrollment);

                if ($day->starts_at->isFuture()) {
                    $this->scheduleDays->plotFromPlan($sessionMember, $enrollment, $day);
                }
            }));

            $this->scheduleDays->handle($dailySession);

            return $enrollment;
        });
    }
}
