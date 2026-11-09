<?php

namespace App\Actions\Clinics;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AddClinicMember
{
    /**
     * Add a user to the clinic with the given roles. An existing user (matched by
     * email) is attached; otherwise a new user is created. A previously removed
     * membership is restored.
     *
     * @param  array{name: string, email: string, password?: string|null}  $userData
     * @param  list<int>  $roleIds
     */
    public function handle(Clinic $clinic, array $userData, array $roleIds): ClinicUser
    {
        return DB::transaction(function () use ($clinic, $userData, $roleIds): ClinicUser {
            $user = User::query()->where('email', $userData['email'])->first();

            if ($user?->is_super_admin) {
                throw ValidationException::withMessages([
                    'email' => __('Super admins cannot be clinic members.'),
                ]);
            }

            $user ??= User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => $userData['password'] ?? Str::password(32),
            ]);

            $clinicUser = ClinicUser::withTrashed()->firstOrNew([
                'clinic_id' => $clinic->id,
                'user_id' => $user->id,
            ]);

            if ($clinicUser->exists && ! $clinicUser->trashed()) {
                throw ValidationException::withMessages([
                    'email' => __('This user is already a member of the clinic.'),
                ]);
            }

            if ($clinicUser->trashed()) {
                $clinicUser->restore();
            } else {
                $clinicUser->save();
            }

            $clinicUser->clinicRoles()->sync($roleIds);

            return $clinicUser;
        });
    }
}
