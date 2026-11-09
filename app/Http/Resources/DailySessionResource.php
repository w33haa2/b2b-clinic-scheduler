<?php

namespace App\Http\Resources;

use App\Models\DailySession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DailySession
 */
class DailySessionResource extends JsonResource
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
            'clinic_user' => new ClinicUserResource($this->whenLoaded('clinicUser')),
            'line_item' => new LineItemResource($this->whenLoaded('lineItem')),
            'unit_price' => $this->unit_price,
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'weekdays' => array_map(intval(...), $this->weekdays),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'timezone' => $this->whenLoaded('clinic', fn (): string => $this->timezone()),
            'patients_count' => $this->whenCounted('dailySessionPatients'),
            'enrollments' => DailySessionPatientResource::collection($this->whenLoaded('dailySessionPatients')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
