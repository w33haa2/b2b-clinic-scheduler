<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Support\PatientFormSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a patient against the clinic's own form, so adding a field to
 * the form is all it takes for the API to start accepting and checking it.
 */
class PatientRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return app(PatientFormSchema::class)->rules($this->clinic(), $this->isMethod('POST'));
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return app(PatientFormSchema::class)->attributes($this->clinic());
    }

    /**
     * The answers, keyed by field key. `clinic_id` can never appear here:
     * the rules are built from the form, and the clinic is not on it.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        return $this->safe()->all();
    }

    private function clinic(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return $clinic;
    }
}
