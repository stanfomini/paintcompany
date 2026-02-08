<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Make package_template_id nullable and add new columns
        Schema::table('package_template_items', function (Blueprint $table) {
            // Drop FK before making nullable (SQLite compat)
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['package_template_id']);
            }
        });

        Schema::table('package_template_items', function (Blueprint $table) {
            $table->unsignedBigInteger('package_template_id')->nullable()->change();
            $table->foreignId('service_category_id')->nullable()->after('package_template_id')->constrained('service_categories')->nullOnDelete();
            $table->boolean('is_customer_selectable')->default(false)->after('is_included_by_default');

            // Re-add FK as nullable
            $table->foreign('package_template_id')
                ->references('id')
                ->on('package_templates')
                ->nullOnDelete();
        });

        // 2. Create pivot table for default package assignments
        Schema::create('package_template_item_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_template_item_id')->constrained('package_template_items')->cascadeOnDelete();
            $table->foreignId('package_template_id')->constrained('package_templates')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['package_template_item_id', 'package_template_id'], 'item_template_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_template_item_defaults');

        Schema::table('package_template_items', function (Blueprint $table) {
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign(['package_template_id']);
                $table->dropForeign(['service_category_id']);
            }

            $table->dropColumn(['service_category_id', 'is_customer_selectable']);
        });

        Schema::table('package_template_items', function (Blueprint $table) {
            $table->unsignedBigInteger('package_template_id')->nullable(false)->change();

            $table->foreign('package_template_id')
                ->references('id')
                ->on('package_templates')
                ->cascadeOnDelete();
        });
    }
};
