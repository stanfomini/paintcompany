<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('lead_sources')->insertOrIgnore([
            [
                'name' => 'Landing Page - Availability',
                'slug' => 'landing_page_scarcity',
                'type' => 'built_in',
                'is_system' => true,
                'icon' => 'clock',
                'color' => 'amber',
                'sort_order' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Agent Signup',
                'slug' => 'agent_signup',
                'type' => 'built_in',
                'is_system' => true,
                'icon' => 'user-plus',
                'color' => 'indigo',
                'sort_order' => 6,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('lead_sources')->whereIn('slug', [
            'landing_page_scarcity',
            'agent_signup',
        ])->delete();
    }
};
