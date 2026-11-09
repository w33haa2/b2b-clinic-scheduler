<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Clinics\UpdateDayPatient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\SessionMemberSlotRequest;
use App\Http\Resources\SessionMemberResource;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;

class SessionMemberSlotController extends Controller
{
    /**
     * Change a patient on one day of a daily session, for that day only.
     *
     * Send `starts_at` / `ends_at` to plot the patient into a slot (`null` for
     * both to unplot), and/or `line_item_id` (optional `unit_price`) to bill a
     * different service that day while it is unpaid. The slot must be inside
     * the day's hours and the staff member must be free then. The day is
     * marked `customised`, so changes to the patient's regular plan skip it.
     * Send `{"reset": true}` to put the patient back on their regular plan
     * for the day.
     */
    public function update(
        SessionMemberSlotRequest $request,
        Clinic $clinic,
        ClinicSession $clinicSession,
        ClinicSessionMember $sessionMember,
        UpdateDayPatient $updateDayPatient,
    ): SessionMemberResource {
        $sessionMember->setRelation('clinicSession', $clinicSession);
        $updateDayPatient->handle($sessionMember, $request->changes());

        return new SessionMemberResource($sessionMember->load(['patient', 'payment', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem']));
    }
}
