<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Clinics\AddSessionPatient;
use App\Actions\Clinics\RemoveSessionPatient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\SessionMemberRequest;
use App\Http\Resources\SessionMemberResource;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SessionMemberController extends Controller
{
    /**
     * List the patients in a session, with their payment status and billed items.
     */
    public function index(Clinic $clinic, ClinicSession $clinicSession): AnonymousResourceCollection
    {
        return SessionMemberResource::collection(
            $clinicSession->sessionMembers()->with(['patient', 'payment', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem'])->orderBy('id')->get(),
        );
    }

    /**
     * Add a patient to a session.
     *
     * The patient gets an unpaid payment. On a day of a daily session they
     * are also billed the daily session's service (for that day only; enrol
     * them in the daily session to add them to every day).
     */
    public function store(SessionMemberRequest $request, Clinic $clinic, ClinicSession $clinicSession, AddSessionPatient $addSessionPatient): SessionMemberResource
    {
        $this->ensureSessionIsOpen($clinicSession);

        $sessionMember = $addSessionPatient->handle($clinicSession, $request->integer('patient_id'));

        return new SessionMemberResource($sessionMember->load(['patient', 'payment', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem']));
    }

    /**
     * Remove a patient from a session.
     *
     * Their unpaid billed items are removed with them. Not allowed once the
     * patient has paid or been refunded, or while their items are on an invoice.
     */
    public function destroy(Clinic $clinic, ClinicSession $clinicSession, ClinicSessionMember $sessionMember, RemoveSessionPatient $removeSessionPatient): Response
    {
        $this->ensureSessionIsOpen($clinicSession);

        $removeSessionPatient->handle($sessionMember);

        return response()->noContent();
    }

    private function ensureSessionIsOpen(ClinicSession $clinicSession): void
    {
        if (! $clinicSession->status_id->isOpen()) {
            throw ValidationException::withMessages([
                'status' => __('Patients cannot be changed on a :status session.', ['status' => $clinicSession->status_id->code()]),
            ]);
        }
    }
}
