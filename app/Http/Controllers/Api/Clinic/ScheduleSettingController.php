<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\ScheduleSettingRequest;
use App\Http\Resources\ClinicScheduleSettingResource;
use App\Models\Clinic;
use Illuminate\Http\JsonResponse;

class ScheduleSettingController extends Controller
{
    /**
     * Get when the clinic is open. Clinic Admin only.
     *
     * Weekdays share one window and the weekend another, with Saturday and
     * Sunday switched on independently. `opens_at` and `closes_at` are the
     * widest of those, which is what the calendar grid spans.
     */
    public function show(Clinic $clinic): JsonResponse
    {
        return response()->json(['data' => new ClinicScheduleSettingResource($clinic->scheduleSettings())]);
    }

    /**
     * Save when the clinic is open. Clinic Admin only.
     *
     * Sessions booked outside these hours are refused unless
     * `allow_outside_hours` is on. Changing the hours never moves a session
     * that already exists, including the days of a daily session; edit the
     * daily session to re-plan those.
     */
    public function update(ScheduleSettingRequest $request, Clinic $clinic): JsonResponse
    {
        $settings = $clinic->scheduleSettings();
        $settings->update($request->settingAttributes());

        return response()->json(['data' => new ClinicScheduleSettingResource($settings->refresh())]);
    }
}
