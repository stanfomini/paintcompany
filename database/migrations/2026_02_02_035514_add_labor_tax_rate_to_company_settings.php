<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds labor_tax_rate column for states that require sales tax on labor.
     * Default is 0% (most states don't tax labor).
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('labor_tax_rate', 5, 2)->default(0)->after('material_tax_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('labor_tax_rate');
        });
    }
};
