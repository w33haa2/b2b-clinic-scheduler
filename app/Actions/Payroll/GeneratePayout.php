<?php

namespace App\Actions\Payroll;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\Payout;
use App\Support\ActivityLogger;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GeneratePayout
{
    public function __construct(private CalculatePayout $calculate) {}

    /**
     * Create a DRAFT payout for a member's work in a period.
     *
     * The split is worked out again inside the transaction, so the payout
     * always matches the data at the moment it is saved. Every item it covers
     * gets a payout_items row, and that row is what stops the item being paid
     * out twice.
     *
     * @param  list<int>  $excludedSessionLineItemIds  Items the admin took out of the run.
     */
    public function handle(
        Clinic $clinic,
        ClinicUser $member,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ClinicUser $generatedBy,
        array $excludedSessionLineItemIds = [],
        ?string $notes = null,
    ): Payout {
        return DB::transaction(function () use ($clinic, $member, $periodStart, $periodEnd, $generatedBy, $excludedSessionLineItemIds, $notes): Payout {
            // Serialise payout numbering within the clinic.
            Clinic::query()->whereKey($clinic->id)->lockForUpdate()->first();

            $preview = $this->calculate->handle($clinic, $member, $periodStart, $periodEnd);
            /** @var Collection<int, array<string, mixed>> $lines */
            $lines = $preview['lines']->reject(
                fn (array $line): bool => in_array($line['session_line_item_id'], $excludedSessionLineItemIds, true),
            )->values();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'period' => __('There is nothing to pay out for :member between :from and :to.', [
                        'member' => $member->activityLabel(),
                        'from' => $periodStart->toDateString(),
                        'to' => $periodEnd->toDateString(),
                    ]),
                ]);
            }

            $totals = $this->totals($lines);

            $payout = $clinic->payouts()->create([
                'clinic_user_id' => $member->id,
                'number' => (int) $clinic->payouts()->max('number') + 1,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'share_percentage' => $preview['share_percentage'],
                'gross_amount' => $totals['gross_amount'],
                'discount_total' => $totals['discount_total'],
                'worker_amount' => $totals['worker_amount'],
                'clinic_amount' => $totals['clinic_amount'],
                'pending_amount' => $totals['pending_amount'],
                'notes' => $notes,
                'generated_by_clinic_user_id' => $generatedBy->id,
            ]);

            $payout->payoutItems()->createMany($lines->map(fn (array $line): array => [
                'session_line_item_id' => $line['session_line_item_id'],
                'share_percentage' => $preview['share_percentage'],
                'gross_amount' => $line['gross_amount'],
                'discount_amount' => $line['discount_amount'],
                'worker_amount' => $line['worker_amount'],
                'clinic_amount' => $line['clinic_amount'],
                'was_pending' => $line['is_pending'],
            ])->all());

            app(ActivityLogger::class)->log(
                action: 'payout.generated',
                subject: $payout,
                clinicId: $clinic->id,
                label: $payout->reference(),
                context: [
                    ...$payout->activityContext(),
                    'items' => $lines->count(),
                    'amount' => $totals['worker_amount'],
                    'pending' => $totals['pending_amount'],
                ],
            );

            return $payout;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{gross_amount: string, discount_total: string, worker_amount: string, clinic_amount: string, pending_amount: string}
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
            'gross_amount' => $sum('gross_amount'),
            'discount_total' => $sum('discount_amount'),
            'worker_amount' => $sum('worker_amount'),
            'clinic_amount' => $sum('clinic_amount'),
            'pending_amount' => $sum('worker_amount', true),
        ];
    }
}
