<?php

namespace App\Http\Resources;

use App\Models\ClinicUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicUser
 */
class ClinicUserResource extends JsonResource
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
            'user' => new UserResource($this->whenLoaded('user')),
            'roles' => ClinicRoleResource::collection($this->whenLoaded('clinicRoles')),
            'payout_share_percentage' => $this->payout_share_percentage,
            'joined_at' => $this->created_at,
        ];
    }
}
