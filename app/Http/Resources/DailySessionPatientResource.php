<?php

namespace App\Http\Resources;

use App\Models\DailySessionPatient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DailySessionPatient
 */
class DailySessionPatientResource extends JsonResource
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
            // The plan: service billed every day and the usual slot (clinic timezone).
            'line_item' => new LineItemResource($this->whenLoaded('lineItem')),
            'unit_price' => $this->unit_price,
            'start_time' => $this->start_time !== null ? substr($this->start_time, 0, 5) : null,
            'duration_minutes' => $this->duration_minutes,
            'enrolled_at' => $this->created_at,
        ];
    }
}
