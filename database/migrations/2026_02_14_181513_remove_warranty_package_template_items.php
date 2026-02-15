<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove warranty PackageTemplateItems and their pivot entries.
     * Also zero out warranty_months/warranty_percent on PackageTemplates.
     */
    public function up(): void
    {
        // Find warranty items: customer-selectable + percent pricing + name contains "Warranty"
        $warrantyItemIds = DB::table('package_template_items')
            ->where('is_customer_selectable', true)
            ->where('pricing_type', 'percent')
            ->where('name', 'like', '%Warranty%')
            ->pluck('id');

        if ($warrantyItemIds->isNotEmpty()) {
            // Remove pivot entries
            DB::table('package_template_item_defaults')
                ->whereIn('package_template_item_id', $warrantyItemIds)
                ->delete();

            // Remove the warranty items themselves
            DB::table('package_template_items')
                ->whereIn('id', $warrantyItemIds)
                ->delete();
        }

        // Zero out warranty fields on all package templates
        DB::table('package_templates')->update([
            'warranty_months' => 0,
            'warranty_percent' => 0,
        ]);
    }

    public function down(): void
    {
        // No reversal - warranty items can be re-seeded if needed
    }
};
