<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\SessionLineItem;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_line_item_id' => SessionLineItem::factory(),
            'clinic_id' => fn (array $attributes) => SessionLineItem::query()->findOrFail((int) $attributes['session_line_item_id'])->sessionMember->clinicSession->clinic_id,
            'payment_id' => fn (array $attributes) => SessionLineItem::query()->findOrFail((int) $attributes['session_line_item_id'])->sessionMember->payment->id,
            'transaction_type_id' => TransactionType::Inbound,
            'amount' => fn (array $attributes) => SessionLineItem::query()->findOrFail((int) $attributes['session_line_item_id'])->amount(),
            'clinic_user_id' => null,
        ];
    }
}
