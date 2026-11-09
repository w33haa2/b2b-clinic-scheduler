<?php

namespace App\Providers;

use App\Actions\Clinics\SyncClinicPermissions;
use App\Models\Clinic;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ClinicContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->syncClinicPermissionsAfterMigrating();
        $this->logAuthenticationEvents();

        // Explicit so web routes without a typed controller argument (Route::inertia) still bind {clinic}.
        Route::model('clinic', Clinic::class);
    }

    /**
     * Sign-ins, sign-outs, failed attempts and password resets go into the
     * platform-level activity log (no clinic).
     */
    protected function logAuthenticationEvents(): void
    {
        $logger = fn (): ActivityLogger => app(ActivityLogger::class);

        Event::listen(fn (Login $event) => $event->user instanceof User
            ? $logger()->log('auth.login', $event->user, label: $event->user->activityLabel(), actor: $event->user)
            : null);
        Event::listen(fn (Logout $event) => $event->user instanceof User
            ? $logger()->log('auth.logout', $event->user, label: $event->user->activityLabel(), actor: $event->user)
            : null);
        // Whoever tried is unknown: the targeted account is the subject, not the actor.
        Event::listen(fn (Failed $event) => $logger()->log(
            'auth.login_failed',
            $event->user instanceof User ? $event->user : null,
            label: (string) ($event->credentials['email'] ?? ''),
        ));
        Event::listen(fn (PasswordReset $event) => $event->user instanceof User
            ? $logger()->log('auth.password_reset', $event->user, label: $event->user->activityLabel(), actor: $event->user)
            : null);
    }

    /**
     * Keep the clinic_permissions table in step with the clinic API routes
     * every time `migrate` runs, even with nothing to migrate (deploys,
     * `migrate:fresh`, and the test suite).
     */
    protected function syncClinicPermissionsAfterMigrating(): void
    {
        Event::listen(function (MigrationsEnded|NoPendingMigrations $event): void {
            $isPretending = $event instanceof MigrationsEnded && ! empty($event->options['pretend']);

            if ($event->method === 'up' && ! $isPretending && Schema::hasTable('clinic_permissions')) {
                app(SyncClinicPermissions::class)->handle();
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
