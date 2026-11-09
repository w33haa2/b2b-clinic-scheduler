<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Billing\UpdatePaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PaymentStatusRequest;
use App\Http\Resources\SessionMemberResource;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Support\ClinicContext;

class PaymentStatusController extends Controller
{
    /**
     * Mark a patient's session as paid or refunded.
     *
     * Changes the patient's payment. `DONE` records one INBOUND transaction
     * per billed item and stores the paid total; `REFUNDED` records one
     * REVERSED transaction per billed item. Allowed: PENDING → DONE →
     * REFUNDED. Billed items are locked once paid. A refund is refused while
     * any of the items is on an invoice.
     */
    public function update(
        PaymentStatusRequest $request,
        Clinic $clinic,
        ClinicSession $clinicSession,
        ClinicSessionMember $sessionMember,
        UpdatePaymentStatus $updatePaymentStatus,
        ClinicContext $context,
    ): SessionMemberResource {
        $updatePaymentStatus->handle($sessionMember->payment()->firstOrFail(), $request->paymentStatus(), $context->member());

        return new SessionMemberResource($sessionMember->load(['patient', 'payment', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem']));
    }
}
