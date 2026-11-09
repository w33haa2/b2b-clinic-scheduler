<?php

namespace App\Actions\Billing;

use App\Enums\PaymentStatus;
use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\SessionLineItem;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveInvoice
{
    /**
     * Create or update an invoice for one patient. Every session line item must
     * belong to that patient in this clinic, be paid (payment DONE) and not be
     * on another invoice.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Clinic $clinic, array $data, ?Invoice $invoice = null): Invoice
    {
        return DB::transaction(function () use ($clinic, $data, $invoice): Invoice {
            $patientId = $invoice !== null ? $invoice->patient_id : (int) $data['patient_id'];

            if ($invoice === null) {
                Clinic::query()->whereKey($clinic->id)->lockForUpdate()->first();

                $invoice = $clinic->invoices()->make([
                    'patient_id' => $patientId,
                    'number' => (int) $clinic->invoices()->max('number') + 1,
                    'issued_at' => $data['issued_at'] ?? now()->toDateString(),
                ]);
            } elseif (array_key_exists('issued_at', $data) && $data['issued_at'] !== null) {
                $invoice->fill(['issued_at' => $data['issued_at']]);
            }

            if (array_key_exists('notes', $data)) {
                $invoice->notes = $data['notes'];
            }

            $invoice->save();

            if (isset($data['session_line_item_ids'])) {
                $sessionLineItemIds = array_values(array_map(intval(...), (array) $data['session_line_item_ids']));

                $this->ensureItemsAreBillable($clinic, $invoice, $patientId, $sessionLineItemIds);

                $isNew = $invoice->wasRecentlyCreated;
                $result = $invoice->sessionLineItems()->sync($sessionLineItemIds);

                if (! $isNew && ($result['attached'] !== [] || $result['detached'] !== [])) {
                    app(ActivityLogger::class)->log(
                        action: 'invoice.items_changed',
                        subject: $invoice,
                        clinicId: $clinic->id,
                        label: $invoice->activityLabel(),
                        changes: ['items' => ['old' => count($result['detached']), 'new' => count($result['attached'])]],
                    );
                }
            }

            return $invoice;
        });
    }

    /**
     * @param  list<int>  $sessionLineItemIds
     */
    private function ensureItemsAreBillable(Clinic $clinic, Invoice $invoice, int $patientId, array $sessionLineItemIds): void
    {
        $billable = SessionLineItem::query()
            ->whereIn('id', $sessionLineItemIds)
            ->whereHas('sessionMember', fn ($query) => $query
                ->where('patient_id', $patientId)
                ->whereHas('payment', fn ($query) => $query->where('payment_status_id', PaymentStatus::Done))
                ->whereHas('clinicSession', fn ($query) => $query->whereBelongsTo($clinic)))
            ->where(fn ($query) => $query
                ->whereDoesntHave('invoiceItem')
                ->orWhereHas('invoiceItem', fn ($query) => $query->whereBelongsTo($invoice)))
            ->pluck('id');

        $invalid = collect($sessionLineItemIds)->diff($billable);

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'session_line_item_ids' => __('Items :ids do not belong to this patient, are not paid, or are already on another invoice.', [
                    'ids' => $invalid->implode(', '),
                ]),
            ]);
        }
    }
}
