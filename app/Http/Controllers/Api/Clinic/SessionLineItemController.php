<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\SessionLineItemRequest;
use App\Http\Resources\SessionLineItemResource;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\Discount;
use App\Models\LineItem;
use App\Models\SessionLineItem;
use App\Support\ClinicContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SessionLineItemController extends Controller
{
    /**
     * List the items billed to a patient in a session.
     */
    public function index(Clinic $clinic, ClinicSession $clinicSession, ClinicSessionMember $sessionMember): AnonymousResourceCollection
    {
        return SessionLineItemResource::collection(
            $sessionMember->sessionLineItems()->with(['lineItem', 'discount', 'invoiceItem', 'payoutItem'])->orderBy('id')->get(),
        );
    }

    /**
     * Bill a catalog item to a patient in a session.
     *
     * `unit_price` defaults to the catalog price and is stored on the bill, so
     * later catalog price changes do not affect it. Pass `discount_id` to
     * take money off: what it deducts, and whose cut pays for it, are stored
     * on the bill the same way. Only allowed while the patient's payment is
     * PENDING and the session is not CANCELLED.
     */
    public function store(
        SessionLineItemRequest $request,
        Clinic $clinic,
        ClinicSession $clinicSession,
        ClinicSessionMember $sessionMember,
        ClinicContext $context,
    ): SessionLineItemResource {
        if ($clinicSession->status_id === SessionStatus::Cancelled) {
            throw ValidationException::withMessages([
                'session' => __('Items cannot be billed on a cancelled session.'),
            ]);
        }

        $this->ensurePaymentIsPending($sessionMember);

        $lineItem = LineItem::query()->findOrFail($request->integer('line_item_id'));

        $sessionLineItem = $sessionMember->sessionLineItems()->make([
            'line_item_id' => $lineItem->id,
            'quantity' => $request->validated('quantity', 1),
            'unit_price' => $request->validated('unit_price', $lineItem->price),
        ]);

        $sessionLineItem->fill($this->discountAttributes(
            $this->resolveDiscount($clinic, $context, $request->validated('discount_id')),
            $sessionLineItem->grossAmount(),
        ))->save();

        return new SessionLineItemResource($sessionLineItem->load(['lineItem', 'discount']));
    }

    /**
     * Change the quantity, unit price or discount of a billed item.
     *
     * The discount is worked out again from the new quantity and price, so a
     * percentage discount stays proportional. Send `discount_id: null` to
     * take the discount off.
     */
    public function update(
        SessionLineItemRequest $request,
        Clinic $clinic,
        ClinicSession $clinicSession,
        ClinicSessionMember $sessionMember,
        SessionLineItem $sessionLineItem,
        ClinicContext $context,
    ): SessionLineItemResource {
        $this->ensureItemIsEditable($sessionMember, $sessionLineItem);

        $sessionLineItem->fill($request->safe()->except('discount_id'));

        $discount = $request->has('discount_id')
            ? $this->resolveDiscount($clinic, $context, $request->validated('discount_id'))
            : $sessionLineItem->discount;

        $sessionLineItem->fill($this->discountAttributes($discount, $sessionLineItem->grossAmount()))->save();

        return new SessionLineItemResource($sessionLineItem->load(['lineItem', 'discount']));
    }

    /**
     * Remove a billed item.
     */
    public function destroy(Clinic $clinic, ClinicSession $clinicSession, ClinicSessionMember $sessionMember, SessionLineItem $sessionLineItem): Response
    {
        $this->ensureItemIsEditable($sessionMember, $sessionLineItem);

        $sessionLineItem->delete();

        return response()->noContent();
    }

    /**
     * The discount to apply, or null for none. A discount another member
     * created for themselves does not exist as far as this member is
     * concerned.
     */
    private function resolveDiscount(Clinic $clinic, ClinicContext $context, int|string|null $discountId): ?Discount
    {
        if ($discountId === null) {
            return null;
        }

        $discount = $clinic->discounts()->visibleTo($context->member())->find($discountId);

        if ($discount === null) {
            throw ValidationException::withMessages([
                'discount_id' => __('That discount is not available to you.'),
            ]);
        }

        if (! $discount->is_active) {
            throw ValidationException::withMessages([
                'discount_id' => __('":name" is switched off and cannot be used.', ['name' => $discount->name]),
            ]);
        }

        return $discount;
    }

    /**
     * What to store on the bill for the given discount.
     *
     * @return array<string, mixed>
     */
    private function discountAttributes(?Discount $discount, string $gross): array
    {
        if ($discount === null) {
            return ['discount_id' => null, 'discount_amount' => '0.00', 'discount_target_id' => null];
        }

        return [
            'discount_id' => $discount->id,
            'discount_amount' => $discount->deductionFrom($gross),
            'discount_target_id' => $discount->discount_target_id,
        ];
    }

    private function ensureItemIsEditable(ClinicSessionMember $sessionMember, SessionLineItem $sessionLineItem): void
    {
        $this->ensurePaymentIsPending($sessionMember);

        if ($sessionLineItem->invoiceItem()->exists()) {
            throw ValidationException::withMessages([
                'session_line_item' => __('This item is on an invoice. Remove it from the invoice first.'),
            ]);
        }

        $payoutItem = $sessionLineItem->payoutItem()->with('payout')->first();

        if ($payoutItem !== null) {
            throw ValidationException::withMessages([
                'session_line_item' => __('This item was paid out on :payout. Cancel that payout before changing it.', [
                    'payout' => $payoutItem->payout->reference(),
                ]),
            ]);
        }
    }

    private function ensurePaymentIsPending(ClinicSessionMember $sessionMember): void
    {
        $status = $sessionMember->paymentStatus();

        if ($status !== PaymentStatus::Pending) {
            throw ValidationException::withMessages([
                'payment_status' => __('Billed items are locked once the payment is :status.', [
                    'status' => $status->code(),
                ]),
            ]);
        }
    }
}
