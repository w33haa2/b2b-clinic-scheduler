<?php

namespace App\Models;

use App\Enums\ClinicRoleKey;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\ClinicUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A user's membership in a clinic. Removing a member soft-deletes the
 * membership so their past sessions and transactions keep their owner.
 *
 * @property int $id
 * @property int $clinic_id
 * @property int $user_id
 * @property string|null $payout_share_percentage
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['clinic_id', 'user_id', 'payout_share_percentage'])]
class ClinicUser extends Model
{
    /** @use HasFactory<ClinicUserFactory> */
    use HasFactory, RecordsActivity, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payout_share_percentage' => 'decimal:2',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<ClinicRole, $this>
     */
    public function clinicRoles(): BelongsToMany
    {
        return $this->belongsToMany(ClinicRole::class, 'clinic_user_role');
    }

    /**
     * Sessions this member is assigned to hold.
     *
     * @return HasMany<ClinicSession, $this>
     */
    public function clinicSessions(): HasMany
    {
        return $this->hasMany(ClinicSession::class);
    }

    /**
     * Schedule filters this member saved.
     *
     * @return HasMany<CalendarFilter, $this>
     */
    public function calendarFilters(): HasMany
    {
        return $this->hasMany(CalendarFilter::class);
    }

    /**
     * Working days recorded for this member.
     *
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Personal discounts this member created.
     *
     * @return HasMany<Discount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /**
     * Payouts generated for this member.
     *
     * @return HasMany<Payout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * Determine whether the member holds the clinic's built-in admin role.
     */
    public function isAdmin(): bool
    {
        return $this->clinicRoles->contains(fn (ClinicRole $role): bool => $role->key === ClinicRoleKey::Admin);
    }

    /**
     * The names of every permission the member holds through their roles.
     *
     * @return Collection<int, string>
     */
    public function permissionNames(): Collection
    {
        if ($this->isAdmin()) {
            return ClinicPermission::query()->orderBy('name')->pluck('name');
        }

        $this->loadMissing('clinicRoles.clinicPermissions');

        return $this->clinicRoles
            ->flatMap(fn (ClinicRole $role) => $role->clinicPermissions->pluck('name'))
            ->unique()
            ->sort()
            ->values()
            ->toBase();
    }

    /**
     * Determine whether the member may call the endpoint with the given route name.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $this->loadMissing('clinicRoles.clinicPermissions');

        return $this->clinicRoles->contains(
            fn (ClinicRole $role): bool => $role->clinicPermissions->contains('name', $permission),
        );
    }

    public function activityEntity(): string
    {
        return 'member';
    }

    public function activityLabel(): string
    {
        return $this->user()->value('name') ?? "Member #{$this->id}";
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    protected function activityAction(string $event, array $changes): string
    {
        return match ($event) {
            'created', 'restored' => 'member.added',
            'archived', 'deleted' => 'member.removed',
            default => "member.{$event}",
        };
    }
}
