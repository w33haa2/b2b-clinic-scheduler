<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Adds a member (POST) or replaces a member's roles (PUT/PATCH). Shared by the
 * clinic API and the internal API.
 */
class MemberRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCreating = $this->isMethod('POST');
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return [
            'name' => [Rule::excludeIf(! $isCreating), 'required', 'string', 'max:255'],
            'email' => [Rule::excludeIf(! $isCreating), 'required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => [Rule::excludeIf(! $isCreating), 'nullable', 'string', Password::default()],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('clinic_roles', 'id')->where('clinic_id', $clinic->id)],
        ];
    }

    /**
     * The validated role IDs.
     *
     * @return list<int>
     */
    public function roleIds(): array
    {
        return array_values(array_map(intval(...), $this->validated('role_ids')));
    }

    /**
     * The validated details of the user to add.
     *
     * @return array{name: string, email: string, password: string|null}
     */
    public function userAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->filled('password') ? $this->string('password')->toString() : null,
        ];
    }
}
