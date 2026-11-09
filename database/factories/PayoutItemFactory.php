<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\SessionLineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutItem>
 */
class PayoutItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payout_id' => Payout::factory(),
            'session_line_item_id' => SessionLineItem::factory(),
            'share_percentage' => '60.00',
            'gross_amount' => '1000.00',
            'discount_amount' => '0.00',
            'worker_amount' => '600.00',
            'clinic_amount' => '400.00',
            'was_pending' => false,
        ];
    }

    /**
     * The member was paid before the patient was, and still has not been.
     */
    public function advanced(): static
    {
        return $this->state(['was_pending' => true, 'settled_at' => null]);
    }
}
