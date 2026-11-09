<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\SessionStatusRequest;
use App\Http\Resources\ClinicSessionResource;
use App\Models\Clinic;
use App\Models\ClinicSession;
use Illuminate\Validation\ValidationException;

class SessionStatusController extends Controller
{
    /**
     * Change a session's status.
     *
     * Allowed: PENDING → ONGOING or CANCELLED; ONGOING → DONE or CANCELLED.
     * DONE and CANCELLED are final. A session (or a day of a daily session)
     * that a patient has paid for cannot be cancelled until the payment is
     * refunded.
     *
     * Days of a daily session start (on their date) and finish (when their
     * hours end) on their own; the only status they accept here is
     * `CANCELLED`, for today or a future day.
     */
    public function update(SessionStatusRequest $request, Clinic $clinic, ClinicSession $clinicSession): ClinicSessionResource
    {
        $status = $request->status();

        if ($clinicSession->isDailyOccurrence() && $status !== SessionStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => __('Days of a daily session start and finish on their own. You can only cancel a day.'),
            ]);
        }

        if (! $clinicSession->status_id->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => __('A session cannot change from :from to :to.', [
                    'from' => $clinicSession->status_id->code(),
                    'to' => $status->code(),
                ]),
            ]);
        }

        $hasPaidPatients = $status === SessionStatus::Cancelled
            && $clinicSession->payments()->where('payment_status_id', PaymentStatus::Done)->exists();

        if ($hasPaidPatients) {
            throw ValidationException::withMessages([
                'status' => __('Patients have paid for this session. Refund their payments before cancelling it.'),
            ]);
        }

        $clinicSession->update(['status_id' => $status]);

        return new ClinicSessionResource($clinicSession->load(['clinicUser.user', 'dailySession', 'sessionMembers.patient', 'sessionMembers.payment']));
    }
}
