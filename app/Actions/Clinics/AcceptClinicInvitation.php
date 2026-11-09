<?php

namespace App\Actions\Clinics;

use App\Models\ClinicInvitation;
use App\Models\ClinicUser;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptClinicInvitation
{
    /**
     * Accept an invitation: the invited email's user joins the clinic with
     * the invitation's role. Without an account, `$newUser` (name and
     * password) creates one, already verified since the email was proven by
     * the link. Someone removed from the clinic before is restored.
     *
     * @param  array{name: string, password: string}|null  $newUser
     */
    public function handle(ClinicInvitation $invitation, ?array $newUser = null): User
    {
        return DB::transaction(function () use ($invitation, $newUser): User {
            $invitation = ClinicInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->status() !== 'pending') {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation is no longer valid.'),
                ]);
            }

            $user = User::query()->where('email', $invitation->email)->first();

            if ($user === null && $newUser === null) {
                throw ValidationException::withMessages([
                    'password' => __('Create a password to set up your account.'),
                ]);
            }

            if ($user === null) {
                $user = User::create([
                    'name' => $newUser['name'],
                    'email' => $invitation->email,
                    'password' => $newUser['password'],
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            if ($user->is_super_admin) {
                throw ValidationException::withMessages([
                    'invitation' => __('Super admins cannot be clinic members.'),
                ]);
            }

            $membership = ClinicUser::withTrashed()->firstOrNew([
                'clinic_id' => $invitation->clinic_id,
                'user_id' => $user->id,
            ]);

            if ($membership->trashed()) {
                $membership->restore();
                $membership->clinicRoles()->sync([$invitation->clinic_role_id]);
            } elseif (! $membership->exists) {
                $membership->save();
                $membership->clinicRoles()->sync([$invitation->clinic_role_id]);
            }

            ActivityLogger::quietly(fn () => $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ])->save());

            app(ActivityLogger::class)->log(
                action: 'invitation.accepted',
                subject: $invitation,
                clinicId: $invitation->clinic_id,
                label: $invitation->activityLabel(),
                actor: $user,
            );

            return $user;
        });
    }
}
