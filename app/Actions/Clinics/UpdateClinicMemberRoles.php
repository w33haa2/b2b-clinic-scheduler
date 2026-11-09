<?php

namespace App\Actions\Clinics;

use App\Enums\ClinicRoleKey;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class UpdateClinicMemberRoles
{
    /**
     * Replace the member's roles, keeping at least one admin in the clinic.
     *
     * @param  list<int>  $roleIds
     */
    public function handle(ClinicUser $clinicUser, array $roleIds): ClinicUser
    {
        return DB::transaction(function () use ($clinicUser, $roleIds): ClinicUser {
            $adminRole = ClinicRole::query()
                ->whereBelongsTo($clinicUser->clinic)
                ->where('key', ClinicRoleKey::Admin)
                ->firstOrFail();

            if ($clinicUser->isAdmin() && ! in_array($adminRole->id, $roleIds, true)) {
                EnsureClinicKeepsAnAdmin::check($clinicUser);
            }

            $before = $clinicUser->clinicRoles()->orderBy('name')->pluck('name')->all();
            $clinicUser->clinicRoles()->sync($roleIds);
            $after = $clinicUser->clinicRoles()->orderBy('name')->pluck('name')->all();

            if ($before !== $after) {
                app(ActivityLogger::class)->log(
                    action: 'member.roles_changed',
                    subject: $clinicUser,
                    clinicId: $clinicUser->clinic_id,
                    label: $clinicUser->activityLabel(),
                    changes: ['roles' => ['old' => $before, 'new' => $after]],
                );
            }

            return $clinicUser->load('clinicRoles');
        });
    }
}
