<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds vendor/internal cost tracking for line items.
     * - cost: What we pay the vendor (e.g., $350 to cleaning company)
     * - total: What customer pays (e.g., $400)
     * - profit: total - cost (e.g., $50)
     */
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->nullable()->after('total')
                ->comment('Vendor/internal cost (if different from total)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
