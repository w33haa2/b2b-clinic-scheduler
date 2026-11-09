<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Clinics\EnrollDailySessionPatient;
use App\Actions\Clinics\RemoveDailySessionPatient;
use App\Actions\Clinics\UpdateDailySessionPatientPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\DailySessionPatientRequest;
use App\Http\Resources\DailySessionPatientResource;
use App\Models\Clinic;
use App\Models\DailySession;
use App\Models\DailySessionPatient;
use Illuminate\Http\Response;

class DailySessionPatientController extends Controller
{
    /**
     * Enrol a patient in a daily session.
     *
     * Optionally with a plan: `line_item_id` / `unit_price` (the service
     * billed every day; the daily session's default otherwise) and a usual
     * slot, `start_time` (H:i, clinic timezone) + `duration_minutes`. The
     * patient joins every scheduled day that has not ended, and every later
     * day, each with an unpaid bill, put into the usual slot where the staff
     * member is free.
     */
    public function store(DailySessionPatientRequest $request, Clinic $clinic, DailySession $dailySession, EnrollDailySessionPatient $enroll): DailySessionPatientResource
    {
        $enrollment = $enroll->handle($dailySession, $request->plan());

        return new DailySessionPatientResource($enrollment->load(['patient', 'lineItem']));
    }

    /**
     * Change a patient's regular plan.
     *
     * Send `line_item_id` (optional `unit_price`) and/or `start_time` +
     * `duration_minutes` (both `null` to drop the usual slot). Every upcoming
     * day follows the new plan, the service only while that day is unpaid,
     * except days changed by hand for this patient. Past days never change.
     */
    public function update(
        DailySessionPatientRequest $request,
        Clinic $clinic,
        DailySession $dailySession,
        DailySessionPatient $dailySessionPatient,
        UpdateDailySessionPatientPlan $updatePlan,
    ): DailySessionPatientResource {
        $enrollment = $updatePlan->handle($dailySessionPatient, $request->plan());

        return new DailySessionPatientResource($enrollment->load(['patient', 'lineItem']));
    }

    /**
     * Remove a patient from a daily session.
     *
     * The patient leaves every day that has not ended, with those days'
     * unpaid bills; past days stay (unpaid ones remain past due). Refused
     * while the patient has prepaid an upcoming day: refund it first.
     */
    public function destroy(Clinic $clinic, DailySession $dailySession, DailySessionPatient $dailySessionPatient, RemoveDailySessionPatient $remove): Response
    {
        $remove->handle($dailySessionPatient);

        return response()->noContent();
    }
}
