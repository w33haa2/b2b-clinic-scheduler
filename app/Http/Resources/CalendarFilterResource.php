<?php

namespace App\Http\Resources;

use App\Models\CalendarFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CalendarFilter
 */
class CalendarFilterResource extends JsonResource
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
            'rules' => $this->rules,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
