<?php

namespace Database\Factories;

use App\Models\CalendarFilter;
use App\Models\ClinicUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarFilter>
 */
class CalendarFilterFactory extends Factory
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
            'name' => 'Unpaid daily patients',
            'rules' => [
                'match' => 'all',
                'conditions' => [
                    ['field' => 'type', 'operator' => 'is', 'values' => ['daily']],
                    ['field' => 'payment', 'operator' => 'is', 'values' => ['unpaid']],
                ],
            ],
        ];
    }
}
