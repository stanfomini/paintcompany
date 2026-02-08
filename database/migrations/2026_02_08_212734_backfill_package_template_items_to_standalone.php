<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill pivot table from existing item-template relationships
        $items = DB::table('package_template_items')
            ->whereNotNull('package_template_id')
            ->get();

        foreach ($items as $item) {
            // Create pivot entry
            DB::table('package_template_item_defaults')->insert([
                'package_template_item_id' => $item->id,
                'package_template_id' => $item->package_template_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Copy the template's service_category_id to the item
            $template = DB::table('package_templates')->find($item->package_template_id);
            if ($template && $template->service_category_id) {
                DB::table('package_template_items')
                    ->where('id', $item->id)
                    ->update(['service_category_id' => $template->service_category_id]);
            }
        }

        // 2. Create warranty items from existing package template warranty configs
        $templates = DB::table('package_templates')
            ->where('warranty_months', '>', 0)
            ->where('warranty_percent', '>', 0)
            ->get();

        foreach ($templates as $template) {
            $months = $template->warranty_months;
            $name = $months >= 12
                ? floor($months / 12).'-Year Warranty'
                : $months.'-Month Warranty';

            // Check if this warranty item already exists (by name + percent)
            $existing = DB::table('package_template_items')
                ->where('name', $name)
                ->where('pricing_type', 'percent')
                ->where('percent', $template->warranty_percent)
                ->where('is_customer_selectable', true)
                ->first();

            if ($existing) {
                // Just add pivot if not already linked
                $pivotExists = DB::table('package_template_item_defaults')
                    ->where('package_template_item_id', $existing->id)
                    ->where('package_template_id', $template->id)
                    ->exists();

                if (! $pivotExists) {
                    DB::table('package_template_item_defaults')->insert([
                        'package_template_item_id' => $existing->id,
                        'package_template_id' => $template->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } else {
                // Create new warranty item
                $itemId = DB::table('package_template_items')->insertGetId([
                    'package_template_id' => null,
                    'service_category_id' => $template->service_category_id,
                    'name' => $name,
                    'description' => "Warranty coverage for {$name}",
                    'pricing_type' => 'percent',
                    'cost' => 0,
                    'price' => 0,
                    'percent' => $template->warranty_percent,
                    'is_optional' => true,
                    'is_included_by_default' => true,
                    'is_customer_selectable' => true,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Link to template via pivot
                DB::table('package_template_item_defaults')->insert([
                    'package_template_item_id' => $itemId,
                    'package_template_id' => $template->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Remove warranty items (those with is_customer_selectable=true and pricing_type=percent and no package_template_id)
        $warrantyItemIds = DB::table('package_template_items')
            ->where('is_customer_selectable', true)
            ->where('pricing_type', 'percent')
            ->whereNull('package_template_id')
            ->pluck('id');

        DB::table('package_template_item_defaults')
            ->whereIn('package_template_item_id', $warrantyItemIds)
            ->delete();

        DB::table('package_template_items')
            ->whereIn('id', $warrantyItemIds)
            ->delete();

        // Clear all remaining pivot entries
        DB::table('package_template_item_defaults')->truncate();
    }
};
