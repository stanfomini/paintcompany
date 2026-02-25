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

        $permissions = [
            'notify.estimate_accepted',
            'notify.estimate_rejected',
            'notify.proposal_viewed',
            'notify.job_created',
            'notify.job_stage_advanced',
            'notify.customer_confirmed_date',
            'notify.customer_confirmed_colors',
            'notify.customer_proposed_date',
            'notify.new_account_created',
            'notify.new_support_chat',
            'notify.new_messenger_lead',
            'notify.customer_portal_login',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Owner gets all notification permissions by default
        $ownerRole = Role::where('name', 'owner')->where('guard_name', 'web')->first();
        if ($ownerRole) {
            $ownerRole->givePermissionTo($permissions);
        }

        // Admin gets all notification permissions by default
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
        }

        // Estimator gets estimate-related notifications
        $estimatorRole = Role::where('name', 'estimator')->where('guard_name', 'web')->first();
        if ($estimatorRole) {
            $estimatorRole->givePermissionTo([
                'notify.estimate_accepted',
                'notify.estimate_rejected',
                'notify.proposal_viewed',
                'notify.job_created',
            ]);
        }

        // Manager gets job/scheduling notifications
        $managerRole = Role::where('name', 'manager')->where('guard_name', 'web')->first();
        if ($managerRole) {
            $managerRole->givePermissionTo([
                'notify.estimate_accepted',
                'notify.job_created',
                'notify.job_stage_advanced',
                'notify.customer_confirmed_date',
                'notify.customer_confirmed_colors',
                'notify.customer_proposed_date',
            ]);
        }
    }

    public function down(): void
    {
        $permissions = Permission::where('name', 'like', 'notify.%')->get();
        foreach ($permissions as $permission) {
            $permission->delete();
        }
    }
};
