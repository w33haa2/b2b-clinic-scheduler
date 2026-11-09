<?php

namespace Database\Factories;

use App\Enums\ClinicRoleKey;
use App\Models\Clinic;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicUser>
 */
class ClinicUserFactory extends Factory
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
            'user_id' => User::factory(),
        ];
    }

    /**
     * Give the member the clinic's built-in Clinic Admin role.
     */
    public function admin(): static
    {
        return $this->withBuiltInRole(ClinicRoleKey::Admin);
    }

    /**
     * Give the member the clinic's built-in Clinic Worker role.
     */
    public function worker(): static
    {
        return $this->withBuiltInRole(ClinicRoleKey::Worker);
    }

    /**
     * Give the member the given role.
     */
    public function withRole(ClinicRole $role): static
    {
        return $this->state(['clinic_id' => $role->clinic_id])
            ->afterCreating(fn (ClinicUser $clinicUser) => $clinicUser->clinicRoles()->attach($role));
    }

    private function withBuiltInRole(ClinicRoleKey $key): static
    {
        return $this->afterCreating(fn (ClinicUser $clinicUser) => $clinicUser->clinicRoles()->attach(
            ClinicRole::query()->where('clinic_id', $clinicUser->clinic_id)->where('key', $key)->firstOrFail(),
        ));
    }
}
