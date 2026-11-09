<?php

namespace App\Actions\Patients;

use App\Models\PatientFormField;
use Illuminate\Validation\ValidationException;

/**
 * Removes a field from a clinic's patient form, or puts one back.
 *
 * Removing is a soft delete: answers already given stay in the database and
 * reappear if the field is restored. The Deleted tab of the form builder is
 * what makes that visible.
 */
class DeletePatientFormField
{
    /**
     * Take a field off the form.
     *
     * Built-in fields cannot go: they are columns on `patients` that the
     * schedule, invoices and search read. A field people have answered needs
     * `confirm`, so nobody removes a filled-in question by accident.
     */
    public function handle(PatientFormField $field, bool $confirmed = false): void
    {
        if ($field->isBuiltIn()) {
            throw ValidationException::withMessages([
                'patient_form_field' => __('":label" is a built-in field and cannot be removed. Make it optional, or move it to the bottom of the form.', [
                    'label' => $field->label,
                ]),
            ]);
        }

        $answers = $field->answerCount();

        if ($answers > 0 && ! $confirmed) {
            throw ValidationException::withMessages([
                'patient_form_field' => __(':count patients have answered ":label". Removing it hides the question; their answers are kept and come back if you restore it.', [
                    'count' => $answers,
                    'label' => $field->label,
                ]),
            ]);
        }

        $field->delete();
    }

    /**
     * Put a removed field back on the form, with every answer it had.
     *
     * No key clash is possible: `patient_form_fields` is unique on
     * (clinic_id, key) across removed rows too, so a removed field keeps
     * hold of its key until it is restored. That is also why adding a field
     * with an archived key is refused, pointing here instead.
     */
    public function restore(PatientFormField $field): PatientFormField
    {
        $field->restore();

        return $field;
    }
}
