<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename legacy space-separated permissions to dot-notation
        $renames = [
            'view roadmap' => 'roadmap.view',
            'edit roadmap' => 'roadmap.edit',
            'manage roadmap' => 'roadmap.manage',
            'view kpis' => 'kpis.view',
            'edit kpi targets' => 'kpis.edit_targets',
            'enter kpi actuals' => 'kpis.enter_actuals',
            'manage kpis' => 'kpis.manage',
            'manage users' => 'team.manage_users',
            'manage invitations' => 'team.manage_invitations',
            'manage roles' => 'team.manage_roles',
            'access admin settings' => 'admin.access_settings',
        ];

        foreach ($renames as $oldName => $newName) {
            Permission::where('name', $oldName)
                ->where('guard_name', 'web')
                ->update(['name' => $newName]);
        }

        // Create new permissions
        $newPermissions = [
            'referrals.view',
            'referrals.manage',
            'settings.service_categories',
            'settings.budget_mappings',
            'settings.referral',
            'settings.contracts',
        ];

        foreach ($newPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Clear Spatie cache before re-seeding
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Re-run seeder to sync role assignments with new permission names
        Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse renames
        $renames = [
            'roadmap.view' => 'view roadmap',
            'roadmap.edit' => 'edit roadmap',
            'roadmap.manage' => 'manage roadmap',
            'kpis.view' => 'view kpis',
            'kpis.edit_targets' => 'edit kpi targets',
            'kpis.enter_actuals' => 'enter kpi actuals',
            'kpis.manage' => 'manage kpis',
            'team.manage_users' => 'manage users',
            'team.manage_invitations' => 'manage invitations',
            'team.manage_roles' => 'manage roles',
            'admin.access_settings' => 'access admin settings',
        ];

        foreach ($renames as $newName => $oldName) {
            Permission::where('name', $newName)
                ->where('guard_name', 'web')
                ->update(['name' => $oldName]);
        }

        // Delete new permissions
        Permission::whereIn('name', [
            'referrals.view',
            'referrals.manage',
            'settings.service_categories',
            'settings.budget_mappings',
            'settings.referral',
            'settings.contracts',
        ])->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
