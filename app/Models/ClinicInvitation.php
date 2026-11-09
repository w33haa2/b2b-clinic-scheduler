<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Database\Factories\ClinicInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An email invited to join a clinic (as a Clinic Worker by default). The
 * invitation link carries a random token; only its SHA-256 hash is stored.
 * Accepting adds the person to the clinic, creating their account first if
 * they do not have one.
 *
 * @property int $id
 * @property int $clinic_id
 * @property string $email
 * @property int $clinic_role_id
 * @property string $token_hash
 * @property int|null $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by_user_id
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email', 'clinic_role_id', 'expires_at'])]
class ClinicInvitation extends Model
{
    /** @use HasFactory<ClinicInvitationFactory> */
    use HasFactory, RecordsActivity;

    /**
     * How long an invitation link works.
     */
    public const VALID_FOR_DAYS = 7;

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
            'accepted_at' => 'datetime',
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
     * @return BelongsTo<ClinicRole, $this>
     */
    public function clinicRole(): BelongsTo
    {
        return $this->belongsTo(ClinicRole::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * Give the invitation a new link token (and a fresh expiry). Returns the
     * plain token for the email; it cannot be recovered later.
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
     * pending, accepted, revoked or expired.
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /**
     * Invitations that can still be accepted.
     *
     * @param  Builder<ClinicInvitation>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function activityEntity(): string
    {
        return 'invitation';
    }

    public function activityLabel(): string
    {
        return $this->email;
    }

    /**
     * Sending logs "invitation.sent" and cancelling "invitation.revoked".
     * Resending and accepting are logged by InviteToClinic and
     * AcceptClinicInvitation.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function activityAction(string $event, array $changes): string
    {
        return match (true) {
            $event === 'created' => 'invitation.sent',
            isset($changes['revoked_at']) => 'invitation.revoked',
            default => "invitation.{$event}",
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
