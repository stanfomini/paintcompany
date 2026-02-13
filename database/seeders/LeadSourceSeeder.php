<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            // Built-in system sources
            ['name' => 'Website', 'slug' => 'website', 'type' => 'built_in', 'is_system' => true, 'icon' => 'globe-alt', 'color' => 'blue', 'sort_order' => 1],
            ['name' => 'Referral', 'slug' => 'referral', 'type' => 'built_in', 'is_system' => true, 'icon' => 'users', 'color' => 'green', 'sort_order' => 2],
            ['name' => 'Landing Page', 'slug' => 'landing_page', 'type' => 'built_in', 'is_system' => true, 'icon' => 'document-text', 'color' => 'purple', 'sort_order' => 3],
            ['name' => 'Invite Link', 'slug' => 'invite_link', 'type' => 'built_in', 'is_system' => true, 'icon' => 'link', 'color' => 'blue', 'sort_order' => 4],
            ['name' => 'Landing Page - Availability', 'slug' => 'landing_page_scarcity', 'type' => 'built_in', 'is_system' => true, 'icon' => 'clock', 'color' => 'amber', 'sort_order' => 5],
            ['name' => 'Agent Signup', 'slug' => 'agent_signup', 'type' => 'built_in', 'is_system' => true, 'icon' => 'user-plus', 'color' => 'indigo', 'sort_order' => 6],

            // Custom sources
            ['name' => 'Google Ads', 'slug' => 'google_ads', 'type' => 'custom', 'icon' => 'megaphone', 'color' => 'red', 'sort_order' => 10],
            ['name' => 'Facebook', 'slug' => 'facebook', 'type' => 'custom', 'icon' => 'chat-bubble-left', 'color' => 'blue', 'sort_order' => 11],
            ['name' => 'Yelp', 'slug' => 'yelp', 'type' => 'custom', 'icon' => 'star', 'color' => 'red', 'sort_order' => 12],
            ['name' => 'Nextdoor', 'slug' => 'nextdoor', 'type' => 'custom', 'icon' => 'home', 'color' => 'green', 'sort_order' => 13],
            ['name' => 'Yard Sign', 'slug' => 'yard_sign', 'type' => 'custom', 'icon' => 'flag', 'color' => 'yellow', 'sort_order' => 14],
            ['name' => 'Door Knock', 'slug' => 'door_knock', 'type' => 'custom', 'icon' => 'hand-raised', 'color' => 'orange', 'sort_order' => 15],
            ['name' => 'Walk-In', 'slug' => 'walk_in', 'type' => 'custom', 'icon' => 'user', 'color' => 'gray', 'sort_order' => 16],
            ['name' => 'Repeat', 'slug' => 'repeat', 'type' => 'custom', 'icon' => 'arrow-path', 'color' => 'green', 'sort_order' => 17],
            ['name' => 'Other', 'slug' => 'other', 'type' => 'custom', 'icon' => 'ellipsis-horizontal', 'color' => 'gray', 'sort_order' => 18],

            // Webhook sources (inactive by default)
            ['name' => 'Angi Leads', 'slug' => 'angi', 'type' => 'webhook', 'icon' => 'signal', 'color' => 'red', 'is_active' => false, 'sort_order' => 30],
            ['name' => 'CraftJack', 'slug' => 'craftjack', 'type' => 'webhook', 'icon' => 'signal', 'color' => 'blue', 'is_active' => false, 'sort_order' => 31],
            ['name' => 'Thumbtack', 'slug' => 'thumbtack', 'type' => 'webhook', 'icon' => 'signal', 'color' => 'green', 'is_active' => false, 'sort_order' => 32],
        ];

        foreach ($sources as $source) {
            LeadSource::firstOrCreate(
                ['slug' => $source['slug']],
                $source
            );
        }
    }
}
