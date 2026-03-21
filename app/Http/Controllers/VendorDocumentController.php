<?php

namespace App\Http\Controllers;

use App\Models\VendorDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorDocumentController extends Controller
{
    public function download(VendorDocument $vendorDocument): StreamedResponse
    {
        abort_unless(auth()->user()?->can('vendors.manage'), 403);
        abort_unless(Storage::exists($vendorDocument->file_path), 404);

        $filename = $vendorDocument->original_filename
            ?? $vendorDocument->name.'.'.pathinfo($vendorDocument->file_path, PATHINFO_EXTENSION);

        return Storage::download($vendorDocument->file_path, $filename);
    }

    public function view(VendorDocument $vendorDocument): StreamedResponse
    {
        abort_unless(auth()->user()?->can('vendors.manage'), 403);
        abort_unless(Storage::exists($vendorDocument->file_path), 404);

        return Storage::response($vendorDocument->file_path, null, [
            'Content-Disposition' => 'inline',
        ]);
    }
}
