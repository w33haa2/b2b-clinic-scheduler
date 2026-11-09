<?php

namespace App\Actions\Clinics;

use App\Models\ClinicRole;
use Illuminate\Validation\ValidationException;

class DeleteClinicRole
{
    /**
     * Delete a custom role. Members holding it simply lose it.
     */
    public function handle(ClinicRole $role): void
    {
        if ($role->isSystem()) {
            throw ValidationException::withMessages([
                'role' => __('The built-in :role role cannot be deleted.', ['role' => $role->name]),
            ]);
        }

        $role->delete();
    }
}
