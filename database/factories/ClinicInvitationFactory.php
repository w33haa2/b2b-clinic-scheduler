<?php

namespace Database\Factories;

use App\Enums\ClinicRoleKey;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use App\Models\ClinicRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A pending invitation to join as a Clinic Worker. The plain token is not
 * kept: use `withToken('…')` when a test needs to open the link.
 *
 * @extends Factory<ClinicInvitation>
 */
class ClinicInvitationFactory extends Factory
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
            'email' => fake()->unique()->safeEmail(),
            'clinic_role_id' => fn (array $attributes) => ClinicRole::query()
                ->where('clinic_id', $attributes['clinic_id'])
                ->where('key', ClinicRoleKey::Worker)
                ->value('id'),
            'token_hash' => ClinicInvitation::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(ClinicInvitation::VALID_FOR_DAYS),
        ];
    }

    /**
     * Use a known link token.
     */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => ClinicInvitation::hashToken($token)]);
    }
}
