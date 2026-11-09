<?php

namespace App\Actions\Payroll;

use App\Enums\PayoutStatus;
use App\Models\ClinicUser;
use App\Models\Payout;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePayoutStatus
{
    /**
     * Move a payout to a new status: DRAFT → PAID, or either of them →
     * CANCELLED.
     *
     * Cancelling deletes the payout's items, which releases them to be paid
     * out again, exactly as deleting an invoice frees its items. The totals
     * stay on the payout as a record of what was cancelled.
     */
    public function handle(Payout $payout, PayoutStatus $status, ClinicUser $recordedBy): Payout
    {
        return DB::transaction(function () use ($payout, $status): Payout {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $previous = $payout->payout_status_id;

            if (! $previous->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'status' => __('A payout cannot change from :from to :to.', [
                        'from' => $previous->code(),
                        'to' => $status->code(),
                    ]),
                ]);
            }

            if ($status === PayoutStatus::Cancelled) {
                $payout->payoutItems()->delete();
            }

            $payout->update($status === PayoutStatus::Paid
                ? ['payout_status_id' => $status, 'paid_at' => now()]
                : ['payout_status_id' => $status]);

            app(ActivityLogger::class)->log(
                action: $status === PayoutStatus::Paid ? 'payout.paid' : 'payout.cancelled',
                subject: $payout,
                clinicId: $payout->clinic_id,
                label: $payout->reference(),
                changes: ['payout_status_id' => ['old' => $previous->value, 'new' => $status->value]],
                context: [...$payout->activityContext(), 'amount' => $payout->worker_amount],
            );

            return $payout;
        });
    }
}
