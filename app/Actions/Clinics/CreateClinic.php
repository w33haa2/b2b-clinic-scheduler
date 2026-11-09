<?php

namespace App\Actions\Clinics;

use App\Actions\Patients\CreateDefaultPatientForm;
use App\Enums\ClinicRoleKey;
use App\Models\Clinic;
use App\Models\ClinicPermission;
use Illuminate\Support\Facades\DB;

class CreateClinic
{
    public function __construct(
        private AddClinicMember $addClinicMember,
        private CreateDefaultPatientForm $createDefaultPatientForm,
    ) {}

    /**
     * Create a clinic with its two built-in roles and, optionally, its first admin.
     *
     * @param  array{name: string, address?: string|null, timezone?: string|null}  $attributes
     * @param  array{name: string, email: string, password?: string|null}|null  $admin
     */
    public function handle(array $attributes, ?array $admin = null): Clinic
    {
        return DB::transaction(function () use ($attributes, $admin): Clinic {
            $clinic = Clinic::create(array_filter($attributes, fn (mixed $value): bool => $value !== null));

            $this->createDefaultRoles($clinic);
            $this->createDefaultPatientForm->handle($clinic);

            if ($admin !== null) {
                $adminRole = $clinic->clinicRoles()->where('key', ClinicRoleKey::Admin)->firstOrFail();

                $this->addClinicMember->handle($clinic, $admin, [$adminRole->id]);
            }

            return $clinic;
        });
    }

    /**
     * Create the built-in Clinic Admin and Clinic Worker roles with their default permissions.
     */
    public function createDefaultRoles(Clinic $clinic): void
    {
        foreach (ClinicRoleKey::cases() as $key) {
            $role = $clinic->clinicRoles()->create(['name' => $key->label(), 'key' => $key]);

            $role->clinicPermissions()->sync(
                ClinicPermission::query()->whereIn('name', $key->defaultPermissions())->pluck('id'),
            );
        }
    }
}
