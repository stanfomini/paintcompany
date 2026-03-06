<?php

namespace App\Http\Controllers;

use App\Models\CorporateDocument;
use App\Models\MeetingMinutes;
use App\Services\CorporateDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorporateDocumentPdfController extends Controller
{
    public function __construct(
        private CorporateDocumentService $service
    ) {}

    public function download(CorporateDocument $corporateDocument): StreamedResponse|Response
    {
        abort_unless(auth()->user()?->can('business_plan.view'), 403);

        $filename = Str::slug($corporateDocument->title).'-v'.$corporateDocument->version.'.pdf';

        // Serve the stored PDF (original for v1, modified for v2+ with edits applied)
        if ($corporateDocument->original_file_path && Storage::exists($corporateDocument->original_file_path)) {
            return Storage::download($corporateDocument->original_file_path, $filename);
        }

        // Fallback: generate PDF from parsed/edited content
        return $this->service->exportToPdf($corporateDocument)->download($filename);
    }

    public function view(CorporateDocument $corporateDocument): StreamedResponse|Response
    {
        abort_unless(auth()->user()?->can('business_plan.view'), 403);

        // Serve the stored PDF (original for v1, modified for v2+ with edits applied)
        if ($corporateDocument->original_file_path && Storage::exists($corporateDocument->original_file_path)) {
            return Storage::response($corporateDocument->original_file_path, null, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline',
            ]);
        }

        // Fallback: generate PDF from parsed/edited content
        return $this->service->exportToPdf($corporateDocument)->stream();
    }

    public function downloadMinutes(MeetingMinutes $meetingMinutes): Response
    {
        abort_unless(auth()->user()?->can('business_plan.view'), 403);

        $pdf = $this->service->exportMinutesToPdf($meetingMinutes);
        $filename = Str::slug($meetingMinutes->title).'-minutes.pdf';

        return $pdf->download($filename);
    }

    public function downloadPacket(Request $request): Response
    {
        abort_unless(auth()->user()?->can('business_plan.view'), 403);

        $request->validate([
            'document_ids' => ['sometimes', 'array'],
            'document_ids.*' => ['integer', 'exists:corporate_documents,id'],
            'minutes_ids' => ['sometimes', 'array'],
            'minutes_ids.*' => ['integer', 'exists:meeting_minutes,id'],
        ]);

        $pdf = $this->buildPacketPdf(
            $request->input('document_ids', []),
            $request->input('minutes_ids', []),
        );

        return $pdf->download('corporate-document-packet.pdf');
    }

    public function sendPacket(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless(auth()->user()?->can('business_plan.manage'), 403);

        $validated = $request->validate([
            'document_ids' => ['sometimes', 'array'],
            'document_ids.*' => ['integer', 'exists:corporate_documents,id'],
            'minutes_ids' => ['sometimes', 'array'],
            'minutes_ids.*' => ['integer', 'exists:meeting_minutes,id'],
            'email' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $pdf = $this->buildPacketPdf(
            $validated['document_ids'] ?? [],
            $validated['minutes_ids'] ?? [],
        );

        $pdfContent = $pdf->output();

        Mail::raw($validated['message'] ?? 'Please find the attached corporate document packet.', function ($mail) use ($validated, $pdfContent) {
            $mail->to($validated['email'])
                ->subject($validated['subject'])
                ->attachData($pdfContent, 'corporate-document-packet.pdf', [
                    'mime' => 'application/pdf',
                ]);
        });

        return response()->json(['success' => true]);
    }

    public function streamAudio(MeetingMinutes $meetingMinutes): StreamedResponse
    {
        abort_unless(auth()->user()?->can('business_plan.view'), 403);

        if (! $meetingMinutes->audio_path || ! Storage::exists($meetingMinutes->audio_path)) {
            abort(404);
        }

        $filename = basename($meetingMinutes->audio_path);
        $mimeType = match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'webm' => 'audio/webm',
            'm4a', 'mp4' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            default => 'audio/webm',
        };

        $size = Storage::size($meetingMinutes->audio_path);

        return response()->stream(function () use ($meetingMinutes) {
            $stream = Storage::readStream($meetingMinutes->audio_path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $size,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=86400',
            'Content-Disposition' => 'inline; filename="'.Str::slug($meetingMinutes->title).'.'.pathinfo($filename, PATHINFO_EXTENSION).'"',
        ]);
    }

    private function buildPacketPdf(array $documentIds, array $minutesIds): \Barryvdh\DomPDF\PDF
    {
        $documents = CorporateDocument::with('corporation')
            ->whereIn('id', $documentIds)
            ->get();

        $minutes = MeetingMinutes::with('corporation')
            ->whereIn('id', $minutesIds)
            ->get();

        $settings = \App\Models\CompanySetting::getInstance();

        return Pdf::loadView('pdf.document-packet', [
            'documents' => $documents,
            'minutes' => $minutes,
            'settings' => $settings,
        ]);
    }
}
