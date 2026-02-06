<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use App\Models\MarketingTrackingEntry;
use Illuminate\Database\Seeder;

class MarketingSourceSeeder extends Seeder
{
    /**
     * Seed default marketing sources for the current year.
     */
    public function run(): void
    {
        $companySetting = CompanySetting::getInstance();
        $year = now()->year;

        foreach (MarketingTrackingEntry::defaultSources() as $index => $source) {
            MarketingTrackingEntry::firstOrCreate(
                [
                    'company_setting_id' => $companySetting->id,
                    'year' => $year,
                    'source_name' => $source,
                ],
                [
                    'marketing_expense' => 0,
                    'leads' => 0,
                    'estimates' => 0,
                    'jobs_booked' => 0,
                    'total_sales' => 0,
                    'is_manual' => false,
                    'sort_order' => $index,
                ]
            );
        }
    }
}
