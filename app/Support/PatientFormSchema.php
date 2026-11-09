<?php

namespace App\Support;

use App\Models\Clinic;
use App\Models\PatientFormField;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Turns a clinic's patient form into validation rules.
 *
 * There is one definition of the form, so the staff dialog, the public
 * intake link and the printable PDF all ask for the same things and the API
 * checks them the same way.
 */
class PatientFormSchema
{
    /**
     * Rules a built-in column needs on top of the ones its type gives it.
     * The form can relabel `birth_date`, but it is still a birth date.
     *
     * @var array<string, list<string>>
     */
    private const BUILT_IN_RULES = [
        'birth_date' => ['before_or_equal:today'],
        'email' => ['max:255'],
        'phone' => ['max:50'],
        'first_name' => ['max:255'],
        'middle_name' => ['max:255'],
        'last_name' => ['max:255'],
    ];

    /**
     * The clinic's live form, in display order.
     *
     * @return Collection<int, PatientFormField>
     */
    public function fieldsFor(Clinic $clinic): Collection
    {
        return $clinic->patientFormFields()->inFormOrder()->get();
    }

    /**
     * Validation rules for a patient payload, keyed by field key.
     *
     * On create, required fields are required. On update everything is
     * `sometimes`, so a partial update stays partial — the same contract the
     * rest of the API has.
     *
     * @param  Collection<int, PatientFormField>|null  $fields
     * @return array<string, mixed>
     */
    public function rules(Clinic $clinic, bool $creating, ?Collection $fields = null): array
    {
        $rules = [];

        foreach ($fields ?? $this->fieldsFor($clinic) as $field) {
            $type = $field->patient_form_field_type_id;
            $presence = match (true) {
                ! $creating => 'sometimes',
                $field->is_required => 'required',
                default => 'nullable',
            };

            $rule = [$presence, ...($creating || $field->is_required ? [] : ['nullable']), ...$type->baseRules()];

            if ($field->isBuiltIn()) {
                $rule = [...$rule, ...(self::BUILT_IN_RULES[$field->patient_column] ?? [])];
            }

            if ($type->hasOptions() && $field->options) {
                $rule[] = $type->isMultiValue() ? 'array' : Rule::in($field->options);
            }

            foreach ($this->extraRules($field) as $extra) {
                $rule[] = $extra;
            }

            $rules[$field->key] = array_values(array_unique($rule, SORT_REGULAR));

            if ($type->isMultiValue() && $field->options) {
                $rules["{$field->key}.*"] = ['string', Rule::in($field->options)];
            }
        }

        return $rules;
    }

    /**
     * Field labels, so a message says "Date of birth" and not "birth_date".
     *
     * @param  Collection<int, PatientFormField>|null  $fields
     * @return array<string, string>
     */
    public function attributes(Clinic $clinic, ?Collection $fields = null): array
    {
        return ($fields ?? $this->fieldsFor($clinic))
            ->mapWithKeys(fn (PatientFormField $field): array => [$field->key => mb_strtolower($field->label)])
            ->all();
    }

    /**
     * The limits the builder lets an admin set on a field.
     *
     * @return list<string>
     */
    private function extraRules(PatientFormField $field): array
    {
        $validation = $field->validation ?? [];
        $rules = [];

        if (isset($validation['max_length']) && ! $field->patient_form_field_type_id->isMultiValue()) {
            $rules[] = 'max:'.(int) $validation['max_length'];
        }

        if (isset($validation['min'])) {
            $rules[] = 'min:'.$validation['min'];
        }

        if (isset($validation['max']) && ! isset($validation['max_length'])) {
            $rules[] = 'max:'.$validation['max'];
        }

        return $rules;
    }
}
