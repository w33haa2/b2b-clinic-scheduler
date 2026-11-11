<?php

use App\Models\User;

/*
 * The one way into a fresh deployment: registration is disabled and every
 * other account is created from inside the app, so without this command a new
 * installation has nobody who can sign in.
 */

test('the command creates a verified super admin who can sign in', function () {
    $this->artisan('clinic:create-super-admin', [
        '--name' => 'Ops',
        '--email' => 'ops@example.com',
        '--password' => 'correct-horse-battery-staple',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'ops@example.com')->sole();

    expect($user->is_super_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe('correct-horse-battery-staple');

    $this->post(route('login.store'), [
        'email' => 'ops@example.com',
        'password' => 'correct-horse-battery-staple',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('it refuses an email that is already taken', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('clinic:create-super-admin', [
        '--name' => 'Ops',
        '--email' => 'taken@example.com',
        '--password' => 'correct-horse-battery-staple',
    ])->assertFailed();

    expect(User::query()->where('email', 'taken@example.com')->count())->toBe(1);
});

test('it refuses a weak password and a malformed email', function (array $options) {
    $this->artisan('clinic:create-super-admin', $options)->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'short password' => [['--name' => 'Ops', '--email' => 'ops@example.com', '--password' => 'short']],
    'not an email' => [['--name' => 'Ops', '--email' => 'not-an-email', '--password' => 'correct-horse-battery-staple']],
]);

test('--if-missing creates the first super admin and then leaves it alone', function () {
    $options = [
        '--name' => 'Ops',
        '--email' => 'ops@example.com',
        '--password' => 'correct-horse-battery-staple9',
        '--if-missing' => true,
    ];

    $this->artisan('clinic:create-super-admin', $options)->assertSuccessful();

    // Running again on every boot must not fail, and must not make a second.
    $this->artisan('clinic:create-super-admin', [...$options, '--email' => 'other@example.com'])
        ->expectsOutputToContain('A super admin already exists')
        ->assertSuccessful();

    expect(User::query()->where('is_super_admin', true)->count())->toBe(1)
        ->and(User::query()->where('email', 'other@example.com')->exists())->toBeFalse();
});

test('--if-missing names the account it kept and the one it skipped', function () {
    User::factory()->superAdmin()->create(['email' => 'first@example.com']);

    // A deployment asking for an account it will not get is the one failure
    // mode here that is otherwise invisible: the boot succeeds, and nobody can
    // sign in with the credentials they configured.
    $this->artisan('clinic:create-super-admin', [
        '--name' => 'Ops',
        '--email' => 'second@example.com',
        '--password' => 'correct-horse-battery-staple9',
        '--if-missing' => true,
    ])
        // Disjoint fragments on purpose: expectsOutputToContain() matches
        // whole writes in declaration order, so a fragment that also appears
        // in the later line would swallow it and never check the warning.
        ->expectsOutputToContain('already exists (first@example.com)')
        ->expectsOutputToContain('second@example.com was NOT created')
        ->assertSuccessful();
});

test('--if-missing stays quiet when the existing super admin is the one asked for', function () {
    User::factory()->superAdmin()->create(['email' => 'ops@example.com']);

    $this->artisan('clinic:create-super-admin', [
        '--name' => 'Ops',
        '--email' => 'ops@example.com',
        '--password' => 'correct-horse-battery-staple9',
        '--if-missing' => true,
    ])
        ->doesntExpectOutputToContain('NOT created')
        ->assertSuccessful();
});

test('--if-missing still creates one when only clinic members exist', function () {
    User::factory()->create(['email' => 'worker@example.com']);

    $this->artisan('clinic:create-super-admin', [
        '--name' => 'Ops',
        '--email' => 'ops@example.com',
        '--password' => 'correct-horse-battery-staple9',
        '--if-missing' => true,
    ])->assertSuccessful();

    expect(User::query()->where('is_super_admin', true)->count())->toBe(1);
});

test('without a terminal it reports what is missing instead of hanging on a prompt', function () {
    $this->artisan('clinic:create-super-admin', ['--email' => 'ops@example.com', '--no-interaction' => true])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('creating one is recorded in the platform activity log', function () {
    $this->artisan('clinic:create-super-admin', [
        '--name' => 'Ops',
        '--email' => 'ops@example.com',
        '--password' => 'correct-horse-battery-staple',
    ])->assertSuccessful();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'user.created',
        'clinic_id' => null,
        'subject_label' => 'Ops',
    ]);
});
