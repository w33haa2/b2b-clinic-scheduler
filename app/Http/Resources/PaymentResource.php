<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
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
            'status' => $this->payment_status_id->code(),
            'is_past_due' => $this->whenLoaded('clinicSession', fn (): bool => $this->isPastDue()),
            // What was paid, or (while unpaid) what the billed items add up to.
            'amount' => $this->whenLoaded('sessionMember', fn (): string => $this->amountDue(), $this->amount),
            'paid_at' => $this->paid_at,
            'refunded_at' => $this->refunded_at,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'session' => new ClinicSessionResource($this->whenLoaded('clinicSession')),
            'session_member_id' => $this->clinic_session_member_id,
            'line_items' => $this->whenLoaded('sessionMember', fn () => SessionLineItemResource::collection(
                $this->sessionMember->relationLoaded('sessionLineItems') ? $this->sessionMember->sessionLineItems : collect(),
            )),
            'created_at' => $this->created_at,
        ];
    }
}
