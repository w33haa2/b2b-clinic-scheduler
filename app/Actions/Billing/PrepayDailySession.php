<?php

namespace App\Actions\Billing;

use App\Actions\Clinics\AddSessionPatient;
use App\Actions\Clinics\ScheduleDailySessionDays;
use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\ClinicUser;
use App\Models\DailySession;
use App\Models\Payment;
use App\Support\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepayDailySession
{
    public function __construct(
        private ScheduleDailySessionDays $scheduleDays,
        private AddSessionPatient $addSessionPatient,
        private UpdatePaymentStatus $updatePaymentStatus,
    ) {}

    /**
     * Pay in advance for days of a daily session. Days not scheduled yet are
     * created, then each day's payment for the patient is recorded as DONE
     * (writing its transactions). All or nothing: if any day cannot be paid,
     * none is.
     *
     * @param  list<string>  $dates  Dates (Y-m-d) in the clinic's timezone.
     * @return Collection<int, Payment>
     */
    public function handle(DailySession $dailySession, int $patientId, array $dates, ClinicUser $recordedBy): Collection
    {
        return DB::transaction(function () use ($dailySession, $patientId, $dates, $recordedBy): Collection {
            $dates = collect($dates)
                ->map(fn (string $date): CarbonImmutable => CarbonImmutable::parse($date))
                ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
                ->sortBy(fn (CarbonImmutable $date): string => $date->toDateString())
                ->values();

            $this->scheduleDays->handle($dailySession, $dates);

            $days = $dailySession->clinicSessions()
                ->whereDate('occurs_on', '>=', $dates->first()->toDateString())
                ->whereDate('occurs_on', '<=', $dates->last()->toDateString())
                ->orderBy('starts_at')
                ->get()
                ->filter(fn (ClinicSession $day): bool => $dates->contains(fn (CarbonImmutable $date): bool => $date->isSameDay($day->occurs_on)))
                ->values();

            $cancelled = $days->filter(fn (ClinicSession $day): bool => $day->status_id === SessionStatus::Cancelled);

            if ($cancelled->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'dates' => __(':days is cancelled and cannot be paid.', ['days' => $this->scheduleDays->describeDays($cancelled)]),
                ]);
            }

            $payments = $days->map(function (ClinicSession $day) use ($dailySession, $patientId): Payment {
                $day->setRelation('dailySession', $dailySession);
                $sessionMember = $day->sessionMembers()->where('patient_id', $patientId)->with('payment')->first()
                    ?? ActivityLogger::quietly(fn () => $this->addSessionPatient->handle($day, $patientId));

                return $sessionMember->payment;
            });

            $alreadyPaid = $days->filter(fn (ClinicSession $day, int $index): bool => $payments[$index]->payment_status_id !== PaymentStatus::Pending);

            if ($alreadyPaid->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'dates' => __(':days already has a payment recorded.', ['days' => $this->scheduleDays->describeDays($alreadyPaid)]),
                ]);
            }

            return $payments->map(fn (Payment $payment): Payment => $this->updatePaymentStatus->handle(
                $payment,
                PaymentStatus::Done,
                $recordedBy,
                ['prepaid' => true],
            ));
        });
    }
}
