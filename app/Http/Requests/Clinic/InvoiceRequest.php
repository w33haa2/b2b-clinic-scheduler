<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
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
            'patient_id' => [Rule::excludeIf(! $isCreating), 'required', 'integer', Rule::exists('patients', 'id')
                ->where('clinic_id', $clinic->id)],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'session_line_item_ids' => [$isCreating ? 'required' : 'sometimes', 'array', 'min:1'],
            'session_line_item_ids.*' => ['integer', 'distinct'],
        ];
    }
}
