<?php

use App\Models\ChatThread;
use App\Models\User;
use App\Services\ChatService;
use App\Services\CommunicationsContactService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

it('has source constants on ChatThread', function () {
    expect(ChatThread::SOURCE_WEBSITE_LEAD)->toBe('website_lead');
    expect(ChatThread::SOURCE_SETTER_SUPPORT)->toBe('setter_support');
});

it('allows source in fillable', function () {
    $thread = ChatThread::factory()->support()->create(['source' => 'website_lead']);
    expect($thread->source)->toBe('website_lead');
});

describe('Contact visibility permissions', function () {
    beforeEach(function () {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        (new RolesAndPermissionsSeeder)->run();
    });

    it('hides website lead contacts when user lacks chat.view_website_leads', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('estimator');

        $leadUser = User::factory()->customer()->create(['name' => 'Website Lead']);
        ChatThread::factory()->websiteLead()->betweenUsers($viewer, $leadUser)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->not->toContain('Website Lead');
    });

    it('shows website lead contacts when user has chat.view_website_leads', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $leadUser = User::factory()->customer()->create(['name' => 'Website Lead']);
        ChatThread::factory()->websiteLead()->betweenUsers($viewer, $leadUser)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->toContain('Website Lead');
    });

    it('hides setter support contacts when user lacks chat.view_setter_support', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('estimator');

        $setterUser = User::factory()->create(['name' => 'Setter Person', 'type' => null]);
        ChatThread::factory()->setterSupport()->betweenUsers($viewer, $setterUser)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->not->toContain('Setter Person');
    });

    it('shows setter support contacts when user has chat.view_setter_support', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $setterUser = User::factory()->create(['name' => 'Setter Person', 'type' => null]);
        ChatThread::factory()->setterSupport()->betweenUsers($viewer, $setterUser)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->toContain('Setter Person');
    });

    it('shows contact if they have at least one visible thread', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $contact = User::factory()->customer()->create(['name' => 'Multi Thread Customer']);

        ChatThread::factory()->websiteLead()->betweenUsers($viewer, $contact)->create(['last_message_at' => now()]);
        ChatThread::factory()->forDeal()->betweenUsers($viewer, $contact)->create(['last_message_at' => now()]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        expect($contacts->pluck('name')->toArray())->toContain('Multi Thread Customer');
    });
});

describe('Unread count scoping', function () {
    beforeEach(function () {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        (new RolesAndPermissionsSeeder)->run();
    });

    it('excludes website lead thread messages from unread count when permission missing', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');

        $leadUser = User::factory()->customer()->create();
        $thread = ChatThread::factory()->websiteLead()->betweenUsers($user, $leadUser)->create([
            'last_message_at' => now(),
        ]);

        $thread->messages()->create([
            'user_id' => $leadUser->id,
            'content' => 'Hello from website',
            'type' => 'text',
        ]);

        $count = app(ChatService::class)->getUnreadCountForUser($user);
        expect($count)->toBe(0);
    });

    it('includes website lead thread messages when permission granted', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $leadUser = User::factory()->customer()->create();
        $thread = ChatThread::factory()->websiteLead()->betweenUsers($user, $leadUser)->create([
            'last_message_at' => now(),
        ]);

        $thread->messages()->create([
            'user_id' => $leadUser->id,
            'content' => 'Hello from website',
            'type' => 'text',
        ]);

        $count = app(ChatService::class)->getUnreadCountForUser($user);
        expect($count)->toBe(1);
    });

    it('excludes setter support thread messages from unread count when permission missing', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');

        $setterUser = User::factory()->create(['type' => null]);
        $thread = ChatThread::factory()->setterSupport()->betweenUsers($user, $setterUser)->create([
            'last_message_at' => now(),
        ]);

        $thread->messages()->create([
            'user_id' => $setterUser->id,
            'content' => 'Setter needs help',
            'type' => 'text',
        ]);

        $count = app(ChatService::class)->getUnreadCountForUser($user);
        expect($count)->toBe(0);
    });
});
