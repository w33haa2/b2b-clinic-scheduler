<?php

namespace Database\Factories;

use App\Models\PatientProgressMetric;
use App\Models\PatientProgressNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientProgressMetric>
 */
class PatientProgressMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_progress_note_id' => PatientProgressNote::factory(),
            'name' => 'Pain',
            'value' => fake()->randomFloat(2, 0, 10),
            'unit' => '/10',
        ];
    }
}
