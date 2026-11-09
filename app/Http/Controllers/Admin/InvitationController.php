<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Clinics\AcceptClinicInvitation;
use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\ClinicInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page an invitation email links to (no sign-in needed). People who
 * already have an account join the clinic on opening it; first-time users
 * choose their name and a password, then they are signed in.
 */
class InvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token, AcceptClinicInvitation $accept): Response
    {
        $invitation = ClinicInvitation::findByToken($token)?->loadMissing(['clinic', 'invitedBy', 'clinicRole']);

        if ($invitation === null || $invitation->clinic === null) {
            return $this->page('invalid');
        }

        $status = $invitation->status();

        if ($status === 'accepted') {
            return $this->page('used', $invitation);
        }

        if ($status !== 'pending') {
            return $this->page($status, $invitation);
        }

        $user = User::query()->where('email', $invitation->email)->first();

        if ($user === null) {
            return $this->page('create', $invitation, [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ]);
        }

        try {
            $accept->handle($invitation);
        } catch (ValidationException) {
            return $this->page('invalid', $invitation);
        }

        return $this->page('joined', $invitation, [
            'name' => $user->name,
            'signedInAsInvitee' => Auth::id() === $user->id,
            'signedInAs' => Auth::user()?->email,
        ]);
    }

    /**
     * Create the account of a first-time user, join the clinic and sign in.
     */
    public function accept(Request $request, string $token, AcceptClinicInvitation $accept): RedirectResponse
    {
        $invitation = ClinicInvitation::findByToken($token);

        if ($invitation === null) {
            return to_route('invitations.show', $token);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $user = $accept->handle($invitation, ['name' => $data['name'], 'password' => $data['password']]);

        Auth::login($user);
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Welcome to :clinic! You’re all set.', ['clinic' => $invitation->clinic->name])]);

        return to_route('admin.clinics.home', $invitation->clinic_id);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function page(string $state, ?ClinicInvitation $invitation = null, array $extra = []): Response
    {
        return Inertia::render('admin/auth/Invitation', [
            'state' => $state,
            'token' => $invitation !== null ? request()->route('token') : null,
            'invitation' => $invitation === null ? null : [
                'email' => $invitation->email,
                'clinic' => ['id' => $invitation->clinic_id, 'name' => $invitation->clinic?->name],
                'inviter' => $invitation->invitedBy?->name,
                'role' => $invitation->clinicRole->name ?? 'Clinic Worker',
                'expires_at' => $invitation->expires_at,
            ],
            ...$extra,
        ]);
    }
}
