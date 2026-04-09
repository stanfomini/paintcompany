<?php

namespace App\Actions\Seo;

use App\Models\Keyword;
use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportKeywordPasteAction
{
    public function __construct(private AssignKeywordAction $assigner) {}

    /**
     * Import a paste-style list of keywords (one per line), creating each keyword if missing
     * and optionally assigning each one to a service/area target with the given role.
     *
     * Returns a summary array with counts and the imported keyword models.
     *
     * @return array{
     *     created: int,
     *     existing: int,
     *     assigned: int,
     *     skipped: int,
     *     keywords: Collection<int, Keyword>
     * }
     */
    public function execute(
        string $pasteBody,
        string $defaultStatus = 'researched',
        string $defaultSource = 'manual',
        ?Service $service = null,
        ?ServiceArea $serviceArea = null,
        string $defaultRole = 'secondary',
    ): array {
        $created = 0;
        $existing = 0;
        $assigned = 0;
        $skipped = 0;
        $keywords = collect();

        $lines = collect(preg_split('/\r\n|\r|\n/', $pasteBody))
            ->map(fn (string $line) => Str::lower(trim($line)))
            ->filter(fn (string $line) => $line !== '' && Str::length($line) <= 255)
            ->unique()
            ->values();

        DB::transaction(function () use (
            $lines, $defaultStatus, $defaultSource, $service, $serviceArea, $defaultRole,
            &$created, &$existing, &$assigned, &$skipped, &$keywords,
        ) {
            foreach ($lines as $line) {
                $keyword = Keyword::query()->where('keyword', $line)->first();

                if ($keyword === null) {
                    $keyword = Keyword::create([
                        'keyword' => $line,
                        'status' => $defaultStatus,
                        'source' => $defaultSource,
                    ]);
                    $created++;
                } else {
                    $existing++;
                }

                $keywords->push($keyword);

                if ($service !== null || $serviceArea !== null) {
                    try {
                        $this->assigner->execute(
                            keyword: $keyword,
                            service: $service,
                            serviceArea: $serviceArea,
                            role: $defaultRole,
                        );
                        $assigned++;
                    } catch (\Throwable) {
                        $skipped++;
                    }
                }
            }
        });

        return [
            'created' => $created,
            'existing' => $existing,
            'assigned' => $assigned,
            'skipped' => $skipped,
            'keywords' => $keywords,
        ];
    }
}
