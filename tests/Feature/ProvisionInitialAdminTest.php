<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/*
 * A deployment with no shell has no other way to get its first account, so
 * the landing page creates it from SUPER_ADMIN_* on the first visit. The
 * danger in hanging work off a public page is that it fires more than once,
 * runs when it should not, or takes the page down with it.
 */

function configureInitialAdmin(string $password = 'correct-horse-battery-staple9'): void
{
    config()->set('auth.initial_admin.name', 'Ops');
    config()->set('auth.initial_admin.email', 'ops@example.com');
    config()->set('auth.initial_admin.password', $password);
}

test('the first visit to the landing page creates the configured super admin', function () {
    configureInitialAdmin();

    $this->get(route('home'))->assertOk();

    $user = User::query()->where('email', 'ops@example.com')->sole();

    expect($user->is_super_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    // The whole point: these credentials have to work at the login form.
    $this->post(route('login.store'), [
        'email' => 'ops@example.com',
        'password' => 'correct-horse-battery-staple9',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('further visits do not create a second account', function () {
    configureInitialAdmin();

    $this->get(route('home'))->assertOk();
    $this->get(route('home'))->assertOk();
    $this->get(route('home'))->assertOk();

    expect(User::query()->where('is_super_admin', true)->count())->toBe(1);
});

test('it leaves an installation that already has a super admin alone', function () {
    User::factory()->superAdmin()->create(['email' => 'first@example.com']);
    configureInitialAdmin();

    $this->get(route('home'))->assertOk();

    expect(User::query()->where('is_super_admin', true)->count())->toBe(1)
        ->and(User::query()->where('email', 'ops@example.com')->exists())->toBeFalse();
});

test('it does nothing when the deployment has not configured an account', function () {
    config()->set('auth.initial_admin.email', null);
    config()->set('auth.initial_admin.password', null);

    $this->get(route('home'))->assertOk();

    expect(User::query()->count())->toBe(0);
});

test('a clinic member is not mistaken for a super admin', function () {
    User::factory()->create(['email' => 'worker@example.com']);
    configureInitialAdmin();

    $this->get(route('home'))->assertOk();

    expect(User::query()->where('is_super_admin', true)->count())->toBe(1);
});

test('a password that fails the policy is reported in the log, not to the visitor', function () {
    Log::spy();
    configureInitialAdmin(password: 'short');

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('password', false);

    expect(User::query()->count())->toBe(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'SUPER_ADMIN_')
            && $context['errors'] !== []);
});

test('a misconfigured deployment stops retrying on every single page view', function () {
    configureInitialAdmin(password: 'short');

    $this->get(route('home'))->assertOk();

    expect(Cache::get('initial-admin:last-attempt-failed'))->toBeTrue();

    // Correcting the configuration takes effect once the window passes,
    // rather than on the very next request.
    configureInitialAdmin();
    $this->get(route('home'))->assertOk();
    expect(User::query()->count())->toBe(0);

    $this->travel(6)->minutes();
    $this->get(route('home'))->assertOk();
    expect(User::query()->where('is_super_admin', true)->count())->toBe(1);
});

test('the landing page still renders when provisioning throws', function () {
    Log::spy();
    configureInitialAdmin();

    // A broken cache backend is the realistic version of this: the lock is
    // the first thing provisioning reaches for.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache is down'));

    $this->get(route('home'))->assertOk();

    expect(User::query()->count())->toBe(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'provision the initial super admin'));
});
