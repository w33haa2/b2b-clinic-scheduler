<?php

namespace App\Actions\Patients;

use App\Enums\PatientFormFieldType;
use App\Enums\Sex;
use App\Models\Clinic;
use App\Models\PatientFormField;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;

/**
 * Seeds a clinic's patient form with the nine built-in fields: the columns
 * `patients` has always had, in the order the old dialog showed them. Every
 * clinic starts from this and edits it.
 *
 * Built-in fields keep a `patient_column`, so they read and write the real
 * column. That is what lets the schedule, invoices and patient search go on
 * working however the clinic rearranges its form.
 */
class CreateDefaultPatientForm
{
    /**
     * The built-in form, in order.
     *
     * @var list<array<string, mixed>>
     */
    public const FIELDS = [
        ['key' => 'first_name', 'label' => 'First name', 'type' => PatientFormFieldType::Text, 'section' => 'Personal details', 'required' => true, 'validation' => ['max_length' => 255]],
        ['key' => 'middle_name', 'label' => 'Middle name', 'type' => PatientFormFieldType::Text, 'section' => 'Personal details', 'validation' => ['max_length' => 255]],
        ['key' => 'last_name', 'label' => 'Last name', 'type' => PatientFormFieldType::Text, 'section' => 'Personal details', 'required' => true, 'validation' => ['max_length' => 255]],
        ['key' => 'birth_date', 'label' => 'Date of birth', 'type' => PatientFormFieldType::Date, 'section' => 'Personal details'],
        // Options come from the Sex enum at run time, so the two cannot drift.
        ['key' => 'sex', 'label' => 'Sex', 'type' => PatientFormFieldType::Select, 'section' => 'Personal details'],
        ['key' => 'email', 'label' => 'Email', 'type' => PatientFormFieldType::Email, 'section' => 'Contact'],
        ['key' => 'phone', 'label' => 'Phone', 'type' => PatientFormFieldType::Phone, 'section' => 'Contact'],
        ['key' => 'address', 'label' => 'Address', 'type' => PatientFormFieldType::Textarea, 'section' => 'Contact', 'validation' => ['max_length' => 1000]],
        ['key' => 'notes', 'label' => 'Notes', 'type' => PatientFormFieldType::Textarea, 'section' => 'Notes', 'help_text' => 'Anything the clinic should know.', 'validation' => ['max_length' => 5000]],
    ];

    /**
     * Give the clinic the built-in fields it does not have yet.
     *
     * Quiet: a clinic is *created with* this form, so the activity log should
     * not read as though somebody added nine fields by hand.
     *
     * @return Collection<int, PatientFormField>
     */
    public function handle(Clinic $clinic): Collection
    {
        return ActivityLogger::quietly(fn (): Collection => $this->seed($clinic));
    }

    /**
     * @return Collection<int, PatientFormField>
     */
    private function seed(Clinic $clinic): Collection
    {
        $existing = $clinic->patientFormFields()->withTrashed()->pluck('key')->all();
        $created = collect();

        foreach (self::FIELDS as $position => $field) {
            if (in_array($field['key'], $existing, true)) {
                continue;
            }

            $created->push($clinic->patientFormFields()->create([
                'key' => $field['key'],
                'label' => $field['label'],
                'patient_form_field_type_id' => $field['type'],
                'options' => $field['key'] === 'sex' ? self::sexOptions() : null,
                'validation' => $field['validation'] ?? null,
                'help_text' => $field['help_text'] ?? null,
                'is_required' => $field['required'] ?? false,
                'section' => $field['section'],
                'position' => $position,
                // The column this field is. Never changes after this point.
                'patient_column' => $field['key'],
            ]));
        }

        return $created;
    }

    /**
     * The choices the `sex` column accepts, so the seeded field and the Sex
     * enum can never drift apart.
     *
     * @return list<string>
     */
    public static function sexOptions(): array
    {
        return array_map(fn (Sex $sex): string => $sex->value, Sex::cases());
    }
}
