<?php

namespace Database\Factories;

use App\Actions\Clinics\CreateClinic;
use App\Actions\Patients\CreateDefaultPatientForm;
use App\Models\Clinic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Clinic',
            'address' => fake()->address(),
            'timezone' => 'UTC',
        ];
    }

    /**
     * Give every clinic what a real one is created with: the built-in Clinic
     * Admin and Clinic Worker roles, and the default patient form.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Clinic $clinic): void {
            app(CreateClinic::class)->createDefaultRoles($clinic);
            app(CreateDefaultPatientForm::class)->handle($clinic);
        });
    }
}
