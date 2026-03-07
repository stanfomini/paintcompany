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
     * Create a document from a template (board resolution, stock ledger, etc.).
     */
    public function createFromTemplate(
        Corporation $corporation,
        string $type,
        string $title,
        array $templateData,
        ?User $user = null,
    ): CorporateDocument {
        $viewMap = [
            'board_resolution' => 'pdf.board-resolution-shares',
            'stock_ledger' => 'pdf.stock-ledger',
        ];

        $view = $viewMap[$type] ?? throw new \InvalidArgumentException("Unknown template type: {$type}");

        $parsedContent = $this->buildSectionsForTemplate($type, $templateData);

        // Render PDF
        $pdf = Pdf::loadView($view, ['data' => $templateData]);
        $pdfContent = $pdf->output();

        // Store PDF
        $filename = Str::uuid().'.pdf';
        $storagePath = "corporate-documents/{$corporation->id}/{$filename}";
        Storage::put($storagePath, $pdfContent);

        return CorporateDocument::create([
            'corporation_id' => $corporation->id,
            'uploaded_by' => $user?->id,
            'title' => $title,
            'type' => $type,
            'original_file_path' => $storagePath,
            'original_filename' => $title.'.pdf',
            'version' => 1,
            'status' => 'ready',
            'parsed_content' => $parsedContent,
            'source' => 'template',
            'template_data' => $templateData,
            'file_size' => strlen($pdfContent),
        ]);
    }

    /**
     * Build parsed_content sections from template data for editor compatibility.
     *
     * @return array<int, array{type: string, text: string, level?: int}>
     */
    public function buildSectionsForTemplate(string $type, array $data): array
    {
        if ($type === 'board_resolution') {
            return $this->buildBoardResolutionSections($data);
        }

        if ($type === 'stock_ledger') {
            return $this->buildStockLedgerSections($data);
        }

        return [];
    }

    /**
     * @return array<int, array{type: string, text: string, level?: int}>
     */
    private function buildBoardResolutionSections(array $data): array
    {
        $corpName = $data['corporation_name'] ?? '';
        $directorName = $data['director_name'] ?? '';
        $shareholderName = $data['shareholder_name'] ?? '';
        $shareCount = number_format((int) ($data['share_count'] ?? 0));
        $shareClass = $data['share_class'] ?? 'Common';
        $consideration = $data['consideration'] ?? 'services rendered and to be rendered';
        $resolutionDate = $data['resolution_date'] ?? '';
        $state = $data['state'] ?? 'Colorado';
        $certificated = ! empty($data['certificated']);

        return [
            ['type' => 'heading', 'level' => 1, 'text' => $corpName],
            ['type' => 'heading', 'level' => 2, 'text' => 'Resolution of the Board of Directors'],
            ['type' => 'heading', 'level' => 3, 'text' => 'Authorization to Issue Shares of Stock'],
            ['type' => 'paragraph', 'text' => "The undersigned, being the sole Director of {$corpName}, a {$state} corporation (the \"Corporation\"), hereby adopts the following resolution effective as of {$resolutionDate}:"],
            ['type' => 'paragraph', 'text' => "WHEREAS, the Corporation's Articles of Incorporation authorize the issuance of shares of capital stock; and"],
            ['type' => 'paragraph', 'text' => 'WHEREAS, the Board of Directors deems it in the best interest of the Corporation to issue shares to its shareholder in exchange for consideration; and'],
            ['type' => 'paragraph', 'text' => 'WHEREAS, the Board of Directors has determined that the consideration to be received for such shares is adequate;'],
            ['type' => 'paragraph', 'text' => "NOW, THEREFORE, BE IT RESOLVED, that the Corporation hereby authorizes the issuance of {$shareCount} shares of {$shareClass} Stock to {$shareholderName} in exchange for the following consideration: {$consideration}."],
            ['type' => 'paragraph', 'text' => 'RESOLVED FURTHER, that the shares shall be '.($certificated ? 'evidenced by stock certificates in the form approved by the Board of Directors.' : "issued in uncertificated (book-entry) form and recorded in the Corporation's stock ledger.")],
            ['type' => 'paragraph', 'text' => 'RESOLVED FURTHER, that the Director is hereby authorized and directed to take all actions necessary to effectuate the foregoing resolutions, including but not limited to updating the Corporation\'s stock ledger and issuing any required notices.'],
            ['type' => 'paragraph', 'text' => "{$directorName}, Director"],
        ];
    }

    /**
     * @return array<int, array{type: string, text: string, level?: int}>
     */
    private function buildStockLedgerSections(array $data): array
    {
        $corpName = $data['corporation_name'] ?? '';

        $sections = [
            ['type' => 'heading', 'level' => 1, 'text' => $corpName],
            ['type' => 'heading', 'level' => 2, 'text' => 'Stock Ledger'],
        ];

        if (! empty($data['authorized_shares'])) {
            $sections[] = ['type' => 'heading', 'level' => 3, 'text' => 'Share Summary'];
            foreach ($data['authorized_shares'] as $auth) {
                $class = $auth['class'] ?? 'Common';
                $authorized = number_format((int) ($auth['authorized'] ?? 0));
                $issued = collect($data['entries'] ?? [])->where('class', $class)->sum('shares');
                $sections[] = ['type' => 'paragraph', 'text' => "{$class} Stock: {$authorized} authorized, ".number_format((int) $issued).' issued'];
            }
        }

        if (! empty($data['entries'])) {
            $sections[] = ['type' => 'heading', 'level' => 3, 'text' => 'Issuance Register'];
            foreach ($data['entries'] as $entry) {
                $sections[] = ['type' => 'paragraph', 'text' => sprintf(
                    '%s - %s: %s shares of %s Stock (Cert: %s) - %s',
                    $entry['date'] ?? '',
                    $entry['shareholder'] ?? '',
                    number_format((int) ($entry['shares'] ?? 0)),
                    $entry['class'] ?? 'Common',
                    $entry['certificate_number'] ?? 'Uncertificated',
                    $entry['consideration'] ?? '',
                )];
            }
        }

        return $sections;
    }

    /**
     * Export a corporate document to PDF.
     */
    public function exportToPdf(CorporateDocument $document): \Barryvdh\DomPDF\PDF
    {
        $document->load('corporation');

        if ($document->isTemplateGenerated() && $document->template_data) {
            $viewMap = [
                'board_resolution' => 'pdf.board-resolution-shares',
                'stock_ledger' => 'pdf.stock-ledger',
            ];

            $view = $viewMap[$document->type] ?? 'pdf.corporate-document';

            if (isset($viewMap[$document->type])) {
                return Pdf::loadView($view, ['data' => $document->template_data]);
            }
        }

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
