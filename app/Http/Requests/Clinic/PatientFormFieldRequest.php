<?php

namespace App\Http\Requests\Clinic;

use App\Enums\PatientFormFieldType;
use App\Models\Clinic;
use App\Models\PatientFormField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adds an input to the clinic's patient form, or edits one.
 *
 * `confirm: true` acknowledges a change that affects answers patients have
 * already given; the action refuses without it.
 */
class PatientFormFieldRequest extends FormRequest
{
    /**
     * Keys the form can never use.
     *
     * The first group would collide with the patient resource; the second
     * with the properties Inertia's form helpers put on a form object, which
     * is keyed by field key on both the staff dialog and the public intake
     * page. A field called "errors" would quietly break the form it is on.
     *
     * @var list<string>
     */
    public const RESERVED_KEYS = [
        'id', 'clinic_id', 'created_at', 'updated_at', 'deleted_at', 'fields', 'archived_fields',
        'data', 'errors', 'hasErrors', 'processing', 'progress', 'wasSuccessful', 'recentlySuccessful',
        'isDirty', 'reset', 'clearErrors', 'setError', 'transform', 'defaults', 'cancel', 'submit',
        'get', 'post', 'put', 'patch', 'delete', '_token', '_method',
    ];

    /**
     * Turn a label into a key when the builder did not send one.
     */
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST') && ! $this->filled('key') && $this->filled('label')) {
            $this->merge(['key' => Str::of($this->string('label'))->ascii()->snake()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->value()]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'key' => [
                $required,
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::notIn(self::RESERVED_KEYS),
                // Live fields only, so an archived clash can be reported with
                // a message that actually helps (see after()).
                Rule::unique('patient_form_fields', 'key')
                    ->where('clinic_id', $this->clinic()->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->field()?->id),
            ],
            'label' => [$required, 'string', 'max:255'],
            'type' => [$required, 'string', Rule::in(PatientFormFieldType::codes())],
            'placeholder' => ['sometimes', 'nullable', 'string', 'max:255'],
            'help_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'options' => ['sometimes', 'array', 'max:50'],
            'options.*' => ['string', 'distinct', 'max:100'],
            'is_required' => ['sometimes', 'boolean'],
            'section' => ['sometimes', 'string', 'max:255'],
            'validation' => ['sometimes', 'nullable', 'array:max_length,min,max'],
            'validation.max_length' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'validation.min' => ['sometimes', 'nullable', 'numeric'],
            'validation.max' => ['sometimes', 'nullable', 'numeric'],
            'confirm' => ['sometimes', 'boolean'],
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
            'key.regex' => __('A key starts with a letter and uses only lower-case letters, numbers and underscores.'),
            'key.unique' => __('Another field on this form already uses that key.'),
            'key.not_in' => __('That key is reserved. Choose another one.'),
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkArchivedKey($validator);
                $this->checkChoices($validator);
            },
        ];
    }

    /**
     * A removed field still owns its key, because its answers are still
     * attached to it. Say so, rather than "already in use" about something
     * the admin cannot see.
     */
    private function checkArchivedKey(Validator $validator): void
    {
        if (! $this->filled('key')) {
            return;
        }

        $archived = $this->clinic()->patientFormFields()
            ->onlyTrashed()
            ->where('key', $this->string('key')->toString())
            ->whereKeyNot($this->field()?->id)
            ->first();

        if ($archived !== null) {
            $validator->errors()->add('key', __('":label" was removed from the form but still holds that key, and the answers given to it. Restore it from the Removed tab, or choose another key.', [
                'label' => $archived->label,
            ]));
        }
    }

    /**
     * A dropdown with one choice is not a dropdown.
     */
    private function checkChoices(Validator $validator): void
    {
        if (! $this->filled('type')) {
            return;
        }

        $type = PatientFormFieldType::fromCode($this->string('type')->toString());
        $field = $this->field();
        $options = (array) $this->input('options', $field === null ? [] : ($field->options ?? []));

        if ($type->hasOptions() && count($options) < 2) {
            $validator->errors()->add('options', __('A :type field needs at least two choices.', [
                'type' => mb_strtolower($type->code()),
            ]));
        }
    }

    /**
     * The attributes to save, with the type code turned into its lookup ID.
     *
     * @return array<string, mixed>
     */
    public function fieldAttributes(): array
    {
        $attributes = $this->safe()->except(['type', 'confirm']);

        if ($this->filled('type')) {
            $attributes['patient_form_field_type_id'] = PatientFormFieldType::fromCode($this->string('type')->toString());

            if (! $attributes['patient_form_field_type_id']->hasOptions()) {
                $attributes['options'] = null;
            }
        }

        return $attributes;
    }

    /**
     * Whether the user has accepted a change that affects existing answers.
     */
    public function isConfirmed(): bool
    {
        return $this->boolean('confirm');
    }

    private function clinic(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->route('clinic');

        return $clinic;
    }

    private function field(): ?PatientFormField
    {
        /** @var PatientFormField|null $field */
        $field = $this->route('patientFormField');

        return $field;
    }
}
