<?php

namespace App\Actions\Clinics;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\ClinicSessionMember;
use App\Models\DailySessionPatient;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveDailySessionPatient
{
    public function __construct(
        private RemoveSessionPatient $removeSessionPatient,
        private ScheduleDailySessionDays $scheduleDays,
    ) {}

    /**
     * Take a patient out of a daily session: they leave every day that has
     * not ended, with those days' unpaid bills. Days already over stay as
     * they are (including unpaid, past-due ones). Refused while the patient
     * has prepaid an upcoming day: refund it first.
     */
    public function handle(DailySessionPatient $enrollment): void
    {
        DB::transaction(function () use ($enrollment): void {
            $upcoming = ClinicSessionMember::query()
                ->where('patient_id', $enrollment->patient_id)
                ->whereHas('clinicSession', fn ($query) => $query
                    ->where('daily_session_id', $enrollment->daily_session_id)
                    ->where('ends_at', '>', now())
                    ->whereIn('status_id', [SessionStatus::Pending, SessionStatus::Ongoing]))
                ->with(['payment', 'clinicSession'])
                ->get();

            $prepaid = $upcoming->filter(fn (ClinicSessionMember $sessionMember): bool => $sessionMember->paymentStatus() !== PaymentStatus::Pending);

            if ($prepaid->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'patient' => __(':patient has paid for :days. Refund those payments before removing the patient.', [
                        'patient' => $enrollment->activityLabel(),
                        'days' => $this->scheduleDays->describeDays($prepaid->map->clinicSession),
                    ]),
                ]);
            }

            ActivityLogger::quietly(fn () => $upcoming->each(fn (ClinicSessionMember $sessionMember) => $this->removeSessionPatient->handle($sessionMember)));

            $enrollment->delete();
        });
    }
}
