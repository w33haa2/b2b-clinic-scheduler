<?php

namespace App\Http\Requests\Internal;

use App\Http\Requests\Clinic\ClinicRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

class StoreClinicRequest extends ClinicRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'admin' => ['sometimes', 'array'],
            'admin.name' => ['required_with:admin', 'string', 'max:255'],
            'admin.email' => ['required_with:admin', 'string', 'lowercase', 'email', 'max:255'],
            'admin.password' => ['nullable', 'string', Password::default()],
        ];
    }

    /**
     * The validated clinic details.
     *
     * @return array{name: string, address: string|null, timezone: string|null}
     */
    public function clinicAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'address' => $this->filled('address') ? $this->string('address')->toString() : null,
            'timezone' => $this->filled('timezone') ? $this->string('timezone')->toString() : null,
        ];
    }

    /**
     * The validated first admin, if one was sent.
     *
     * @return array{name: string, email: string, password: string|null}|null
     */
    public function adminAttributes(): ?array
    {
        if (! $this->has('admin')) {
            return null;
        }

        return [
            'name' => $this->string('admin.name')->toString(),
            'email' => $this->string('admin.email')->toString(),
            'password' => $this->filled('admin.password') ? $this->string('admin.password')->toString() : null,
        ];
    }
}
