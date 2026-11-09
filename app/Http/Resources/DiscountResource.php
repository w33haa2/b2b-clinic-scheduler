<?php

namespace App\Http\Resources;

use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Discount
 */
class DiscountResource extends JsonResource
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
            'name' => $this->name,
            'scope' => $this->isClinicWide() ? 'CLINIC_WIDE' : 'PERSONAL',
            'owner_id' => $this->clinic_user_id,
            'owner' => new ClinicUserResource($this->whenLoaded('clinicUser')),
            'type' => $this->discount_type_id->code(),
            'target' => $this->discount_target_id->code(),
            'value' => $this->value,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
