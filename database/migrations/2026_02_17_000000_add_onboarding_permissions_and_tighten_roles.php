<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create new permissions
        Permission::firstOrCreate(['name' => 'onboarding.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'onboarding.manage', 'guard_name' => 'web']);

        // Grant to admin and manager
        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        $manager = Role::where('name', 'manager')->where('guard_name', 'web')->first();

        if ($admin) {
            $admin->givePermissionTo(['onboarding.view', 'onboarding.manage']);
        }
        if ($manager) {
            $manager->givePermissionTo(['onboarding.view', 'onboarding.manage']);
        }

        // Re-sync owner to include new permissions
        $owner = Role::where('name', 'owner')->where('guard_name', 'web')->first();
        if ($owner) {
            $owner->syncPermissions(Permission::all());
        }

        // Tighten estimator
        $estimator = Role::where('name', 'estimator')->where('guard_name', 'web')->first();
        if ($estimator) {
            $estimator->revokePermissionTo(['employees.view', 'time_tracking.view', 'time_tracking.clock', 'scheduling.view']);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Re-grant revoked permissions
        $estimator = Role::where('name', 'estimator')->where('guard_name', 'web')->first();
        if ($estimator) {
            $estimator->givePermissionTo(['employees.view', 'time_tracking.view', 'time_tracking.clock', 'scheduling.view']);
        }

        // Remove onboarding permissions
        Permission::where('name', 'onboarding.view')->delete();
        Permission::where('name', 'onboarding.manage')->delete();

        // Re-sync owner
        $owner = Role::where('name', 'owner')->where('guard_name', 'web')->first();
        if ($owner) {
            $owner->syncPermissions(Permission::all());
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
