<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Notifications\QueuedResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_super_admin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * The user's clinic memberships.
     *
     * @return HasMany<ClinicUser, $this>
     */
    public function clinicUsers(): HasMany
    {
        return $this->hasMany(ClinicUser::class);
    }

    /**
     * The clinics the user is a member of.
     *
     * @return BelongsToMany<Clinic, $this>
     */
    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class, 'clinic_users')
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /**
     * Send the password reset notification through the queue.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    public function activityEntity(): string
    {
        return 'user';
    }

    public function activityLabel(): string
    {
        return "{$this->name} ({$this->email})";
    }

    public function activityClinicId(): ?int
    {
        return null;
    }
}
