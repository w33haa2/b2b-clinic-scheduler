<?php

namespace App\Actions\Clinics;

use App\Enums\ClinicRoleKey;
use App\Mail\ClinicInvitationMail;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use App\Models\ClinicUser;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteToClinic
{
    /**
     * Invite an email to join the clinic as a Clinic Worker and email them
     * the link. Refused for current members, super admins and emails that
     * already have a pending invitation (resend that one instead).
     */
    public function handle(Clinic $clinic, string $email, ?User $invitedBy): ClinicInvitation
    {
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($clinic, $email, $invitedBy): ClinicInvitation {
            $user = User::query()->where('email', $email)->first();

            if ($user?->is_super_admin) {
                throw ValidationException::withMessages(['email' => __('Super admins cannot be clinic members.')]);
            }

            if ($user !== null && ClinicUser::query()->whereBelongsTo($clinic)->whereBelongsTo($user)->exists()) {
                throw ValidationException::withMessages(['email' => __(':email is already a member of :clinic.', ['email' => $email, 'clinic' => $clinic->name])]);
            }

            if ($clinic->clinicInvitations()->pending()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => __(':email has already been invited. Resend that invitation instead.', ['email' => $email])]);
            }

            $invitation = $clinic->clinicInvitations()->make([
                'email' => $email,
                'clinic_role_id' => $clinic->clinicRoles()->where('key', ClinicRoleKey::Worker)->valueOrFail('id'),
            ]);
            $invitation->invitedBy()->associate($invitedBy);
            $token = $invitation->issueToken();
            $invitation->save();

            $this->send($invitation, $token);

            return $invitation;
        });
    }

    /**
     * Send a pending or expired invitation again, with a new link (the old
     * one stops working) and a fresh expiry.
     */
    public function resend(ClinicInvitation $invitation): ClinicInvitation
    {
        if (in_array($invitation->status(), ['accepted', 'revoked'], true)) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation was :status and cannot be sent again.', ['status' => $invitation->status()]),
            ]);
        }

        $token = $invitation->issueToken();
        $invitation->save();

        app(ActivityLogger::class)->log(
            action: 'invitation.resent',
            subject: $invitation,
            clinicId: $invitation->clinic_id,
            label: $invitation->activityLabel(),
        );

        $this->send($invitation, $token);

        return $invitation;
    }

    /**
     * Cancel a pending invitation; its link stops working.
     */
    public function revoke(ClinicInvitation $invitation): void
    {
        if ($invitation->status() === 'accepted') {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation was already accepted. Remove the member instead.'),
            ]);
        }

        if ($invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();
        }
    }

    private function send(ClinicInvitation $invitation, string $token): void
    {
        Mail::to($invitation->email)->queue(new ClinicInvitationMail(
            $invitation->loadMissing(['clinic', 'invitedBy']),
            $token,
            User::query()->where('email', $invitation->email)->exists(),
        ));
    }
}
