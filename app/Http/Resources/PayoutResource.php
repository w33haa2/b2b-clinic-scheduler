<?php

namespace App\Http\Resources;

use App\Models\Payout;
use App\Models\PayoutItem;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payout
 */
class PayoutResource extends JsonResource
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
            'reference' => $this->reference(),
            'number' => $this->number,
            'member_id' => $this->clinic_user_id,
            'member' => new ClinicUserResource($this->whenLoaded('clinicUser')),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->payout_status_id->code(),
            'share_percentage' => $this->share_percentage,
            'gross_amount' => $this->gross_amount,
            'discount_total' => $this->discount_total,
            'worker_amount' => $this->worker_amount,
            'clinic_amount' => $this->clinic_amount,
            'pending_amount' => $this->pending_amount,
            'unsettled_amount' => $this->whenLoaded('payoutItems', fn (): string => (string) $this->payoutItems
                ->filter(fn (PayoutItem $item): bool => $item->was_pending && $item->settled_at === null)
                ->reduce(fn (BigDecimal $total, PayoutItem $item): BigDecimal => $total->plus($item->worker_amount), BigDecimal::zero())
                ->toScale(2)),
            'notes' => $this->notes,
            'generated_by' => new ClinicUserResource($this->whenLoaded('generatedBy')),
            'items' => PayoutItemResource::collection($this->whenLoaded('payoutItems')),
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
