<?php

namespace Database\Seeders;

use App\Models\PaintBrand;
use App\Models\PaintColor;
use Illuminate\Database\Seeder;

class PaintColorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brandMap = PaintBrand::pluck('id', 'code')->toArray();

        $this->seedSherwinWilliams($brandMap);
        $this->seedBenjaminMoore($brandMap);
        $this->seedBehr($brandMap);
        $this->seedPpg($brandMap);
    }

    private function seedSherwinWilliams(array $brandMap): void
    {
        $brandId = $brandMap['SW'] ?? null;
        if (! $brandId) {
            $this->command?->warn('Sherwin-Williams brand (SW) not found, skipping colors.');

            return;
        }

        $colors = [
            ['name' => 'Extra White', 'code' => 'SW 7006', 'hex' => '#F1EFEA', 'family' => 'white', 'popular' => true],
            ['name' => 'Alabaster', 'code' => 'SW 7008', 'hex' => '#F0EDE3', 'family' => 'white', 'popular' => true],
            ['name' => 'Pure White', 'code' => 'SW 7005', 'hex' => '#F0EDEA', 'family' => 'white', 'popular' => true],
            ['name' => 'Snowbound', 'code' => 'SW 7004', 'hex' => '#EFEBE2', 'family' => 'white', 'popular' => false],
            ['name' => 'Greek Villa', 'code' => 'SW 7551', 'hex' => '#F0E8D7', 'family' => 'white', 'popular' => false],
            ['name' => 'Shoji White', 'code' => 'SW 7042', 'hex' => '#EDE4D4', 'family' => 'white', 'popular' => false],
            ['name' => 'Agreeable Gray', 'code' => 'SW 7029', 'hex' => '#D0C8BD', 'family' => 'gray', 'popular' => true],
            ['name' => 'Repose Gray', 'code' => 'SW 7015', 'hex' => '#C2BDB5', 'family' => 'gray', 'popular' => true],
            ['name' => 'Mindful Gray', 'code' => 'SW 7016', 'hex' => '#B5AFA7', 'family' => 'gray', 'popular' => false],
            ['name' => 'Worldly Gray', 'code' => 'SW 7043', 'hex' => '#C1B9AA', 'family' => 'gray', 'popular' => false],
            ['name' => 'Amazing Gray', 'code' => 'SW 7044', 'hex' => '#B0A999', 'family' => 'gray', 'popular' => false],
            ['name' => 'Accessible Beige', 'code' => 'SW 7036', 'hex' => '#D0C4AE', 'family' => 'beige', 'popular' => true],
            ['name' => 'Balanced Beige', 'code' => 'SW 7037', 'hex' => '#C4B49A', 'family' => 'beige', 'popular' => false],
            ['name' => 'Naval', 'code' => 'SW 6244', 'hex' => '#2E3441', 'family' => 'blue', 'popular' => true],
            ['name' => 'Sea Salt', 'code' => 'SW 6204', 'hex' => '#C8D5CD', 'family' => 'green', 'popular' => true],
            ['name' => 'Rainwashed', 'code' => 'SW 6211', 'hex' => '#C4D5CA', 'family' => 'green', 'popular' => false],
            ['name' => 'Silvermist', 'code' => 'SW 7621', 'hex' => '#B3C2C4', 'family' => 'blue', 'popular' => false],
            ['name' => 'Evergreen Fog', 'code' => 'SW 9130', 'hex' => '#B6BFB0', 'family' => 'green', 'popular' => false],
            ['name' => 'Oyster Bay', 'code' => 'SW 6206', 'hex' => '#AABEBC', 'family' => 'blue', 'popular' => false],
            ['name' => 'Iron Ore', 'code' => 'SW 7069', 'hex' => '#433F3E', 'family' => 'black', 'popular' => false],
            ['name' => 'Tricorn Black', 'code' => 'SW 6258', 'hex' => '#2F2F30', 'family' => 'black', 'popular' => false],
            ['name' => 'Urbane Bronze', 'code' => 'SW 7048', 'hex' => '#5B544B', 'family' => 'brown', 'popular' => false],
        ];

        $this->seedColors($brandId, $colors);
    }

    private function seedBenjaminMoore(array $brandMap): void
    {
        $brandId = $brandMap['BM'] ?? null;
        if (! $brandId) {
            $this->command?->warn('Benjamin Moore brand (BM) not found, skipping colors.');

            return;
        }

        $colors = [
            ['name' => 'White Dove', 'code' => 'OC-17', 'hex' => '#F3EFE0', 'family' => 'white', 'popular' => true],
            ['name' => 'Chantilly Lace', 'code' => 'OC-65', 'hex' => '#F5F2ED', 'family' => 'white', 'popular' => true],
            ['name' => 'Simply White', 'code' => 'OC-117', 'hex' => '#F1EDE3', 'family' => 'white', 'popular' => true],
            ['name' => 'Swiss Coffee', 'code' => 'OC-45', 'hex' => '#E9E1D2', 'family' => 'white', 'popular' => false],
            ['name' => 'Linen White', 'code' => 'OC-146', 'hex' => '#EDE5D5', 'family' => 'white', 'popular' => false],
            ['name' => 'Super White', 'code' => 'OC-152', 'hex' => '#F0EEEB', 'family' => 'white', 'popular' => false],
            ['name' => 'Decorator\'s White', 'code' => 'OC-149', 'hex' => '#EEECEA', 'family' => 'white', 'popular' => false],
            ['name' => 'Cloud White', 'code' => 'OC-130', 'hex' => '#EDE8D9', 'family' => 'white', 'popular' => false],
            ['name' => 'Revere Pewter', 'code' => 'HC-172', 'hex' => '#C2B9A7', 'family' => 'beige', 'popular' => true],
            ['name' => 'Edgecomb Gray', 'code' => 'HC-173', 'hex' => '#D5CBBA', 'family' => 'beige', 'popular' => true],
            ['name' => 'Balboa Mist', 'code' => 'OC-27', 'hex' => '#D8D0C4', 'family' => 'gray', 'popular' => false],
            ['name' => 'Pale Oak', 'code' => 'OC-20', 'hex' => '#D8CFC2', 'family' => 'beige', 'popular' => false],
            ['name' => 'Classic Gray', 'code' => 'OC-23', 'hex' => '#D5CFC7', 'family' => 'gray', 'popular' => false],
            ['name' => 'Chelsea Gray', 'code' => 'HC-168', 'hex' => '#8A8580', 'family' => 'gray', 'popular' => false],
            ['name' => 'Kendall Charcoal', 'code' => 'HC-166', 'hex' => '#5A5856', 'family' => 'gray', 'popular' => false],
            ['name' => 'Stonington Gray', 'code' => 'HC-170', 'hex' => '#B8B7B2', 'family' => 'gray', 'popular' => false],
            ['name' => 'Coventry Gray', 'code' => 'HC-169', 'hex' => '#A5A4A0', 'family' => 'gray', 'popular' => false],
            ['name' => 'Hale Navy', 'code' => 'HC-154', 'hex' => '#3F4955', 'family' => 'blue', 'popular' => true],
            ['name' => 'Palladian Blue', 'code' => 'HC-144', 'hex' => '#C1D0C8', 'family' => 'blue', 'popular' => true],
            ['name' => 'Quiet Moments', 'code' => '1563', 'hex' => '#C4D2CE', 'family' => 'blue', 'popular' => false],
            ['name' => 'Wrought Iron', 'code' => '2124-10', 'hex' => '#3E3F40', 'family' => 'black', 'popular' => true],
            ['name' => 'Black', 'code' => '2132-10', 'hex' => '#333333', 'family' => 'black', 'popular' => false],
        ];

        $this->seedColors($brandId, $colors);
    }

    private function seedBehr(array $brandMap): void
    {
        $brandId = $brandMap['BEHR'] ?? null;
        if (! $brandId) {
            $this->command?->warn('Behr brand (BEHR) not found, skipping colors.');

            return;
        }

        $colors = [
            ['name' => 'Ultra Pure White', 'code' => 'PPU18-06', 'hex' => '#F6F3EF', 'family' => 'white', 'popular' => true],
            ['name' => 'Cameo White', 'code' => 'PPU5-11', 'hex' => '#EBE0D1', 'family' => 'white', 'popular' => true],
            ['name' => 'Polar Bear', 'code' => 'PPU18-08', 'hex' => '#E4DFD9', 'family' => 'white', 'popular' => false],
            ['name' => 'Silver Drop', 'code' => 'PPU18-07', 'hex' => '#D1CDC6', 'family' => 'gray', 'popular' => true],
            ['name' => 'Dolphin Fin', 'code' => 'PPU26-06', 'hex' => '#B3AFAA', 'family' => 'gray', 'popular' => true],
            ['name' => 'Wheat Bread', 'code' => 'PPU4-14', 'hex' => '#C8B494', 'family' => 'beige', 'popular' => false],
            ['name' => 'Blueprint', 'code' => 'S530-5', 'hex' => '#546A82', 'family' => 'blue', 'popular' => true],
            ['name' => 'In The Moment', 'code' => 'T18-15', 'hex' => '#748A8C', 'family' => 'green', 'popular' => true],
            ['name' => 'Cracked Pepper', 'code' => 'PPU18-01', 'hex' => '#504D4B', 'family' => 'black', 'popular' => false],
            ['name' => 'Black Mocha', 'code' => 'PPU5-01', 'hex' => '#3E3431', 'family' => 'brown', 'popular' => false],
        ];

        $this->seedColors($brandId, $colors);
    }

    private function seedPpg(array $brandMap): void
    {
        $brandId = $brandMap['PPG'] ?? null;
        if (! $brandId) {
            $this->command?->warn('PPG brand (PPG) not found, skipping colors.');

            return;
        }

        $colors = [
            ['name' => 'Delicate White', 'code' => 'PPG1001-1', 'hex' => '#F1EEEA', 'family' => 'white', 'popular' => true],
            ['name' => 'Gypsum', 'code' => 'PPG1025-2', 'hex' => '#E3DDD1', 'family' => 'beige', 'popular' => true],
            ['name' => 'Dusty Miller', 'code' => 'PPG1029-3', 'hex' => '#C8BBAB', 'family' => 'beige', 'popular' => true],
            ['name' => 'Gray Marble', 'code' => 'PPG0995-3', 'hex' => '#B7B0A7', 'family' => 'gray', 'popular' => true],
            ['name' => 'Juniper Berry', 'code' => 'PPG1145-6', 'hex' => '#466962', 'family' => 'green', 'popular' => true],
            ['name' => 'Dark Ashton', 'code' => 'PPG1025-7', 'hex' => '#55493C', 'family' => 'brown', 'popular' => false],
        ];

        $this->seedColors($brandId, $colors);
    }

    /**
     * @param  array<int, array{name: string, code: string, hex: string, family: string, popular: bool}>  $colors
     */
    private function seedColors(int $brandId, array $colors): void
    {
        foreach ($colors as $color) {
            $r = hexdec(substr($color['hex'], 1, 2));
            $g = hexdec(substr($color['hex'], 3, 2));
            $b = hexdec(substr($color['hex'], 5, 2));

            PaintColor::updateOrCreate(
                ['paint_brand_id' => $brandId, 'code' => $color['code']],
                [
                    'name' => $color['name'],
                    'hex_value' => $color['hex'],
                    'rgb_r' => $r,
                    'rgb_g' => $g,
                    'rgb_b' => $b,
                    'color_family' => $color['family'],
                    'is_popular' => $color['popular'],
                    'is_active' => true,
                ]
            );
        }
    }
}
