<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicSessionMember>
 */
class ClinicSessionMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_session_id' => ClinicSession::factory(),
            'patient_id' => fn (array $attributes) => Patient::factory()->state([
                'clinic_id' => ClinicSession::query()->findOrFail((int) $attributes['clinic_session_id'])->clinic_id,
            ]),
        ];
    }

    /**
     * Every session patient has a payment, like those added through the API.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ClinicSessionMember $sessionMember): void {
            $sessionMember->payment()->create([
                'clinic_id' => $sessionMember->clinicSession->clinic_id,
                'clinic_session_id' => $sessionMember->clinic_session_id,
                'patient_id' => $sessionMember->patient_id,
            ]);
        });
    }

    /**
     * Put the payment in the given status (without writing transactions).
     */
    public function paymentStatus(PaymentStatus $status): static
    {
        return $this->afterCreating(fn (ClinicSessionMember $sessionMember) => $sessionMember->payment()->update([
            'payment_status_id' => $status,
            'paid_at' => $status === PaymentStatus::Pending ? null : now(),
            'refunded_at' => $status === PaymentStatus::Refunded ? now() : null,
        ]));
    }
}
