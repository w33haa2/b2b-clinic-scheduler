<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Payroll\CalculatePayout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PayoutRequest;
use App\Http\Resources\ClinicPayoutSettingResource;
use App\Http\Resources\ClinicUserResource;
use App\Models\Clinic;
use Illuminate\Http\JsonResponse;

class PayoutPreviewController extends Controller
{
    /**
     * Work out what a member would be paid for a period, without saving.
     *
     * Takes the same body as `POST /payouts`, so what is shown is what gets
     * generated. The response lists every billed item that would be
     * included, the totals, how the period looks on the attendance sheet,
     * and a count of what was left out and why.
     */
    public function store(PayoutRequest $request, Clinic $clinic, CalculatePayout $calculate): JsonResponse
    {
        $member = $request->member();
        $preview = $calculate->handle($clinic, $member, $request->periodStart(), $request->periodEnd());

        return response()->json(['data' => [
            'member' => new ClinicUserResource($member->load('user')),
            'period_start' => $request->periodStart()->toDateString(),
            'period_end' => $request->periodEnd()->toDateString(),
            'share_percentage' => $preview['share_percentage'],
            'uses_member_override' => $member->payout_share_percentage !== null,
            'settings' => new ClinicPayoutSettingResource($preview['settings']),
            'lines' => $preview['lines'],
            'totals' => $preview['totals'],
            'attendance' => $preview['attendance'],
            'skipped' => $preview['skipped'],
        ]]);
    }
}
