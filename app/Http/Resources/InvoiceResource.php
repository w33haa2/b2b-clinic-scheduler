<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\SessionLineItem;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
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
            'number' => $this->number,
            'reference' => $this->reference(),
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'issued_at' => $this->issued_at->toDateString(),
            'notes' => $this->notes,
            'items' => SessionLineItemResource::collection($this->whenLoaded('sessionLineItems')),
            'total' => $this->whenLoaded('sessionLineItems', fn (): string => (string) $this->sessionLineItems
                ->reduce(fn (BigDecimal $total, SessionLineItem $item): BigDecimal => $total->plus($item->amount()), BigDecimal::zero())
                ->toScale(2)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
