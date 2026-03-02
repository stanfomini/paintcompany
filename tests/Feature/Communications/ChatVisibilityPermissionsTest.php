<?php

use App\Models\ChatThread;
use App\Models\GuestChatSession;
use App\Models\User;
use App\Services\ChatService;
use App\Services\CommunicationsContactService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
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

describe('Setter support chat creates user', function () {
    beforeEach(function () {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        (new RolesAndPermissionsSeeder)->run();
    });

    it('creates a user with null type when setter starts chat', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        Livewire::test('public.setter-support-chat')
            ->set('email', 'setter@example.com')
            ->set('name', 'Jane Setter')
            ->call('startChat');

        $user = User::where('email', 'setter@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->type)->toBeNull();
        expect($user->name)->toBe('Jane Setter');

        $session = GuestChatSession::where('email', 'setter@example.com')->first();
        expect($session->user_id)->toBe($user->id);

        $thread = $session->thread;
        expect($thread->source)->toBe('setter_support');
        expect($thread->hasParticipant($user))->toBeTrue();
    });

    it('reuses existing user when setter starts another chat', function () {
        $existing = User::factory()->create([
            'email' => 'returning@setter.com',
            'name' => 'Returning Setter',
            'type' => null,
        ]);

        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        Livewire::test('public.setter-support-chat')
            ->set('email', 'returning@setter.com')
            ->set('name', 'Returning Setter')
            ->call('startChat');

        expect(User::where('email', 'returning@setter.com')->count())->toBe(1);

        $session = GuestChatSession::where('email', 'returning@setter.com')->first();
        expect($session->user_id)->toBe($existing->id);
    });

    it('only adds permitted internal users as participants', function () {
        $admin = User::factory()->internal()->create();
        $admin->assignRole('admin');

        $estimator = User::factory()->internal()->create();
        $estimator->assignRole('estimator');

        Livewire::test('public.setter-support-chat')
            ->set('email', 'setter2@example.com')
            ->set('name', 'Test Setter')
            ->call('startChat');

        $session = GuestChatSession::where('email', 'setter2@example.com')->first();
        $thread = $session->thread;

        expect($thread->hasParticipant($admin))->toBeTrue();
        expect($thread->hasParticipant($estimator))->toBeFalse();
    });
});
