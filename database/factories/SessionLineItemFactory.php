<?php

namespace Database\Factories;

use App\Models\ClinicSessionMember;
use App\Models\LineItem;
use App\Models\SessionLineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionLineItem>
 */
class SessionLineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_session_member_id' => ClinicSessionMember::factory(),
            'line_item_id' => fn (array $attributes) => LineItem::factory()->state([
                'clinic_id' => ClinicSessionMember::query()->findOrFail((int) $attributes['clinic_session_member_id'])->clinicSession->clinic_id,
            ]),
            'quantity' => 1,
            'unit_price' => fn (array $attributes) => LineItem::query()->findOrFail((int) $attributes['line_item_id'])->price,
        ];
    }
}
