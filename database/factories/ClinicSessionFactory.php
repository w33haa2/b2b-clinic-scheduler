<?php

namespace Database\Factories;

use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\ClinicUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicSession>
 */
class ClinicSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 30))->setTime(fake()->numberBetween(8, 16), 0);

        return [
            'clinic_user_id' => ClinicUser::factory()->worker(),
            'clinic_id' => fn (array $attributes) => ClinicUser::query()->findOrFail((int) $attributes['clinic_user_id'])->clinic_id,
            'name' => fake()->optional()->randomElement(['Initial assessment', 'Follow-up', 'Group therapy', 'Speech therapy', 'Sports rehab', 'Check-up']),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'status_id' => SessionStatus::Pending,
        ];
    }

    /**
     * Put the session in the given status.
     */
    public function status(SessionStatus $status): static
    {
        return $this->state(['status_id' => $status]);
    }
}
