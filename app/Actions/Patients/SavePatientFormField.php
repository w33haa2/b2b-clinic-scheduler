<?php

namespace App\Actions\Patients;

use App\Enums\PatientFormFieldType;
use App\Models\Clinic;
use App\Models\PatientFormField;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a field to a clinic's patient form, or changes one.
 *
 * Changing a form that people have already filled in is the dangerous part,
 * so three things are protected:
 *
 * - A built-in field keeps its key, its type and its column. It is a real
 *   column on `patients` that the schedule, invoices and search read.
 * - Renaming a custom field's key, or changing its type, needs `confirm`
 *   once answers exist.
 * - A type change that the existing answers cannot survive is refused
 *   outright, listing the answers that would break.
 */
class SavePatientFormField
{
    /**
     * How many offending answers to name in a refusal.
     */
    private const SAMPLE_SIZE = 3;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Clinic $clinic, array $attributes, ?PatientFormField $field = null, bool $confirmed = false): PatientFormField
    {
        return DB::transaction(function () use ($clinic, $attributes, $field, $confirmed): PatientFormField {
            if ($field === null) {
                return $clinic->patientFormFields()->create([
                    ...$attributes,
                    'position' => $attributes['position'] ?? ((int) $clinic->patientFormFields()->withTrashed()->max('position') + 1),
                    // Only the seeder creates built-in fields.
                    'patient_column' => null,
                ]);
            }

            $attributes = $this->guard($field, $attributes, $confirmed);

            $field->update($attributes);

            return $field;
        });
    }

    /**
     * Strip or refuse changes that would break existing answers.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function guard(PatientFormField $field, array $attributes, bool $confirmed): array
    {
        // The column a built-in field points at is never settable, by anyone.
        unset($attributes['patient_column']);

        if ($field->isBuiltIn()) {
            return $this->guardBuiltIn($field, $attributes);
        }

        $answers = $field->answerCount();

        if ($answers > 0 && isset($attributes['key']) && $attributes['key'] !== $field->key && ! $confirmed) {
            throw ValidationException::withMessages([
                'key' => __(':count patients have already answered ":label". Renaming its key keeps their answers, but anything that reads the form by key (exports, your own tools) will need updating.', [
                    'count' => $answers,
                    'label' => $field->label,
                ]),
            ]);
        }

        if (isset($attributes['patient_form_field_type_id'])) {
            $this->guardTypeChange($field, $attributes['patient_form_field_type_id'], $answers, $confirmed);
        }

        return $attributes;
    }

    /**
     * A built-in field may be relabelled, reordered, regrouped and given
     * help text. Its key, type and column stay put, and the two columns the
     * database insists on stay required.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function guardBuiltIn(PatientFormField $field, array $attributes): array
    {
        foreach (['key', 'patient_form_field_type_id'] as $locked) {
            if (isset($attributes[$locked]) && $attributes[$locked] != $field->getAttribute($locked)) {
                throw ValidationException::withMessages([
                    $locked => __('":label" is a built-in field. You can rename and reorder it, but its :attribute is fixed.', [
                        'label' => $field->label,
                        'attribute' => $locked === 'key' ? __('key') : __('type'),
                    ]),
                ]);
            }

            unset($attributes[$locked]);
        }

        if (in_array($field->patient_column, PatientFormField::ALWAYS_REQUIRED_COLUMNS, true) && ($attributes['is_required'] ?? true) === false) {
            throw ValidationException::withMessages([
                'is_required' => __('Every patient needs a :label, so this field cannot be made optional.', [
                    'label' => mb_strtolower($field->label),
                ]),
            ]);
        }

        return $attributes;
    }

    /**
     * Answers are plain text, so widening a field is always safe and
     * narrowing one is only safe when every answer already fits.
     */
    private function guardTypeChange(PatientFormField $field, mixed $newType, int $answers, bool $confirmed): void
    {
        $type = $newType instanceof PatientFormFieldType ? $newType : PatientFormFieldType::from((int) $newType);

        if ($type === $field->patient_form_field_type_id || $answers === 0) {
            return;
        }

        if (! $field->patient_form_field_type_id->canSafelyBecome($type)) {
            $offending = $this->answersThatDoNotFit($field, $type);

            if ($offending !== []) {
                throw ValidationException::withMessages([
                    'patient_form_field_type_id' => __('Some answers are not valid :type values, for example: :values. Fix or clear them before changing the type.', [
                        'type' => mb_strtolower($type->code()),
                        'values' => implode(', ', $offending),
                    ]),
                ]);
            }
        }

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'patient_form_field_type_id' => __(':count patients have already answered ":label". Changing its type keeps their answers as they are.', [
                    'count' => $answers,
                    'label' => $field->label,
                ]),
            ]);
        }
    }

    /**
     * A few existing answers the new type would reject.
     *
     * @return list<string>
     */
    private function answersThatDoNotFit(PatientFormField $field, PatientFormFieldType $type): array
    {
        $check = match ($type) {
            PatientFormFieldType::Number => fn (string $value): bool => ! is_numeric($value),
            PatientFormFieldType::Date => fn (string $value): bool => strtotime($value) === false,
            PatientFormFieldType::Email => fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) === false,
            PatientFormFieldType::Select, PatientFormFieldType::Radio => fn (string $value): bool => ! in_array($value, $field->options ?? [], true),
            default => null,
        };

        if ($check === null) {
            return [];
        }

        return array_values(array_map(strval(...), $field->patientFieldValues()
            ->whereNotNull('value')
            ->pluck('value')
            ->filter(fn (string $value): bool => $check($value))
            ->take(self::SAMPLE_SIZE)
            ->all()));
    }

    /**
     * Add several fields at once, the way the import wizard commits what the
     * user confirmed. Keys that already exist are skipped, not overwritten.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<PatientFormField>
     */
    public function bulk(Clinic $clinic, array $fields): array
    {
        return DB::transaction(function () use ($clinic, $fields): array {
            $taken = $clinic->patientFormFields()->withTrashed()->pluck('key')->all();
            $position = (int) $clinic->patientFormFields()->withTrashed()->max('position');
            $created = [];

            foreach ($fields as $attributes) {
                if (in_array($attributes['key'], $taken, true)) {
                    continue;
                }

                $taken[] = $attributes['key'];
                $created[] = $this->handle($clinic, [...$attributes, 'position' => ++$position]);
            }

            if ($created === []) {
                throw ValidationException::withMessages([
                    'fields' => __('Every one of those fields is already on your form.'),
                ]);
            }

            return $created;
        });
    }
}
