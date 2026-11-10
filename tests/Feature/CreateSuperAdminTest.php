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
