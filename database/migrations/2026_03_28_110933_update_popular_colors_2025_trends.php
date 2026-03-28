<?php

use App\Models\PaintBrand;
use App\Models\PaintColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Resets all popular color flags and re-tags colors based on actual
     * 2025-2026 brand trending colors with proper interior/exterior separation.
     * Uses specific color codes to avoid duplicate-name issues.
     */
    public function up(): void
    {
        // Step 1: Reset ALL colors
        DB::table('paint_colors')->update([
            'is_popular' => false,
            'intended_use' => 'both',
        ]);

        $brands = PaintBrand::pluck('id', 'code')->toArray();

        // Step 2: Tag curated popular colors per brand using specific codes
        // 'both' = appears in both interior and exterior trending lists
        // 'interior' = interior-only trending
        // 'exterior' = exterior-only trending

        // --- Sherwin-Williams ---
        if ($sw = $brands['SW'] ?? null) {
            $this->tagByCodes($sw, 'both', [
                'SW 7008',  // Alabaster
                'SW 7005',  // Pure White
                'SW 9130',  // Evergreen Fog
                'SW 7004',  // Snowbound
                'SW 7036',  // Accessible Beige
                'SW 7029',  // Agreeable Gray
            ]);
            $this->tagByCodes($sw, 'interior', [
                'SW 7015',  // Repose Gray
                'SW 6204',  // Sea Salt
                'SW 6089',  // Grounded (2025 COTY)
                'SW 9639',  // Rain Cloud (2025 Capsule)
                'SW 7016',  // Mindful Gray
                'SW 7042',  // Shoji White
            ]);
            $this->tagByCodes($sw, 'exterior', [
                'SW 6385',  // Dover White
                'SW 7674',  // Peppercorn
                'SW 7069',  // Iron Ore
                'SW 6258',  // Tricorn Black
                'SW 6244',  // Naval
                'SW 7048',  // Urbane Bronze
            ]);
        }

        // --- Benjamin Moore ---
        if ($bm = $brands['BM'] ?? null) {
            $this->tagByCodes($bm, 'both', [
                'OC-17',    // White Dove
                'OC-65',    // Chantilly Lace
                'OC-45',    // Swiss Coffee
                'HC-172',   // Revere Pewter
                'HC-154',   // Hale Navy
            ]);
            $this->tagByCodes($bm, 'interior', [
                'OC-117',   // Simply White
                'HC-173',   // Edgecomb Gray
                '2137-60',  // Gray Owl
                '2113-40',  // Cinnamon Slate (2025 COTY)
            ]);
            $this->tagByCodes($bm, 'exterior', [
                'HC-104',   // Copley Gray
                'HC-169',   // Coventry Gray
                '1495',     // October Mist
                '2120-10',  // Jet Black
                'HC-113',   // Louisburg Green
            ]);
        }

        // --- BEHR ---
        if ($behr = $brands['BEHR'] ?? null) {
            $this->tagByCodes($behr, 'both', [
                '12',           // Swiss Coffee
                'MQ1-15',       // Rumors (2025 COTY)
                'DC-003',       // Blank Canvas
            ]);
            $this->tagByCodes($behr, 'interior', [
                'DC-010',       // Even Better Beige
                'PPU11-13',     // Frosted Jade
                'PPU15-19',     // Black Sapphire
                'HDC-NT-27B',   // Wild Truffle
                'HDC-CL-28',   // Nocturne Blue
            ]);
            $this->tagByCodes($behr, 'exterior', [
                '75',           // Polar Bear
                'OR-W7',        // Spanish Sand
                'PPU5-1',       // Espresso Beans
                'PPU18-01',     // Cracked Pepper
                'HDC-CL-21A',  // Dark Everglade
            ]);
        }

        // --- PPG ---
        if ($ppg = $brands['PPG'] ?? null) {
            $this->tagByCodes($ppg, 'both', [
                'PPG1001-1',    // Delicate White
                'PPG1020-1',    // Atrium White
                'PPG1025-2',    // Gypsum
                'PPG1032-2',    // Whiskers
                'PPG1133-5',    // Slate Green
                'PPG1125-4',    // Olive Sprig
                'PPG1085-3',    // Seriously Sand
                'PPG1046-7',    // Purple Basil (2025 COTY)
            ]);
            $this->tagByCodes($ppg, 'interior', [
                'PPG1029-3',    // Dusty Miller
            ]);
            $this->tagByCodes($ppg, 'exterior', [
                'PPG1001-5',    // Dover Gray
                'PPG1145-6',    // Juniper Berry
            ]);
        }

        PaintColor::clearCache();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('paint_colors')->update([
            'is_popular' => false,
            'intended_use' => 'both',
        ]);

        PaintColor::clearCache();
    }

    /**
     * Tag colors as popular with a specific intended use, matched by code.
     */
    private function tagByCodes(int $brandId, string $intendedUse, array $codes): void
    {
        DB::table('paint_colors')
            ->where('paint_brand_id', $brandId)
            ->whereIn('code', $codes)
            ->update([
                'is_popular' => true,
                'intended_use' => $intendedUse,
            ]);
    }
};
