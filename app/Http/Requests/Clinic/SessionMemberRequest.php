<?php

namespace App\Http\Requests\Clinic;

use App\Models\Clinic;
use App\Models\ClinicSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SessionMemberRequest extends FormRequest
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
        /** @var ClinicSession $session */
        $session = $this->route('clinicSession');

        return [
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
                Rule::unique('clinic_session_members', 'patient_id')->where('clinic_session_id', $session->id),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patient_id.unique' => __('This patient is already in the session.'),
        ];
    }
}
