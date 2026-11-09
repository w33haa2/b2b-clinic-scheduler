<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\ClinicRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or updates a clinic role. Shared by the clinic API and the internal API.
 */
class RoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');
        /** @var ClinicRole|null $role */
        $role = $this->route('clinicRole');

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:100', Rule::unique('clinic_roles', 'name')
                ->where('clinic_id', $clinic->id)
                ->ignore($role?->id)],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('clinic_permissions', 'id')],
        ];
    }
}
