<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class OwnerUserSeeder extends Seeder
{
    /**
     * Seed the owner user.
     *
     * This seeder ensures there is at least one owner user in the system.
     * If an internal user already exists, it will assign the owner role to the first one.
     * Otherwise, it creates a new owner user with default credentials.
     */
    public function run(): void
    {
        // First, ensure roles and permissions exist
        $this->call(RolesAndPermissionsSeeder::class);

        // Check if owner role exists
        $ownerRole = Role::where('name', 'owner')->first();
        if (! $ownerRole) {
            $this->command->error('Owner role not found. Please run RolesAndPermissionsSeeder first.');

            return;
        }

        // Check if any user already has the owner role
        $existingOwner = User::role('owner')->first();
        if ($existingOwner) {
            $this->command->info("Owner already exists: {$existingOwner->email}");

            return;
        }

        // Try to find an existing internal user to promote
        $internalUser = User::where('type', 'internal')->first();

        if ($internalUser) {
            $internalUser->assignRole('owner');
            $this->command->info("Assigned owner role to existing user: {$internalUser->email}");

            return;
        }

        // Create a new owner user
        $owner = User::create([
            'name' => 'Business Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'type' => 'internal',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $owner->assignRole('owner');

        $this->command->info('Created new owner user:');
        $this->command->info('  Email: owner@example.com');
        $this->command->info('  Password: password');
        $this->command->warn('  Please change these credentials immediately!');
    }
}
