<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Payroll\GeneratePayout;
use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PayoutRequest;
use App\Http\Resources\PayoutResource;
use App\Models\Clinic;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Support\ActivityLogger;
use App\Support\ClinicContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayoutController extends Controller
{
    /**
     * List payouts, newest period first.
     *
     * Filter by `status` (`DRAFT`, `PAID`, `CANCELLED`), `clinic_user_id`
     * and the period (`from` / `to`). A member who is not a Clinic Admin
     * only ever sees their own payouts. `meta.summary` has the count and
     * total of each status, plus the money advanced against patients who
     * have still not paid.
     */
    public function index(Request $request, Clinic $clinic, ClinicContext $context): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(PayoutStatus::codes())],
            'clinic_user_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $member = $context->member();

        $base = fn (): HasMany => $clinic->payouts()
            ->unless($member->isAdmin(), fn ($query) => $query->where('clinic_user_id', $member->id))
            ->when($filters['clinic_user_id'] ?? null, fn ($query, int|string $id) => $query->where('clinic_user_id', $id))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('period_end', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('period_start', '<=', $to));

        $payouts = $base()
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('payout_status_id', PayoutStatus::fromCode($status)))
            ->with(['clinicUser.user', 'generatedBy.user'])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return PayoutResource::collection($payouts)->additional(['meta' => ['summary' => [
            'draft' => $this->totalOf($base(), PayoutStatus::Draft),
            'paid' => $this->totalOf($base(), PayoutStatus::Paid),
            'cancelled' => $this->totalOf($base(), PayoutStatus::Cancelled),
            'unsettled' => $this->unsettled($base()),
        ]]]);
    }

    /**
     * Generate a payout for a member's work in a period.
     *
     * The split is worked out from the clinic's payout settings (and the
     * member's own cut, if they have one) and saved on the payout, so later
     * configuration changes never move it. Every item it covers is locked to
     * this payout and cannot be paid out again unless the payout is
     * cancelled. Send `exclude_session_line_item_ids` to leave items out.
     */
    public function store(PayoutRequest $request, Clinic $clinic, GeneratePayout $generatePayout, ClinicContext $context): PayoutResource
    {
        $payout = $generatePayout->handle(
            clinic: $clinic,
            member: $request->member(),
            periodStart: $request->periodStart(),
            periodEnd: $request->periodEnd(),
            generatedBy: $context->member(),
            excludedSessionLineItemIds: $request->excludedSessionLineItemIds(),
            notes: $request->validated('notes'),
        );

        return new PayoutResource($payout->load($this->detailRelations()));
    }

    /**
     * Get a payout with every item it covers.
     */
    public function show(Clinic $clinic, Payout $payout, ClinicContext $context): PayoutResource
    {
        $this->ensureVisibleTo($payout, $context);

        return new PayoutResource($payout->load($this->detailRelations()));
    }

    /**
     * Delete a payout and release its items. Only while it is a draft; a
     * payout that has been paid is cancelled instead.
     */
    public function destroy(Clinic $clinic, Payout $payout, ClinicContext $context): Response
    {
        $this->ensureVisibleTo($payout, $context);

        if ($payout->payout_status_id !== PayoutStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft payout can be deleted. Cancel this one instead.'),
            ]);
        }

        app(ActivityLogger::class)->log(
            action: 'payout.deleted',
            subject: $payout,
            clinicId: $payout->clinic_id,
            label: $payout->reference(),
            context: $payout->activityContext(),
        );

        $payout->delete();

        return response()->noContent();
    }

    /**
     * A member who is not a Clinic Admin only sees their own payouts.
     */
    private function ensureVisibleTo(Payout $payout, ClinicContext $context): void
    {
        $member = $context->member();

        abort_unless($member->isAdmin() || $payout->clinic_user_id === $member->id, 404);
    }

    /**
     * @return list<string>
     */
    private function detailRelations(): array
    {
        return [
            'clinicUser.user',
            'generatedBy.user',
            'payoutItems.sessionLineItem.lineItem',
            'payoutItems.sessionLineItem.sessionMember.patient',
            'payoutItems.sessionLineItem.sessionMember.payment',
            'payoutItems.sessionLineItem.sessionMember.clinicSession',
        ];
    }

    /**
     * Count and total owed for one status.
     *
     * @param  HasMany<Payout, Clinic>  $query
     * @return array{count: int, total: string}
     */
    private function totalOf(HasMany $query, PayoutStatus $status): array
    {
        $scoped = $query->where('payout_status_id', $status);

        return ['count' => (clone $scoped)->count(), 'total' => $this->money((clone $scoped)->sum('worker_amount'))];
    }

    /**
     * Money paid out before the patient paid, and still not collected.
     *
     * @param  HasMany<Payout, Clinic>  $query
     * @return array{count: int, total: string}
     */
    private function unsettled(HasMany $query): array
    {
        $items = PayoutItem::query()
            ->unsettledAdvance()
            ->whereIn('payout_id', (clone $query)->select('payouts.id'));

        return ['count' => (clone $items)->count(), 'total' => $this->money((clone $items)->sum('worker_amount'))];
    }

    private function money(mixed $sum): string
    {
        return (string) BigDecimal::of((string) ($sum ?: 0))->toScale(2, RoundingMode::HalfUp);
    }
}
