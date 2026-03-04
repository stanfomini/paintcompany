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
        Schema::table('paint_colors', function (Blueprint $table) {
            $table->index('name');
            $table->index(['is_active', 'paint_brand_id', 'color_family']);
            $table->index(['is_active', 'is_popular']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paint_colors', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['is_active', 'paint_brand_id', 'color_family']);
            $table->dropIndex(['is_active', 'is_popular']);
        });
    }
};
