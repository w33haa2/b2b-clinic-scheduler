<?php

namespace Database\Factories;

use App\Models\ClinicUser;
use App\Models\DailySession;
use App\Models\LineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates the daily session only. Use App\Actions\Clinics\SaveDailySession
 * (or ScheduleDailySessionDays) to enrol patients and schedule its days.
 *
 * @extends Factory<DailySession>
 */
class DailySessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_user_id' => ClinicUser::factory()->worker(),
            'clinic_id' => fn (array $attributes) => ClinicUser::query()->findOrFail((int) $attributes['clinic_user_id'])->clinic_id,
            'name' => fake()->randomElement(['Daily physio', 'Morning rehab', 'Speech drills', 'Walk-in therapy']),
            'line_item_id' => fn (array $attributes) => LineItem::factory()->state(['clinic_id' => $attributes['clinic_id']]),
            'unit_price' => fn (array $attributes) => LineItem::query()->findOrFail((int) $attributes['line_item_id'])->price,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
        ];
    }
}
