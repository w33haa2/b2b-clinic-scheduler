<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One grantable clinic API endpoint, named after its route (e.g. "clinic.patients.store").
 * Rows are generated from the route list by `php artisan clinic:sync-permissions`.
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class ClinicPermission extends Model
{
    /**
     * @return BelongsToMany<ClinicRole, $this>
     */
    public function clinicRoles(): BelongsToMany
    {
        return $this->belongsToMany(ClinicRole::class, 'clinic_role_permission');
    }
}
