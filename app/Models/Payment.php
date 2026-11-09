<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use Brick\Math\BigDecimal;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What one patient owes for one session, and whether it is paid. Every
 * session patient has exactly one payment, created with them. Its status is
 * the only place payment state lives; changing it writes transactions
 * (App\Actions\Billing\UpdatePaymentStatus).
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $clinic_session_id
 * @property int $clinic_session_member_id
 * @property int $patient_id
 * @property PaymentStatus $payment_status_id
 * @property string|null $amount
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['clinic_id', 'clinic_session_id', 'patient_id', 'payment_status_id', 'amount', 'paid_at', 'refunded_at'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payment_status_id' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_status_id' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
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
     * @return BelongsTo<ClinicSession, $this>
     */
    public function clinicSession(): BelongsTo
    {
        return $this->belongsTo(ClinicSession::class);
    }

    /**
     * @return BelongsTo<ClinicSessionMember, $this>
     */
    public function sessionMember(): BelongsTo
    {
        return $this->belongsTo(ClinicSessionMember::class, 'clinic_session_member_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * The amount recorded when paid, or what the billed items add up to while unpaid.
     */
    public function amountDue(): string
    {
        if ($this->amount !== null) {
            return $this->amount;
        }

        return (string) $this->sessionMember->sessionLineItems
            ->reduce(fn (BigDecimal $total, SessionLineItem $item): BigDecimal => $total->plus($item->amount()), BigDecimal::zero())
            ->toScale(2);
    }

    /**
     * Unpaid although the session is over (and was not cancelled).
     */
    public function isPastDue(): bool
    {
        return $this->payment_status_id === PaymentStatus::Pending
            && $this->clinicSession->status_id !== SessionStatus::Cancelled
            && $this->clinicSession->ends_at->isPast();
    }

    /**
     * Unpaid payments for sessions that still count (not cancelled).
     *
     * @param  Builder<Payment>  $query
     */
    #[Scope]
    protected function outstanding(Builder $query): void
    {
        $query->where('payment_status_id', PaymentStatus::Pending)
            ->whereHas('clinicSession', fn (Builder $query) => $query->where('status_id', '!=', SessionStatus::Cancelled));
    }

    /**
     * Outstanding payments whose session has already ended.
     *
     * @param  Builder<Payment>  $query
     */
    #[Scope]
    protected function pastDue(Builder $query): void
    {
        $query->where('payment_status_id', PaymentStatus::Pending)
            ->whereHas('clinicSession', fn (Builder $query) => $query
                ->where('status_id', '!=', SessionStatus::Cancelled)
                ->where('ends_at', '<', now()));
    }

    /**
     * e.g. "Ana Reyes" — used in activity entries about this payment.
     */
    public function activityLabel(): string
    {
        $patient = $this->patient;

        return $patient ? "{$patient->first_name} {$patient->last_name}" : "Patient #{$this->patient_id}";
    }
}
