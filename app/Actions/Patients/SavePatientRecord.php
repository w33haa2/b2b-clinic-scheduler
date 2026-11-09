<?php

namespace App\Actions\Patients;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientFieldValue;
use App\Models\PatientFormField;
use App\Support\PatientFormSchema;
use Illuminate\Support\Facades\DB;

/**
 * Saves a patient from a form payload keyed by field key.
 *
 * Built-in fields go to their column on `patients`; everything else goes to
 * `patient_field_values`. The staff dialog, the public intake link and the
 * API all come through here, so a patient is written the same way whoever
 * fills the form in.
 */
class SavePatientRecord
{
    public function __construct(private PatientFormSchema $schema) {}

    /**
     * @param  array<string, mixed>  $data  Validated answers, keyed by field key.
     */
    public function handle(Clinic $clinic, array $data, ?Patient $patient = null): Patient
    {
        return DB::transaction(function () use ($clinic, $data, $patient): Patient {
            $fields = $this->schema->fieldsFor($clinic);
            $columns = [];

            foreach ($fields as $field) {
                if ($field->isBuiltIn() && array_key_exists($field->key, $data)) {
                    $columns[$field->patient_column] = $data[$field->key] === '' ? null : $data[$field->key];
                }
            }

            // clinic_id is never taken from the payload: it is the tenant key.
            $patient = $patient === null
                ? $clinic->patients()->create($columns)
                : tap($patient)->update($columns);

            foreach ($fields as $field) {
                if ($field->isBuiltIn() || ! array_key_exists($field->key, $data)) {
                    continue;
                }

                $this->saveValue($patient, $field, $data[$field->key]);
            }

            return $patient;
        });
    }

    /**
     * Store one custom answer, or clear it when the patient left it blank.
     */
    private function saveValue(Patient $patient, PatientFormField $field, mixed $value): void
    {
        $encoded = PatientFieldValue::encode($value);

        if ($encoded === null) {
            $patient->patientFieldValues()->where('patient_form_field_id', $field->id)->delete();

            return;
        }

        $patient->patientFieldValues()->updateOrCreate(
            ['patient_form_field_id' => $field->id],
            ['value' => $encoded],
        );
    }
}
