<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use App\Support\ActivityDescriber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $describer = app(ActivityDescriber::class);
        $category = $this->category();

        return [
            'id' => $this->id,
            'action' => $this->action,
            'category' => $category ? ['value' => $category->value, 'label' => $category->label()] : null,
            'description' => $describer->describe($this->resource),
            'subject_label' => $this->subject_label,
            'changes' => $describer->readableChanges($this->resource),
            'actor' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'is_super_admin' => $this->user->is_super_admin,
            ] : null),
            'clinic' => $this->whenLoaded('clinic', fn () => $this->clinic ? [
                'id' => $this->clinic->id,
                'name' => $this->clinic->name,
            ] : null),
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ];
    }
}
