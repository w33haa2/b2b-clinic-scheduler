<?php

namespace App\Http\Requests\Clinic;

use App\Enums\SessionStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SessionStatusRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(SessionStatus::codes())],
        ];
    }

    public function status(): SessionStatus
    {
        return SessionStatus::fromCode($this->string('status')->toString());
    }
}
