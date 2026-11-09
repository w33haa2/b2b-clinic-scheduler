<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Database\Factories\PatientFormInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A link that lets one patient fill the clinic's form in themselves, without
 * an account. Only a SHA-256 hash of the token is stored and the link works
 * once, exactly like ClinicInvitation.
 *
 * `patient_id` is set when the link updates an existing record and null when
 * it takes on a new patient.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int|null $patient_id
 * @property string|null $name
 * @property string|null $email
 * @property string $token_hash
 * @property int|null $created_by_clinic_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['patient_id', 'name', 'email', 'expires_at'])]
class PatientFormInvitation extends Model
{
    /** @use HasFactory<PatientFormInvitationFactory> */
    use HasFactory, RecordsActivity;

    /**
     * How long an intake link works.
     */
    public const VALID_FOR_DAYS = 14;

    /**
     * Get the attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ClinicUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ClinicUser::class, 'created_by_clinic_user_id')->withTrashed();
    }

    /**
     * Give the link a new token and a fresh expiry. Returns the plain token
     * for the link; it cannot be recovered later.
     */
    public function issueToken(): string
    {
        $token = Str::random(64);

        $this->forceFill([
            'token_hash' => self::hashToken($token),
            'expires_at' => now()->addDays(self::VALID_FOR_DAYS),
        ]);

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The invitation a link token belongs to, if any.
     */
    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * pending, submitted, revoked or expired.
     */
    public function status(): string
    {
        return match (true) {
            $this->submitted_at !== null => 'submitted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /**
     * Links that can still be filled in.
     *
     * @param  Builder<PatientFormInvitation>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('submitted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function activityEntity(): string
    {
        return 'patient_form_invitation';
    }

    public function activityLabel(): string
    {
        return $this->name ?? $this->email ?? $this->patient?->activityLabel() ?? "Link #{$this->id}";
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function activityAction(string $event, array $changes): string
    {
        return match (true) {
            $event === 'created' => 'patient_form_invitation.sent',
            isset($changes['revoked_at']) => 'patient_form_invitation.revoked',
            isset($changes['submitted_at']) => 'patient_form_invitation.submitted',
            default => "patient_form_invitation.{$event}",
        };
    }

    /**
     * @return list<string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'token_hash', 'expires_at'];
    }
}
