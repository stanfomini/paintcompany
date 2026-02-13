<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Property;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PropertyFlyerController extends Controller
{
    public function download(Property $property): Response
    {
        // Authorize: Only the agent who owns the listing can download
        if ($property->agent_id !== auth('agent')->id()) {
            abort(403, __('Unauthorized access to this listing flyer.'));
        }

        $property->load(['agent.agentProfile']);

        // Generate the sign-up URL
        $signupUrl = url("/open-house/{$property->id}");

        // Generate QR code SVG
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $qrCodeSvg = $writer->writeString($signupUrl);

        $pdf = Pdf::loadView('pdf.open-house-flyer', [
            'listing' => $property,
            'agent' => $property->agent,
            'qrCodeSvg' => $qrCodeSvg,
            'signupUrl' => $signupUrl,
        ]);

        // Generate filename based on address
        $addressSlug = Str::slug($property->address);
        $filename = "open-house-flyer-{$addressSlug}.pdf";

        return $pdf->download($filename);
    }
}
