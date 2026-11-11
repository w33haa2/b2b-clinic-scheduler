<?php

namespace App\Console\Commands;

use App\Actions\Auth\ProvisionInitialAdmin;
use App\Models\User;
use Illuminate\Console\Command;

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
 * Hosts that cannot run a command at all do the same thing from the landing
 * page instead; see App\Actions\Auth\ProvisionInitialAdmin.
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

    public function handle(ProvisionInitialAdmin $provisionInitialAdmin): int
    {
        if ($this->option('if-missing')) {
            $existing = User::query()->where('is_super_admin', true)->orderBy('id')->first();

            if ($existing !== null) {
                $this->components->info("A super admin already exists ({$existing->email}); leaving it alone.");

                // Naming both accounts turns the otherwise silent case of "the
                // deployment asked for a different person" into something the
                // boot log explains, rather than an account that never shows up.
                // Read as a raw option: prompting here would ask an interactive
                // operator for details only to discard them a line later.
                $requested = $this->option('email');
                $requested = is_scalar($requested) ? trim((string) $requested) : '';

                if ($requested !== '' && $requested !== $existing->email) {
                    $this->components->warn(
                        "{$requested} was NOT created. Remove {$existing->email} first if you meant to replace it."
                    );
                }

                return self::SUCCESS;
            }
        }

        $data = [
            'name' => $this->value('name', 'Name'),
            'email' => $this->value('email', 'Email'),
            'password' => $this->value('password', 'Password', secret: true),
        ];

        $validator = $provisionInitialAdmin->validator($data);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = $provisionInitialAdmin->create($data, via: 'console');

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
