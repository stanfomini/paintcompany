<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\CommunicationsContactService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    (new RolesAndPermissionsSeeder)->run();

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-verify-token',
        'app_secret' => 'test-app-secret',
        'page_access_token' => 'test-page-token',
        'page_id' => '123456',
    ]);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('CommunicationsContactService::getMessengerContacts', function () {
    it('returns facebook threads as messenger contacts', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread = ChatThread::factory()->facebook('12345678')->create([
            'subject' => 'Jane Smith',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getMessengerContacts($viewer);

        expect($contacts)->toHaveCount(1);
        expect($contacts->first()['id'])->toBe('messenger-'.$thread->id);
        expect($contacts->first()['name'])->toBe('Jane Smith');
        expect($contacts->first()['type'])->toBe('messenger');
    });

    it('filters messenger contacts by search term', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread1 = ChatThread::factory()->facebook('11111111')->create([
            'subject' => 'Alice Jones',
            'last_message_at' => now(),
        ]);
        $thread1->addParticipant($viewer);

        $thread2 = ChatThread::factory()->facebook('22222222')->create([
            'subject' => 'Bob Smith',
            'last_message_at' => now(),
        ]);
        $thread2->addParticipant($viewer);

        $service = app(CommunicationsContactService::class);

        $contacts = $service->getMessengerContacts($viewer, 'alice');
        expect($contacts)->toHaveCount(1);
        expect($contacts->first()['name'])->toBe('Alice Jones');
    });

    it('returns empty collection when viewer has no facebook threads', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getMessengerContacts($viewer);

        expect($contacts)->toHaveCount(0);
    });

    it('includes unread count for messenger contacts', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread = ChatThread::factory()->facebook('33333333')->create([
            'subject' => 'Unread Lead',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        // Add a message (not from viewer, so it's unread)
        ChatMessage::create([
            'thread_id' => $thread->id,
            'user_id' => null,
            'content' => 'Hi there!',
            'type' => 'text',
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getMessengerContacts($viewer);

        expect($contacts->first()['unread_count'])->toBeGreaterThan(0);
        expect($contacts->first()['has_unread'])->toBeTrue();
    });

    it('includes last message preview', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread = ChatThread::factory()->facebook('44444444')->create([
            'subject' => 'Preview Lead',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        ChatMessage::create([
            'thread_id' => $thread->id,
            'user_id' => null,
            'content' => 'I need a painting estimate',
            'type' => 'text',
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getMessengerContacts($viewer);

        expect($contacts->first()['last_message_preview'])->toContain('I need a painting estimate');
    });
});

describe('Communications Hub messenger contacts', function () {
    it('shows messenger contacts in the sidebar contact list', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread = ChatThread::factory()->facebook('55555555')->create([
            'subject' => 'Facebook Lead',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        Livewire::actingAs($viewer)
            ->test('communications.hub')
            ->assertSee('Facebook Lead');
    });

    it('shows messenger contacts when type filter is set to messenger', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread = ChatThread::factory()->facebook('66666666')->create([
            'subject' => 'Messenger Only',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        Livewire::actingAs($viewer)
            ->test('communications.hub')
            ->set('typeFilter', 'messenger')
            ->assertSee('Messenger Only');
    });

    it('hides messenger contacts when filtering by customer type', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread = ChatThread::factory()->facebook('77777777')->create([
            'subject' => 'Should Be Hidden',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        Livewire::actingAs($viewer)
            ->test('communications.hub')
            ->set('typeFilter', 'customer')
            ->assertDontSee('Should Be Hidden');
    });

    it('selects a messenger contact and shows the chat panel', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread = ChatThread::factory()->facebook('88888888')->create([
            'subject' => 'Selected Lead',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        ChatMessage::create([
            'thread_id' => $thread->id,
            'user_id' => null,
            'content' => 'Hello from Messenger',
            'type' => 'text',
        ]);

        Livewire::actingAs($viewer)
            ->test('communications.hub')
            ->call('selectContact', 'messenger-'.$thread->id)
            ->assertSet('messengerThreadId', $thread->id)
            ->assertSet('expandedThreadId', $thread->id)
            ->assertSee('Facebook Messenger conversation')
            ->assertSee('Hello from Messenger');
    });

    it('clears messenger state when clearing contact', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $thread = ChatThread::factory()->facebook('99999999')->create([
            'subject' => 'Clear Test',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        Livewire::actingAs($viewer)
            ->test('communications.hub')
            ->call('selectContact', 'messenger-'.$thread->id)
            ->assertSet('messengerThreadId', $thread->id)
            ->call('clearContact')
            ->assertSet('messengerThreadId', null)
            ->assertSet('expandedThreadId', null)
            ->assertSet('contactId', null);
    });
});
