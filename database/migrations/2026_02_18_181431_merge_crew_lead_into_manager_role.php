<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $crewLead = Role::where('name', 'crew_lead')->where('guard_name', 'web')->first();

        if (! $crewLead) {
            return;
        }

        $manager = Role::where('name', 'manager')->where('guard_name', 'web')->first();

        // Reassign all crew_lead users to manager
        foreach ($crewLead->users as $user) {
            if ($manager) {
                $user->assignRole('manager');
            }
            $user->removeRole('crew_lead');
        }

        // Delete the crew_lead role
        $crewLead->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Recreate crew_lead role (without restoring user assignments)
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'crew_lead', 'guard_name' => 'web']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
