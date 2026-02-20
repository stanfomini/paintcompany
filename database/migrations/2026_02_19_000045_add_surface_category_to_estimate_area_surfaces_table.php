<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->string('surface_category', 50)->nullable()->after('surface_type');
        });

        // Backfill existing data (MySQL only — SQLite test DB starts empty)
        if (DB::getDriverName() === 'mysql') {
            // From production rate's surface_category where available
            DB::statement('
                UPDATE estimate_area_surfaces eas
                JOIN production_rates pr ON pr.id = eas.production_rate_id
                SET eas.surface_category = pr.surface_category
                WHERE eas.production_rate_id IS NOT NULL
            ');

            // Interior scope areas
            DB::statement("
                UPDATE estimate_area_surfaces eas
                JOIN estimate_areas ea ON ea.id = eas.area_id
                SET eas.surface_category = CASE eas.surface_type
                    WHEN 'walls' THEN 'walls'
                    WHEN 'ceiling' THEN 'ceiling'
                    WHEN 'trim' THEN 'baseboard'
                    WHEN 'doors' THEN 'doors'
                    WHEN 'windows' THEN 'windows'
                    ELSE 'walls'
                END
                WHERE eas.surface_category IS NULL
                  AND (ea.scope_type = 'interior' OR ea.scope_type IS NULL)
            ");

            // Exterior scope areas
            DB::statement("
                UPDATE estimate_area_surfaces eas
                JOIN estimate_areas ea ON ea.id = eas.area_id
                SET eas.surface_category = CASE eas.surface_type
                    WHEN 'walls' THEN 'body'
                    WHEN 'ceiling' THEN 'eaves'
                    WHEN 'trim' THEN 'trim'
                    WHEN 'doors' THEN 'exterior_doors'
                    WHEN 'windows' THEN 'windows'
                    ELSE 'body'
                END
                WHERE eas.surface_category IS NULL
                  AND ea.scope_type = 'exterior'
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropColumn('surface_category');
        });
    }
};
