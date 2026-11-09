<?php

namespace App\Actions\Billing;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Models\ClinicUser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PayoutItem;
use App\Models\SessionLineItem;
use App\Support\ActivityLogger;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePaymentStatus
{
    /**
     * Move a payment to a new status and record the money movement: one
     * INBOUND transaction per billed item when paid, one REVERSED transaction
     * per billed item when refunded. The paid total is stored on the payment.
     *
     * Items that were already paid out while this payment was still pending
     * are settled here too: the clinic advanced that money to a member and
     * has now collected it. A refund re-opens the receivable.
     *
     * @param  array<string, mixed>  $context  Extra facts for the activity log entry.
     */
    public function handle(Payment $payment, PaymentStatus $status, ClinicUser $recordedBy, array $context = []): Payment
    {
        return DB::transaction(function () use ($payment, $status, $recordedBy, $context): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $previous = $payment->payment_status_id;

            if (! $previous->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'payment_status' => __('Payment cannot change from :from to :to.', [
                        'from' => $previous->code(),
                        'to' => $status->code(),
                    ]),
                ]);
            }

            if ($status === PaymentStatus::Done && $payment->clinicSession->status_id === SessionStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'payment_status' => __('A cancelled session cannot be paid.'),
                ]);
            }

            $lineItems = SessionLineItem::query()
                ->where('clinic_session_member_id', $payment->clinic_session_member_id)
                ->with('invoiceItem.invoice')
                ->orderBy('id')
                ->get();

            if ($lineItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'payment_status' => __('This patient has no billed items in the session.'),
                ]);
            }

            if ($status === PaymentStatus::Refunded) {
                $invoices = $lineItems->map(fn (SessionLineItem $item): ?Invoice => $item->invoiceItem?->invoice)->filter()->unique('id');

                if ($invoices->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'payment_status' => __('These items are on invoice :invoices. Remove them from the invoice before refunding.', [
                            'invoices' => $invoices->map(fn (Invoice $invoice): string => $invoice->reference())->implode(', '),
                        ]),
                    ]);
                }
            }

            $type = $status === PaymentStatus::Done ? TransactionType::Inbound : TransactionType::Reversed;
            $total = BigDecimal::zero();

            foreach ($lineItems as $lineItem) {
                $lineItem->transactions()->create([
                    'clinic_id' => $payment->clinic_id,
                    'payment_id' => $payment->id,
                    'transaction_type_id' => $type,
                    'amount' => $lineItem->amount(),
                    'clinic_user_id' => $recordedBy->id,
                ]);
                $total = $total->plus($lineItem->amount());
            }

            $payment->update($status === PaymentStatus::Done
                ? ['payment_status_id' => $status, 'amount' => (string) $total->toScale(2), 'paid_at' => now()]
                : ['payment_status_id' => $status, 'refunded_at' => now()]);

            PayoutItem::query()
                ->whereIn('session_line_item_id', $lineItems->modelKeys())
                ->where('was_pending', true)
                ->update(['settled_at' => $status === PaymentStatus::Done ? now() : null]);

            app(ActivityLogger::class)->log(
                action: $status === PaymentStatus::Done ? 'payment.recorded' : 'payment.refunded',
                subject: $payment,
                clinicId: $payment->clinic_id,
                label: $payment->activityLabel(),
                changes: ['payment_status_id' => ['old' => $previous->value, 'new' => $status->value]],
                context: ['session' => $payment->clinicSession->activityLabel(), 'amount' => (string) $total->toScale(2), ...$context],
            );

            return $payment;
        });
    }
}
