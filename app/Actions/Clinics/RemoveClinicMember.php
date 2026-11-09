<?php

namespace App\Actions\Clinics;

use App\Models\ClinicUser;
use Illuminate\Support\Facades\DB;

class RemoveClinicMember
{
    /**
     * Remove a member from the clinic. The membership is soft-deleted so the
     * member's past sessions and recorded transactions keep their owner.
     */
    public function handle(ClinicUser $clinicUser): void
    {
        DB::transaction(function () use ($clinicUser): void {
            if ($clinicUser->isAdmin()) {
                EnsureClinicKeepsAnAdmin::check($clinicUser);
            }

            $clinicUser->clinicRoles()->detach();
            $clinicUser->delete();
        });
    }
}
