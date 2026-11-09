<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonInterface;
use Database\Factories\ClinicSessionMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A patient attending a session. What they owe and whether it is paid lives
 * on their Payment. On a day of a daily session, starts_at / ends_at is the
 * time slot the patient was plotted into (null until plotted), and
 * `customised` marks a day changed by hand, which plan changes leave alone.
 *
 * @property int $id
 * @property int $clinic_session_id
 * @property int $patient_id
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $customised
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['patient_id', 'starts_at', 'ends_at', 'customised'])]
class ClinicSessionMember extends Model
{
    /** @use HasFactory<ClinicSessionMemberFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'customised' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ClinicSession, $this>
     */
    public function clinicSession(): BelongsTo
    {
        return $this->belongsTo(ClinicSession::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * The items billed to this patient for this session.
     *
     * @return HasMany<SessionLineItem, $this>
     */
    public function sessionLineItems(): HasMany
    {
        return $this->hasMany(SessionLineItem::class);
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * The payment status, from the patient's payment.
     */
    public function paymentStatus(): PaymentStatus
    {
        return $this->payment->payment_status_id ?? PaymentStatus::Pending;
    }

    /**
     * Plotted slots of the given holder's daily-session days that overlap a
     * time range (cancelled days excluded).
     *
     * @param  Builder<ClinicSessionMember>  $query
     */
    #[Scope]
    protected function plottedOverlapping(Builder $query, int $clinicUserId, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $query->whereNotNull('starts_at')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->whereHas('clinicSession', fn (Builder $query) => $query
                ->where('clinic_user_id', $clinicUserId)
                ->whereNotNull('daily_session_id')
                ->where('status_id', '!=', SessionStatus::Cancelled));
    }

    public function activityEntity(): string
    {
        return 'session_patient';
    }

    public function activityLabel(): string
    {
        $patient = $this->patient;

        return $patient ? "{$patient->first_name} {$patient->last_name}" : "Patient #{$this->patient_id}";
    }

    public function activityClinicId(): ?int
    {
        return $this->clinicSession?->clinic_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return ['session' => $this->clinicSession?->activityLabel()];
    }

    /**
     * Plotting a patient into a time slot logs "session_patient.plotted" (or
     * "unplotted" when the slot is cleared).
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function activityAction(string $event, array $changes): string
    {
        if ($event === 'updated' && isset($changes['starts_at'])) {
            return $changes['starts_at']['new'] === null ? 'session_patient.unplotted' : 'session_patient.plotted';
        }

        return match ($event) {
            'created' => 'session_patient.added',
            'deleted' => 'session_patient.removed',
            default => "session_patient.{$event}",
        };
    }
}
