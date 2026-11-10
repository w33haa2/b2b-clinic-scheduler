<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Patients\SavePatientRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PatientController extends Controller
{
    /**
     * List the clinic's patients.
     *
     * `search` matches first name, last name, email or phone. Pass
     * `with_fields=1` to include the answers to the clinic's own form
     * fields under `fields`.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'with_fields' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $patients = $clinic->patients()
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->whereLike('first_name', "%{$search}%")
                ->orWhereLike('last_name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")
                ->orWhereLike('phone', "%{$search}%")))
            ->when($filters['with_fields'] ?? false, fn ($query) => $query->with('patientFieldValues.patientFormField'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return PatientResource::collection($patients);
    }

    /**
     * Create a patient.
     *
     * The body is checked against the clinic's own patient form, so it
     * accepts whatever fields the clinic has added. Built-in fields are
     * written to their column; the rest are stored as answers.
     */
    public function store(PatientRequest $request, Clinic $clinic, SavePatientRecord $save): PatientResource
    {
        $patient = $save->handle($clinic, $request->answers());

        return new PatientResource($patient->load('patientFieldValues.patientFormField'));
    }

    /**
     * Get a patient, with the answers to the clinic's form fields.
     */
    public function show(Clinic $clinic, Patient $patient): PatientResource
    {
        return new PatientResource($patient->load('patientFieldValues.patientFormField'));
    }

    /**
     * Update a patient. Partial: send only the fields to change.
     */
    public function update(PatientRequest $request, Clinic $clinic, Patient $patient, SavePatientRecord $save): PatientResource
    {
        $save->handle($clinic, $request->answers(), $patient);

        return new PatientResource($patient->fresh()->load('patientFieldValues.patientFormField'));
    }

    /**
     * Archive a patient.
     *
     * The patient is soft-deleted: they disappear from lists, but their
     * sessions, bills and invoices are kept.
     */
    public function destroy(Clinic $clinic, Patient $patient): Response
    {
        $patient->delete();

        return response()->noContent();
    }
}
