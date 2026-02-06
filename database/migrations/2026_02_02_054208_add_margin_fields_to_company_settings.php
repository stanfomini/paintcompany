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
        Schema::table('company_settings', function (Blueprint $table) {
            // Add margin fields after their markup counterparts
            $table->decimal('margin_percent', 5, 2)->default(45.00)->after('markup_percent');
            $table->decimal('interior_margin_percent', 5, 2)->default(50.00)->after('interior_markup_percent');
            $table->decimal('exterior_margin_percent', 5, 2)->default(41.00)->after('exterior_markup_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'margin_percent',
                'interior_margin_percent',
                'exterior_margin_percent',
            ]);
        });
    }
};
