<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cost_cards', function (Blueprint $table) {
            // Projected COGS decomposition (from EstimateCostBreakdownService)
            $table->decimal('labor_cost', 12, 2)->default(0)->after('sales_commission');
            $table->decimal('cleanup_cost', 12, 2)->default(0)->after('labor_cost');
            $table->decimal('sundries_cost', 12, 2)->default(0)->after('cleanup_cost');
            $table->decimal('material_tax', 12, 2)->default(0)->after('sundries_cost');
            $table->decimal('labor_tax', 12, 2)->default(0)->after('material_tax');
            $table->decimal('addon_cost', 12, 2)->default(0)->after('labor_tax');
            $table->decimal('items_vendor_cost', 12, 2)->default(0)->after('addon_cost');
            $table->decimal('warranty_reserve', 12, 2)->default(0)->after('items_vendor_cost');
            $table->decimal('total_cogs', 12, 2)->default(0)->after('warranty_reserve');

            // Hours
            $table->decimal('labor_hours', 8, 2)->default(0)->after('total_cogs');
            $table->decimal('cleanup_hours', 8, 2)->default(0)->after('labor_hours');
            $table->decimal('total_hours', 8, 2)->default(0)->after('cleanup_hours');

            // Profit snapshot
            $table->decimal('projected_gross_profit', 12, 2)->default(0)->after('total_hours');
            $table->decimal('projected_gross_profit_percent', 6, 2)->default(0)->after('projected_gross_profit');
            $table->decimal('crew_bonus', 12, 2)->default(0)->after('projected_gross_profit_percent');

            // Actual tracking (nullable = not tracked yet)
            $table->decimal('actual_material_cost', 12, 2)->nullable()->after('crew_bonus');
            $table->decimal('actual_labor_cost', 12, 2)->nullable()->after('actual_material_cost');
            $table->decimal('actual_subcontractor_cost', 12, 2)->nullable()->after('actual_labor_cost');
            $table->decimal('actual_other_cost', 12, 2)->nullable()->after('actual_subcontractor_cost');
            $table->decimal('actual_total_cost', 12, 2)->nullable()->after('actual_other_cost');
            $table->text('actual_notes')->nullable()->after('actual_total_cost');

            // Payment summary (denormalized from invoices)
            $table->decimal('total_invoiced', 12, 2)->default(0)->after('actual_notes');
            $table->decimal('total_collected', 12, 2)->default(0)->after('total_invoiced');
            $table->decimal('balance_due', 12, 2)->default(0)->after('total_collected');

            // Metadata
            $table->string('scope_type', 20)->nullable()->after('balance_due');
            $table->json('settings_snapshot')->nullable()->after('scope_type');
        });
    }

    public function down(): void
    {
        Schema::table('job_cost_cards', function (Blueprint $table) {
            $table->dropColumn([
                'labor_cost', 'cleanup_cost', 'sundries_cost',
                'material_tax', 'labor_tax', 'addon_cost',
                'items_vendor_cost', 'warranty_reserve', 'total_cogs',
                'labor_hours', 'cleanup_hours', 'total_hours',
                'projected_gross_profit', 'projected_gross_profit_percent', 'crew_bonus',
                'actual_material_cost', 'actual_labor_cost', 'actual_subcontractor_cost',
                'actual_other_cost', 'actual_total_cost', 'actual_notes',
                'total_invoiced', 'total_collected', 'balance_due',
                'scope_type', 'settings_snapshot',
            ]);
        });
    }
};
