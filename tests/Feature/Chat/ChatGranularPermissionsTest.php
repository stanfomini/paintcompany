<?php

use App\Models\User;
use App\Services\ChatService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    (new RolesAndPermissionsSeeder)->run();
});

describe('Granular chat permissions for internal users', function () {
    it('allows internal user with chat.with_customers to chat with customers', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin'); // admin has chat.with_customers

        $customer = User::factory()->customer()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal, $customer))->toBeTrue();
    });

    it('prevents internal user without chat.with_customers from chatting with customers', function () {
        $internal = User::factory()->internal()->create();

        // Create a custom role without chat.with_customers
        $restrictedRole = Role::firstOrCreate(['name' => 'restricted_tester', 'guard_name' => 'web']);
        $restrictedRole->syncPermissions(['chat.access', 'chat.with_internal']);
        $internal->assignRole('restricted_tester');

        $customer = User::factory()->customer()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal, $customer))->toBeFalse();
    });

    it('allows internal user with chat.with_agents to chat with agents', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin'); // admin has chat.with_agents

        $agent = User::factory()->agent()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal, $agent))->toBeTrue();
    });

    it('prevents internal user without chat.with_agents from chatting with agents', function () {
        $internal = User::factory()->internal()->create();

        $restrictedRole = Role::firstOrCreate(['name' => 'restricted_tester', 'guard_name' => 'web']);
        $restrictedRole->syncPermissions(['chat.access', 'chat.with_internal']);
        $internal->assignRole('restricted_tester');

        $agent = User::factory()->agent()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal, $agent))->toBeFalse();
    });

    it('allows internal user with chat.with_crew to chat with crew', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin'); // admin has chat.with_crew

        $crew = User::factory()->crew()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal, $crew))->toBeTrue();
    });

    it('prevents internal user without chat.with_crew from chatting with crew', function () {
        $internal = User::factory()->internal()->create();

        // Estimator does not have chat.with_crew
        $internal->assignRole('estimator');

        $crew = User::factory()->crew()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal, $crew))->toBeFalse();
    });

    it('allows internal user with chat.with_internal to chat with other internal users', function () {
        $internal1 = User::factory()->internal()->create();
        $internal1->assignRole('admin');

        $internal2 = User::factory()->internal()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal1, $internal2))->toBeTrue();
    });

    it('prevents internal user without chat.with_internal from chatting with internal users', function () {
        $internal1 = User::factory()->internal()->create();

        $restrictedRole = Role::firstOrCreate(['name' => 'no_internal_chat', 'guard_name' => 'web']);
        $restrictedRole->syncPermissions(['chat.access', 'chat.with_customers']);
        $internal1->assignRole('no_internal_chat');

        $internal2 = User::factory()->internal()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal1, $internal2))->toBeFalse();
    });
});

describe('Backward compatibility for external users', function () {
    it('allows customer to chat with internal users without Spatie roles', function () {
        $customer = User::factory()->customer()->create();
        $internal = User::factory()->internal()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($customer, $internal))->toBeTrue();
    });

    it('allows agent to chat with internal users without Spatie roles', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($agent, $internal))->toBeTrue();
    });

    it('allows crew to chat with internal users without Spatie roles', function () {
        $crew = User::factory()->crew()->create();
        $internal = User::factory()->internal()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($crew, $internal))->toBeTrue();
    });

    it('prevents customer from chatting with another customer', function () {
        $customer1 = User::factory()->customer()->create();
        $customer2 = User::factory()->customer()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($customer1, $customer2))->toBeFalse();
    });

    it('prevents crew from chatting with customers', function () {
        $crew = User::factory()->crew()->create();
        $customer = User::factory()->customer()->create();
        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($crew, $customer))->toBeFalse();
    });
});

describe('Internal users without roles use base matrix', function () {
    it('allows internal user without any role to chat with anyone in base matrix', function () {
        $internal = User::factory()->internal()->create();
        // No role assigned - should fall through to base matrix

        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();
        $crew = User::factory()->crew()->create();
        $internal2 = User::factory()->internal()->create();

        $chatService = app(ChatService::class);

        expect($chatService->canChatWith($internal, $customer))->toBeTrue();
        expect($chatService->canChatWith($internal, $agent))->toBeTrue();
        expect($chatService->canChatWith($internal, $crew))->toBeTrue();
        expect($chatService->canChatWith($internal, $internal2))->toBeTrue();
    });
});

describe('Seeder creates new permissions correctly', function () {
    it('creates all granular chat permissions', function () {
        $permissions = ['chat.with_customers', 'chat.with_agents', 'chat.with_crew', 'chat.with_internal'];

        foreach ($permissions as $perm) {
            expect(Permission::where('name', $perm)->where('guard_name', 'web')->exists())->toBeTrue();
        }
    });

    it('assigns chat.with_customers to admin but not crew_lead', function () {
        $admin = Role::where('name', 'admin')->first();
        $crewLead = Role::where('name', 'crew_lead')->first();

        expect($admin->hasPermissionTo('chat.with_customers'))->toBeTrue();
        expect($crewLead->hasPermissionTo('chat.with_customers'))->toBeFalse();
    });

    it('assigns chat.with_crew to admin and crew_lead but not estimator', function () {
        $admin = Role::where('name', 'admin')->first();
        $crewLead = Role::where('name', 'crew_lead')->first();
        $estimator = Role::where('name', 'estimator')->first();

        expect($admin->hasPermissionTo('chat.with_crew'))->toBeTrue();
        expect($crewLead->hasPermissionTo('chat.with_crew'))->toBeTrue();
        expect($estimator->hasPermissionTo('chat.with_crew'))->toBeFalse();
    });

    it('assigns chat.with_internal to all roles', function () {
        $roles = ['admin', 'manager', 'estimator', 'crew_lead'];

        foreach ($roles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            expect($role->hasPermissionTo('chat.with_internal'))->toBeTrue();
        }
    });
});
