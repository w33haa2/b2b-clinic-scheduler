<?php

namespace App\Http\Resources;

use App\Models\PatientFormInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The link itself is only ever returned once, right after it is issued:
 * only a hash of the token is stored, so it cannot be shown again.
 *
 * @mixin PatientFormInvitation
 */
class PatientFormInvitationResource extends JsonResource
{
    /**
     * Set only on the response that issues the link.
     */
    public ?string $linkUrl = null;

    /**
     * The one response that may carry the plain link.
     */
    public static function withLink(PatientFormInvitation $invitation, string $url): self
    {
        $resource = new self($invitation);
        $resource->linkUrl = $url;

        return $resource;
    }

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
            'email' => $this->email,
            'status' => $this->status(),
            'patient_id' => $this->patient_id,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'created_by' => new ClinicUserResource($this->whenLoaded('createdBy')),
            'expires_at' => $this->expires_at,
            'submitted_at' => $this->submitted_at,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
            'url' => $this->when($this->linkUrl !== null, fn (): ?string => $this->linkUrl),
        ];
    }
}
