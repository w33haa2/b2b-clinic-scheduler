<?php

namespace App\Http\Resources;

use App\Models\ClinicRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicRole
 */
class ClinicRoleResource extends JsonResource
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
            'key' => $this->key,
            'is_system' => $this->isSystem(),
            'permissions' => $this->whenLoaded('clinicPermissions', fn () => $this->clinicPermissions->pluck('name')),
        ];
    }
}
