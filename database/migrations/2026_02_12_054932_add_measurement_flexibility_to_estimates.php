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
            $table->string('measurement_type', 20)->default('dimensions')->after('scope_type');
            $table->unsignedTinyInteger('wall_count')->default(4)->after('measurement_type');
            $table->decimal('direct_sqft', 10, 2)->nullable()->after('wall_count');
            $table->decimal('direct_lnft', 10, 2)->nullable()->after('direct_sqft');
            $table->decimal('direct_ceiling_sqft', 10, 2)->nullable()->after('direct_lnft');
            $table->foreignId('service_category_id')->nullable()->after('default_paint_product_id')
                ->constrained('service_categories')->nullOnDelete();
        });

        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('is_included');
        });

        Schema::table('nested_area_templates', function (Blueprint $table) {
            $table->string('measurement_type', 20)->default('dimensions')->after('scope_type');
            $table->unsignedTinyInteger('wall_count')->default(4)->after('measurement_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_areas', function (Blueprint $table) {
            $table->dropForeign(['service_category_id']);
            $table->dropColumn([
                'measurement_type',
                'wall_count',
                'direct_sqft',
                'direct_lnft',
                'direct_ceiling_sqft',
                'service_category_id',
            ]);
        });

        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropColumn('is_custom');
        });

        Schema::table('nested_area_templates', function (Blueprint $table) {
            $table->dropColumn(['measurement_type', 'wall_count']);
        });
    }
};
