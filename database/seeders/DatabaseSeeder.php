<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create default internal admin user
        User::factory()->internal()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        // Create additional internal team members
        User::factory(5)->internal()->create();

        // Create test agent user
        User::factory()->agent()->create([
            'name' => 'Test Agent',
            'email' => 'agent@example.com',
        ]);

        // Create additional agents
        User::factory(10)->agent()->create();

        // Create test customer user
        User::factory()->customer()->create([
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
        ]);

        // Create additional customers
        User::factory(20)->customer()->create();
    }
}
