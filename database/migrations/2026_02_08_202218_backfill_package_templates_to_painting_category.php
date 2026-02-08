<?php

use App\Models\ServiceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Seed the Cleaning category
        ServiceCategory::updateOrCreate(
            ['slug' => 'cleaning'],
            [
                'name' => 'Cleaning',
                'description' => 'Post-painting cleanup and pressure washing',
                'icon' => 'sparkles',
                'color' => 'green',
                'is_active' => true,
                'sort_order' => 2,
            ]
        );

        // Backfill existing records to the Painting category
        $painting = ServiceCategory::where('slug', 'painting')->first();

        if ($painting) {
            DB::table('package_templates')
                ->whereNull('service_category_id')
                ->update(['service_category_id' => $painting->id]);

            DB::table('estimate_packages')
                ->whereNull('service_category_id')
                ->update(['service_category_id' => $painting->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('package_templates')->update(['service_category_id' => null]);
        DB::table('estimate_packages')->update(['service_category_id' => null]);

        ServiceCategory::where('slug', 'cleaning')->delete();
    }
};
