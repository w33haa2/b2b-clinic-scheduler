<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a clinic owes one member for the sessions they facilitated between
 * period_start and period_end. The split is worked out once, when the payout
 * is generated, and snapshotted on the payout and its items.
 *
 * Like Payment, this model does not record activity by itself: the payroll
 * actions log "payout.generated", "payout.paid" and "payout.cancelled"
 * explicitly, because one save can mean very different things.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $clinic_user_id
 * @property int $number
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property PayoutStatus $payout_status_id
 * @property string $share_percentage
 * @property string $gross_amount
 * @property string $discount_total
 * @property string $worker_amount
 * @property string $clinic_amount
 * @property string $pending_amount
 * @property string|null $notes
 * @property int|null $generated_by_clinic_user_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'clinic_user_id', 'number', 'period_start', 'period_end', 'payout_status_id', 'share_percentage',
    'gross_amount', 'discount_total', 'worker_amount', 'clinic_amount', 'pending_amount',
    'notes', 'generated_by_clinic_user_id', 'paid_at',
])]
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payout_status_id' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'payout_status_id' => PayoutStatus::class,
            'share_percentage' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'worker_amount' => 'decimal:2',
            'clinic_amount' => 'decimal:2',
            'pending_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * The member being paid.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function clinicUser(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class)->withTrashed();
    }

    /**
     * The member who generated it.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class, 'generated_by_clinic_user_id')->withTrashed();
    }

    /**
     * @return HasMany<PayoutItem, $this>
     */
    public function payoutItems(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    /**
     * Payouts that still owe the member money.
     *
     * @param  Builder<Payout>  $query
     */
    #[Scope]
    protected function unpaid(Builder $query): void
    {
        $query->where('payout_status_id', PayoutStatus::Draft);
    }

    /**
     * e.g. "PAY-000014".
     */
    public function reference(): string
    {
        return 'PAY-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * e.g. "PAY-000014" — used in activity entries about this payout.
     */
    public function activityLabel(): string
    {
        return $this->reference();
    }

    /**
     * Facts every payout activity entry carries.
     *
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return [
            'member' => $this->clinicUser?->activityLabel(),
            'period' => "{$this->period_start->toDateString()} to {$this->period_end->toDateString()}",
        ];
    }
}
