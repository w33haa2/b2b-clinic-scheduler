<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attendance
 */
class AttendanceResource extends JsonResource
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
            'member_id' => $this->clinic_user_id,
            'member' => new ClinicUserResource($this->whenLoaded('clinicUser')),
            'work_date' => $this->work_date->toDateString(),
            'status' => $this->attendance_status_id->code(),
            'counts_as_worked' => $this->attendance_status_id->countsAsWorked(),
            'checked_in_at' => $this->checked_in_at,
            'checked_out_at' => $this->checked_out_at,
            'worked_minutes' => $this->workedMinutes(),
            'notes' => $this->notes,
            'recorded_by' => new ClinicUserResource($this->whenLoaded('recordedBy')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
