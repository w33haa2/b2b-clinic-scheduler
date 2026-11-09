<?php

namespace App\Actions\Payroll;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\Attendance;
use App\Models\Clinic;
use App\Models\ClinicPayoutSetting;
use App\Models\ClinicUser;
use App\Models\SessionLineItem;
use App\Support\RevenueSplit;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Works out what a clinic owes one member for a period, without writing
 * anything. The Payouts page previews with this, and GeneratePayout runs it
 * again inside its transaction so the saved payout always matches the data
 * at the moment it is created.
 *
 * An item earns the member money when all of this holds:
 *
 * - the session is theirs (clinic_sessions.clinic_user_id)
 * - the session started inside the period (dates in the clinic's timezone)
 * - the session is not cancelled and is over
 * - the payment was not refunded
 * - the item is not already on a payout
 * - the clinic does not require attendance, or the member was present or
 *   late on that day
 */
class CalculatePayout
{
    /**
     * @phpstan-type PayoutLine array{
     *     session_line_item_id: int,
     *     clinic_session_id: int,
     *     session: string,
     *     occurred_on: string,
     *     patient: string,
     *     service: string,
     *     quantity: int,
     *     unit_price: string,
     *     gross_amount: string,
     *     discount_amount: string,
     *     discount: string|null,
     *     discount_target: string|null,
     *     worker_amount: string,
     *     clinic_amount: string,
     *     payment_status: string,
     *     is_pending: bool,
     * }
     *
     * @return array{
     *     share_percentage: string,
     *     settings: ClinicPayoutSetting,
     *     lines: Collection<int, array<string, mixed>>,
     *     totals: array{items: int, gross_amount: string, discount_total: string, worker_amount: string, clinic_amount: string, pending_amount: string},
     *     attendance: array{worked: int, not_worked: int, unrecorded: int},
     *     skipped: array{refunded: int, unpaid: int, not_worked: int},
     * }
     */
    public function handle(Clinic $clinic, ClinicUser $member, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $settings = $clinic->payoutSettings();
        $share = $this->shareFor($member, $settings);
        $workedDays = $this->workedDays($member, $periodStart, $periodEnd);
        $timezone = $this->timezone($clinic);

        $lines = collect();
        $skipped = ['refunded' => 0, 'unpaid' => 0, 'not_worked' => 0];

        foreach ($this->eligibleItems($clinic, $member, $periodStart, $periodEnd) as $sessionLineItem) {
            $sessionMember = $sessionLineItem->sessionMember;
            $session = $sessionMember->clinicSession;
            $status = $sessionMember->paymentStatus();
            $occurredOn = CarbonImmutable::instance($session->starts_at)->setTimezone($timezone)->toDateString();

            if ($status === PaymentStatus::Refunded) {
                $skipped['refunded']++;

                continue;
            }

            if ($status === PaymentStatus::Pending && ! $settings->include_pending_payments) {
                $skipped['unpaid']++;

                continue;
            }

            if ($settings->require_attendance && ! in_array($occurredOn, $workedDays, true)) {
                $skipped['not_worked']++;

                continue;
            }

            $split = RevenueSplit::forItem($sessionLineItem, $share);

            $lines->push([
                'session_line_item_id' => $sessionLineItem->id,
                'clinic_session_id' => $session->id,
                'session' => $session->activityLabel(),
                'occurred_on' => $occurredOn,
                'patient' => $sessionMember->activityLabel(),
                'service' => $sessionLineItem->lineItem->name ?? 'Item',
                'quantity' => $sessionLineItem->quantity,
                'unit_price' => $sessionLineItem->unit_price,
                'gross_amount' => $split->gross,
                'discount_amount' => $split->discount,
                'discount' => $sessionLineItem->discount?->name,
                'discount_target' => $sessionLineItem->discount_target_id?->code(),
                'worker_amount' => $split->worker,
                'clinic_amount' => $split->clinic,
                'payment_status' => $status->code(),
                'is_pending' => $status === PaymentStatus::Pending,
            ]);
        }

        return [
            'share_percentage' => $share,
            'settings' => $settings,
            'lines' => $lines,
            'totals' => $this->totals($lines),
            'attendance' => $this->attendanceSummary($member, $periodStart, $periodEnd),
            'skipped' => $skipped,
        ];
    }

    /**
     * The member's own cut, falling back to the clinic's default.
     */
    public function shareFor(ClinicUser $member, ClinicPayoutSetting $settings): string
    {
        return $member->payout_share_percentage ?? $settings->worker_share_percentage;
    }

    /**
     * Billed items the member could be paid for, before the payment and
     * attendance rules are applied.
     *
     * @return Collection<int, SessionLineItem>
     */
    private function eligibleItems(Clinic $clinic, ClinicUser $member, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Collection
    {
        return SessionLineItem::query()
            ->whereHas('sessionMember.clinicSession', fn (Builder $query) => $query
                ->where('clinic_id', $clinic->id)
                ->where('clinic_user_id', $member->id)
                ->where('status_id', '!=', SessionStatus::Cancelled)
                ->where('ends_at', '<', now())
                ->whereBetween('starts_at', [
                    $this->startOfDay($clinic, $periodStart),
                    $this->endOfDay($clinic, $periodEnd),
                ]))
            ->whereDoesntHave('payoutItem')
            ->with([
                'lineItem',
                'discount',
                'sessionMember.patient',
                'sessionMember.payment',
                'sessionMember.clinicSession',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * The dates in the period the member was present or late, as "Y-m-d".
     *
     * @return list<string>
     */
    private function workedDays(ClinicUser $member, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        return array_values($member->attendances()
            ->worked()
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->pluck('work_date')
            ->map(fn (CarbonImmutable $date): string => $date->toDateString())
            ->all());
    }

    /**
     * How many days of the period were worked, missed, or never recorded.
     *
     * @return array{worked: int, not_worked: int, unrecorded: int}
     */
    private function attendanceSummary(ClinicUser $member, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        /** @var Collection<int, Attendance> $records */
        $records = $member->attendances()
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->get(['attendance_status_id']);

        $worked = $records->filter(fn (Attendance $attendance): bool => $attendance->attendance_status_id->countsAsWorked())->count();
        $days = (int) $periodStart->startOfDay()->diffInDays($periodEnd->startOfDay()) + 1;

        return [
            'worked' => $worked,
            'not_worked' => $records->count() - $worked,
            'unrecorded' => max(0, $days - $records->count()),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{items: int, gross_amount: string, discount_total: string, worker_amount: string, clinic_amount: string, pending_amount: string}
     */
    private function totals(Collection $lines): array
    {
        $sum = fn (string $key, ?bool $pendingOnly = null): string => (string) $lines
            ->when($pendingOnly !== null, fn (Collection $lines) => $lines->where('is_pending', $pendingOnly))
            ->reduce(
                fn (BigDecimal $total, array $line): BigDecimal => $total->plus($line[$key]),
                BigDecimal::zero(),
            )->toScale(2);

        return [
            'items' => $lines->count(),
            'gross_amount' => $sum('gross_amount'),
            'discount_total' => $sum('discount_amount'),
            'worker_amount' => $sum('worker_amount'),
            'clinic_amount' => $sum('clinic_amount'),
            'pending_amount' => $sum('worker_amount', true),
        ];
    }

    private function startOfDay(Clinic $clinic, CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), $this->timezone($clinic))->startOfDay()->utc();
    }

    private function endOfDay(Clinic $clinic, CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), $this->timezone($clinic))->endOfDay()->utc();
    }

    /**
     * The clinic's timezone. A clinic created without one falls back to UTC,
     * the same way DailySession::timezone() does.
     */
    private function timezone(Clinic $clinic): string
    {
        return $clinic->timezone ?? 'UTC';
    }
}
