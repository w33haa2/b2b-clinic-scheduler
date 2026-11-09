<?php

namespace App\Actions\Clinics;

use App\Enums\PaymentStatus;
use App\Models\DailySession;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteDailySession
{
    public function __construct(private ScheduleDailySessionDays $scheduleDays) {}

    /**
     * Delete a daily session with all its days and unpaid bills. Only
     * possible while nobody has paid for any day; otherwise end it instead
     * (set `ends_on`), which keeps the history.
     */
    public function handle(DailySession $dailySession): void
    {
        DB::transaction(function () use ($dailySession): void {
            $hasPayments = Payment::query()
                ->whereHas('clinicSession', fn ($query) => $query->whereBelongsTo($dailySession))
                ->where('payment_status_id', '!=', PaymentStatus::Pending)
                ->exists();

            if ($hasPayments) {
                throw ValidationException::withMessages([
                    'daily_session' => __('Patients have paid for days of this daily session. Set an end date instead of deleting it.'),
                ]);
            }

            $this->scheduleDays->remove($dailySession->clinicSessions()->get());

            $dailySession->delete();
        });
    }
}
