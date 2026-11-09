<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientFieldValue;
use App\Models\PatientFormField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientFieldValue>
 */
class PatientFieldValueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_form_field_id' => PatientFormField::factory(),
            'patient_id' => fn (array $attributes) => Patient::factory()->state([
                'clinic_id' => PatientFormField::query()->findOrFail((int) $attributes['patient_form_field_id'])->clinic_id,
            ]),
            'value' => fake()->word(),
        ];
    }
}
