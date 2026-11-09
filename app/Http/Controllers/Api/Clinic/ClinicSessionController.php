<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Clinics\AddSessionPatient;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\ClinicSessionRequest;
use App\Http\Resources\ClinicSessionResource;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\SessionLineItem;
use App\Support\HolderAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicSessionController extends Controller
{
    /**
     * List sessions.
     *
     * Filter by a time window (`from` / `to`, ISO 8601), `status`, the member
     * holding the session (`clinic_user_id`), an attending `patient_id`, or
     * `type`: `regular` sessions or `daily` (days of daily sessions, with
     * `daily_session` set and each patient's plotted slot), or the days of
     * one daily session (`daily_session_id`).
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', Rule::in(SessionStatus::codes())],
            'clinic_user_id' => ['sometimes', 'integer'],
            'patient_id' => ['sometimes', 'integer'],
            'type' => ['sometimes', 'string', Rule::in(['regular', 'daily'])],
            'daily_session_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sessions = $clinic->clinicSessions()
            ->with(['clinicUser.user', 'dailySession', 'sessionMembers.patient', 'sessionMembers.payment'])
            ->when(($filters['type'] ?? null) === 'regular', fn ($query) => $query->whereNull('daily_session_id'))
            ->when(($filters['type'] ?? null) === 'daily', fn ($query) => $query->whereNotNull('daily_session_id'))
            ->when($filters['daily_session_id'] ?? null, fn ($query, int|string $id) => $query->where('daily_session_id', $id))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('ends_at', '>', now()->parse($from)->utc()))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('starts_at', '<', now()->parse($to)->utc()))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status_id', SessionStatus::fromCode($status)))
            ->when($filters['clinic_user_id'] ?? null, fn ($query, int|string $id) => $query->where('clinic_user_id', $id))
            ->when($filters['patient_id'] ?? null, fn ($query, int|string $id) => $query->whereHas('sessionMembers', fn ($query) => $query->where('patient_id', $id)))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ClinicSessionResource::collection($sessions);
    }

    /**
     * Create a session.
     *
     * Times may include an offset (e.g. `2026-11-02T09:00:00+08:00`) and are
     * stored in UTC. The member must not already hold an overlapping session.
     * Optionally add patients right away with `patient_ids`.
     */
    public function store(ClinicSessionRequest $request, Clinic $clinic, AddSessionPatient $addSessionPatient): ClinicSessionResource
    {
        $session = DB::transaction(function () use ($request, $clinic, $addSessionPatient): ClinicSession {
            $session = $clinic->clinicSessions()->create($request->sessionAttributes());

            foreach ($request->validated('patient_ids', []) as $patientId) {
                $addSessionPatient->handle($session, (int) $patientId);
            }

            return $session;
        });

        return new ClinicSessionResource($session->load(['clinicUser.user', 'dailySession', 'sessionMembers.patient', 'sessionMembers.payment']));
    }

    /**
     * Get a session with its patients, their billed items and payments.
     *
     * For a day of a daily session, `meta.vacancies` lists the free time
     * inside the day's hours (UTC) where patients can still be plotted, and
     * `meta.busy` the time taken by the staff member's other sessions.
     */
    public function show(Clinic $clinic, ClinicSession $clinicSession, HolderAvailability $availability): ClinicSessionResource
    {
        $resource = new ClinicSessionResource($clinicSession->load([
            'clinicUser.user',
            'dailySession',
            'sessionMembers' => fn ($query) => $query->orderByRaw('starts_at is null')->orderBy('starts_at')->orderBy('id'),
            'sessionMembers.patient',
            'sessionMembers.payment',
            'sessionMembers.sessionLineItems.lineItem',
        ]));

        if (! $clinicSession->isDailyOccurrence()) {
            return $resource;
        }

        $intervals = fn (Collection $intervals): Collection => $intervals
            ->map(fn (array $interval): array => [
                'starts_at' => $interval['starts_at'],
                'ends_at' => $interval['ends_at'],
                'minutes' => (int) $interval['starts_at']->diffInMinutes($interval['ends_at']),
            ])
            ->values();

        return $resource->additional(['meta' => [
            'vacancies' => $intervals($availability->vacancies($clinicSession)),
            'busy' => $intervals($availability->blockedElsewhere($clinicSession)),
        ]]);
    }

    /**
     * Update a session's name, time or assigned member.
     *
     * Only PENDING and ONGOING sessions can change. Use the status endpoint
     * to change the status.
     */
    public function update(ClinicSessionRequest $request, Clinic $clinic, ClinicSession $clinicSession): ClinicSessionResource
    {
        $this->ensureNotDailyOccurrence($clinicSession, __('This is a day of a daily session. Edit the daily session instead.'));

        if (! $clinicSession->status_id->isOpen()) {
            throw ValidationException::withMessages([
                'status' => __('A :status session cannot be changed.', ['status' => $clinicSession->status_id->code()]),
            ]);
        }

        $clinicSession->update($request->sessionAttributes());

        return new ClinicSessionResource($clinicSession->load(['clinicUser.user', 'dailySession', 'sessionMembers.patient', 'sessionMembers.payment']));
    }

    /**
     * Delete a session.
     *
     * Only sessions with no billed items can be deleted; cancel the others.
     */
    public function destroy(Clinic $clinic, ClinicSession $clinicSession): Response
    {
        $this->ensureNotDailyOccurrence($clinicSession, __('A day of a daily session cannot be deleted. Cancel the day instead.'));

        $hasBilledItems = SessionLineItem::query()
            ->whereHas('sessionMember', fn ($query) => $query->whereBelongsTo($clinicSession))
            ->exists();

        if ($hasBilledItems) {
            throw ValidationException::withMessages([
                'session' => __('A session with billed items cannot be deleted. Cancel it instead.'),
            ]);
        }

        $clinicSession->delete();

        return response()->noContent();
    }

    private function ensureNotDailyOccurrence(ClinicSession $clinicSession, string $message): void
    {
        if ($clinicSession->isDailyOccurrence()) {
            throw ValidationException::withMessages(['session' => $message]);
        }
    }
}
