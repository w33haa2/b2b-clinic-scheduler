<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issues a link that lets one patient fill the clinic's form in themselves.
 * With `patient_id` the link updates that record; without it, it takes on a
 * new patient.
 */
class PatientFormInvitationRequest extends FormRequest
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
            'patient_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('patients', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
