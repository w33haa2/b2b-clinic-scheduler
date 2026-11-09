<?php

namespace App\Actions\Clinics;

use App\Enums\PaymentStatus;
use App\Models\ClinicSessionMember;
use App\Models\LineItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDayPatient
{
    public function __construct(private ScheduleDailySessionDays $scheduleDays) {}

    /**
     * Change one patient on one day of a daily session, for that day only:
     * their slot (null to unplot) and/or the service billed. The day is
     * marked as changed by hand, so plan changes leave it alone. `reset`
     * puts the patient back on their regular plan for the day instead.
     *
     * @param  array{slot?: array{0: CarbonImmutable|null, 1: CarbonImmutable|null}, line_item_id?: int, unit_price?: string|null, reset?: bool}  $changes
     */
    public function handle(ClinicSessionMember $sessionMember, array $changes): ClinicSessionMember
    {
        return DB::transaction(function () use ($sessionMember, $changes): ClinicSessionMember {
            $day = $sessionMember->clinicSession;

            if (! empty($changes['reset'])) {
                $plan = $day->dailySession->dailySessionPatients()->where('patient_id', $sessionMember->patient_id)->first();

                if ($plan === null) {
                    throw ValidationException::withMessages([
                        'reset' => __('This patient is not enrolled, so there is no regular plan to go back to.'),
                    ]);
                }

                $this->scheduleDays->applyPlan($sessionMember, $plan, $day);

                return $sessionMember;
            }

            if (array_key_exists('line_item_id', $changes)) {
                $this->changeService($sessionMember, (int) $changes['line_item_id'], $changes['unit_price'] ?? null);
            }

            if (array_key_exists('slot', $changes)) {
                [$startsAt, $endsAt] = $changes['slot'];
                $sessionMember->fill(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
            }

            $sessionMember->fill(['customised' => true])->save();

            return $sessionMember;
        });
    }

    /**
     * Swap the service billed for the day, while it is still unpaid.
     */
    private function changeService(ClinicSessionMember $sessionMember, int $lineItemId, ?string $unitPrice): void
    {
        if ($sessionMember->paymentStatus() !== PaymentStatus::Pending) {
            throw ValidationException::withMessages([
                'line_item_id' => __('The service cannot change once the day is :status.', ['status' => $sessionMember->paymentStatus()->code()]),
            ]);
        }

        $unitPrice ??= LineItem::query()->findOrFail($lineItemId)->price;
        $item = $sessionMember->sessionLineItems()->orderBy('id')->first();

        if ($item === null) {
            $sessionMember->sessionLineItems()->create(['line_item_id' => $lineItemId, 'quantity' => 1, 'unit_price' => $unitPrice]);

            return;
        }

        $item->update(['line_item_id' => $lineItemId, 'unit_price' => $unitPrice]);
    }
}
