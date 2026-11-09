<?php

namespace Database\Factories;

use App\Enums\PatientFormFieldType;
use App\Models\Clinic;
use App\Models\PatientFormField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A custom field a clinic added to its patient form. Built-in fields are
 * created by CreateDefaultPatientForm, not here.
 *
 * @extends Factory<PatientFormField>
 */
class PatientFormFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->randomElement([
            'Allergies', 'Current medication', 'Emergency contact', 'Referred by',
            'Occupation', 'Insurance provider', 'Preferred language',
        ]).' '.fake()->unique()->numberBetween(1, 99999);

        return [
            'clinic_id' => Clinic::factory(),
            'key' => Str::of($label)->snake()->replaceMatches('/[^a-z0-9_]+/', '_')->value(),
            'label' => $label,
            'patient_form_field_type_id' => PatientFormFieldType::Text,
            'is_required' => false,
            'section' => 'Patient details',
            'position' => fake()->numberBetween(10, 99),
            'patient_column' => null,
        ];
    }

    /**
     * Make it a field of the given type.
     *
     * @param  list<string>  $options
     */
    public function type(PatientFormFieldType $type, array $options = []): static
    {
        return $this->state([
            'patient_form_field_type_id' => $type,
            'options' => $type->hasOptions() ? ($options ?: ['yes', 'no']) : null,
        ]);
    }

    public function required(): static
    {
        return $this->state(['is_required' => true]);
    }
}
