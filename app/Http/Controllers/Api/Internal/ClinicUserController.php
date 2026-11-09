<?php

namespace App\Http\Controllers\Api\Internal;

use App\Actions\Clinics\AddClinicMember;
use App\Actions\Clinics\RemoveClinicMember;
use App\Actions\Clinics\UpdateClinicMemberRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\MemberRequest;
use App\Http\Resources\ClinicUserResource;
use App\Models\Clinic;
use App\Models\ClinicUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClinicUserController extends Controller
{
    /**
     * List a clinic's users and their roles.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])['per_page'] ?? 25;

        return ClinicUserResource::collection(
            $clinic->clinicUsers()->with(['user', 'clinicRoles'])->orderBy('id')->paginate($perPage),
        );
    }

    /**
     * Add a user to a clinic.
     *
     * An existing user with this email is attached instead of created.
     */
    public function store(MemberRequest $request, Clinic $clinic, AddClinicMember $addClinicMember): ClinicUserResource
    {
        $clinicUser = $addClinicMember->handle($clinic, $request->userAttributes(), $request->roleIds());

        return new ClinicUserResource($clinicUser->load(['user', 'clinicRoles']));
    }

    /**
     * Get a clinic user.
     */
    public function show(Clinic $clinic, ClinicUser $clinicUser): ClinicUserResource
    {
        return new ClinicUserResource($clinicUser->load(['user', 'clinicRoles']));
    }

    /**
     * Replace a clinic user's roles.
     */
    public function update(MemberRequest $request, Clinic $clinic, ClinicUser $clinicUser, UpdateClinicMemberRoles $updateRoles): ClinicUserResource
    {
        return new ClinicUserResource($updateRoles->handle($clinicUser, $request->roleIds())->load('user'));
    }

    /**
     * Remove a user from a clinic.
     */
    public function destroy(Clinic $clinic, ClinicUser $clinicUser, RemoveClinicMember $removeClinicMember): Response
    {
        $removeClinicMember->handle($clinicUser);

        return response()->noContent();
    }
}
