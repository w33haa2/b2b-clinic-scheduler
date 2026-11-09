<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionLineItemResource;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\SessionLineItem;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UninvoicedItemController extends Controller
{
    /**
     * List a patient's paid billed items that are not on an invoice yet.
     *
     * Only items whose payment is DONE can be invoiced. Use the IDs as
     * `session_line_item_ids` when creating an invoice.
     */
    public function __invoke(Clinic $clinic, Patient $patient): AnonymousResourceCollection
    {
        $items = SessionLineItem::query()
            ->whereHas('sessionMember', fn ($query) => $query
                ->whereBelongsTo($patient)
                ->whereHas('payment', fn ($query) => $query->where('payment_status_id', PaymentStatus::Done)))
            ->whereDoesntHave('invoiceItem')
            ->with('lineItem')
            ->orderBy('id')
            ->get();

        return SessionLineItemResource::collection($items);
    }
}
