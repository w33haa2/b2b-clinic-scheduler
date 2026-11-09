<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\LineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LineItem>
 */
class LineItemFactory extends Factory
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
            'item_code' => fake()->unique()->bothify('ITEM-####'),
            'name' => fake()->randomElement(['Consultation', 'Therapy session', 'Assessment', 'Follow-up']),
            'price' => fake()->randomFloat(2, 100, 5000),
        ];
    }
}
