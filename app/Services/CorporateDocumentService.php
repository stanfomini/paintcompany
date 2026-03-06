<?php

namespace App\Services;

use App\Jobs\ParseCorporateDocumentJob;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\MeetingMinutes;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CorporateDocumentService
{
    public function __construct(
        private AudioMessageService $audioService
    ) {}

    /**
     * Upload a document, store the PDF, and dispatch parsing.
     */
    public function uploadDocument(
        Corporation $corporation,
        UploadedFile $file,
        string $title,
        string $type,
        ?User $user = null,
    ): CorporateDocument {
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $storagePath = "corporate-documents/{$corporation->id}/{$filename}";

        Storage::put($storagePath, file_get_contents($file->getRealPath()));

        $document = CorporateDocument::create([
            'corporation_id' => $corporation->id,
            'uploaded_by' => $user?->id,
            'title' => $title,
            'type' => $type,
            'original_file_path' => $storagePath,
            'original_filename' => $file->getClientOriginalName(),
            'version' => 1,
            'status' => 'processing',
            'file_size' => $file->getSize(),
        ]);

        ParseCorporateDocumentJob::dispatch($document);

        return $document;
    }

    /**
     * Save edits to a document's content. Creates a new version if content changed.
     */
    public function saveEdits(CorporateDocument $document, array $sections): CorporateDocument
    {
        if ($document->edited_content === $sections) {
            return $document;
        }

        // Find the root parent (v1) which holds the original uploaded PDF
        $parentId = $document->parent_document_id ?? $document->id;
        $parent = $document->parent_document_id
            ? CorporateDocument::find($parentId)
            : $document;

        // Get the highest version number for this document family
        $latestVersion = CorporateDocument::where('parent_document_id', $parentId)
            ->max('version') ?? $parent->version;

        // Always create a new version so v1's original file is never modified
        $newVersion = CorporateDocument::create([
            'corporation_id' => $document->corporation_id,
            'parent_document_id' => $parentId,
            'uploaded_by' => $document->uploaded_by,
            'title' => $document->title,
            'type' => $document->type,
            'original_file_path' => $parent->original_file_path,
            'original_filename' => $document->original_filename,
            'version' => $latestVersion + 1,
            'status' => 'ready',
            'parsed_content' => $parent->parsed_content,
            'edited_content' => $sections,
            'file_size' => $document->file_size,
            'page_count' => $document->page_count,
        ]);

        $this->generateEditedPdf($newVersion, $sections);

        return $newVersion;
    }

    /**
     * Generate a modified PDF from the original file with text edits applied.
     */
    private function generateEditedPdf(CorporateDocument $document, array $editedSections): void
    {
        // Get the root parent's original uploaded PDF and parsed content
        $parent = CorporateDocument::find($document->parent_document_id);

        if (! $parent) {
            return;
        }

        $originalStoragePath = $parent->original_file_path;
        $parsedContent = $parent->parsed_content;

        if (! $originalStoragePath || ! $parsedContent) {
            return;
        }

        if (! Storage::exists($originalStoragePath)) {
            return;
        }

        // Download to a temp file so Python can read it (works with any disk driver)
        $tempFile = tempnam(sys_get_temp_dir(), 'corp_edit_').'.pdf';
        file_put_contents($tempFile, Storage::get($originalStoragePath));

        try {
            $python = app(PythonBridgeService::class);

            if (! $python->isEnabled()) {
                return;
            }

            $modifiedPdfBytes = $python->applyDocumentEdits(
                $tempFile,
                $parsedContent,
                $editedSections,
            );

            if (! $modifiedPdfBytes) {
                return;
            }

            // Store the modified PDF
            $editedPath = "corporate-documents/{$document->corporation_id}/edited-v{$document->version}-"
                .Str::uuid().'.pdf';
            Storage::put($editedPath, $modifiedPdfBytes);

            // Point this version to the modified PDF
            $document->update(['original_file_path' => $editedPath]);
        } catch (\Throwable $e) {
            // Non-critical: fall back to template-generated PDF
            \Illuminate\Support\Facades\Log::warning('Failed to generate edited PDF', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Export a corporate document to PDF.
     */
    public function exportToPdf(CorporateDocument $document): \Barryvdh\DomPDF\PDF
    {
        $document->load('corporation');

        return Pdf::loadView('pdf.corporate-document', [
            'document' => $document,
            'settings' => \App\Models\CompanySetting::getInstance(),
        ]);
    }

    /**
     * Export meeting minutes to PDF.
     */
    public function exportMinutesToPdf(MeetingMinutes $minutes): \Barryvdh\DomPDF\PDF
    {
        $minutes->load('corporation');

        return Pdf::loadView('pdf.meeting-minutes', [
            'minutes' => $minutes,
            'settings' => \App\Models\CompanySetting::getInstance(),
        ]);
    }

    /**
     * Process an audio recording for meeting minutes.
     *
     * @return array{audio_path: string, audio_duration: float|null, transcript: string|null}
     */
    public function processAudioRecording(string $base64Audio, string $mimeType = 'audio/webm'): array
    {
        $result = $this->audioService->processBase64Audio($base64Audio, $mimeType);

        return [
            'audio_path' => $result['audio_path'],
            'audio_duration' => $result['audio_duration'],
            'transcript' => $result['transcription'],
        ];
    }
}
