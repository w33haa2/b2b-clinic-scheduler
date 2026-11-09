<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientFormField;
use App\Support\ActivityLogger;
use App\Support\PatientFormSchema;
use BackedEnum;
use DateTimeInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Printable versions of the clinic's patient form: a blank one to hand out
 * at the front desk, and a filled one for a patient's file.
 */
class PatientFormPdfController extends Controller
{
    public function __construct(private PatientFormSchema $schema) {}

    /**
     * Download the clinic's patient form as a blank, printable PDF.
     *
     * Requires the permission to see the form (`clinic.patient-form.show`).
     */
    public function blank(Clinic $clinic): Response
    {
        return $this->render(
            data: ['clinic' => $clinic, 'sections' => $this->sections($clinic), 'patient' => null, 'answers' => []],
            filename: 'patient-form.pdf',
        );
    }

    /**
     * Download one patient's record as a PDF, laid out like the form.
     *
     * Requires the permission to view a patient (`clinic.patients.show`).
     * Each download is recorded in the activity log.
     */
    public function filled(Clinic $clinic, Patient $patient, ActivityLogger $activity): Response
    {
        $patient->loadMissing('patientFieldValues.patientFormField');

        $response = $this->render(
            data: [
                'clinic' => $clinic,
                'sections' => $this->sections($clinic),
                'patient' => $patient,
                'answers' => $this->answers($clinic, $patient),
            ],
            filename: 'patient-'.$patient->id.'.pdf',
        );

        $activity->log('patient.downloaded', $patient, $clinic->id, $patient->activityLabel());

        return $response;
    }

    /**
     * The form grouped into the sections it prints in.
     *
     * @return Collection<string, Collection<int, PatientFormField>>
     */
    private function sections(Clinic $clinic): Collection
    {
        return $this->schema->fieldsFor($clinic)->groupBy('section');
    }

    /**
     * Every answer the patient has, keyed by field key and already turned
     * into something printable.
     *
     * @return array<string, string>
     */
    private function answers(Clinic $clinic, Patient $patient): array
    {
        $answers = [];

        foreach ($this->schema->fieldsFor($clinic) as $field) {
            $value = $field->isBuiltIn()
                ? $patient->getAttribute($field->patient_column)
                : $patient->patientFieldValues->firstWhere('patient_form_field_id', $field->id)?->typedValue();

            $answers[$field->key] = match (true) {
                $value === null || $value === '' => '',
                $value instanceof DateTimeInterface => $value->format('j M Y'),
                // A built-in column can be cast to an enum, e.g. Sex.
                $value instanceof BackedEnum => (string) $value->value,
                is_array($value) => implode(', ', array_map(strval(...), $value)),
                is_bool($value) => $value ? 'Yes' : 'No',
                default => (string) $value,
            };
        }

        return $answers;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data, string $filename): Response
    {
        $fontCache = storage_path('framework/dompdf');
        File::ensureDirectoryExists($fontCache);

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        // vendor/ is read-only in production; font metrics are cached in storage instead.
        $options->setFontCache($fontCache);
        $options->setTempDir(sys_get_temp_dir());

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.patient-form', $data)->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
