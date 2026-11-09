<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SessionLineItemRequest extends FormRequest
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

        return [
            'line_item_id' => [
                Rule::excludeIf(! $this->isMethod('POST')),
                'required',
                'integer',
                Rule::exists('line_items', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
            ],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'unit_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            // Null takes the discount off again. What it deducts is snapshotted on the item.
            'discount_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('discounts', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
            ],
        ];
    }
}
