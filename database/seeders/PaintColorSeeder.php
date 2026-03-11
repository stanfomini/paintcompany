<?php

namespace Database\Seeders;

use App\Models\PaintBrand;
use App\Models\PaintColor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class PaintColorSeeder extends Seeder
{
    /** @var array<string, string> Brand code => JSON filename mapping */
    private const BRAND_FILES = [
        'SW' => 'sherwin-williams.json',
        'BM' => 'benjamin-moore.json',
        'BEHR' => 'behr.json',
        'PPG' => 'ppg.json',
    ];

    private const CHUNK_SIZE = 100;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brandMap = PaintBrand::pluck('id', 'code')->toArray();
        $totalSeeded = 0;

        foreach (self::BRAND_FILES as $brandCode => $filename) {
            $brandId = $brandMap[$brandCode] ?? null;

            if (! $brandId) {
                $this->command?->warn("Brand '{$brandCode}' not found, skipping {$filename}.");

                continue;
            }

            $count = $this->seedFromFile($brandId, $filename);
            $totalSeeded += $count;
            $this->command?->info("Seeded {$count} colors for {$brandCode}.");
        }

        PaintColor::clearCache();
        $this->command?->info("Total: {$totalSeeded} colors seeded. Cache cleared.");
    }

    private function seedFromFile(int $brandId, string $filename): int
    {
        $path = database_path("data/colors/{$filename}");

        if (! file_exists($path)) {
            $this->command?->warn("File not found: {$path}");

            return 0;
        }

        $json = file_get_contents($path);
        $colors = json_decode($json, true);

        if (! is_array($colors)) {
            $this->command?->error("Invalid JSON in {$filename}");

            return 0;
        }

        $count = 0;

        collect($colors)->chunk(self::CHUNK_SIZE)->each(function (Collection $chunk) use ($brandId, &$count): void {
            foreach ($chunk as $color) {
                $hex = $color['hex'];
                $r = hexdec(substr($hex, 1, 2));
                $g = hexdec(substr($hex, 3, 2));
                $b = hexdec(substr($hex, 5, 2));

                PaintColor::updateOrCreate(
                    ['paint_brand_id' => $brandId, 'code' => $color['code']],
                    [
                        'name' => $color['name'],
                        'hex_value' => $hex,
                        'rgb_r' => $r,
                        'rgb_g' => $g,
                        'rgb_b' => $b,
                        'color_family' => $color['family'] ?? null,
                        'is_popular' => $color['popular'] ?? false,
                        'is_active' => true,
                        'intended_use' => $color['intended_use'] ?? 'both',
                    ]
                );

                $count++;
            }
        });

        return $count;
    }
}
