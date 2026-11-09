<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PatientFormInvitationRequest;
use App\Http\Resources\PatientFormInvitationResource;
use App\Models\Clinic;
use App\Models\PatientFormInvitation;
use App\Support\ClinicContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PatientFormInvitationController extends Controller
{
    /**
     * List the intake links the clinic has issued.
     *
     * Filter by `status` (`pending`, `submitted`, `revoked`, `expired`) and
     * `patient_id`. The link itself is never in the list: only a hash of the
     * token is stored, so it exists in one response and then only in the
     * message you sent.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['pending', 'submitted', 'revoked', 'expired'])],
            'patient_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $invitations = $clinic->patientFormInvitations()
            ->when($filters['patient_id'] ?? null, fn ($query, int|string $id) => $query->where('patient_id', $id))
            ->when($filters['status'] ?? null, fn ($query, string $status) => match ($status) {
                'pending' => $query->pending(),
                'submitted' => $query->whereNotNull('submitted_at'),
                'revoked' => $query->whereNull('submitted_at')->whereNotNull('revoked_at'),
                default => $query->whereNull('submitted_at')->whereNull('revoked_at')->where('expires_at', '<=', now()),
            })
            ->with(['patient', 'createdBy.user'])
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);

        return PatientFormInvitationResource::collection($invitations);
    }

    /**
     * Issue a link that lets a patient fill the form in themselves.
     *
     * With `patient_id` the link updates that record; without it, it takes
     * on a new patient. The response is the only place the link appears —
     * copy it, or send it on. It works once and expires after 14 days.
     */
    public function store(PatientFormInvitationRequest $request, Clinic $clinic, ClinicContext $context): JsonResponse
    {
        $invitation = $clinic->patientFormInvitations()->make($request->validated());
        $invitation->created_by_clinic_user_id = $context->member()->id;
        $token = $invitation->issueToken();
        $invitation->save();

        return PatientFormInvitationResource::withLink($invitation->load(['patient', 'createdBy.user']), route('patient-form.show', $token))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Issue a new link for the same person. The old one stops working.
     */
    public function resend(Clinic $clinic, PatientFormInvitation $patientFormInvitation): JsonResponse
    {
        abort_unless($patientFormInvitation->submitted_at === null, 422);

        $token = $patientFormInvitation->issueToken();
        $patientFormInvitation->revoked_at = null;
        $patientFormInvitation->save();

        return PatientFormInvitationResource::withLink(
            $patientFormInvitation->load(['patient', 'createdBy.user']),
            route('patient-form.show', $token),
        )->response();
    }

    /**
     * Cancel a link so it stops working.
     */
    public function destroy(Clinic $clinic, PatientFormInvitation $patientFormInvitation): Response
    {
        $patientFormInvitation->forceFill(['revoked_at' => now()])->save();

        return response()->noContent();
    }
}
