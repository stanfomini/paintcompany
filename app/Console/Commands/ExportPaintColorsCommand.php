<?php

namespace App\Console\Commands;

use App\Models\PaintBrand;
use App\Models\PaintColor;
use Illuminate\Console\Command;

class ExportPaintColorsCommand extends Command
{
    protected $signature = 'color-visualizer:export-colors
        {--out= : Destination directory (default: database/data/colors)}';

    protected $description = 'Export paint_brands + paint_colors to JSON files in the format PaintColorSeeder consumes. Used to sync the canonical color DB into the quick-color-visualizer package.';

    /** @var array<string, string> Brand code => filename */
    private const BRAND_FILES = [
        'SW' => 'sherwin-williams.json',
        'BM' => 'benjamin-moore.json',
        'BEHR' => 'behr.json',
        'PPG' => 'ppg.json',
    ];

    /**
     * Per-brand sort preference. BM uses natural-numeric ordering (001, 002,
     * ..., 100, 101) while the other brands' source files are lexical. Matching
     * the original ordering keeps the round-trip diff small.
     *
     * @var array<string, string>
     */
    private const BRAND_SORT = [
        'SW' => 'lexical',
        'BM' => 'natural',
        'BEHR' => 'lexical',
        'PPG' => 'lexical',
    ];

    public function handle(): int
    {
        $outDir = $this->option('out') ?: database_path('data/colors');
        $outDir = rtrim($outDir, '/');

        if (! is_dir($outDir)) {
            if (! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
                $this->error("Failed to create output directory: {$outDir}");

                return self::FAILURE;
            }
        }

        if (! is_writable($outDir)) {
            $this->error("Output directory is not writable: {$outDir}");

            return self::FAILURE;
        }

        $this->info("Exporting paint colors to: {$outDir}");
        $this->newLine();

        $brands = PaintBrand::orderBy('sort_order')->get()->keyBy('code');
        $totalExported = 0;

        foreach (self::BRAND_FILES as $code => $filename) {
            $brand = $brands->get($code);

            if (! $brand) {
                $this->warn("Brand '{$code}' not found in database, skipping.");

                continue;
            }

            $query = PaintColor::where('paint_brand_id', $brand->id);
            $colors = (self::BRAND_SORT[$code] ?? 'lexical') === 'natural'
                ? $query->get()->sort(fn ($a, $b) => strnatcmp($a->code, $b->code))->values()
                : $query->orderBy('code')->get();

            $payload = $colors->map(function (PaintColor $color): array {
                // Match the existing JSON format so diffs stay minimal:
                //   always include name/code/hex/family/popular;
                //   include intended_use only when it's not the default 'both'.
                $entry = [
                    'name' => $color->name,
                    'code' => $color->code,
                    'hex' => $color->hex_value,
                    'family' => $color->color_family,
                    'popular' => (bool) $color->is_popular,
                ];

                if ($color->intended_use !== null && $color->intended_use !== 'both') {
                    $entry['intended_use'] = $color->intended_use;
                }

                return $entry;
            })->values()->all();

            $path = "{$outDir}/{$filename}";
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                $this->error("Failed to encode JSON for {$code}");

                return self::FAILURE;
            }

            // PHP's JSON_PRETTY_PRINT hard-codes 4-space indents but the existing
            // source files use 2-space. Halve every run of leading spaces so the
            // round-trip diff stays clean.
            $json = (string) preg_replace_callback(
                '/^( {4})+/m',
                fn (array $m): string => str_repeat('  ', strlen($m[0]) / 4),
                $json
            );

            file_put_contents($path, $json.PHP_EOL);

            $count = count($payload);
            $totalExported += $count;
            $bytes = number_format(filesize($path));

            $this->line(sprintf('  %-6s %-22s %5d colors  (%s bytes)', $code, $filename, $count, $bytes));
        }

        $this->newLine();
        $this->info("Exported {$totalExported} colors across ".count(self::BRAND_FILES).' files.');

        return self::SUCCESS;
    }
}
