<?php

namespace Database\Factories;

use App\Models\ClinicSessionMember;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Session patients get their payment from ClinicSessionMemberFactory; use
 * this only for a payment row on its own.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'clinic_session_id' => fn (array $attributes) => ClinicSessionMember::query()->findOrFail((int) $attributes['clinic_session_member_id'])->clinic_session_id,
            'clinic_id' => fn (array $attributes) => ClinicSessionMember::query()->findOrFail((int) $attributes['clinic_session_member_id'])->clinicSession->clinic_id,
            'patient_id' => fn (array $attributes) => ClinicSessionMember::query()->findOrFail((int) $attributes['clinic_session_member_id'])->patient_id,
        ];
    }
}
