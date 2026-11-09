<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\ClinicRequest;
use App\Http\Resources\ClinicResource;
use App\Http\Resources\ClinicRoleResource;
use App\Models\Clinic;
use App\Support\ClinicContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicController extends Controller
{
    /**
     * List the clinics the authenticated user belongs to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ClinicResource::collection(
            $request->user()->clinics()->orderBy('name')->get(),
        );
    }

    /**
     * Get the clinic with the current member's roles and effective permissions.
     *
     * Use `permissions` to decide which actions to show in the UI. Clinic
     * Admins receive every permission.
     */
    public function show(Clinic $clinic, ClinicContext $context): JsonResource
    {
        $member = $context->member();

        return (new ClinicResource($clinic))->additional(['meta' => [
            'member_id' => $member->id,
            'is_admin' => $member->isAdmin(),
            'roles' => ClinicRoleResource::collection($member->clinicRoles),
            'permissions' => $member->permissionNames(),
        ]]);
    }

    /**
     * Update the clinic's details. Clinic Admin only.
     */
    public function update(ClinicRequest $request, Clinic $clinic): ClinicResource
    {
        $clinic->update($request->validated());

        return new ClinicResource($clinic);
    }
}
