<?php

namespace App\Actions\Clinics;

use App\Enums\ClinicRoleKey;
use App\Models\Clinic;
use App\Models\ClinicPermission;
use App\Models\ClinicRole;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveClinicRole
{
    /**
     * Create a custom role, or update an existing one. The admin role can never
     * change, and the worker role keeps its name (only its permissions change).
     *
     * @param  array{name?: string|null, permission_ids?: list<int>|null}  $data
     */
    public function handle(Clinic $clinic, array $data, ?ClinicRole $role = null): ClinicRole
    {
        if ($role?->key === ClinicRoleKey::Admin) {
            throw ValidationException::withMessages([
                'role' => __('The Clinic Admin role cannot be changed.'),
            ]);
        }

        if ($role?->key === ClinicRoleKey::Worker && isset($data['name']) && $data['name'] !== $role->name) {
            throw ValidationException::withMessages([
                'name' => __('The Clinic Worker role cannot be renamed.'),
            ]);
        }

        return DB::transaction(function () use ($clinic, $data, $role): ClinicRole {
            $role ??= new ClinicRole(['clinic_id' => $clinic->id]);

            if (isset($data['name'])) {
                $role->name = $data['name'];
            }

            $role->save();

            if (array_key_exists('permission_ids', $data) && $data['permission_ids'] !== null) {
                $result = $role->clinicPermissions()->sync($data['permission_ids']);

                if ($result['attached'] !== [] || $result['detached'] !== []) {
                    $names = fn (array $ids): array => ClinicPermission::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();

                    app(ActivityLogger::class)->log(
                        action: 'role.permissions_changed',
                        subject: $role,
                        clinicId: $clinic->id,
                        label: $role->name,
                        changes: ['permissions' => ['old' => $names($result['detached']), 'new' => $names($result['attached'])]],
                    );
                }
            }

            return $role->load('clinicPermissions');
        });
    }
}
