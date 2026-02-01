<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentListing;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class AgentListingFlyerController extends Controller
{
    public function download(AgentListing $agentListing): Response
    {
        // Authorize: Only the agent who owns the listing can download
        if ($agentListing->agent_id !== auth('agent')->id()) {
            abort(403, 'Unauthorized access to this listing flyer.');
        }

        $agentListing->load(['agent.agentProfile']);

        // Generate the sign-up URL
        $signupUrl = url("/open-house/{$agentListing->id}");

        // Generate QR code SVG
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $qrCodeSvg = $writer->writeString($signupUrl);

        $pdf = Pdf::loadView('pdf.open-house-flyer', [
            'listing' => $agentListing,
            'agent' => $agentListing->agent,
            'qrCodeSvg' => $qrCodeSvg,
            'signupUrl' => $signupUrl,
        ]);

        // Generate filename based on address
        $addressSlug = Str::slug($agentListing->address);
        $filename = "open-house-flyer-{$addressSlug}.pdf";

        return $pdf->download($filename);
    }
}
