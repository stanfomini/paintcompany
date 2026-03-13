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

describe('CommunicationsContactService - Email Integration', function () {
    it('counts email unreads for customer contacts without ambiguous column error', function () {
        $user = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = \App\Models\Customer::factory()->create(['user_id' => $customerUser->id]);

        // Create a shared chat thread so the customer shows up as a contact
        ChatThread::factory()->direct()->betweenUsers($user, $customerUser)->create([
            'last_message_at' => now(),
        ]);

        // Create email messages linked to this customer via CRM links
        $account = \App\Models\EmailAccount::factory()->create();
        $inboxLabel = \App\Models\EmailLabel::factory()->system()->create([
            'email_account_id' => $account->id,
            'name' => 'Inbox',
            'slug' => 'inbox',
        ]);

        $messages = \App\Models\EmailMessage::factory()->count(3)->create([
            'email_account_id' => $account->id,
            'is_read' => false,
        ]);

        foreach ($messages as $message) {
            $message->labels()->attach($inboxLabel->id);

            \App\Models\EmailCrmLink::factory()->create([
                'email_message_id' => $message->id,
                'linkable_type' => \App\Models\Customer::class,
                'linkable_id' => $customer->id,
            ]);
        }

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($user);

        expect($contacts)->toHaveCount(1);
        expect($contacts->first()['name'])->toBe($customerUser->name);
        expect($contacts->first()['unread_count'])->toBe(3);
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

    it('counts emails from shared accounts in unread badge', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        // Shared email account (no personal account)
        $sharedAccount = \App\Models\EmailAccount::factory()->shared()->create();
        $sharedLabel = \App\Models\EmailLabel::factory()->system()->create([
            'email_account_id' => $sharedAccount->id,
            'name' => 'Inbox',
            'slug' => 'inbox',
        ]);

        $user->sharedEmailAccounts()->attach($sharedAccount->id, [
            'can_send' => true,
            'receives_notifications' => true,
        ]);

        // Create 2 unread emails in the shared account
        $messages = \App\Models\EmailMessage::factory()->count(2)->create([
            'email_account_id' => $sharedAccount->id,
            'is_read' => false,
        ]);
        foreach ($messages as $message) {
            $message->labels()->attach($sharedLabel->id);
        }

        Livewire::actingAs($user)
            ->test('communications.unread-badge')
            ->assertSee('2');
    });

    it('counts emails from both personal and shared accounts', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('admin');

        // Personal email account
        $personalAccount = \App\Models\EmailAccount::factory()->create(['user_id' => $user->id]);
        $personalLabel = \App\Models\EmailLabel::factory()->system()->create([
            'email_account_id' => $personalAccount->id,
            'name' => 'Inbox',
            'slug' => 'inbox',
        ]);
        $personalMsg = \App\Models\EmailMessage::factory()->create([
            'email_account_id' => $personalAccount->id,
            'is_read' => false,
        ]);
        $personalMsg->labels()->attach($personalLabel->id);

        // Shared email account
        $sharedAccount = \App\Models\EmailAccount::factory()->shared()->create();
        $sharedLabel = \App\Models\EmailLabel::factory()->system()->create([
            'email_account_id' => $sharedAccount->id,
            'name' => 'Inbox',
            'slug' => 'inbox',
        ]);

        $user->sharedEmailAccounts()->attach($sharedAccount->id, [
            'can_send' => true,
            'receives_notifications' => true,
        ]);

        $sharedMsg = \App\Models\EmailMessage::factory()->create([
            'email_account_id' => $sharedAccount->id,
            'is_read' => false,
        ]);
        $sharedMsg->labels()->attach($sharedLabel->id);

        // Should count 1 personal + 1 shared = 2
        Livewire::actingAs($user)
            ->test('communications.unread-badge')
            ->assertSee('2');
    });
});

describe('SMS Contact Merging', function () {
    it('excludes SMS threads from getSmsContacts when customer has portal user', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customer = \App\Models\Customer::factory()->create(['user_id' => $customerUser->id]);

        $smsThread = ChatThread::factory()->smsInbound()->forCustomer($customer)->create([
            'subject' => 'SMS from +15551234567',
            'last_message_at' => now(),
        ]);
        $smsThread->addParticipant($viewer);

        $service = app(CommunicationsContactService::class);
        $smsContacts = $service->getSmsContacts($viewer);

        expect($smsContacts)->toHaveCount(0);
    });

    it('shows customer name on SMS contacts when customer has no portal user', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $customer = \App\Models\Customer::factory()->create([
            'user_id' => null,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $smsThread = ChatThread::factory()->smsInbound()->forCustomer($customer)->create([
            'subject' => 'SMS from +15559876543',
            'last_message_at' => now(),
        ]);
        $smsThread->addParticipant($viewer);

        $service = app(CommunicationsContactService::class);
        $smsContacts = $service->getSmsContacts($viewer);

        expect($smsContacts)->toHaveCount(1);
        expect($smsContacts->first()['name'])->toBe('Jane Doe');
        expect($smsContacts->first()['initials'])->toBe('JD');
    });

    it('shows phone number for unlinked SMS threads', function () {
        $viewer = User::factory()->internal()->create();
        $viewer->assignRole('admin');

        $smsThread = ChatThread::factory()->smsInbound()->create([
            'customer_id' => null,
            'subject' => 'SMS from +15550001111',
            'last_message_at' => now(),
        ]);
        $smsThread->addParticipant($viewer);

        $service = app(CommunicationsContactService::class);
        $smsContacts = $service->getSmsContacts($viewer);

        expect($smsContacts)->toHaveCount(1);
        expect($smsContacts->first()['name'])->toBe('+15550001111');
        expect($smsContacts->first()['initials'])->toBe('SM');
    });

    it('includes SMS thread unreads in the user contact unread count', function () {
        $viewer = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = \App\Models\Customer::factory()->create(['user_id' => $customerUser->id]);

        // Direct chat thread so customer shows in contacts
        ChatThread::factory()->direct()->betweenUsers($viewer, $customerUser)->create([
            'last_message_at' => now()->subHour(),
        ]);

        // SMS thread linked to same customer
        $smsThread = ChatThread::factory()->smsInbound()->forCustomer($customer)->create([
            'subject' => 'SMS from +15551234567',
            'last_message_at' => now(),
        ]);
        $smsThread->addParticipant($viewer);

        // Add unread message to SMS thread
        $smsThread->messages()->create([
            'user_id' => null,
            'content' => 'Hi via SMS',
            'type' => 'text',
        ]);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getContacts($viewer);

        $contact = $contacts->firstWhere('id', $customerUser->id);
        expect($contact)->not->toBeNull();
        expect($contact['unread_count'])->toBe(1);
    });

    it('avoids duplicate contacts when customer has both chat and SMS threads', function () {
        $viewer = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = \App\Models\Customer::factory()->create(['user_id' => $customerUser->id]);

        // Direct chat thread
        ChatThread::factory()->direct()->betweenUsers($viewer, $customerUser)->create([
            'last_message_at' => now()->subHour(),
        ]);

        // SMS thread linked to same customer
        $smsThread = ChatThread::factory()->smsInbound()->forCustomer($customer)->create([
            'subject' => 'SMS from +15551234567',
            'last_message_at' => now(),
        ]);
        $smsThread->addParticipant($viewer);

        $service = app(CommunicationsContactService::class);

        // User contacts: customer should appear once
        $contacts = $service->getContacts($viewer);
        $customerContacts = $contacts->where('id', $customerUser->id);
        expect($customerContacts)->toHaveCount(1);

        // SMS contacts: should NOT include this thread (customer has portal)
        $smsContacts = $service->getSmsContacts($viewer);
        expect($smsContacts)->toHaveCount(0);
    });
});

describe('Messenger Contact Customer Name', function () {
    it('shows customer name on messenger contacts when linked to customer without portal', function () {
        $viewer = User::factory()->internal()->create();

        $customer = \App\Models\Customer::factory()->create([
            'user_id' => null,
            'first_name' => 'Bob',
            'last_name' => 'Builder',
        ]);

        $thread = ChatThread::factory()->facebook()->forCustomer($customer)->create([
            'subject' => 'Facebook User',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($viewer);

        $service = app(CommunicationsContactService::class);
        $contacts = $service->getMessengerContacts($viewer);

        expect($contacts)->toHaveCount(1);
        expect($contacts->first()['name'])->toBe('Bob Builder');
        expect($contacts->first()['initials'])->toBe('BB');
    });
});

describe('Tab Unread Counts with SMS', function () {
    it('includes sms key in getTabUnreadCounts', function () {
        $viewer = User::factory()->internal()->create();
        $contact = User::factory()->customer()->create();
        $customer = \App\Models\Customer::factory()->create(['user_id' => $contact->id]);

        // Direct thread so they share a connection
        ChatThread::factory()->direct()->betweenUsers($viewer, $contact)->create([
            'last_message_at' => now(),
        ]);

        // SMS thread linked via customer
        $smsThread = ChatThread::factory()->smsInbound()->forCustomer($customer)->create([
            'subject' => 'SMS from +15551234567',
            'last_message_at' => now(),
        ]);
        $smsThread->addParticipant($viewer);

        // Add unread message to SMS thread
        $smsThread->messages()->create([
            'user_id' => null,
            'content' => 'SMS unread message',
            'type' => 'text',
        ]);

        $service = app(CommunicationsContactService::class);
        $counts = $service->getTabUnreadCounts($viewer, $contact);

        expect($counts)->toHaveKey('sms');
        expect($counts['sms'])->toBe(1);
        expect($counts['all'])->toBeGreaterThanOrEqual(1);
    });
});
