<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Patients\SavePatientFormField;
use App\Enums\PatientFormFieldType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PatientFormFieldResource;
use App\Models\Clinic;
use App\Support\FormExtraction\FormExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PatientFormExtractionController extends Controller
{
    /**
     * Read an existing form and suggest the inputs it contains.
     *
     * Send a `file` (CSV, TSV, JSON, HTML, DOCX or a text-based PDF) or
     * `text` pasted from one. **Nothing is saved**: the response is a list
     * of suggestions for the import wizard to show, each with the line it
     * came from, how sure we are, and whether the clinic already has it.
     * The upload is read in place and never written to storage.
     *
     * A scanned PDF holds no text to read; the response says so in
     * `warning` and the wizard offers the paste box instead.
     */
    public function store(Request $request, Clinic $clinic, FormExtractor $extractor): JsonResponse
    {
        $supported = implode(',', $extractor->supportedExtensions());

        $request->validate([
            // `extensions` checks what the user uploaded, `mimes` checks what
            // it actually contains. An image renamed to .csv fails the second;
            // a text file renamed to .png fails the first.
            'file' => ['required_without:text', 'file', 'max:10240', "extensions:{$supported}", "mimes:{$supported}"],
            'text' => ['required_without:file', 'string', 'max:200000'],
        ], [
            'file.extensions' => __('We can read CSV, TSV, JSON, HTML, Word and text-based PDF forms. For a scan or a photo, paste the text instead.'),
            'file.mimes' => __('That file is not the kind it claims to be.'),
        ]);

        $result = $request->hasFile('file')
            ? $extractor->fromUpload($clinic, $request->file('file'))
            : $extractor->fromText($clinic, $request->string('text')->toString());

        return response()->json(['data' => $result]);
    }

    /**
     * Add the inputs the user confirmed in the import wizard.
     *
     * Keys the form already has are skipped rather than overwritten, so
     * running the wizard twice is harmless.
     */
    public function bulk(Request $request, Clinic $clinic, SavePatientFormField $save): AnonymousResourceCollection
    {
        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1', 'max:100'],
            'fields.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', 'string', Rule::in(PatientFormFieldType::codes())],
            'fields.*.options' => ['sometimes', 'array', 'max:50'],
            'fields.*.options.*' => ['string', 'max:100'],
            'fields.*.section' => ['sometimes', 'string', 'max:255'],
            'fields.*.is_required' => ['sometimes', 'boolean'],
            'fields.*.help_text' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $created = $save->bulk($clinic, array_values(array_map(fn (array $field): array => [
            'key' => $field['key'],
            'label' => $field['label'],
            'patient_form_field_type_id' => PatientFormFieldType::fromCode($field['type']),
            'options' => PatientFormFieldType::fromCode($field['type'])->hasOptions() ? ($field['options'] ?? []) : null,
            'section' => $field['section'] ?? 'Patient details',
            'is_required' => $field['is_required'] ?? false,
            'help_text' => $field['help_text'] ?? null,
        ], $data['fields'])));

        return PatientFormFieldResource::collection(collect($created));
    }
}
