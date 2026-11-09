<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PayoutSettingRequest;
use App\Http\Resources\ClinicPayoutSettingResource;
use App\Http\Resources\ClinicUserResource;
use App\Models\Clinic;
use App\Models\ClinicUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PayoutSettingController extends Controller
{
    /**
     * Get the clinic's payout configuration. Clinic Admin only.
     *
     * Returns the clinic-wide cut and the two payout rules, together with
     * every member and the share that actually applies to them: their own
     * override when they have one, the clinic default otherwise.
     */
    public function show(Clinic $clinic): JsonResponse
    {
        return response()->json(['data' => $this->payload($clinic)]);
    }

    /**
     * Save the clinic's payout configuration. Clinic Admin only.
     *
     * Send `worker_share_percentage`, `include_pending_payments`,
     * `require_attendance`, and `member_shares` as
     * `[{clinic_user_id, share_percentage}]`. A null `share_percentage`
     * clears that member's override so they follow the clinic default again.
     * Changing any of this never moves a payout that already exists.
     */
    public function update(PayoutSettingRequest $request, Clinic $clinic): JsonResponse
    {
        DB::transaction(function () use ($request, $clinic): void {
            $clinic->payoutSettings()->update($request->settingAttributes());

            foreach ($request->memberShares() as $clinicUserId => $share) {
                $clinic->clinicUsers()->whereKey($clinicUserId)->update(['payout_share_percentage' => $share]);
            }
        });

        return response()->json(['data' => $this->payload($clinic->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Clinic $clinic): array
    {
        $settings = $clinic->payoutSettings()->refresh();

        $members = $clinic->clinicUsers()
            ->with(['user', 'clinicRoles'])
            ->get()
            ->sortBy(fn (ClinicUser $member): string => (string) $member->user?->name)
            ->values();

        return [
            'settings' => new ClinicPayoutSettingResource($settings),
            'members' => $members->map(fn (ClinicUser $member): array => [
                ...(new ClinicUserResource($member))->toArray(request()),
                'effective_share_percentage' => $member->payout_share_percentage ?? $settings->worker_share_percentage,
                'uses_override' => $member->payout_share_percentage !== null,
            ])->all(),
        ];
    }
}
