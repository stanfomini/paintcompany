<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cost_cards', function (Blueprint $table) {
            $table->decimal('actual_cleanup_cost', 10, 2)->nullable()->after('actual_other_cost');
            $table->decimal('actual_sundries_cost', 10, 2)->nullable()->after('actual_cleanup_cost');
            $table->decimal('actual_material_tax', 10, 2)->nullable()->after('actual_sundries_cost');
            $table->decimal('actual_labor_tax', 10, 2)->nullable()->after('actual_material_tax');
            $table->decimal('actual_addon_cost', 10, 2)->nullable()->after('actual_labor_tax');
            $table->decimal('actual_items_vendor_cost', 10, 2)->nullable()->after('actual_addon_cost');
            $table->decimal('actual_warranty_reserve', 10, 2)->nullable()->after('actual_items_vendor_cost');
            $table->decimal('actual_crew_bonus', 10, 2)->nullable()->after('actual_warranty_reserve');
            $table->decimal('actual_allocation_total', 10, 2)->nullable()->after('actual_crew_bonus');
            $table->foreignId('journal_entry_id')->nullable()->after('actual_allocation_total')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_cost_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn([
                'actual_cleanup_cost',
                'actual_sundries_cost',
                'actual_material_tax',
                'actual_labor_tax',
                'actual_addon_cost',
                'actual_items_vendor_cost',
                'actual_warranty_reserve',
                'actual_crew_bonus',
                'actual_allocation_total',
            ]);
        });
    }
};
