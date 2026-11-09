<?php

namespace App\Http\Resources;

use App\Models\ClinicPayoutSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicPayoutSetting
 */
class ClinicPayoutSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'worker_share_percentage' => $this->worker_share_percentage,
            'clinic_share_percentage' => $this->clinicSharePercentage(),
            'include_pending_payments' => $this->include_pending_payments,
            'require_attendance' => $this->require_attendance,
            'updated_at' => $this->updated_at,
        ];
    }
}
