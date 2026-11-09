<?php

namespace App\Models;

use App\Enums\ClinicRoleKey;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\ClinicRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $clinic_id
 * @property string $name
 * @property ClinicRoleKey|null $key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['clinic_id', 'name', 'key'])]
class ClinicRole extends Model
{
    /** @use HasFactory<ClinicRoleFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => ClinicRoleKey::class,
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
     * @return BelongsToMany<ClinicUser, $this>
     */
    public function clinicUsers(): BelongsToMany
    {
        return $this->belongsToMany(ClinicUser::class, 'clinic_user_role');
    }

    /**
     * @return BelongsToMany<ClinicPermission, $this>
     */
    public function clinicPermissions(): BelongsToMany
    {
        return $this->belongsToMany(ClinicPermission::class, 'clinic_role_permission');
    }

    /**
     * Determine whether this is one of the built-in roles (Clinic Admin or Clinic Worker).
     */
    public function isSystem(): bool
    {
        return $this->key !== null;
    }

    public function activityEntity(): string
    {
        return 'role';
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
