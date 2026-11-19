<?php

namespace App\Http\Resources;

use App\Models\ClinicScheduleSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicScheduleSetting
 */
class ClinicScheduleSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Times go out as "H:i": the frontend compares them against the "H:i"
     * values its time pickers produce.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $widest = $this->widestHours();

        return [
            'weekday_opens_at' => substr($this->weekday_opens_at, 0, 5),
            'weekday_closes_at' => substr($this->weekday_closes_at, 0, 5),
            'weekend_opens_at' => substr($this->weekend_opens_at, 0, 5),
            'weekend_closes_at' => substr($this->weekend_closes_at, 0, 5),
            'saturday_enabled' => $this->saturday_enabled,
            'sunday_enabled' => $this->sunday_enabled,
            'allow_outside_hours' => $this->allow_outside_hours,
            // What the calendar grid and the time pickers span.
            'opens_at' => $widest[0] ?? null,
            'closes_at' => $widest[1] ?? null,
            'open_weekdays' => array_values(array_filter(
                range(1, 7),
                fn (int $weekday): bool => $this->opensOnWeekday($weekday),
            )),
            'updated_at' => $this->updated_at,
        ];
    }
}
