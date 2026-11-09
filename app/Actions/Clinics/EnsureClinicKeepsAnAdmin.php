<?php

namespace App\Actions\Clinics;

use App\Enums\ClinicRoleKey;
use App\Models\ClinicUser;
use Illuminate\Validation\ValidationException;

class EnsureClinicKeepsAnAdmin
{
    /**
     * Fail when the given member is the clinic's only remaining admin.
     */
    public static function check(ClinicUser $clinicUser): void
    {
        $otherAdmins = ClinicUser::query()
            ->where('clinic_id', $clinicUser->clinic_id)
            ->whereKeyNot($clinicUser->id)
            ->whereHas('clinicRoles', fn ($query) => $query->where('key', ClinicRoleKey::Admin))
            ->exists();

        if (! $otherAdmins) {
            throw ValidationException::withMessages([
                'roles' => __('A clinic must keep at least one Clinic Admin.'),
            ]);
        }
    }
}
