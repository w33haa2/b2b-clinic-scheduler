<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\ClinicUser;
use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Payouts are normally created by App\Actions\Payroll\GeneratePayout, which
 * also writes the payout_items that lock the billed items. Use this factory
 * only when the items do not matter.
 *
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_user_id' => ClinicUser::factory(),
            'clinic_id' => fn (array $attributes) => ClinicUser::query()->findOrFail((int) $attributes['clinic_user_id'])->clinic_id,
            'number' => fake()->unique()->numberBetween(1, 100000),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'payout_status_id' => PayoutStatus::Draft,
            'share_percentage' => '60.00',
            'gross_amount' => '0.00',
            'discount_total' => '0.00',
            'worker_amount' => '0.00',
            'clinic_amount' => '0.00',
            'pending_amount' => '0.00',
        ];
    }

    /**
     * Mark the payout as already paid out.
     */
    public function paid(): static
    {
        return $this->state(['payout_status_id' => PayoutStatus::Paid, 'paid_at' => now()]);
    }
}
