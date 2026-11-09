<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\LineItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LineItemRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');
        /** @var LineItem|null $lineItem */
        $lineItem = $this->route('lineItem');

        return [
            'item_code' => [$required, 'string', 'max:50', Rule::unique('line_items', 'item_code')
                ->where('clinic_id', $clinic->id)
                ->ignore($lineItem?->id)],
            'name' => [$required, 'string', 'max:255'],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }
}
