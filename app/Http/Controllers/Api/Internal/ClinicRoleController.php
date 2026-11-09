<?php

namespace App\Http\Controllers\Api\Internal;

use App\Actions\Clinics\DeleteClinicRole;
use App\Actions\Clinics\SaveClinicRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\RoleRequest;
use App\Http\Resources\ClinicRoleResource;
use App\Models\Clinic;
use App\Models\ClinicRole;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClinicRoleController extends Controller
{
    /**
     * List a clinic's roles with their permissions.
     */
    public function index(Clinic $clinic): AnonymousResourceCollection
    {
        return ClinicRoleResource::collection(
            $clinic->clinicRoles()->with('clinicPermissions')->orderBy('id')->get(),
        );
    }

    /**
     * Create a custom role in a clinic.
     */
    public function store(RoleRequest $request, Clinic $clinic, SaveClinicRole $saveClinicRole): ClinicRoleResource
    {
        return new ClinicRoleResource($saveClinicRole->handle($clinic, $request->validated()));
    }

    /**
     * Get a clinic role.
     */
    public function show(Clinic $clinic, ClinicRole $clinicRole): ClinicRoleResource
    {
        return new ClinicRoleResource($clinicRole->load('clinicPermissions'));
    }

    /**
     * Update a clinic role's name or permissions.
     *
     * The same rules as the clinic API apply: the Clinic Admin role cannot
     * change and the Clinic Worker role cannot be renamed.
     */
    public function update(RoleRequest $request, Clinic $clinic, ClinicRole $clinicRole, SaveClinicRole $saveClinicRole): ClinicRoleResource
    {
        return new ClinicRoleResource($saveClinicRole->handle($clinic, $request->validated(), $clinicRole));
    }

    /**
     * Delete a custom clinic role.
     */
    public function destroy(Clinic $clinic, ClinicRole $clinicRole, DeleteClinicRole $deleteClinicRole): Response
    {
        $deleteClinicRole->handle($clinicRole);

        return response()->noContent();
    }
}
