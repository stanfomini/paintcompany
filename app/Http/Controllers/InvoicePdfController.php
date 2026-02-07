<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InvoicePdfController extends Controller
{
    public function download(Invoice $invoice): Response
    {
        abort_unless(auth()->user()?->can('invoices.view'), 403);

        $invoice->load(['customer', 'estimate', 'job', 'payments', 'companySetting']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
        ]);

        $filename = 'invoice-'.$invoice->invoice_number.'.pdf';

        return $pdf->download($filename);
    }
}
