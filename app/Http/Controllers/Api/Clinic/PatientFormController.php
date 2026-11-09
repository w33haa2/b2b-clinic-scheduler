<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Patients\ReorderPatientForm;
use App\Http\Controllers\Controller;
use App\Http\Resources\PatientFormFieldResource;
use App\Models\Clinic;
use App\Support\PatientFormSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientFormController extends Controller
{
    /**
     * The clinic's patient form, grouped into the sections it is drawn in.
     *
     * This is what the patient dialog, the intake link and the printable
     * form all render from, so they can never drift apart.
     */
    public function show(Clinic $clinic, PatientFormSchema $schema): JsonResponse
    {
        $fields = $schema->fieldsFor($clinic);

        return response()->json(['data' => [
            'clinic' => ['id' => $clinic->id, 'name' => $clinic->name],
            'sections' => $fields
                ->groupBy('section')
                ->map(fn ($sectionFields, string $section): array => [
                    'name' => $section,
                    'fields' => PatientFormFieldResource::collection($sectionFields->values())->resolve(),
                ])
                ->values()
                ->all(),
            'fields' => PatientFormFieldResource::collection($fields)->resolve(),
        ]]);
    }

    /**
     * Put the form in a new order, and move fields between sections.
     *
     * Send every field once, in the order it should appear. One activity
     * entry is written for the whole rearrangement.
     */
    public function reorder(Request $request, Clinic $clinic, ReorderPatientForm $reorder): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('patient_form_fields', 'id')->where('clinic_id', $clinic->id)->whereNull('deleted_at'),
            ],
            'order.*.position' => ['sometimes', 'integer', 'min:0'],
            'order.*.section' => ['sometimes', 'string', 'max:255'],
        ]);

        $reorder->handle($clinic, $data['order']);

        return $this->show($clinic, app(PatientFormSchema::class));
    }
}
