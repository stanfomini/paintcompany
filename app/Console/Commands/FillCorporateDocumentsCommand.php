<?php

namespace App\Console\Commands;

use App\Models\CorporateDocument;
use App\Services\CorporateDocumentService;
use Illuminate\Console\Command;

class FillCorporateDocumentsCommand extends Command
{
    protected $signature = 'corporate:fill-documents
        {--document= : Process a specific document by ID}
        {--dry-run : Preview changes without saving}';

    protected $description = 'Fill blank placeholders in corporate documents (bylaws, resolutions) with correct data';

    /**
     * Replacement mappings keyed by document type.
     *
     * @return array<string, array<string, string>>
     */
    private function getReplacements(string $type): array
    {
        $replacements = [
            'bylaws' => [
                // Section 2: Corporation name heading
                '______________________________________________________________' => 'Painting Made Easy Denver Inc.',
                // Section 4: State of formation
                'State of ________' => 'State of Colorado',
                // Section 80: Annual meeting date placeholder
                'held each year on ______________' => 'held each year on the first Monday of March',
                // Section 198: Adoption date
                '____ day of __________, 20____' => '3rd day of March, 2026',
                // Section 199: Director signature
                '____________________________________________ Director' => 'Matt Stanford, Director',
                // Section 200: Bank resolution corp name
                'Corporate Resolution to Open a Bank Account ___________________________________________________________________________________' => 'Corporate Resolution to Open a Bank Account - Painting Made Easy Denver Inc.',
                // Section 205: Board meeting date
                'held on ____________, 20___,' => 'held on March 3, 2026,',
                // Section 213: Agreement date
                '______ day of ___________________________, 20________' => '3rd day of March, 2026',
            ],
            'resolution' => [
                // Section 1: Incorporator, address, corp name, state
                'I, ___________________,' => 'I, Colorado Registered Agents Inc.,',
                'of _______________________________________' => 'of 1500 N Grant St Ste R, Denver, CO 80203',
                'Incorporator of __________________________________,' => 'Incorporator of Painting Made Easy Denver Inc.,',
                'State of ________________,' => 'State of Colorado,',
                // Section 4: Officers/Directors - use regex for these
                'President: __________________________' => 'President: Matt Stanford',
                'Treasurer: __________________________' => 'Treasurer: Matt Stanford',
                'Secretary: __________________________' => 'Secretary: Matt Stanford',
                'Director: __________________________' => 'Director: Matt Stanford',
                // Section 6: Corp name and date
                'that _________________________ was' => 'that Painting Made Easy Denver Inc. was',
                'incorporated on ______________' => 'incorporated on March 3, 2026',
                // Section 7: State and filing number
                'State of ___________________' => 'State of Colorado',
                'filing number _____________.' => 'filing number 20261275863.',
                // Section 14: Incorporator signature
                '___________________________ Incorporator' => 'Colorado Registered Agents Inc., Incorporator',
                // Section 15: Date
                '___________________________ Date' => 'March 3, 2026 Date',
            ],
        ];

        return $replacements[$type] ?? [];
    }

    public function handle(): int
    {
        $documentId = $this->option('document');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('[DRY RUN] No changes will be saved.');
            $this->newLine();
        }

        $query = CorporateDocument::query()
            ->whereIn('type', ['bylaws', 'resolution'])
            ->where('status', 'ready');

        if ($documentId) {
            $query->where('id', $documentId);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->info('No fillable documents found.');

            return self::SUCCESS;
        }

        $this->info("Processing {$documents->count()} document(s)...");
        $this->newLine();

        $filled = 0;
        $skipped = 0;

        foreach ($documents as $document) {
            $result = $this->processDocument($document, $isDryRun);

            if ($result) {
                $filled++;
            } else {
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Done! Filled: {$filled}, Skipped (no changes): {$skipped}");

        return self::SUCCESS;
    }

    private function processDocument(CorporateDocument $document, bool $isDryRun): bool
    {
        $this->info("Document #{$document->id}: {$document->title} (type: {$document->type})");

        $replacements = $this->getReplacements($document->type);

        if (empty($replacements)) {
            $this->warn("  No replacement rules defined for type '{$document->type}'. Skipping.");

            return false;
        }

        $sections = $document->getContentForExport();

        if (empty($sections)) {
            $this->warn('  No content to process. Skipping.');

            return false;
        }

        $filledSections = [];
        $hasChanges = false;

        foreach ($sections as $index => $section) {
            $filledSection = $section;

            if (isset($section['text'])) {
                $originalText = $section['text'];
                $newText = $originalText;

                // Apply explicit replacements
                foreach ($replacements as $search => $replace) {
                    $newText = str_replace($search, $replace, $newText);
                }

                if ($newText !== $originalText) {
                    $hasChanges = true;
                    $filledSection['text'] = $newText;

                    if ($isDryRun) {
                        $this->line("  Section {$index}:");
                        $this->line("    Before: {$this->truncate($originalText)}");
                        $this->line("    After:  {$this->truncate($newText)}");
                        $this->newLine();
                    }
                }
            }

            $filledSections[] = $filledSection;
        }

        if (! $hasChanges) {
            $this->line('  No blanks found. Skipping.');

            return false;
        }

        if ($isDryRun) {
            $this->info('  [DRY RUN] Would create new version with filled content.');

            return true;
        }

        $service = app(CorporateDocumentService::class);
        $newVersion = $service->saveEdits($document, $filledSections);
        $this->info("  Created V{$newVersion->version} (document #{$newVersion->id})");

        return true;
    }

    private function truncate(string $text, int $length = 120): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length).'...';
    }
}
