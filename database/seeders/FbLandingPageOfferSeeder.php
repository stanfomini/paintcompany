<?php

namespace Database\Seeders;

use App\Models\FbLandingPageOffer;
use App\Models\LeadSource;
use Illuminate\Database\Seeder;

class FbLandingPageOfferSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure facebook_ads lead source exists
        $leadSource = LeadSource::firstOrCreate(
            ['slug' => 'facebook_ads'],
            [
                'name' => 'Facebook Ads',
                'type' => 'paid',
                'icon' => 'signal',
                'color' => '#1877F2',
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 20,
            ]
        );

        // Create the default VIP Paint Package offer
        FbLandingPageOffer::firstOrCreate(
            ['slug' => 'vip-paint-package'],
            [
                'title' => 'VIP Paint Package - Facebook Campaign',
                'headline' => 'The VIP Paint Package',
                'subheadline' => 'Everything you need for a stress-free painting experience — plus exclusive savings when you let us feature your home.',
                'offer_bullets' => [
                    'Free access to our AI color selection tools (pick your perfect colors before we start)',
                    'Options-based estimates (choose what fits your budget — no pressure)',
                    'Written project plan before any work begins (know exactly what to expect)',
                    'Up to 25% of your project FREE when you let us feature your home (LIMITED OFFER)',
                    'All the extras included: furniture moving, floor protection, daily cleanup',
                ],
                'cta_text' => 'Claim Your Free Estimate',
                'is_active' => true,
                'lead_source_id' => $leadSource->id,
            ]
        );
    }
}
