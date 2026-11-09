<?php

namespace App\Http\Resources;

use App\Models\ClinicSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicSession
 */
class ClinicSessionResource extends JsonResource
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
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'status' => $this->status_id->code(),
            'clinic_user' => new ClinicUserResource($this->whenLoaded('clinicUser')),
            // Set when this session is one day of a daily session.
            'daily_session' => $this->whenLoaded('dailySession', fn (): ?array => $this->dailySession ? [
                'id' => $this->dailySession->id,
                'name' => $this->dailySession->name,
            ] : null),
            'occurs_on' => $this->occurs_on?->toDateString(),
            'members' => SessionMemberResource::collection($this->whenLoaded('sessionMembers')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
