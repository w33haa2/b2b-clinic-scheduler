<?php

namespace App\Http\Resources;

use App\Models\SessionLineItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SessionLineItem
 */
class SessionLineItemResource extends JsonResource
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
            'session_member_id' => $this->clinic_session_member_id,
            'line_item' => new LineItemResource($this->whenLoaded('lineItem')),
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'gross_amount' => $this->grossAmount(),
            'discount_amount' => $this->discount_amount,
            'discount_target' => $this->discount_target_id?->code(),
            'discount' => new DiscountResource($this->whenLoaded('discount')),
            'amount' => $this->amount(),
            'invoice_id' => $this->whenLoaded('invoiceItem', fn () => $this->invoiceItem?->invoice_id),
            'payout_id' => $this->whenLoaded('payoutItem', fn () => $this->payoutItem?->payout_id),
        ];
    }
}
