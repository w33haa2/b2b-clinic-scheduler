<?php

namespace App\Http\Resources;

use App\Models\ClinicInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicInvitation
 */
class ClinicInvitationResource extends JsonResource
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
            'email' => $this->email,
            // pending, accepted, revoked or expired
            'status' => $this->status(),
            'role' => $this->whenLoaded('clinicRole', fn (): array => ['id' => $this->clinicRole->id, 'name' => $this->clinicRole->name]),
            'invited_by' => $this->whenLoaded('invitedBy', fn (): ?array => $this->invitedBy ? ['id' => $this->invitedBy->id, 'name' => $this->invitedBy->name] : null),
            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
        ];
    }
}
