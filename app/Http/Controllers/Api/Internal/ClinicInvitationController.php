<?php

namespace App\Http\Controllers\Api\Internal;

use App\Actions\Clinics\InviteToClinic;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\ClinicInvitationRequest;
use App\Http\Resources\ClinicInvitationResource;
use App\Models\Clinic;
use App\Models\ClinicInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClinicInvitationController extends Controller
{
    /**
     * List a clinic's invitations, newest first.
     *
     * Each has a `status`: pending, accepted, revoked or expired.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $perPage = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])['per_page'] ?? 25;

        return ClinicInvitationResource::collection(
            $clinic->clinicInvitations()->with(['clinicRole', 'invitedBy'])->latest()->orderByDesc('id')->paginate($perPage),
        );
    }

    /**
     * Invite an email to the clinic.
     *
     * They are emailed a link that works for 7 days and join as a Clinic
     * Worker. With an account they are added on opening the link; without
     * one they choose a password first. Refused for current members, super
     * admins and emails with a pending invitation (resend it instead).
     */
    public function store(ClinicInvitationRequest $request, Clinic $clinic, InviteToClinic $invite): ClinicInvitationResource
    {
        $invitation = $invite->handle($clinic, $request->string('email')->toString(), $request->user());

        return new ClinicInvitationResource($invitation->load(['clinicRole', 'invitedBy']));
    }

    /**
     * Send an invitation again.
     *
     * The email gets a new link (the previous one stops working) valid for
     * another 7 days. Accepted or revoked invitations cannot be resent.
     */
    public function resend(Clinic $clinic, ClinicInvitation $clinicInvitation, InviteToClinic $invite): ClinicInvitationResource
    {
        return new ClinicInvitationResource($invite->resend($clinicInvitation)->load(['clinicRole', 'invitedBy']));
    }

    /**
     * Revoke an invitation; its link stops working.
     */
    public function destroy(Clinic $clinic, ClinicInvitation $clinicInvitation, InviteToClinic $invite): Response
    {
        $invite->revoke($clinicInvitation);

        return response()->noContent();
    }
}
