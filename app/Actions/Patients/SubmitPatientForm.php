<?php

namespace App\Actions\Patients;

use App\Models\Patient;
use App\Models\PatientFormInvitation;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a patient's own answers from an intake link and saves them.
 *
 * The link is the only proof of identity, so it is checked again here and
 * burned on success: a link fills one form in, once.
 */
class SubmitPatientForm
{
    public function __construct(private SavePatientRecord $savePatientRecord) {}

    /**
     * @param  array<string, mixed>  $data  Validated answers, keyed by field key.
     */
    public function handle(PatientFormInvitation $invitation, array $data): Patient
    {
        return DB::transaction(function () use ($invitation, $data): Patient {
            $invitation = PatientFormInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->status() !== 'pending') {
                throw ValidationException::withMessages([
                    'token' => __('This link is no longer open.'),
                ]);
            }

            // The patient is filling this in themselves, so there is no clinic
            // member to attribute the change to.
            $patient = ActivityLogger::quietly(fn (): Patient => $this->savePatientRecord->handle(
                $invitation->clinic,
                $data,
                $invitation->patient,
            ));

            $invitation->forceFill(['submitted_at' => now(), 'patient_id' => $patient->id])->save();

            app(ActivityLogger::class)->log(
                action: 'patient_form_invitation.submitted',
                subject: $invitation,
                clinicId: $invitation->clinic_id,
                label: $patient->activityLabel(),
                context: ['via' => 'intake link'],
            );

            return $patient;
        });
    }
}
