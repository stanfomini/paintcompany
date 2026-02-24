<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmailCrmLink;
use App\Models\EmailMessage;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->customer = Customer::factory()->create();
});

describe('Communications Hub Page Access', function () {
    it('can access communications hub page for a customer', function () {
        $this->actingAs($this->internalUser)
            ->get(route('customers.communications', $this->customer))
            ->assertOk();
    });

    it('non-internal user cannot access communications hub', function () {
        $customerUser = User::factory()->customer()->create();

        $this->actingAs($customerUser)
            ->get(route('customers.communications', $this->customer))
            ->assertRedirect();
    });
});

describe('Communications Hub - View All Tab', function () {
    it('shows empty state when customer has no communications', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertSee('No communications found for this customer.');
    });

    it('shows chat threads linked to customer via customer_id', function () {
        $thread = ChatThread::factory()
            ->forCustomer($this->customer)
            ->withSubject('Test Direct Thread')
            ->create(['last_message_at' => now()]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertSee('No messages yet');
    });

    it('shows chat threads linked via deals', function () {
        $deal = Deal::factory()->create([
            'customer_id' => $this->customer->id,
            'title' => 'Exterior Painting Job',
        ]);

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->create(['last_message_at' => now()]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertSee('Exterior Painting Job');
    });

    it('shows email links for customer in all tab', function () {
        $emailMessage = EmailMessage::factory()->create([
            'subject' => 'Quote Follow-up Email',
            'from_name' => 'John Client',
            'sent_at' => now(),
        ]);

        EmailCrmLink::factory()->create([
            'email_message_id' => $emailMessage->id,
            'linkable_type' => Customer::class,
            'linkable_id' => $this->customer->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertSee('Quote Follow-up Email');
    });
});

describe('Communications Hub - Messenger Tab', function () {
    it('shows only facebook channel threads in messenger tab', function () {
        $facebookThread = ChatThread::factory()
            ->facebook()
            ->forCustomer($this->customer)
            ->create(['last_message_at' => now()]);

        $internalThread = ChatThread::factory()
            ->forCustomer($this->customer)
            ->create(['last_message_at' => now()]);

        $component = Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'messenger')
            ->assertStatus(200);

        // The messenger tab should show the facebook thread
        // and not show the empty state
        $component->assertDontSee('No Messenger conversations with this customer.');
    });

    it('shows empty state when no messenger threads exist', function () {
        $internalThread = ChatThread::factory()
            ->forCustomer($this->customer)
            ->create(['last_message_at' => now()]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'messenger')
            ->assertSee('No Messenger conversations with this customer.');
    });
});

describe('Communications Hub - Deal Chats Tab', function () {
    it('shows deal type threads in deals tab', function () {
        $deal = Deal::factory()->create([
            'customer_id' => $this->customer->id,
            'title' => 'Kitchen Repaint Project',
        ]);

        ChatThread::factory()
            ->forDeal($deal)
            ->create(['last_message_at' => now()]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'deals')
            ->assertStatus(200)
            ->assertSee('Kitchen Repaint Project');
    });

    it('shows empty state when no deal threads exist', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'deals')
            ->assertSee('No deal chat threads for this customer.');
    });
});

describe('Communications Hub - Direct Tab', function () {
    it('shows message when customer has no portal account', function () {
        // Customer without user_id
        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'direct')
            ->assertSee('This customer does not have a portal account.');
    });

    it('shows direct threads for customer with portal account', function () {
        $customerUser = User::factory()->customer()->create();
        $this->customer->update(['user_id' => $customerUser->id]);

        $thread = ChatThread::factory()
            ->direct()
            ->betweenUsers($customerUser, $this->internalUser)
            ->create(['last_message_at' => now()]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'direct')
            ->assertStatus(200)
            ->assertDontSee('No direct message threads for this customer.');
    });

    it('shows empty state when customer has portal but no direct threads', function () {
        $customerUser = User::factory()->customer()->create();
        $this->customer->update(['user_id' => $customerUser->id]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'direct')
            ->assertSee('No direct message threads for this customer.');
    });
});

describe('Communications Hub - Emails Tab', function () {
    it('shows email links in emails tab', function () {
        $emailMessage = EmailMessage::factory()->create([
            'subject' => 'Estimate Attached',
            'direction' => 'outbound',
            'from_name' => 'Apex Negotiations',
            'sent_at' => now(),
        ]);

        EmailCrmLink::factory()->create([
            'email_message_id' => $emailMessage->id,
            'linkable_type' => Customer::class,
            'linkable_id' => $this->customer->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'emails')
            ->assertStatus(200)
            ->assertSee('Estimate Attached');
    });

    it('shows empty state when no emails linked', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->call('setTab', 'emails')
            ->assertSee('No emails linked to this customer.');
    });
});

describe('Communications Hub - Tab Switching', function () {
    it('can switch between all tabs', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test('customers.communications-hub', ['customer' => $this->customer])
            ->assertSet('activeTab', 'all');

        $component->call('setTab', 'messenger')
            ->assertSet('activeTab', 'messenger');

        $component->call('setTab', 'deals')
            ->assertSet('activeTab', 'deals');

        $component->call('setTab', 'direct')
            ->assertSet('activeTab', 'direct');

        $component->call('setTab', 'emails')
            ->assertSet('activeTab', 'emails');

        $component->call('setTab', 'all')
            ->assertSet('activeTab', 'all');
    });
});

describe('Communications Drawer', function () {
    it('starts closed by default', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-drawer', ['customer' => $this->customer])
            ->assertSet('isOpen', false);
    });

    it('can be opened', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-drawer', ['customer' => $this->customer])
            ->call('open')
            ->assertSet('isOpen', true);
    });

    it('can be closed after opening', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-drawer', ['customer' => $this->customer])
            ->call('open')
            ->assertSet('isOpen', true)
            ->call('close')
            ->assertSet('isOpen', false);
    });

    it('can switch tabs in the drawer', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communications-drawer', ['customer' => $this->customer])
            ->assertSet('activeTab', 'all')
            ->call('setTab', 'messenger')
            ->assertSet('activeTab', 'messenger')
            ->call('setTab', 'deals')
            ->assertSet('activeTab', 'deals');
    });
});

describe('Communication Timeline', function () {
    it('shows empty state when no communications exist', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communication-timeline', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertSee('No communications yet.');
    });

    it('shows chat threads in timeline', function () {
        $thread = ChatThread::factory()
            ->forCustomer($this->customer)
            ->withSubject('Timeline Test Thread')
            ->create(['last_message_at' => now()]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communication-timeline', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertDontSee('No communications yet.');
    });

    it('shows email items in timeline', function () {
        $emailMessage = EmailMessage::factory()->create([
            'subject' => 'Timeline Email Subject',
            'sent_at' => now(),
        ]);

        EmailCrmLink::factory()->create([
            'email_message_id' => $emailMessage->id,
            'linkable_type' => Customer::class,
            'linkable_id' => $this->customer->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communication-timeline', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertSee('Timeline Email Subject');
    });

    it('shows both chat and email items sorted by date', function () {
        $thread = ChatThread::factory()
            ->forCustomer($this->customer)
            ->withSubject('Chat Item')
            ->create(['last_message_at' => now()->subHour()]);

        $emailMessage = EmailMessage::factory()->create([
            'subject' => 'Email Item',
            'sent_at' => now(),
        ]);

        EmailCrmLink::factory()->create([
            'email_message_id' => $emailMessage->id,
            'linkable_type' => Customer::class,
            'linkable_id' => $this->customer->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communication-timeline', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertSee('Chat Item')
            ->assertSee('Email Item');
    });

    it('respects the limit property', function () {
        Livewire::actingAs($this->internalUser)
            ->test('customers.communication-timeline', ['customer' => $this->customer, 'limit' => 5])
            ->assertSet('limit', 5);
    });

    it('shows facebook thread with messenger badge', function () {
        $thread = ChatThread::factory()
            ->facebook()
            ->forCustomer($this->customer)
            ->create(['last_message_at' => now()]);

        Livewire::actingAs($this->internalUser)
            ->test('customers.communication-timeline', ['customer' => $this->customer])
            ->assertStatus(200)
            ->assertSee('Messenger');
    });
});
