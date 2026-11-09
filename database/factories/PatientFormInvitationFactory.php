<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\PatientFormInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A pending intake link. The plain token is not kept: use
 * `withToken('…')` when a test needs to open the link.
 *
 * @extends Factory<PatientFormInvitation>
 */
class PatientFormInvitationFactory extends Factory
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => PatientFormInvitation::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(PatientFormInvitation::VALID_FOR_DAYS),
        ];
    }

    /**
     * Use a known link token.
     */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => PatientFormInvitation::hashToken($token)]);
    }

    public function submitted(): static
    {
        return $this->state(['submitted_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
