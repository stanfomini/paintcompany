<?php

namespace Database\Seeders;

use App\Models\MarketingStrategy;
use Illuminate\Database\Seeder;

class MarketingStrategySeeder extends Seeder
{
    public function run(): void
    {
        $strategies = [
            // Automated Campaigns
            ['key' => 'neighborhood_campaigns', 'category' => 'automated', 'name' => 'Neighborhood Campaigns'],
            ['key' => 'referral_program', 'category' => 'automated', 'name' => 'Referral Program'],
            ['key' => 'review_requests', 'category' => 'automated', 'name' => 'Review Requests'],
            ['key' => 'sms_reengagement', 'category' => 'automated', 'name' => 'SMS Re-engagement'],

            // Paid Advertising
            ['key' => 'google_lsa', 'category' => 'paid_ads', 'name' => 'Google Local Services Ads'],
            ['key' => 'geofencing', 'category' => 'paid_ads', 'name' => 'Geofencing Paint Stores'],
            ['key' => 'retargeting', 'category' => 'paid_ads', 'name' => 'Retargeting Pixels'],
            ['key' => 'nextdoor', 'category' => 'paid_ads', 'name' => 'Nextdoor Advertising'],
            ['key' => 'ctv', 'category' => 'paid_ads', 'name' => 'Connected TV (CTV)'],

            // New Homeowners
            ['key' => 'new_homeowner_triggers', 'category' => 'new_homeowners', 'name' => 'New Homeowner Triggers'],

            // Partnerships & Offline
            ['key' => 'sherwin_williams', 'category' => 'partnerships', 'name' => 'Sherwin-Williams PRO+'],
            ['key' => 'home_inspectors', 'category' => 'partnerships', 'name' => 'Home Inspector Referrals'],
            ['key' => 'hoa_property_managers', 'category' => 'partnerships', 'name' => 'HOA & Property Managers'],
            ['key' => 'vehicle_wraps', 'category' => 'partnerships', 'name' => 'Vehicle Wraps & QR Codes'],
            ['key' => 'yard_signs', 'category' => 'partnerships', 'name' => 'Yard Signs & Door Hangers'],
            ['key' => 'events', 'category' => 'partnerships', 'name' => 'Events & Home Shows'],
        ];

        foreach ($strategies as $data) {
            MarketingStrategy::firstOrCreate(
                ['key' => $data['key']],
                array_merge($data, ['status' => 'not_started'])
            );
        }
    }
}
