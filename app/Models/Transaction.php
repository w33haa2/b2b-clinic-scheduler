<?php

namespace App\Models;

use App\Enums\TransactionType;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only money movement for one session line item. Rows are written
 * only when a payment's status changes; they are never edited.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $payment_id
 * @property int $session_line_item_id
 * @property TransactionType $transaction_type_id
 * @property string $amount
 * @property int|null $clinic_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['clinic_id', 'payment_id', 'session_line_item_id', 'transaction_type_id', 'amount', 'clinic_user_id'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_type_id' => TransactionType::class,
            'amount' => 'decimal:2',
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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<SessionLineItem, $this>
     */
    public function sessionLineItem(): BelongsTo
    {
        return $this->belongsTo(SessionLineItem::class);
    }

    /**
     * The member who recorded the payment or refund.
     *
     * @return BelongsTo<ClinicUser, $this>
     */
    public function clinicUser(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class)->withTrashed();
    }
}
