<?php

namespace App\Actions\Patients;

use App\Models\Clinic;
use App\Models\PatientFormField;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderPatientForm
{
    /**
     * Put the form in the given order, and optionally move fields between
     * sections in the same drag.
     *
     * One activity entry is written for the whole rearrangement rather than
     * one per field, so the history reads like what the user actually did.
     *
     * @param  list<array{id: int, position?: int, section?: string}>  $order
     */
    public function handle(Clinic $clinic, array $order): void
    {
        DB::transaction(function () use ($clinic, $order): void {
            $fields = $clinic->patientFormFields()->whereIn('id', array_column($order, 'id'))->get()->keyBy('id');

            if ($fields->count() !== count($order)) {
                throw ValidationException::withMessages([
                    'order' => __('That list includes a field this clinic does not have.'),
                ]);
            }

            ActivityLogger::quietly(function () use ($fields, $order): void {
                foreach ($order as $position => $row) {
                    /** @var PatientFormField $field */
                    $field = $fields->get($row['id']);

                    $field->update([
                        'position' => $row['position'] ?? $position,
                        ...(isset($row['section']) ? ['section' => $row['section']] : []),
                    ]);
                }
            });

            app(ActivityLogger::class)->log(
                action: 'patient_form.reordered',
                clinicId: $clinic->id,
                label: $clinic->name,
                context: ['fields' => count($order)],
            );
        });
    }
}
