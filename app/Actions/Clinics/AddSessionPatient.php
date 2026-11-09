<?php

namespace App\Actions\Clinics;

use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\DailySessionPatient;
use Illuminate\Support\Facades\DB;

class AddSessionPatient
{
    /**
     * Add a patient to a session together with their unpaid payment. On a day
     * of a daily session the patient is also billed a service, so every day
     * has something to pay for: the one in their plan if they are enrolled
     * (pass `$plan` to save a lookup), else the daily session's default.
     */
    public function handle(ClinicSession $session, int $patientId, ?DailySessionPatient $plan = null): ClinicSessionMember
    {
        return DB::transaction(function () use ($session, $patientId, $plan): ClinicSessionMember {
            $sessionMember = $session->sessionMembers()->create(['patient_id' => $patientId]);
            $sessionMember->setRelation('clinicSession', $session);

            $payment = $sessionMember->payment()->create([
                'clinic_id' => $session->clinic_id,
                'clinic_session_id' => $session->id,
                'patient_id' => $patientId,
            ]);
            $sessionMember->setRelation('payment', $payment);

            $dailySession = $session->dailySession;

            if ($dailySession !== null) {
                $plan ??= $dailySession->dailySessionPatients()->where('patient_id', $patientId)->first();

                $sessionMember->sessionLineItems()->create([
                    'line_item_id' => $plan->line_item_id ?? $dailySession->line_item_id,
                    'quantity' => 1,
                    'unit_price' => $plan->unit_price ?? $dailySession->unit_price,
                ]);
            }

            return $sessionMember;
        });
    }
}
