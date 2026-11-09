<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'clinic_id' => fn (array $attributes) => Patient::query()->findOrFail((int) $attributes['patient_id'])->clinic_id,
            'number' => fake()->unique()->numberBetween(1, 999999),
            'issued_at' => now()->toDateString(),
            'notes' => null,
        ];
    }
}
