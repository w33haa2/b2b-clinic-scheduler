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
            /*
             * Set when the patient missed this day and was given a makeup
             * elsewhere. Their bill and payment went with them, so the row
             * that stays here is the record that they were expected.
             */
            'moved_to' => $this->when($this->wasMoved(), fn (): array => [
                'session_member_id' => $this->moved_to_clinic_session_member_id,
                'moved_at' => $this->moved_at,
                'reason' => $this->moved_reason,
            ]),
            'line_items' => SessionLineItemResource::collection($this->whenLoaded('sessionLineItems')),
        ];
    }
}
