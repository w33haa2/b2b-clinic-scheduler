<?php

namespace App\Http\Resources;

use App\Models\ClinicSessionMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicSessionMember
 */
class SessionMemberResource extends JsonResource
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
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'payment_id' => $this->whenLoaded('payment', fn (): ?int => $this->payment?->id),
            'payment_status' => $this->whenLoaded('payment', fn (): string => $this->paymentStatus()->code()),
            // The plotted time slot on a day of a daily session; null until plotted.
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            // Changed by hand for this day only; regular plan changes skip it.
            'customised' => $this->customised,
            'line_items' => SessionLineItemResource::collection($this->whenLoaded('sessionLineItems')),
        ];
    }
}
