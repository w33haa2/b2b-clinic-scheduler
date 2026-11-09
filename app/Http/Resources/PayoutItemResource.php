<?php

namespace App\Http\Resources;

use App\Models\PayoutItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One billed item inside a payout. Load `sessionLineItem.lineItem` and
 * `sessionLineItem.sessionMember.{patient,payment,clinicSession}` to get the
 * patient, service and payment state with it.
 *
 * @mixin PayoutItem
 */
class PayoutItemResource extends JsonResource
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
            'payout_id' => $this->payout_id,
            'session_line_item_id' => $this->session_line_item_id,
            'share_percentage' => $this->share_percentage,
            'gross_amount' => $this->gross_amount,
            'discount_amount' => $this->discount_amount,
            'worker_amount' => $this->worker_amount,
            'clinic_amount' => $this->clinic_amount,
            'net_amount' => $this->netAmount(),
            'was_pending' => $this->was_pending,
            'settled_at' => $this->settled_at,
            'is_unsettled' => $this->was_pending && $this->settled_at === null,
            ...$this->whenLoaded('sessionLineItem', fn (): array => $this->billedItemDetails()),
        ];
    }

    /**
     * The patient, service and session behind the item.
     *
     * @return array<string, mixed>
     */
    private function billedItemDetails(): array
    {
        $sessionMember = $this->sessionLineItem->sessionMember;
        $session = $sessionMember?->clinicSession;

        return [
            'service' => $this->sessionLineItem->lineItem->name ?? null,
            'quantity' => $this->sessionLineItem->quantity,
            'patient' => $sessionMember?->patient ? new PatientResource($sessionMember->patient) : null,
            'payment_status' => $sessionMember?->paymentStatus()->code(),
            'session' => $session === null ? null : [
                'id' => $session->id,
                'starts_at' => $session->starts_at,
                'label' => $session->activityLabel(),
            ],
        ];
    }
}
