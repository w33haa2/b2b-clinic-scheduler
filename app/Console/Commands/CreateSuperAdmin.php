<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first super admin on a fresh deployment.
 *
 * Registration is disabled and every other account is created from inside the
 * app, so a new installation has nobody who can sign in. The demo seeder fills
 * that gap locally, but it needs Faker, which production images do not have.
 *
 * With --if-missing this is safe to run on every boot, which is how a host
 * without shell access bootstraps itself (see docker/render/entrypoint.sh).
 */
class CreateSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clinic:create-super-admin
                            {--name= : The person\'s name}
                            {--email= : The email they sign in with}
                            {--password= : Their password (prompted for when omitted)}
                            {--if-missing : Do nothing, successfully, if the clinic already has a super admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a super admin, for bootstrapping a new deployment';

    public function handle(): int
    {
        if ($this->option('if-missing') && User::query()->where('is_super_admin', true)->exists()) {
            $this->components->info('A super admin already exists; leaving it alone.');

            return self::SUCCESS;
        }

        $name = $this->value('name', 'Name');
        $email = $this->value('email', 'Email');
        $plainPassword = $this->value('password', 'Password', secret: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plainPassword],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
                // The same strength policy the web forms use. Their
                // "confirmed" rule is left off: there is no second field to
                // confirm against on a command line.
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        // is_super_admin is deliberately not mass-assignable, and the account
        // is created verified because nobody can send it a verification email
        // before it exists.
        $user = ActivityLogger::quietly(function () use ($name, $email, $plainPassword): User {
            $user = new User(['name' => $name, 'email' => $email, 'password' => $plainPassword]);
            $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

            return $user;
        });

        app(ActivityLogger::class)->log('user.created', $user, null, $user->name, context: ['via' => 'console']);

        $this->components->info("Super admin {$user->email} created. Sign in at ".url('/admin/login').'.');

        return self::SUCCESS;
    }

    /**
     * An option's value, asking for it only when someone is there to answer.
     * Running without a terminal (a container entrypoint) returns an empty
     * string, which the validation below reports properly instead of hanging
     * on a prompt nobody can see.
     */
    private function value(string $option, string $label, bool $secret = false): string
    {
        $given = $this->option($option);
        $value = is_scalar($given) ? trim((string) $given) : '';

        if ($value !== '' || ! $this->input->isInteractive()) {
            return $value;
        }

        return $secret ? password($label, required: true) : text($label, required: true);
    }
}
