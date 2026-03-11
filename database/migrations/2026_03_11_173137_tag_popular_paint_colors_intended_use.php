<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Exterior-only: colors commonly used for house body/trim/accents
        DB::table('paint_colors')
            ->where('is_popular', true)
            ->where('intended_use', 'both')
            ->where(function ($q) {
                $q->whereIn('color_family', ['red', 'deep blue', 'forest green', 'navy', 'dark green'])
                    ->orWhere('name', 'like', '%Exterior%')
                    ->orWhere('name', 'like', '%Siding%');
            })
            ->update(['intended_use' => 'exterior']);

        // Interior-only: soft pastels, accent wall colors, light warm tones
        DB::table('paint_colors')
            ->where('is_popular', true)
            ->where('intended_use', 'both')
            ->where(function ($q) {
                $q->whereIn('color_family', ['pink', 'lavender', 'peach', 'lilac', 'mauve'])
                    ->orWhere('name', 'like', '%Nursery%')
                    ->orWhere('name', 'like', '%Accent%');
            })
            ->update(['intended_use' => 'interior']);

        // Everything else stays 'both' (whites, grays, beiges, neutrals)
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('paint_colors')
            ->where('is_popular', true)
            ->whereIn('intended_use', ['interior', 'exterior'])
            ->update(['intended_use' => 'both']);
    }
};
