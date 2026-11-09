<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Clinics\DeleteClinicRole;
use App\Actions\Clinics\SaveClinicRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\RoleRequest;
use App\Http\Resources\ClinicRoleResource;
use App\Models\Clinic;
use App\Models\ClinicRole;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RoleController extends Controller
{
    /**
     * List the clinic's roles with their permissions.
     *
     * The Clinic Admin role has every permission implicitly, so its list is empty.
     */
    public function index(Clinic $clinic): AnonymousResourceCollection
    {
        return ClinicRoleResource::collection(
            $clinic->clinicRoles()->with('clinicPermissions')->orderBy('id')->get(),
        );
    }

    /**
     * Get a role.
     */
    public function show(Clinic $clinic, ClinicRole $clinicRole): ClinicRoleResource
    {
        return new ClinicRoleResource($clinicRole->load('clinicPermissions'));
    }

    /**
     * Create a custom role. Clinic Admin only.
     *
     * `permission_ids` come from the permissions list.
     */
    public function store(RoleRequest $request, Clinic $clinic, SaveClinicRole $saveClinicRole): ClinicRoleResource
    {
        return new ClinicRoleResource($saveClinicRole->handle($clinic, $request->validated()));
    }

    /**
     * Update a role's name or permissions. Clinic Admin only.
     *
     * `permission_ids` replaces the whole list. The Clinic Admin role cannot
     * change; the Clinic Worker role cannot be renamed.
     */
    public function update(RoleRequest $request, Clinic $clinic, ClinicRole $clinicRole, SaveClinicRole $saveClinicRole): ClinicRoleResource
    {
        return new ClinicRoleResource($saveClinicRole->handle($clinic, $request->validated(), $clinicRole));
    }

    /**
     * Delete a custom role. Clinic Admin only.
     *
     * The built-in Clinic Admin and Clinic Worker roles cannot be deleted.
     */
    public function destroy(Clinic $clinic, ClinicRole $clinicRole, DeleteClinicRole $deleteClinicRole): Response
    {
        $deleteClinicRole->handle($clinicRole);

        return response()->noContent();
    }
}
