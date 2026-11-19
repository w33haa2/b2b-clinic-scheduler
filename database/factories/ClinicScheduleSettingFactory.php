<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicScheduleSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicScheduleSetting>
 */
class ClinicScheduleSettingFactory extends Factory
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
            'weekday_opens_at' => '08:00:00',
            'weekday_closes_at' => '17:00:00',
            'weekend_opens_at' => '09:00:00',
            'weekend_closes_at' => '12:00:00',
            'saturday_enabled' => false,
            'sunday_enabled' => false,
            'allow_outside_hours' => false,
        ];
    }

    /**
     * Open at the weekend too.
     */
    public function weekends(): static
    {
        return $this->state(['saturday_enabled' => true, 'sunday_enabled' => true]);
    }

    /**
     * Let the clinic book whenever it likes.
     */
    public function allowingOutsideHours(): static
    {
        return $this->state(['allow_outside_hours' => true]);
    }
}
