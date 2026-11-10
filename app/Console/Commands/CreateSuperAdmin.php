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
                            {--password= : Their password (prompted for when omitted)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a super admin, for bootstrapping a new deployment';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email', required: true);
        $plainPassword = $this->option('password') ?: password('Password', required: true);

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
}
