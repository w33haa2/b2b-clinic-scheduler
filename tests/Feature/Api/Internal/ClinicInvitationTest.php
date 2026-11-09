<?php

use App\Actions\Clinics\SyncClinicPermissions;
use App\Enums\ClinicRoleKey;
use App\Mail\ClinicInvitationMail;
use App\Models\ActivityLog;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use App\Models\ClinicUser;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

test('a super admin invites an email; it is mailed a link and stored hashed', function () {
    Mail::fake();
    $clinic = Clinic::factory()->create(['name' => 'Bayview Physio']);
    $superAdmin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($superAdmin);

    $this->postJson(route('internal.clinics.invitations.store', $clinic), ['email' => 'Ana@Example.com '])
        ->assertCreated()
        ->assertJsonPath('data.email', 'ana@example.com')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.role.name', 'Clinic Worker');

    $invitation = ClinicInvitation::query()->sole();
    $sentToken = null;

    Mail::assertQueued(ClinicInvitationMail::class, function (ClinicInvitationMail $mail) use (&$sentToken) {
        $sentToken = $mail->token;

        return $mail->hasTo('ana@example.com') && ! $mail->hasAccount;
    });

    expect($invitation->token_hash)->toBe(hash('sha256', $sentToken))
        ->and(ActivityLog::query()->where('action', 'invitation.sent')->sole()->clinic_id)->toBe($clinic->id);
});

test('the email shows the clinic and a working link', function () {
    $invitation = ClinicInvitation::factory()->create();

    $html = (new ClinicInvitationMail($invitation->load(['clinic', 'clinicRole']), 'abc123', false))->render();

    expect($html)->toContain($invitation->clinic->name)
        ->toContain(route('invitations.show', 'abc123'))
        ->toContain('Accept and set up my account');
});

test('members, super admins and emails already invited cannot be invited', function (string $who) {
    Mail::fake();
    $member = ClinicUser::factory()->worker()->create();
    $email = match ($who) {
        'member' => $member->user->email,
        'super admin' => User::factory()->superAdmin()->create()->email,
        'already invited' => ClinicInvitation::factory()->create(['clinic_id' => $member->clinic_id])->email,
    };
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->postJson(route('internal.clinics.invitations.store', $member->clinic_id), ['email' => $email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    Mail::assertNothingQueued();
})->with(['member', 'super admin', 'already invited']);

test('clinic members cannot reach the invitation endpoints', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('internal.clinics.invitations.store', $admin->clinic_id), ['email' => 'x@example.com'])->assertForbidden();
    $this->getJson(route('internal.clinics.invitations.index', $admin->clinic_id))->assertForbidden();
});

test('resending issues a new link and revoking stops the old one', function () {
    Mail::fake();
    $invitation = ClinicInvitation::factory()->withToken('old-token')->create();
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->postJson(route('internal.clinics.invitations.resend', [$invitation->clinic_id, $invitation]))->assertOk();
    Mail::assertQueued(ClinicInvitationMail::class);
    expect(ClinicInvitation::findByToken('old-token'))->toBeNull()
        ->and(ActivityLog::query()->where('action', 'invitation.resent')->exists())->toBeTrue();

    $this->deleteJson(route('internal.clinics.invitations.destroy', [$invitation->clinic_id, $invitation]))->assertNoContent();
    expect($invitation->fresh()->status())->toBe('revoked');
});

test('an invitation of another clinic is not found', function () {
    $invitation = ClinicInvitation::factory()->create();
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->deleteJson(route('internal.clinics.invitations.destroy', [Clinic::factory()->create(), $invitation]))->assertNotFound();
});

test('an existing user joins the clinic as a worker on opening the link', function () {
    $user = User::factory()->create(['email' => 'ben@example.com']);
    $invitation = ClinicInvitation::factory()->withToken('tok')->create(['email' => 'ben@example.com']);

    $this->get(route('invitations.show', 'tok'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/auth/Invitation')
            ->where('state', 'joined')
            ->where('invitation.clinic.name', $invitation->clinic->name));

    $membership = ClinicUser::query()->whereBelongsTo($user)->sole();

    expect($membership->clinic_id)->toBe($invitation->clinic_id)
        ->and($membership->clinicRoles()->sole()->key)->toBe(ClinicRoleKey::Worker)
        ->and($invitation->fresh()->status())->toBe('accepted')
        ->and(ActivityLog::query()->where('action', 'invitation.accepted')->sole()->user_id)->toBe($user->id);

    // The link only works once.
    $this->get(route('invitations.show', 'tok'))->assertInertia(fn (Assert $page) => $page->where('state', 'used'));
});

test('someone removed from the clinic before is restored', function () {
    $member = ClinicUser::factory()->worker()->create();
    $member->delete();
    ClinicInvitation::factory()->withToken('tok')->create(['clinic_id' => $member->clinic_id, 'email' => $member->user->email]);

    $this->get(route('invitations.show', 'tok'))->assertOk();

    expect($member->fresh()->trashed())->toBeFalse();
});

test('a first-time user chooses a password, joins and is signed in', function () {
    $invitation = ClinicInvitation::factory()->withToken('tok')->create(['email' => 'cara@example.com']);

    $this->get(route('invitations.show', 'tok'))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'create')->where('invitation.email', 'cara@example.com'));

    $this->post(route('invitations.accept', 'tok'), [
        'name' => 'Cara Santos',
        'password' => 'a-long-password-1',
        'password_confirmation' => 'a-long-password-1',
    ])->assertRedirect(route('admin.clinics.home', $invitation->clinic_id));

    $user = User::query()->where('email', 'cara@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and(ClinicUser::query()->whereBelongsTo($user)->sole()->clinic_id)->toBe($invitation->clinic_id);
});

test('a new account needs a confirmed password', function () {
    ClinicInvitation::factory()->withToken('tok')->create(['email' => 'cara@example.com']);

    $this->post(route('invitations.accept', 'tok'), ['name' => 'Cara', 'password' => 'one', 'password_confirmation' => 'two'])
        ->assertSessionHasErrors('password');

    $this->assertGuest();
    expect(User::query()->where('email', 'cara@example.com')->exists())->toBeFalse();
});

test('expired, revoked and unknown links join nobody', function (string $state) {
    $invitation = ClinicInvitation::factory()->withToken('tok')->create(['email' => User::factory()->create()->email]);
    match ($state) {
        'expired' => $invitation->forceFill(['expires_at' => now()->subMinute()])->save(),
        'revoked' => $invitation->forceFill(['revoked_at' => now()])->save(),
        default => null,
    };

    $this->get(route('invitations.show', $state === 'invalid' ? 'wrong-token' : 'tok'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('state', $state));

    $this->assertDatabaseCount('clinic_users', 0);
})->with(['expired', 'revoked', 'invalid']);

test('a clinic admin invites people to their own clinic', function () {
    Mail::fake();
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.invitations.store', $admin->clinic_id), ['email' => 'dana@example.com'])
        ->assertCreated()
        ->assertJsonPath('data.invited_by.id', $admin->user_id)
        ->assertJsonPath('data.role.name', 'Clinic Worker');
    $this->getJson(route('clinic.invitations.index', $admin->clinic_id))->assertJsonCount(1, 'data');

    Mail::assertQueued(ClinicInvitationMail::class, fn (ClinicInvitationMail $mail) => $mail->hasTo('dana@example.com'));
    expect(ActivityLog::query()->where('action', 'invitation.sent')->sole()->user_id)->toBe($admin->user_id);
});

test('only clinic admins can invite, and only to their own clinic', function () {
    Mail::fake();
    $worker = ClinicUser::factory()->worker()->create();
    $otherInvitation = ClinicInvitation::factory()->create();
    $otherAdmin = ClinicUser::factory()->admin()->create(['clinic_id' => $otherInvitation->clinic_id]);
    $admin = ClinicUser::factory()->admin()->create(['clinic_id' => $worker->clinic_id]);

    Sanctum::actingAs($worker->user);
    $this->postJson(route('clinic.invitations.store', $worker->clinic_id), ['email' => 'x@example.com'])->assertForbidden();

    Sanctum::actingAs($admin->user);
    $this->deleteJson(route('clinic.invitations.destroy', [$worker->clinic_id, $otherInvitation]))->assertNotFound();
    $this->postJson(route('clinic.invitations.store', $otherAdmin->clinic_id), ['email' => 'x@example.com'])->assertNotFound();

    Mail::assertNothingQueued();
    expect($otherInvitation->fresh()->status())->toBe('pending');
});

test('inviting is not a grantable permission', function () {
    expect(app(SyncClinicPermissions::class)->permissionRouteNames())
        ->not->toContain('clinic.invitations.store');
});
