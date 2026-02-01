<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class EstimatePdfController extends Controller
{
    public function download(Estimate $estimate): Response
    {
        $estimate->load([
            'deal.customer',
            'createdBy',
            'items',
            'areas.surfaces.productionRate',
            'options',
            'photos' => fn ($q) => $q->where('show_on_proposal', true)->orderBy('sort_order'),
            'signature',
        ]);

        $pdf = Pdf::loadView('pdf.estimate', [
            'estimate' => $estimate,
        ]);

        $filename = 'estimate-'.$estimate->estimate_number.'.pdf';

        return $pdf->download($filename);
    }
}
