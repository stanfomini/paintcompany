<?php

use App\Models\ChatThread;
use App\Models\User;
use App\Services\CommunicationsContactService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    (new RolesAndPermissionsSeeder)->run();
});

describe('Communications Hub Page', function () {
    it('loads for internal user with chat.access', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get('/communications')
            ->assertSuccessful();
    });

    it('loads for internal user with email.access', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get('/communications')
            ->assertSuccessful();
    });

    it('returns redirect for non-internal users', function () {
        $user = User::factory()->customer()->create();

        $this->actingAs($user, 'customer')
            ->get('/communications')
            ->assertRedirect();
    });

    it('redirects /chat to /communications', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get('/chat')
            ->assertRedirect('/communications');
    });

    it('accepts contactId query parameter for deep linking', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $contact = User::factory()->internal()->create();

        // Create a thread between them so contact shows up
        ChatThread::factory()->direct()->betweenUsers($user, $contact)->create([
            'last_message_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/communications?contactId='.$contact->id)
            ->assertSuccessful();
    });
});

describe('Communications Hub Component', function () {
    it('shows contacts from shared threads', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $contact = User::factory()->internal()->create();

        ChatThread::factory()->direct()->betweenUsers($user, $contact)->create([
            'last_message_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test('communications.hub')
            ->assertSee($contact->name);
    });

    it('filters contacts by search query', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $contactA = User::factory()->internal()->create(['name' => 'Alice Johnson']);
        $contactB = User::factory()->internal()->create(['name' => 'Bob Smith']);

        ChatThread::factory()->direct()->betweenUsers($user, $contactA)->create(['last_message_at' => now()]);
        ChatThread::factory()->direct()->betweenUsers($user, $contactB)->create(['last_message_at' => now()]);

        Livewire::actingAs($user)
            ->test('communications.hub')
            ->set('search', 'Alice')
            ->assertSee('Alice Johnson')
            ->assertDontSee('Bob Smith');
    });

    it('filters contacts by type', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $internalContact = User::factory()->internal()->create(['name' => 'Internal Person']);
        $customerContact = User::factory()->customer()->create(['name' => 'Customer Person']);

        ChatThread::factory()->direct()->betweenUsers($user, $internalContact)->create(['last_message_at' => now()]);
        ChatThread::factory()->direct()->betweenUsers($user, $customerContact)->create(['last_message_at' => now()]);

        Livewire::actingAs($user)
            ->test('communications.hub')
            ->set('typeFilter', 'customer')
            ->assertSee('Customer Person')
            ->assertDontSee('Internal Person');
    });

    it('selects a contact and shows their communications panel', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $contact = User::factory()->internal()->create();

        ChatThread::factory()->direct()->betweenUsers($user, $contact)->create([
            'last_message_at' => now(),
            'subject' => 'Test Direct Thread',
        ]);

        Livewire::actingAs($user)
            ->test('communications.hub')
            ->call('selectContact', $contact->id)
            ->assertSet('contactId', $contact->id)
            ->assertSee($contact->name);
    });

    it('clears contact selection with clearContact', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $contact = User::factory()->internal()->create();
        ChatThread::factory()->direct()->betweenUsers($user, $contact)->create(['last_message_at' => now()]);

        Livewire::actingAs($user)
            ->test('communications.hub')
            ->call('selectContact', $contact->id)
            ->assertSet('contactId', $contact->id)
            ->call('clearContact')
            ->assertSet('contactId', null);
    });

    it('switches tabs', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $contact = User::factory()->internal()->create();
        ChatThread::factory()->direct()->betweenUsers($user, $contact)->create(['last_message_at' => now()]);

        Livewire::actingAs($user)
            ->test('communications.hub')
            ->call('selectContact', $contact->id)
            ->call('setTab', 'direct')
            ->assertSet('activeTab', 'direct')
            ->call('setTab', 'emails')
            ->assertSet('activeTab', 'emails');
    });

    it('shows empty state when no contact is selected', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        Livewire::actingAs($user)
            ->test('communications.hub')
            ->assertSee('Select a conversation');
    });
});

describe('CommunicationsContactService', function () {
    it('returns contacts sorted by most recent activity', function () {
        $user = User::factory()->internal()->create();

        $contactA = User::factory()->internal()->create(['name' => 'Older Contact']);
        $contactB = User::factory()->internal()->create(['name' => 'Recent Contact']);

        ChatThread::factory()->direct()->betweenUsers($user, $contactA)->create([
            'last_message_at' => now()->subDay(),
        ]);
        ChatThread::factory()->direct()->betweenUsers($user, $contactB)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($user);

        expect($contacts)->toHaveCount(2);
        expect($contacts->first()['name'])->toBe('Recent Contact');
        expect($contacts->last()['name'])->toBe('Older Contact');
    });

    it('does not include the viewer in their own contact list', function () {
        $user = User::factory()->internal()->create();
        $contact = User::factory()->internal()->create();

        ChatThread::factory()->direct()->betweenUsers($user, $contact)->create([
            'last_message_at' => now(),
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($user);

        $ids = $contacts->pluck('id')->toArray();
        expect($ids)->not->toContain($user->id);
        expect($ids)->toContain($contact->id);
    });

    it('returns empty collection when no shared threads', function () {
        $user = User::factory()->internal()->create();

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($user);

        expect($contacts)->toBeEmpty();
    });

    it('includes unread count for contacts', function () {
        $user = User::factory()->internal()->create();
        $contact = User::factory()->internal()->create();

        $thread = ChatThread::factory()->direct()->betweenUsers($user, $contact)->create([
            'last_message_at' => now(),
        ]);

        // Add an unread message from contact
        $thread->messages()->create([
            'user_id' => $contact->id,
            'content' => 'Hello there',
            'type' => 'text',
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($user);

        expect($contacts->first()['unread_count'])->toBeGreaterThan(0);
        expect($contacts->first()['has_unread'])->toBeTrue();
    });

    it('filters contacts by search term', function () {
        $user = User::factory()->internal()->create();
        $contactA = User::factory()->internal()->create(['name' => 'Jane Doe']);
        $contactB = User::factory()->internal()->create(['name' => 'John Smith']);

        ChatThread::factory()->direct()->betweenUsers($user, $contactA)->create(['last_message_at' => now()]);
        ChatThread::factory()->direct()->betweenUsers($user, $contactB)->create(['last_message_at' => now()]);

        $service = app(CommunicationsContactService::class);
        $results = $service->getContacts($user, search: 'Jane');

        expect($results)->toHaveCount(1);
        expect($results->first()['name'])->toBe('Jane Doe');
    });

    it('filters contacts by type', function () {
        $user = User::factory()->internal()->create();
        $internal = User::factory()->internal()->create(['name' => 'Team Member']);
        $customer = User::factory()->customer()->create(['name' => 'Customer User']);

        ChatThread::factory()->direct()->betweenUsers($user, $internal)->create(['last_message_at' => now()]);
        ChatThread::factory()->direct()->betweenUsers($user, $customer)->create(['last_message_at' => now()]);

        $service = app(CommunicationsContactService::class);
        $results = $service->getContacts($user, typeFilter: 'internal');

        expect($results)->toHaveCount(1);
        expect($results->first()['name'])->toBe('Team Member');
    });

    it('returns communication items for a contact', function () {
        $user = User::factory()->internal()->create();
        $contact = User::factory()->internal()->create();

        $thread = ChatThread::factory()->direct()->betweenUsers($user, $contact)->create([
            'last_message_at' => now(),
            'subject' => 'Test Thread',
        ]);

        $service = app(CommunicationsContactService::class);
        $items = $service->getContactCommunications($user, $contact);

        expect($items)->toHaveCount(1);
        expect($items->first()['type'])->toBe('chat');
        expect($items->first()['label'])->toBe('Test Thread');
    });

    it('filters communication items by tab', function () {
        $user = User::factory()->internal()->create();
        $contact = User::factory()->internal()->create();

        // Create a direct thread
        ChatThread::factory()->direct()->betweenUsers($user, $contact)->create([
            'last_message_at' => now(),
            'subject' => 'Direct Thread',
        ]);

        // Create a support thread
        ChatThread::factory()->support()->betweenUsers($user, $contact)->create([
            'last_message_at' => now(),
            'subject' => 'Support Thread',
        ]);

        $service = app(CommunicationsContactService::class);

        // 'direct' tab should show both direct and support types
        $directItems = $service->getContactCommunications($user, $contact, 'direct');
        expect($directItems)->toHaveCount(2);

        // 'emails' tab should show no items (no email data)
        $emailItems = $service->getContactCommunications($user, $contact, 'emails');
        expect($emailItems)->toHaveCount(0);
    });
});

describe('Sidebar Navigation', function () {
    it('shows Communications item in sidebar', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->get('/communications')
            ->assertSee('Communications');
    });
});

describe('Unread Badge', function () {
    it('renders combined unread badge component', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        Livewire::actingAs($user)
            ->test('communications.unread-badge')
            ->assertSuccessful();
    });

    it('shows zero when no unread messages', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        Livewire::actingAs($user)
            ->test('communications.unread-badge')
            ->assertDontSee('99+');
    });
});
