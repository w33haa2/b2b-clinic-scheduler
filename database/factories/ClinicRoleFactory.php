<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicPermission;
use App\Models\ClinicRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicRole>
 */
class ClinicRoleFactory extends Factory
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
            'name' => fake()->unique()->jobTitle(),
            'key' => null,
        ];
    }

    /**
     * Grant the role the named permissions (clinic API route names).
     *
     * @param  list<string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(fn (ClinicRole $role) => $role->clinicPermissions()->sync(
            ClinicPermission::query()->whereIn('name', $permissions)->pluck('id'),
        ));
    }
}
