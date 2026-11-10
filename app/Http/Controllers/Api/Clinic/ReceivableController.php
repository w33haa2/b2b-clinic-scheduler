<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutItemResource;
use App\Models\Clinic;
use App\Models\PayoutItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ReceivableController extends Controller
{
    /**
     * Money the clinic paid out before it collected it. Clinic Admin only.
     *
     * Every row is a billed item that went into a payout while the
     * patient's payment was still pending: the member was paid, the clinic
     * is still owed. A row settles itself when the payment is recorded, and
     * opens again if that payment is refunded.
     *
     * Filter with `state` (`UNSETTLED`, the default, or `SETTLED` or `ALL`),
     * `clinic_user_id`, `patient_id` and the payout period (`from` / `to`).
     * `meta.summary` totals the advanced, settled and outstanding money, and
     * `meta.by_member` breaks the outstanding total down per member.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'state' => ['sometimes', 'string', Rule::in(['UNSETTLED', 'SETTLED', 'ALL'])],
            'clinic_user_id' => ['sometimes', 'integer'],
            'patient_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $base = fn (): Builder => PayoutItem::query()
            ->where('was_pending', true)
            ->whereHas('payout', fn (Builder $query) => $query
                ->where('clinic_id', $clinic->id)
                ->when($filters['clinic_user_id'] ?? null, fn ($query, int|string $id) => $query->where('clinic_user_id', $id))
                ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('period_end', '>=', $from))
                ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('period_start', '<=', $to)))
            ->when($filters['patient_id'] ?? null, fn ($query, int|string $id) => $query
                ->whereHas('sessionLineItem.sessionMember', fn (Builder $query) => $query->where('patient_id', $id)));

        $receivables = $this->withState($base(), $filters['state'] ?? 'UNSETTLED')
            ->with([
                'payout.clinicUser.user',
                'sessionLineItem.lineItem',
                'sessionLineItem.sessionMember.patient',
                'sessionLineItem.sessionMember.payment',
                'sessionLineItem.sessionMember.clinicSession',
            ])
            // Still outstanding first. Written out rather than ordering by
            // the nullable settled_at, because MySQL sorts NULLs first and
            // PostgreSQL sorts them last.
            ->orderByRaw('settled_at is null desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return PayoutItemResource::collection($receivables)->additional(['meta' => [
            'summary' => [
                'advanced' => $this->totalOf($base()),
                'settled' => $this->totalOf($this->withState($base(), 'SETTLED')),
                'outstanding' => $this->totalOf($this->withState($base(), 'UNSETTLED')),
            ],
            'by_member' => $this->byMember($this->withState($base(), 'UNSETTLED')),
        ]]);
    }

    /**
     * @param  Builder<PayoutItem>  $query
     * @return Builder<PayoutItem>
     */
    private function withState(Builder $query, string $state): Builder
    {
        return match ($state) {
            'UNSETTLED' => $query->whereNull('settled_at'),
            'SETTLED' => $query->whereNotNull('settled_at'),
            default => $query,
        };
    }

    /**
     * @param  Builder<PayoutItem>  $query
     * @return array{count: int, total: string}
     */
    private function totalOf(Builder $query): array
    {
        return ['count' => (clone $query)->count(), 'total' => $this->money((clone $query)->sum('worker_amount'))];
    }

    /**
     * Outstanding money per member, biggest first.
     *
     * @param  Builder<PayoutItem>  $query
     * @return list<array{clinic_user_id: int, name: string, count: int, total: string}>
     */
    private function byMember(Builder $query): array
    {
        return array_values((clone $query)
            ->with('payout.clinicUser.user')
            ->get()
            ->groupBy(fn (PayoutItem $item): int => $item->payout->clinic_user_id)
            ->map(fn ($items, int|string $clinicUserId): array => [
                'clinic_user_id' => (int) $clinicUserId,
                'name' => $items->first()->payout->clinicUser?->activityLabel() ?? "Member #{$clinicUserId}",
                'count' => $items->count(),
                'total' => (string) $items->reduce(
                    fn (BigDecimal $total, PayoutItem $item): BigDecimal => $total->plus($item->worker_amount),
                    BigDecimal::zero(),
                )->toScale(2),
            ])
            ->sortByDesc(fn (array $row): string => $row['total'])
            ->all());
    }

    private function money(mixed $sum): string
    {
        return (string) BigDecimal::of((string) ($sum ?: 0))->toScale(2, RoundingMode::HalfUp);
    }
}
