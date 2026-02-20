<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'deals.view_all',
            'estimates.view_all',
            'jobs.view_all',
            'people.view_all',
            'properties.view_all',
            'invoices.view_all',
            'chat.view_deal_chats',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Grant all view_all permissions to owner (automatic via syncPermissions on all)
        $owner = Role::where('name', 'owner')->where('guard_name', 'web')->first();
        if ($owner) {
            $owner->syncPermissions(Permission::all());
        }

        // Grant to admin
        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            foreach ($permissions as $permission) {
                if (! $admin->hasPermissionTo($permission)) {
                    $admin->givePermissionTo($permission);
                }
            }
        }

        // Grant to manager
        $manager = Role::where('name', 'manager')->where('guard_name', 'web')->first();
        if ($manager) {
            foreach ($permissions as $permission) {
                if (! $manager->hasPermissionTo($permission)) {
                    $manager->givePermissionTo($permission);
                }
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'deals.view_all',
            'estimates.view_all',
            'jobs.view_all',
            'people.view_all',
            'properties.view_all',
            'invoices.view_all',
            'chat.view_deal_chats',
        ];

        foreach ($permissions as $permission) {
            Permission::where('name', $permission)->where('guard_name', 'web')->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
