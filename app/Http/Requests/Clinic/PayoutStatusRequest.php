<?php

namespace App\Http\Requests\Clinic;

use App\Enums\PayoutStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayoutStatusRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([PayoutStatus::Paid->code(), PayoutStatus::Cancelled->code()])],
        ];
    }

    public function payoutStatus(): PayoutStatus
    {
        return PayoutStatus::fromCode($this->string('status')->toString());
    }
}
