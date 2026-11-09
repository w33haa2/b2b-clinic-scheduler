<?php

namespace App\Actions\Clinics;

use App\Models\ClinicSessionMember;
use App\Models\DailySessionPatient;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class UpdateDailySessionPatientPlan
{
    public function __construct(private ScheduleDailySessionDays $scheduleDays) {}

    /**
     * Change a patient's regular plan (service, price, usual slot). Every
     * upcoming day follows it (the service only while that day is unpaid),
     * except days changed by hand for this patient, which keep their changes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(DailySessionPatient $enrollment, array $attributes): DailySessionPatient
    {
        return DB::transaction(function () use ($enrollment, $attributes): DailySessionPatient {
            $enrollment->update($attributes);
            $dailySession = $enrollment->dailySession;

            $days = $this->scheduleDays->upcomingDays($dailySession);

            ActivityLogger::quietly(fn () => ClinicSessionMember::query()
                ->whereIn('clinic_session_id', $days->pluck('id'))
                ->where('patient_id', $enrollment->patient_id)
                ->where('customised', false)
                ->with('payment')
                ->orderBy('id')
                ->get()
                ->each(function (ClinicSessionMember $sessionMember) use ($days, $dailySession, $enrollment): void {
                    $day = $days->firstWhere('id', $sessionMember->clinic_session_id);
                    $day->setRelation('dailySession', $dailySession);

                    $this->scheduleDays->applyPlan($sessionMember, $enrollment, $day);
                }));

            return $enrollment;
        });
    }
}
