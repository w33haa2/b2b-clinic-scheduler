<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\CalendarFilterRequest;
use App\Http\Resources\CalendarFilterResource;
use App\Models\CalendarFilter;
use App\Models\Clinic;
use App\Support\ClinicContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CalendarFilterController extends Controller
{
    /**
     * List the schedule filters you saved in this clinic.
     *
     * Saved filters are personal: nobody else sees them, so no permission is
     * needed beyond being a member. Each has `rules`: `match` (`all` or
     * `any`) and `conditions`, each a `field` (staff, status, type, patient,
     * payment, name), an `operator` (is, is_not, contains) and `values`.
     */
    public function index(Clinic $clinic, ClinicContext $context): AnonymousResourceCollection
    {
        return CalendarFilterResource::collection(
            $context->member()->calendarFilters()->orderBy('name')->orderBy('id')->get(),
        );
    }

    /**
     * Save a schedule filter.
     */
    public function store(CalendarFilterRequest $request, Clinic $clinic, ClinicContext $context): CalendarFilterResource
    {
        $filter = $context->member()->calendarFilters()->make($request->validated());
        $filter->clinic()->associate($clinic);
        $filter->save();

        return new CalendarFilterResource($filter);
    }

    /**
     * Rename a saved filter or change its rules.
     */
    public function update(CalendarFilterRequest $request, Clinic $clinic, CalendarFilter $calendarFilter, ClinicContext $context): CalendarFilterResource
    {
        $this->ensureOwnedBy($calendarFilter, $context);

        $calendarFilter->update($request->validated());

        return new CalendarFilterResource($calendarFilter);
    }

    /**
     * Delete a saved filter.
     */
    public function destroy(Clinic $clinic, CalendarFilter $calendarFilter, ClinicContext $context): Response
    {
        $this->ensureOwnedBy($calendarFilter, $context);

        $calendarFilter->delete();

        return response()->noContent();
    }

    /**
     * Another member's filter is reported as missing, not forbidden.
     */
    private function ensureOwnedBy(CalendarFilter $calendarFilter, ClinicContext $context): void
    {
        abort_unless($calendarFilter->clinic_user_id === $context->member()->id, 404);
    }
}
