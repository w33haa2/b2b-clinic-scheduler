<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Invoice;
use App\Support\ActivityLogger;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class InvoicePdfController extends Controller
{
    /**
     * Download an invoice as a PDF.
     *
     * Requires the permission to view invoices (`clinic.invoices.show`).
     * Each download is recorded in the activity log.
     */
    public function show(Clinic $clinic, Invoice $invoice, ActivityLogger $activity): Response
    {
        $invoice->load(['patient', 'sessionLineItems.lineItem', 'sessionLineItems.sessionMember.clinicSession']);

        $fontCache = storage_path('framework/dompdf');
        File::ensureDirectoryExists($fontCache);

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        // vendor/ is read-only in production; font metrics are cached in storage instead.
        $options->setFontCache($fontCache);
        $options->setTempDir(sys_get_temp_dir());

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.invoice', ['clinic' => $clinic, 'invoice' => $invoice])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        $activity->log('invoice.downloaded', $invoice, $clinic->id, $invoice->activityLabel());

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->reference().'.pdf"',
        ]);
    }
}
