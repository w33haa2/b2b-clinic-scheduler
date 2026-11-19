<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Clinics\RescheduleDayPatient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\RescheduleSessionMemberRequest;
use App\Http\Resources\SessionMemberResource;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;

class SessionMemberRescheduleController extends Controller
{
    /**
     * Give a patient who misses a day of a daily session a makeup on another
     * date.
     *
     * Send `starts_at` and `ends_at`, optionally `clinic_user_id` to hand the
     * makeup to a different staff member (it stays with the one holding the
     * day otherwise) and a short `reason`.
     *
     * The makeup is a session of its own, because the date is usually one the
     * daily session already runs and a patient can only be on a day once.
     * What the patient owes moves with them, so a payment they already made
     * follows and nobody is billed twice. The missed day keeps its record of
     * them, pointing at the makeup.
     *
     * Can be used before, during or after the day: last-minute cancellations
     * and no-shows both end up here. Refused when the day was cancelled, when
     * the patient already has a makeup, or when what they owe is on an
     * invoice.
     *
     * Returns the patient's place in the new makeup session.
     */
    public function store(
        RescheduleSessionMemberRequest $request,
        Clinic $clinic,
        ClinicSession $clinicSession,
        ClinicSessionMember $sessionMember,
        RescheduleDayPatient $rescheduleDayPatient,
    ): SessionMemberResource {
        $sessionMember->setRelation('clinicSession', $clinicSession);

        $makeupMember = $rescheduleDayPatient->handle($sessionMember, $request->makeup());

        return new SessionMemberResource(
            $makeupMember->load(['patient', 'payment', 'clinicSession', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem']),
        );
    }
}
