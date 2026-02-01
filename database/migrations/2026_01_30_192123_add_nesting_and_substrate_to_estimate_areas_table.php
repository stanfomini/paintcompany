<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('estimate_areas', function (Blueprint $table) {
            // Nested area support
            $table->foreignId('parent_area_id')->nullable()->after('estimate_id')
                ->constrained('estimate_areas')->cascadeOnDelete();
            $table->foreignId('nested_area_template_id')->nullable()->after('parent_area_id')
                ->constrained('nested_area_templates')->nullOnDelete();

            // Fixed price support (for closets, etc.)
            $table->boolean('is_fixed_price')->default(false)->after('floor_sqft');
            $table->decimal('fixed_price_amount', 10, 2)->nullable()->after('is_fixed_price');

            // Substrate selection for the area
            $table->foreignId('substrate_id')->nullable()->after('fixed_price_amount')
                ->constrained('substrates')->nullOnDelete();

            // Additional calculated fields
            $table->decimal('perimeter_lnft', 10, 2)->default(0)->after('floor_sqft');
            $table->decimal('baseboard_lnft', 10, 2)->default(0)->after('perimeter_lnft');

            // Application method for the area
            $table->string('application_method')->default('brush_roll')->after('baseboard_lnft'); // spray, brush_roll

            // Scope type
            $table->string('scope_type')->default('interior')->after('application_method'); // interior, exterior

            // Indexes
            $table->index('parent_area_id');
            $table->index('substrate_id');
            $table->index('scope_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->dropForeign(['parent_area_id']);
            $table->dropForeign(['nested_area_template_id']);
            $table->dropForeign(['substrate_id']);
            $table->dropIndex(['parent_area_id']);
            $table->dropIndex(['substrate_id']);
            $table->dropIndex(['scope_type']);
            $table->dropColumn([
                'parent_area_id',
                'nested_area_template_id',
                'is_fixed_price',
                'fixed_price_amount',
                'substrate_id',
                'perimeter_lnft',
                'baseboard_lnft',
                'application_method',
                'scope_type',
            ]);
        });
    }
};
