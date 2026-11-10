<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Clinic;
use App\Models\Payment;
use App\Models\SessionLineItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * List payments: one per patient per session.
     *
     * `status` is `PENDING` (unpaid, session not cancelled), `PAST_DUE`
     * (unpaid and the session is over), `DONE` or `REFUNDED`. Also filter by
     * `patient_id`, `daily_session_id`, `clinic_user_id` (who holds the
     * session), the session's time (`from` / `to`) or the patient's name
     * (`search`). `meta.summary` has the count and total of each status for
     * the same filters.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['PENDING', 'PAST_DUE', 'DONE', 'REFUNDED'])],
            'patient_id' => ['sometimes', 'integer'],
            'daily_session_id' => ['sometimes', 'integer'],
            'clinic_user_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $base = fn (): HasMany => $clinic->payments()
            ->when($filters['patient_id'] ?? null, fn ($query, int|string $id) => $query->where('patient_id', $id))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->whereHas('patient', fn ($query) => $query
                ->whereLike('first_name', "%{$search}%")
                ->orWhereLike('last_name', "%{$search}%")))
            ->whereHas('clinicSession', fn (Builder $query) => $query
                ->when($filters['daily_session_id'] ?? null, fn ($query, int|string $id) => $query->where('daily_session_id', $id))
                ->when($filters['clinic_user_id'] ?? null, fn ($query, int|string $id) => $query->where('clinic_user_id', $id))
                ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('ends_at', '>', now()->parse($from)->utc()))
                ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('starts_at', '<', now()->parse($to)->utc())));

        $payments = $this->withStatus($base(), $filters['status'] ?? null)
            ->with([
                'patient',
                'clinicSession.clinicUser.user',
                'clinicSession.dailySession',
                'sessionMember.sessionLineItems.lineItem',
                'sessionMember.sessionLineItems.invoiceItem',
                'sessionMember.sessionLineItems.discount',
                'sessionMember.sessionLineItems.payoutItem',
            ])
            ->orderByDesc(DB::table('clinic_sessions')->select('starts_at')->whereColumn('clinic_sessions.id', 'payments.clinic_session_id'))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return PaymentResource::collection($payments)->additional(['meta' => ['summary' => [
            'outstanding' => $this->owed($this->withStatus($base(), 'PENDING')),
            'past_due' => $this->owed($this->withStatus($base(), 'PAST_DUE')),
            'paid' => $this->recorded($this->withStatus($base(), 'DONE')),
            'refunded' => $this->recorded($this->withStatus($base(), 'REFUNDED')),
        ]]]);
    }

    /**
     * Get a payment with its billed items and transactions.
     */
    public function show(Clinic $clinic, Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load([
            'patient',
            'clinicSession.clinicUser.user',
            'clinicSession.dailySession',
            'sessionMember.sessionLineItems.lineItem',
            'sessionMember.sessionLineItems.invoiceItem',
            'sessionMember.sessionLineItems.discount',
            'sessionMember.sessionLineItems.payoutItem',
        ]));
    }

    /**
     * @param  HasMany<Payment, Clinic>  $query
     * @return HasMany<Payment, Clinic>
     */
    private function withStatus(HasMany $query, ?string $status): HasMany
    {
        return match ($status) {
            'PENDING' => $query->outstanding(),
            'PAST_DUE' => $query->pastDue(),
            'DONE' => $query->where('payment_status_id', PaymentStatus::Done),
            'REFUNDED' => $query->where('payment_status_id', PaymentStatus::Refunded),
            default => $query,
        };
    }

    /**
     * Count and total of unpaid payments (the sum of their billed items).
     *
     * @param  HasMany<Payment, Clinic>  $query
     * @return array{count: int, total: string}
     */
    private function owed(HasMany $query): array
    {
        $total = SessionLineItem::query()
            ->whereIn('clinic_session_member_id', (clone $query)->select('clinic_session_member_id'))
            ->sum(DB::raw('quantity * unit_price'));

        return ['count' => (clone $query)->count(), 'total' => $this->money($total)];
    }

    /**
     * Count and total of paid or refunded payments (the amounts recorded).
     *
     * @param  HasMany<Payment, Clinic>  $query
     * @return array{count: int, total: string}
     */
    private function recorded(HasMany $query): array
    {
        return ['count' => (clone $query)->count(), 'total' => $this->money((clone $query)->sum('amount'))];
    }

    private function money(mixed $sum): string
    {
        return (string) BigDecimal::of((string) ($sum ?: 0))->toScale(2, RoundingMode::HalfUp);
    }
}
