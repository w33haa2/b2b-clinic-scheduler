<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Billing\PrepayDailySession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\PrepaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Clinic;
use App\Models\Payment;
use App\Support\ClinicContext;
use Illuminate\Http\JsonResponse;

class PrepaymentController extends Controller
{
    /**
     * Prepay days of a daily session for an enrolled patient.
     *
     * Each date (Y-m-d, clinic timezone) must be a day the session runs on,
     * from today up to 90 days ahead. Days not scheduled yet are created;
     * each day's payment is recorded as DONE with its transactions. All or
     * nothing: a day that is cancelled or already paid fails the request.
     */
    public function store(PrepaymentRequest $request, Clinic $clinic, PrepayDailySession $prepay, ClinicContext $context): JsonResponse
    {
        $payments = $prepay->handle($request->dailySession(), $request->integer('patient_id'), $request->dates(), $context->member());

        $payments->each(fn (Payment $payment) => $payment->load([
            'patient',
            'clinicSession.clinicUser.user',
            'clinicSession.dailySession',
            'sessionMember.sessionLineItems.lineItem',
        ]));

        return PaymentResource::collection($payments)->response()->setStatusCode(201);
    }
}
