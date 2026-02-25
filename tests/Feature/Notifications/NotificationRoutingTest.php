<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Services\NotificationRouter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

describe('NotificationRouter', function () {
    it('returns assigned user for estimate notification', function () {
        $assignee = User::factory()->internal()->create();
        $customer = Customer::factory()->assignedTo($assignee)->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $assignee->id,
        ]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients->pluck('id')->all())->toContain($assignee->id);
    });

    it('returns role subscribers who have the notify permission', function () {
        $permission = Permission::firstOrCreate(['name' => 'notify.estimate_accepted', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $owner = User::factory()->internal()->create();
        $owner->assignRole($role);

        $otherUser = User::factory()->internal()->create();

        $estimate = Estimate::factory()->create();

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients->pluck('id')->all())->toContain($owner->id);
        expect($recipients->pluck('id')->all())->not->toContain($otherUser->id);
    });

    it('deduplicates when assigned user also has role permission', function () {
        $permission = Permission::firstOrCreate(['name' => 'notify.estimate_accepted', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $assignee = User::factory()->internal()->create();
        $assignee->assignRole($role);
        $customer = Customer::factory()->assignedTo($assignee)->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $assignee->id,
        ]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        // Assigned user appears only once despite also having the role permission
        expect($recipients->where('id', $assignee->id))->toHaveCount(1);
    });

    it('only returns internal users', function () {
        $permission = Permission::firstOrCreate(['name' => 'notify.estimate_accepted', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'test_role', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $agent = User::factory()->agent()->create();
        $agent->assignRole($role);

        $estimate = Estimate::factory()->create();

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients->pluck('id')->all())->not->toContain($agent->id);
    });

    it('resolves assigned user from deal for job notifications', function () {
        $assignee = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $assignee->id]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.job_created', $job);

        expect($recipients->pluck('id')->all())->toContain($assignee->id);
    });

    it('falls back to customer assignedTo when deal has no assignee', function () {
        $assignee = User::factory()->internal()->create();
        $customer = Customer::factory()->assignedTo($assignee)->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => null,
        ]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients->pluck('id')->all())->toContain($assignee->id);
    });

    it('returns empty collection when no assigned user and no role subscribers', function () {
        $estimate = Estimate::factory()->create();

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients)->toBeEmpty();
    });
});
