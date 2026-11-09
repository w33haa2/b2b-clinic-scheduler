<?php

namespace App\Actions\Clinics;

use App\Enums\PaymentStatus;
use App\Models\ClinicSessionMember;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveSessionPatient
{
    /**
     * Remove a patient from a session along with their unpaid bill. Patients
     * who paid (or were refunded) stay, because the money trail refers to them.
     */
    public function handle(ClinicSessionMember $sessionMember): void
    {
        DB::transaction(function () use ($sessionMember): void {
            $payment = Payment::query()->whereBelongsTo($sessionMember, 'sessionMember')->lockForUpdate()->first();

            if ($payment !== null && $payment->payment_status_id !== PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'session_member' => __('A patient whose payment is :status cannot be removed from the session.', [
                        'status' => $payment->payment_status_id->code(),
                    ]),
                ]);
            }

            if ($sessionMember->sessionLineItems()->whereHas('invoiceItem')->exists()) {
                throw ValidationException::withMessages([
                    'session_member' => __('This patient has items on an invoice. Remove them from the invoice first.'),
                ]);
            }

            $sessionMember->sessionLineItems()->get()->each->delete();
            $payment?->delete();
            $sessionMember->delete();
        });
    }
}
