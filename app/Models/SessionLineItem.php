<?php

namespace App\Models;

use App\Enums\DiscountTarget;
use App\Models\Concerns\RecordsActivity;
use Brick\Math\BigDecimal;
use Database\Factories\SessionLineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A catalog item billed to one patient in one session. The unit price is
 * copied from the catalog when the item is added, so later price changes
 * do not alter past bills. A discount works the same way: the rule is
 * referenced, but what it took off is snapshotted in `discount_amount`.
 *
 * @property int $id
 * @property int $clinic_session_member_id
 * @property int $line_item_id
 * @property int $quantity
 * @property string $unit_price
 * @property int|null $discount_id
 * @property string $discount_amount
 * @property DiscountTarget|null $discount_target_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['line_item_id', 'quantity', 'unit_price', 'discount_id', 'discount_amount', 'discount_target_id'])]
class SessionLineItem extends Model
{
    /** @use HasFactory<SessionLineItemFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'discount_target_id' => DiscountTarget::class,
        ];
    }

    /**
     * @return BelongsTo<ClinicSessionMember, $this>
     */
    public function sessionMember(): BelongsTo
    {
        return $this->belongsTo(ClinicSessionMember::class, 'clinic_session_member_id');
    }

    /**
     * @return BelongsTo<LineItem, $this>
     */
    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(LineItem::class)->withTrashed();
    }

    /**
     * The discount applied when the item was billed, if any.
     *
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class)->withTrashed();
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasOne<InvoiceItem, $this>
     */
    public function invoiceItem(): HasOne
    {
        return $this->hasOne(InvoiceItem::class);
    }

    /**
     * The payout this item was paid out on, if any.
     *
     * @return HasOne<PayoutItem, $this>
     */
    public function payoutItem(): HasOne
    {
        return $this->hasOne(PayoutItem::class);
    }

    /**
     * Before any discount: quantity × unit price.
     */
    public function grossAmount(): string
    {
        return (string) BigDecimal::of($this->unit_price)->multipliedBy($this->quantity)->toScale(2);
    }

    /**
     * What the patient owes: the gross less the discount. This is the amount
     * payments, transactions and invoices are built from.
     */
    public function amount(): string
    {
        return (string) BigDecimal::of($this->grossAmount())->minus($this->discount_amount)->toScale(2);
    }

    public function activityEntity(): string
    {
        return 'billed_item';
    }

    public function activityLabel(): string
    {
        return ($this->lineItem->name ?? 'Item')." × {$this->quantity}";
    }

    public function activityClinicId(): ?int
    {
        return $this->sessionMember?->clinicSession?->clinic_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return [
            'patient' => $this->sessionMember?->activityLabel(),
            'session' => $this->sessionMember?->clinicSession?->activityLabel(),
        ];
    }
}
