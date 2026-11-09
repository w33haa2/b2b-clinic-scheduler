<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Patients\DeletePatientFormField;
use App\Actions\Patients\SavePatientFormField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PatientFormFieldRequest;
use App\Http\Resources\PatientFormFieldResource;
use App\Models\Clinic;
use App\Models\PatientFormField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PatientFormFieldController extends Controller
{
    /**
     * List the inputs on the clinic's patient form, in display order.
     *
     * `state` is `active` (the default), `archived` for fields that were
     * removed, or `all`. Pass `with_usage=1` to include how many patients
     * have answered each one — what the removal and rename prompts count.
     *
     * The list is short and naturally bounded, so it is returned whole.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'state' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])],
            'with_usage' => ['sometimes', 'boolean'],
        ]);

        $fields = $clinic->patientFormFields()
            ->when(($filters['state'] ?? 'active') === 'archived', fn ($query) => $query->onlyTrashed())
            ->when(($filters['state'] ?? 'active') === 'all', fn ($query) => $query->withTrashed())
            ->inFormOrder()
            ->get();

        return PatientFormFieldResource::collection($fields);
    }

    /**
     * Add an input to the patient form.
     */
    public function store(PatientFormFieldRequest $request, Clinic $clinic, SavePatientFormField $save): PatientFormFieldResource
    {
        return new PatientFormFieldResource($save->handle($clinic, $request->fieldAttributes()));
    }

    /**
     * Get one input.
     */
    public function show(Clinic $clinic, PatientFormField $patientFormField): PatientFormFieldResource
    {
        return new PatientFormFieldResource($patientFormField);
    }

    /**
     * Change an input.
     *
     * Renaming the key or changing the type of a field patients have already
     * answered is refused with a 422 explaining what is at stake; send
     * `confirm: true` to go ahead. A built-in field keeps its key, type and
     * column whatever is sent.
     */
    public function update(
        PatientFormFieldRequest $request,
        Clinic $clinic,
        PatientFormField $patientFormField,
        SavePatientFormField $save,
    ): PatientFormFieldResource {
        return new PatientFormFieldResource(
            $save->handle($clinic, $request->fieldAttributes(), $patientFormField, $request->isConfirmed()),
        );
    }

    /**
     * Take an input off the form.
     *
     * This is a soft delete: answers already given are kept and come back if
     * the field is restored. Removing a field patients have answered needs
     * `confirm: true`; a built-in field cannot be removed at all.
     */
    public function destroy(
        Request $request,
        Clinic $clinic,
        PatientFormField $patientFormField,
        DeletePatientFormField $delete,
    ): Response {
        $delete->handle($patientFormField, $request->boolean('confirm'));

        return response()->noContent();
    }

    /**
     * Put a removed input back, with every answer it had.
     */
    public function restore(Clinic $clinic, int $patientFormField, DeletePatientFormField $delete): PatientFormFieldResource
    {
        $field = $clinic->patientFormFields()->onlyTrashed()->findOrFail($patientFormField);

        return new PatientFormFieldResource($delete->restore($field));
    }

    /**
     * How many patients have answered this input.
     *
     * The form builder asks before offering to remove, rename or retype a
     * field, so the warning it shows names a real number.
     */
    public function usage(Clinic $clinic, PatientFormField $patientFormField): JsonResponse
    {
        return response()->json(['data' => [
            'field_id' => $patientFormField->id,
            'answer_count' => $patientFormField->answerCount(),
            'is_built_in' => $patientFormField->isBuiltIn(),
            'can_be_removed' => ! $patientFormField->isBuiltIn(),
        ]]);
    }
}
