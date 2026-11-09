<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Payroll\UpdatePayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PayoutStatusRequest;
use App\Http\Resources\PayoutResource;
use App\Models\Clinic;
use App\Models\Payout;
use App\Support\ClinicContext;

class PayoutStatusController extends Controller
{
    /**
     * Mark a payout paid, or cancel it.
     *
     * `DRAFT` → `PAID` records when the member was paid. `CANCELLED`
     * releases every item the payout covered, so they can go into another
     * one; the totals stay as a record of what was cancelled.
     */
    public function update(
        PayoutStatusRequest $request,
        Clinic $clinic,
        Payout $payout,
        UpdatePayoutStatus $updatePayoutStatus,
        ClinicContext $context,
    ): PayoutResource {
        $payout = $updatePayoutStatus->handle($payout, $request->payoutStatus(), $context->member());

        return new PayoutResource($payout->load(['clinicUser.user', 'generatedBy.user']));
    }
}
