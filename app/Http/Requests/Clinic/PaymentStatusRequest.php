<?php

namespace App\Http\Requests\Clinic;

use App\Enums\PaymentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentStatusRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_status' => ['required', 'string', Rule::in([PaymentStatus::Done->code(), PaymentStatus::Refunded->code()])],
        ];
    }

    public function paymentStatus(): PaymentStatus
    {
        return PaymentStatus::fromCode($this->string('payment_status')->toString());
    }
}
