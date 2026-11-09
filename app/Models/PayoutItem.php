<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\PayoutItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One billed item inside a payout, with its split snapshotted. An item can be
 * on at most one payout (session_line_item_id is unique).
 *
 * `was_pending` means the member was paid before the patient did: the clinic
 * advanced the money and is still owed it until `settled_at` is stamped by
 * App\Actions\Billing\UpdatePaymentStatus.
 *
 * @property int $id
 * @property int $payout_id
 * @property int $session_line_item_id
 * @property string $share_percentage
 * @property string $gross_amount
 * @property string $discount_amount
 * @property string $worker_amount
 * @property string $clinic_amount
 * @property bool $was_pending
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'session_line_item_id', 'share_percentage', 'gross_amount', 'discount_amount',
    'worker_amount', 'clinic_amount', 'was_pending', 'settled_at',
])]
class PayoutItem extends Model
{
    /** @use HasFactory<PayoutItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'share_percentage' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'worker_amount' => 'decimal:2',
            'clinic_amount' => 'decimal:2',
            'was_pending' => 'boolean',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    /**
     * @return BelongsTo<SessionLineItem, $this>
     */
    public function sessionLineItem(): BelongsTo
    {
        return $this->belongsTo(SessionLineItem::class);
    }

    /**
     * Money the clinic paid out before collecting it from the patient, and
     * has still not collected.
     *
     * @param  Builder<PayoutItem>  $query
     */
    #[Scope]
    protected function unsettledAdvance(Builder $query): void
    {
        $query->where('was_pending', true)->whereNull('settled_at');
    }

    /**
     * The net amount the patient owes for this item: the two sides of the split.
     */
    public function netAmount(): string
    {
        return (string) BigDecimal::of($this->worker_amount)->plus($this->clinic_amount)->toScale(2);
    }
}
