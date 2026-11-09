<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Actions\Billing\SaveInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinic\InvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Clinic;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    /**
     * List invoices, newest first.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'patient_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $invoices = $clinic->invoices()
            ->with(['patient', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem'])
            ->when($filters['patient_id'] ?? null, fn ($query, int|string $id) => $query->where('patient_id', $id))
            ->orderByDesc('number')
            ->paginate($filters['per_page'] ?? 25);

        return InvoiceResource::collection($invoices);
    }

    /**
     * Create an invoice for one patient.
     *
     * Every item in `session_line_item_ids` must be billed to that patient and
     * not already on another invoice (see the patient's uninvoiced-items list).
     * The invoice number counts up per clinic.
     */
    public function store(InvoiceRequest $request, Clinic $clinic, SaveInvoice $saveInvoice): InvoiceResource
    {
        $invoice = $saveInvoice->handle($clinic, $request->validated());

        return new InvoiceResource($invoice->load(['patient', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem']));
    }

    /**
     * Get an invoice with its items and total.
     */
    public function show(Clinic $clinic, Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load(['patient', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem']));
    }

    /**
     * Update an invoice's date, notes or items.
     *
     * `session_line_item_ids` replaces the whole item list.
     */
    public function update(InvoiceRequest $request, Clinic $clinic, Invoice $invoice, SaveInvoice $saveInvoice): InvoiceResource
    {
        $invoice = $saveInvoice->handle($clinic, $request->validated(), $invoice);

        return new InvoiceResource($invoice->load(['patient', 'sessionLineItems.lineItem', 'sessionLineItems.discount', 'sessionLineItems.payoutItem']));
    }

    /**
     * Delete an invoice.
     *
     * Its items become available to invoice again.
     */
    public function destroy(Clinic $clinic, Invoice $invoice): Response
    {
        $invoice->delete();

        return response()->noContent();
    }
}
