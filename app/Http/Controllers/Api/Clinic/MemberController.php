<?php

namespace App\Http\Controllers\Api\Clinic;

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

class MemberController extends Controller
{
    /**
     * List the clinic's members (staff) and their roles.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])['per_page'] ?? 25;

        return ClinicUserResource::collection(
            $clinic->clinicUsers()->with(['user', 'clinicRoles'])->orderBy('id')->paginate($perPage),
        );
    }

    /**
     * Get a member.
     */
    public function show(Clinic $clinic, ClinicUser $clinicUser): ClinicUserResource
    {
        return new ClinicUserResource($clinicUser->load(['user', 'clinicRoles']));
    }

    /**
     * Add a member. Clinic Admin only.
     *
     * If a user with this email already exists, they are added to the clinic
     * and `name` / `password` are ignored. Without a `password`, the new user
     * sets one through the password-reset flow.
     */
    public function store(MemberRequest $request, Clinic $clinic, AddClinicMember $addClinicMember): ClinicUserResource
    {
        $clinicUser = $addClinicMember->handle($clinic, $request->userAttributes(), $request->roleIds());

        return new ClinicUserResource($clinicUser->load(['user', 'clinicRoles']));
    }

    /**
     * Replace a member's roles. Clinic Admin only.
     *
     * The clinic must keep at least one Clinic Admin.
     */
    public function update(MemberRequest $request, Clinic $clinic, ClinicUser $clinicUser, UpdateClinicMemberRoles $updateRoles): ClinicUserResource
    {
        return new ClinicUserResource($updateRoles->handle($clinicUser, $request->roleIds())->load('user'));
    }

    /**
     * Remove a member. Clinic Admin only.
     *
     * The membership is archived so past sessions keep their member. The
     * last Clinic Admin cannot be removed.
     */
    public function destroy(Clinic $clinic, ClinicUser $clinicUser, RemoveClinicMember $removeClinicMember): Response
    {
        $removeClinicMember->handle($clinicUser);

        return response()->noContent();
    }
}
