<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->transaction_type_id->code(),
            'amount' => $this->amount,
            'payment_id' => $this->payment_id,
            'patient' => $this->whenLoaded('payment', fn () => new PatientResource($this->payment->patient)),
            'session_line_item' => new SessionLineItemResource($this->whenLoaded('sessionLineItem')),
            'payout_id' => $this->whenLoaded('sessionLineItem', fn () => $this->sessionLineItem->relationLoaded('payoutItem')
                ? $this->sessionLineItem->payoutItem?->payout_id
                : null),
            'recorded_by' => new ClinicUserResource($this->whenLoaded('clinicUser')),
            'created_at' => $this->created_at,
        ];
    }
}
