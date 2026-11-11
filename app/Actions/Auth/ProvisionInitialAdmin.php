<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Creates the first super admin of an installation.
 *
 * Registration is disabled and every other account is created from inside the
 * app, so a new deployment has nobody who can sign in. Hosts with a shell run
 * `clinic:create-super-admin`; hosts without one configure SUPER_ADMIN_EMAIL
 * and SUPER_ADMIN_PASSWORD and let the landing page do it on first visit.
 */
class ProvisionInitialAdmin
{
    /**
     * Only one attempt per window while an installation is unprovisioned.
     *
     * Without this, every hit on the landing page would re-run the password
     * rules, and in production those include an "uncompromised" check that
     * calls an external API. A misconfigured deployment would turn its own
     * public home page into an outbound request amplifier.
     */
    private const RETRY_AFTER_MINUTES = 5;

    private const FAILED_KEY = 'initial-admin:last-attempt-failed';

    private const LOCK_KEY = 'initial-admin:provisioning';

    /**
     * Whether the installation already has somebody who can sign in.
     */
    public function exists(): bool
    {
        return User::query()->where('is_super_admin', true)->exists();
    }

    /**
     * Create the configured super admin, if the installation has none.
     *
     * Safe to call on every request: it is a no-op once a super admin exists
     * and when nothing is configured. Never throws. This runs on the public
     * landing page, and a bootstrap problem must not take the site down with
     * it, so failures are logged and swallowed.
     *
     * @return User|null The account created, or null when nothing was done.
     */
    public function handle(): ?User
    {
        try {
            return $this->provision();
        } catch (Throwable $e) {
            Log::error('Could not provision the initial super admin.', ['exception' => $e]);

            return null;
        }
    }

    /**
     * The validation rules for a super admin, shared with the console command
     * so that both doors into this enforce the same password policy.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            // The same strength policy the web forms use. Their "confirmed"
            // rule is left off: there is no second field to confirm against
            // in a console command or an environment variable.
            'password' => ['required', 'string', Password::default()],
        ];
    }

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function validator(array $data): ValidatorContract
    {
        return Validator::make($data, $this->rules());
    }

    /**
     * Write the account. Callers validate first.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function create(array $data, string $via): User
    {
        // is_super_admin is deliberately not mass-assignable, and the account
        // is created verified because nobody can send it a verification email
        // before it exists.
        $user = ActivityLogger::quietly(function () use ($data): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

            return $user;
        });

        app(ActivityLogger::class)->log('user.created', $user, null, $user->name, context: ['via' => $via]);

        return $user;
    }

    /**
     * The guarded path: decides whether to act, then acts once.
     */
    private function provision(): ?User
    {
        if ($this->exists()) {
            return null;
        }

        $data = $this->configured();

        if ($data === null) {
            return null;
        }

        if (Cache::get(self::FAILED_KEY) === true) {
            return null;
        }

        // Concurrent first visits must not race into two accounts. Whoever
        // loses the lock simply does nothing: the winner is already creating
        // the account, and the loser's page does not depend on the outcome.
        $lock = Cache::lock(self::LOCK_KEY, 30);

        if (! $lock->get()) {
            return null;
        }

        try {
            // Re-check: the request that held the lock before us may have
            // been the one that created it. Static analysis sees the earlier
            // call return false and concludes this cannot be true, which is
            // exactly the assumption the lock exists to break.
            // @phpstan-ignore if.alwaysFalse
            if ($this->exists()) {
                return null;
            }

            $validator = $this->validator($data);

            if ($validator->fails()) {
                // The values come from the deployment's environment, so the
                // operator reading the logs is the person who can fix them.
                // The password itself is never logged, only what is wrong.
                Log::error('SUPER_ADMIN_* is set but not valid, so no super admin was created.', [
                    'errors' => $validator->errors()->all(),
                ]);

                Cache::put(self::FAILED_KEY, true, now()->addMinutes(self::RETRY_AFTER_MINUTES));

                return null;
            }

            $user = $this->create($data, via: 'landing-page');

            Log::info("Created the initial super admin {$user->email} on first visit.");

            return $user;
        } finally {
            $lock->release();
        }
    }

    /**
     * The configured credentials, or null when this deployment has not asked
     * for an account to be created.
     *
     * @return array{name: string, email: string, password: string}|null
     */
    private function configured(): ?array
    {
        $name = $this->stringConfig('auth.initial_admin.name');
        $email = $this->stringConfig('auth.initial_admin.email');
        $password = $this->stringConfig('auth.initial_admin.password');

        if ($email === '' || $password === '') {
            return null;
        }

        return [
            'name' => $name === '' ? 'Super Admin' : $name,
            'email' => $email,
            'password' => $password,
        ];
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
