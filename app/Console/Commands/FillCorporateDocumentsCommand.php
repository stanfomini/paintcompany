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
                '______________________________________________________________' => 'Painting Made Easy Denver Inc.',
                'State of ________' => 'State of Colorado',
                '________________________, at the hour of ______________' => 'March 1st, at the hour of 10:00 AM',
                '____ day of __________, 20____' => '3rd day of March, 2026',
                '____________________________________________ Director' => 'Matt Stanford, Director',
            ],
            'resolution' => [
                // Incorporator
                'Incorporator: ____________________' => 'Incorporator: Colorado Registered Agents Inc.',
                'Incorporator:____________________' => 'Incorporator: Colorado Registered Agents Inc.',
                'incorporator, ____________________' => 'incorporator, Colorado Registered Agents Inc.',
                // Address patterns
                'Address: ____________________' => 'Address: 1500 N Grant St Ste R, Denver, CO 80203',
                'address: ____________________' => 'address: 1500 N Grant St Ste R, Denver, CO 80203',
                // Corporation name (long blanks)
                '______________________________________________________________' => 'Painting Made Easy Denver Inc.',
                // State
                'State of ________' => 'State of Colorado',
                'state of ________' => 'state of Colorado',
                // Filing number
                'Filing Number: ____________________' => 'Filing Number: 20261275863',
                'Filing Number:____________________' => 'Filing Number: 20261275863',
                'filing number ____________________' => 'filing number 20261275863',
                // Officer/Director titles
                'President: ____________________' => 'President: Matt Stanford',
                'Director: ____________________' => 'Director: Matt Stanford',
                'Treasurer: ____________________' => 'Treasurer: Matt Stanford',
                'Secretary: ____________________' => 'Secretary: Matt Stanford',
                'President:____________________' => 'President: Matt Stanford',
                'Director:____________________' => 'Director: Matt Stanford',
                'Treasurer:____________________' => 'Treasurer: Matt Stanford',
                'Secretary:____________________' => 'Secretary: Matt Stanford',
                // Date patterns
                '____ day of __________, 20____' => '3rd day of March, 2026',
                'Date: ____________________' => 'Date: March 3, 2026',
                'Date:____________________' => 'Date: March 3, 2026',
                'Dated: ____________________' => 'Dated: March 3, 2026',
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

                // Replace any remaining long underscore blanks (4+ underscores)
                $newText = $this->fillRemainingBlanks($newText, $document->type);

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

    /**
     * Fill remaining underscore blanks that weren't caught by explicit replacements.
     */
    private function fillRemainingBlanks(string $text, string $type): string
    {
        $corpName = 'Painting Made Easy Denver Inc.';
        $directorName = 'Matt Stanford';

        // Replace any remaining runs of 4+ underscores with corp name as a sensible default
        return preg_replace('/_{4,}/', $corpName, $text) ?? $text;
    }

    private function truncate(string $text, int $length = 120): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length).'...';
    }
}
