<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicPayoutSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicPayoutSetting>
 */
class ClinicPayoutSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'worker_share_percentage' => ClinicPayoutSetting::DEFAULT_WORKER_SHARE,
            'include_pending_payments' => true,
            'require_attendance' => false,
        ];
    }
}
