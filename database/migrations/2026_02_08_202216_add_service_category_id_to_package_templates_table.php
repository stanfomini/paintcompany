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
        Schema::table('package_templates', function (Blueprint $table) {
            $table->foreignId('service_category_id')->nullable()->after('sort_order')
                ->constrained('service_categories')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('package_templates', function (Blueprint $table) {
                $table->dropUnique('package_templates_tier_unique');
                $table->unique(['tier', 'service_category_id']);
            });
        } else {
            // SQLite: drop old unique index via raw SQL, then add composite
            DB::statement('DROP INDEX IF EXISTS package_templates_tier_unique');
            DB::statement('CREATE UNIQUE INDEX package_templates_tier_service_category_id_unique ON package_templates (tier, service_category_id)');
        }

        Schema::table('estimate_packages', function (Blueprint $table) {
            $table->foreignId('service_category_id')->nullable()->after('sort_order')
                ->constrained('service_categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_category_id');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('package_templates', function (Blueprint $table) {
                $table->dropUnique(['tier', 'service_category_id']);
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS package_templates_tier_service_category_id_unique');
        }

        Schema::table('package_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_category_id');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('package_templates', function (Blueprint $table) {
                $table->unique('tier');
            });
        } else {
            DB::statement('CREATE UNIQUE INDEX package_templates_tier_unique ON package_templates (tier)');
        }
    }
};
