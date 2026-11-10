<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Clinics\DeleteDailySession;
use App\Actions\Clinics\SaveDailySession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\DailySessionRequest;
use App\Http\Requests\Clinic\PrepaymentRequest;
use App\Http\Resources\DailySessionResource;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\DailySession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DailySessionController extends Controller
{
    /**
     * List daily sessions.
     *
     * `active=1` leaves out the ones whose end date has passed. `search`
     * matches the name.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $today = CarbonImmutable::now($clinic->timezone)->toDateString();

        $dailySessions = $clinic->dailySessions()
            ->with(['clinicUser.user', 'lineItem', 'clinic'])
            ->withCount('dailySessionPatients')
            ->when($request->boolean('active'), fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $today)))
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return DailySessionResource::collection($dailySessions);
    }

    /**
     * Create a daily session.
     *
     * A staff member holds at most one daily session (`clinic_user_id` is
     * unique): keep it up to date with an update instead of creating another.
     * The session repeats on `weekdays` (ISO: 1 = Monday … 7 = Sunday) from
     * `starts_on` until `ends_on` (optional), between `start_time` and
     * `end_time` in the clinic's timezone. `line_item_id` at `unit_price`
     * (defaults to the catalog price) is the default service. Enrol patients
     * right away with `patients`: each a `patient_id` with an optional plan,
     * `line_item_id` / `unit_price` (their service; the default otherwise)
     * and a usual slot, `start_time` + `duration_minutes`. Usual slots must
     * be inside the hours and must not overlap. `patient_ids` enrols with the
     * default service and no slot. The next 14 days are scheduled at once:
     * every patient is billed their service with an unpaid payment and put
     * into their usual slot where the staff member is free.
     */
    public function store(DailySessionRequest $request, Clinic $clinic, SaveDailySession $saveDailySession): DailySessionResource
    {
        $dailySession = $saveDailySession->create($clinic, $request->dailySessionAttributes(), $request->patients());

        return $this->resource($dailySession);
    }

    /**
     * Get a daily session with its enrolled patients.
     *
     * `meta.upcoming_days` lists the days it runs on from today (up to 90
     * days ahead): the date, the window in UTC and, once scheduled, the
     * clinic session ID of that day. Use them to prepay days.
     */
    public function show(Clinic $clinic, DailySession $dailySession): DailySessionResource
    {
        $today = $dailySession->today();
        $scheduled = $dailySession->clinicSessions()
            ->whereDate('occurs_on', '>=', $today->toDateString())
            ->get(['id', 'occurs_on', 'status_id'])
            ->keyBy(fn (ClinicSession $day): string => $day->occurs_on->toDateString());

        $upcomingDays = $dailySession->datesBetween($today, $today->addDays(PrepaymentRequest::MAX_DAYS_AHEAD))
            ->map(function (CarbonImmutable $date) use ($dailySession, $scheduled): array {
                [$startsAt, $endsAt] = $dailySession->windowOn($date);
                $day = $scheduled->get($date->toDateString());

                return [
                    'date' => $date->toDateString(),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'clinic_session_id' => $day?->id,
                    'status' => $day?->status_id->code(),
                ];
            })
            ->values();

        return $this->resource($dailySession)->additional(['meta' => ['upcoming_days' => $upcomingDays]]);
    }

    /**
     * Update a daily session.
     *
     * Days that have not started are re-planned: they take the new name,
     * holder and hours, and patients go back into their usual slots (days
     * changed by hand keep their slot if it still fits). Days no longer on
     * the schedule are removed (refused if a patient prepaid one). A new
     * default service applies to patients enrolled afterwards. Set `ends_on`
     * to stop the session. Days that already happened never change.
     *
     * Send `patients` (the full list, as on create) to sync the patients:
     * new ones are enrolled, changed plans apply to upcoming days (except
     * days changed by hand) and those left out leave upcoming days. All or
     * nothing: a patient who prepaid an upcoming day cannot be left out.
     */
    public function update(DailySessionRequest $request, Clinic $clinic, DailySession $dailySession, SaveDailySession $saveDailySession): DailySessionResource
    {
        $dailySession = $saveDailySession->update($dailySession, $request->dailySessionAttributes(), $request->patientPlans());

        return $this->resource($dailySession);
    }

    /**
     * Delete a daily session with all its days.
     *
     * Only possible while nobody has paid for any day; otherwise set
     * `ends_on` instead.
     */
    public function destroy(Clinic $clinic, DailySession $dailySession, DeleteDailySession $deleteDailySession): Response
    {
        $deleteDailySession->handle($dailySession);

        return response()->noContent();
    }

    private function resource(DailySession $dailySession): DailySessionResource
    {
        return new DailySessionResource($dailySession->load(['clinicUser.user', 'lineItem', 'clinic', 'dailySessionPatients.patient', 'dailySessionPatients.lineItem'])
            ->loadCount('dailySessionPatients'));
    }
}
