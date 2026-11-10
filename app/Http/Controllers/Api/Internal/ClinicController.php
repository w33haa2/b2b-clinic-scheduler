<?php

namespace App\Http\Controllers\Api\Internal;

use App\Actions\Clinics\CreateClinic;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\ClinicRequest;
use App\Http\Requests\Internal\StoreClinicRequest;
use App\Http\Resources\ClinicResource;
use App\Models\Clinic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClinicController extends Controller
{
    /**
     * List all clinics.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $clinics = Clinic::query()
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ClinicResource::collection($clinics);
    }

    /**
     * Create a clinic.
     *
     * The Clinic Admin and Clinic Worker roles are created automatically.
     * Pass `admin` to also create (or attach, by email) the first Clinic Admin.
     */
    public function store(StoreClinicRequest $request, CreateClinic $createClinic): ClinicResource
    {
        $clinic = $createClinic->handle($request->clinicAttributes(), $request->adminAttributes());

        return new ClinicResource($clinic);
    }

    /**
     * Get a clinic.
     */
    public function show(Clinic $clinic): ClinicResource
    {
        return new ClinicResource($clinic);
    }

    /**
     * Update a clinic.
     */
    public function update(ClinicRequest $request, Clinic $clinic): ClinicResource
    {
        $clinic->update($request->validated());

        return new ClinicResource($clinic);
    }

    /**
     * Archive a clinic.
     *
     * The clinic is soft-deleted: its members lose access, but its data is kept.
     */
    public function destroy(Clinic $clinic): Response
    {
        $clinic->delete();

        return response()->noContent();
    }
}
