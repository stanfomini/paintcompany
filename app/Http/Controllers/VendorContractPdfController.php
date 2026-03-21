<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorContract;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorContractPdfController extends Controller
{
    public function download(Vendor $vendor, VendorContract $contract): StreamedResponse
    {
        abort_unless($contract->isFullyExecuted() && $contract->executed_pdf_path, 404);
        abort_unless($contract->vendor_id === $vendor->id, 404);

        return Storage::download($contract->executed_pdf_path, "contract-{$contract->id}.pdf");
    }

    public function publicDownload(string $token): StreamedResponse
    {
        $contract = VendorContract::byPublicToken($token)->firstOrFail();
        abort_unless($contract->isFullyExecuted() && $contract->executed_pdf_path, 404);
        abort_unless($contract->isPublicTokenValid(), 410);

        return Storage::download($contract->executed_pdf_path, "contract-{$contract->id}.pdf");
    }
}
