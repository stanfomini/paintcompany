<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Customer list access', function () {
    it('allows internal user to view customer list', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.index'));

        $response->assertOk();
        $response->assertSeeLivewire('customers.index');
    });

    it('denies agent access to customer list', function () {
        $user = User::factory()->agent()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.index'));

        $response->assertRedirect();
    });

    it('denies customer access to customer list', function () {
        $user = User::factory()->customer()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.index'));

        $response->assertRedirect();
    });

    it('redirects unauthenticated users to login', function () {
        $response = $this->get(route('customers.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('Customer detail access', function () {
    it('allows internal user to view customer detail', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.show', $customer));

        $response->assertOk();
        $response->assertSeeLivewire('customers.show');
    });

    it('denies agent access to customer detail', function () {
        $user = User::factory()->agent()->create();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.show', $customer));

        $response->assertRedirect();
    });

    it('denies customer access to customer detail', function () {
        $user = User::factory()->customer()->create();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.show', $customer));

        $response->assertRedirect();
    });
});

describe('Customer creation', function () {
    it('allows internal user to create customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john.doe@example.com')
            ->set('phone', '555-123-4567')
            ->set('sendInvite', false) // Disable to avoid invitation service call
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '555-123-4567',
            'created_by' => $user->id,
        ]);
    });

    it('validates required fields on create', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', '')
            ->set('lastName', '')
            ->set('email', '')
            ->call('save')
            ->assertHasErrors(['firstName', 'lastName', 'email']);
    });

    it('validates email format on create', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'invalid-email')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('shows duplicate warning when email exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $existingCustomer = Customer::factory()->create(['email' => 'existing@example.com']);

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('email', 'existing@example.com')
            ->assertSet('showDuplicateWarning', true)
            ->assertSet('existingCustomer.id', $existingCustomer->id);
    });

    it('allows creating customer with duplicate email when explicitly chosen', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        Customer::factory()->create(['email' => 'existing@example.com']);

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'existing@example.com')
            ->set('duplicateAction', 'confirmed')
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        expect(Customer::where('email', 'existing@example.com')->count())->toBe(2);
    });
});

describe('Customer editing', function () {
    it('allows internal user to edit customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
        ]);

        Livewire::actingAs($user)
            ->test('customers.edit-modal', ['customerId' => $customer->id])
            ->set('firstName', 'Janet')
            ->set('lastName', 'Johnson')
            ->set('email', 'janet.johnson@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'first_name' => 'Janet',
            'last_name' => 'Johnson',
            'email' => 'janet.johnson@example.com',
        ]);
    });

    it('allows same email when editing same customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'email' => 'same@example.com',
        ]);

        Livewire::actingAs($user)
            ->test('customers.edit-modal', ['customerId' => $customer->id])
            ->set('firstName', 'Updated')
            ->call('save')
            ->assertHasNoErrors();
    });
});

describe('Customer search functionality', function () {
    it('filters customers by search term', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Customer::factory()->create(['first_name' => 'Alice', 'last_name' => 'Anderson']);
        Customer::factory()->create(['first_name' => 'Bob', 'last_name' => 'Baker']);
        Customer::factory()->create(['first_name' => 'Charlie', 'last_name' => 'Cooper']);

        Livewire::actingAs($user)
            ->test('customers.index')
            ->set('search', 'Alice')
            ->assertSee('Alice Anderson')
            ->assertDontSee('Bob Baker')
            ->assertDontSee('Charlie Cooper');
    });

    it('searches by email', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Customer::factory()->create(['first_name' => 'Alice', 'email' => 'alice@test.com']);
        Customer::factory()->create(['first_name' => 'Bob', 'email' => 'bob@other.com']);

        Livewire::actingAs($user)
            ->test('customers.index')
            ->set('search', 'alice@test.com')
            ->assertSee('Alice')
            ->assertDontSee('Bob');
    });
});

describe('Customer show page quick actions', function () {
    it('can add property from customer show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'address' => null,
            'city' => null,
        ]);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('openAddProperty')
            ->assertSet('showAddProperty', true)
            ->set('propertyAddress', '123 Test St')
            ->set('propertyCity', 'Phoenix')
            ->set('propertyState', 'AZ')
            ->set('propertyZip', '85001')
            ->call('saveProperty')
            ->assertHasNoErrors()
            ->assertDispatched('property-created');

        $customer->refresh();
        expect($customer->properties()->count())->toBe(1);
        expect($customer->properties->first()->address)->toBe('123 Test St');
    });

    it('can add deal from customer show page', function () {
        \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['first_name' => 'Test', 'last_name' => 'Customer']);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('openAddDeal')
            ->assertSet('showAddDeal', true)
            ->set('dealTitle', 'Kitchen Repaint')
            ->call('saveDeal')
            ->assertHasNoErrors()
            ->assertDispatched('deal-created');

        $customer->refresh();
        expect($customer->deals()->count())->toBe(1);
        $deal = $customer->deals->first();
        expect($deal->title)->toBe('Kitchen Repaint');
        expect($deal->stage)->toBe('lead');

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\NewLeadCreated::class);
    });

    it('can add activity from customer show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('openAddActivity')
            ->assertSet('showAddActivity', true)
            ->set('activityTitle', 'Follow up call')
            ->set('activityType', 'call')
            ->call('saveActivity')
            ->assertHasNoErrors()
            ->assertDispatched('activity-created');

        $customer->refresh();
        expect($customer->activities()->count())->toBe(1);
        $activity = $customer->activities->first();
        expect($activity->title)->toBe('Follow up call');
        expect($activity->type)->toBe('call');
    });

    it('can toggle customer status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['status' => 'active']);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('toggleStatus');

        $customer->refresh();
        expect($customer->status)->toBe('inactive');

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('toggleStatus');

        $customer->refresh();
        expect($customer->status)->toBe('active');
    });

    it('can toggle activity completion from customer show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $activity = \App\Models\Activity::factory()->create([
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'user_id' => $user->id,
            'type' => 'task',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('toggleActivityComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue();
    });
});

describe('Customer status filter', function () {
    it('filters customers by active status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Customer::factory()->create(['first_name' => 'ActiveUser', 'status' => 'active']);
        Customer::factory()->create(['first_name' => 'InactiveUser', 'status' => 'inactive']);

        Livewire::actingAs($user)
            ->test('customers.index')
            ->set('statusFilter', 'active')
            ->assertSee('ActiveUser')
            ->assertDontSee('InactiveUser');
    });

    it('filters customers by inactive status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Customer::factory()->create(['first_name' => 'ActiveUser', 'status' => 'active']);
        Customer::factory()->create(['first_name' => 'InactiveUser', 'status' => 'inactive']);

        Livewire::actingAs($user)
            ->test('customers.index')
            ->set('statusFilter', 'inactive')
            ->assertDontSee('ActiveUser')
            ->assertSee('InactiveUser');
    });

    it('shows all customers when filter is all', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Customer::factory()->create(['first_name' => 'ActiveUser', 'status' => 'active']);
        Customer::factory()->create(['first_name' => 'InactiveUser', 'status' => 'inactive']);

        Livewire::actingAs($user)
            ->test('customers.index')
            ->set('statusFilter', 'all')
            ->assertSee('ActiveUser')
            ->assertSee('InactiveUser');
    });
});

describe('Auto-property from customer address', function () {
    it('auto-creates property when customer is created with address', function () {
        $customer = Customer::factory()->create([
            'address' => '123 Main St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);

        $property = Property::where('customer_id', $customer->id)->first();
        expect($property)->not->toBeNull();
        expect($property->address)->toBe('123 Main St');
        expect($property->city)->toBe('Denver');
        expect($property->state)->toBe('CO');
        expect($property->zip)->toBe('80202');
        expect($property->type)->toBe('residential');
    });

    it('auto-creates property when customer address is updated', function () {
        $customer = Customer::factory()->create([
            'address' => null,
            'city' => null,
        ]);

        expect(Property::where('customer_id', $customer->id)->count())->toBe(0);

        $customer->update([
            'address' => '456 Oak Ave',
            'city' => 'Boulder',
            'state' => 'CO',
            'zip' => '80302',
        ]);

        $property = Property::where('customer_id', $customer->id)->first();
        expect($property)->not->toBeNull();
        expect($property->address)->toBe('456 Oak Ave');
        expect($property->city)->toBe('Boulder');
    });

    it('does not create duplicate property for same address', function () {
        $customer = Customer::factory()->create([
            'address' => '123 Main St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);

        // Save again to trigger the hook
        $customer->update(['first_name' => 'Updated']);

        expect(Property::where('customer_id', $customer->id)->count())->toBe(1);
    });

    it('creates new property when address changes', function () {
        $customer = Customer::factory()->create([
            'address' => '123 Main St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);

        expect(Property::where('customer_id', $customer->id)->count())->toBe(1);

        $customer->update([
            'address' => '789 Pine St',
            'city' => 'Boulder',
        ]);

        expect(Property::where('customer_id', $customer->id)->count())->toBe(2);
    });

    it('does not auto-create property when address is missing', function () {
        $customer = Customer::factory()->create([
            'address' => null,
            'city' => null,
        ]);

        expect(Property::where('customer_id', $customer->id)->count())->toBe(0);
    });

    it('does not auto-create property when city is missing', function () {
        $customer = Customer::factory()->create([
            'address' => '123 Main St',
            'city' => null,
        ]);

        expect(Property::where('customer_id', $customer->id)->count())->toBe(0);
    });
});

describe('Customer show page chat threads', function () {
    it('shows customer chat threads on show page', function () {
        Event::fake([\App\Events\MessageSent::class, \App\Events\NewThreadNotification::class, \App\Events\NewLeadCreated::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $user);
        $chatService->sendMessage($thread, $user, 'Hello regarding your estimate');

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Messages')
            ->assertSee('Hello regarding your estimate');
    });

    it('shows unread badge count for threads with unread messages', function () {
        Event::fake([\App\Events\MessageSent::class, \App\Events\NewThreadNotification::class, \App\Events\NewLeadCreated::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $user);

        // Add other user as participant and have them send messages
        $thread->addParticipant($otherUser);
        $chatService->sendMessage($thread, $otherUser, 'First unread message');
        $chatService->sendMessage($thread, $otherUser, 'Second unread message');

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Messages')
            ->assertSee('unread');
    });

    it('includes chat messages in unified activity feed', function () {
        Event::fake([\App\Events\MessageSent::class, \App\Events\NewThreadNotification::class, \App\Events\NewLeadCreated::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $user);
        $chatService->sendMessage($thread, $user, 'Discussing the kitchen repaint project');

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Discussing the kitchen repaint');
    });

    it('shows deal thread for customer deal', function () {
        Event::fake([\App\Events\MessageSent::class, \App\Events\NewThreadNotification::class, \App\Events\NewLeadCreated::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Kitchen Repaint Deal',
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDealThread($deal, $user);
        $chatService->sendMessage($thread, $user, 'Starting the quote process');

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Messages')
            ->assertSee('Kitchen Repaint Deal');
    });
});

describe('Customer assignment', function () {
    it('can assign a customer to an internal user on creation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john.assignment@example.com')
            ->set('assignedTo', $assignee->id)
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'email' => 'john.assignment@example.com',
            'assigned_to' => $assignee->id,
        ]);
    });

    it('defaults assigned_to to current user when not specified on creation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john.default@example.com')
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'email' => 'john.default@example.com',
            'assigned_to' => $user->id,
        ]);
    });

    it('can update customer assignment from show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('updateAssignment', $assignee->id);

        $customer->refresh();
        expect($customer->assigned_to)->toBe($assignee->id);
    });

    it('can unassign a customer from show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->assignedTo($assignee)->create();

        expect($customer->assigned_to)->toBe($assignee->id);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('updateAssignment', null);

        $customer->refresh();
        expect($customer->assigned_to)->toBeNull();
    });

    it('displays assigned user name on customer show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee = User::factory()->internal()->create(['name' => 'Mike Johnson']);
        $customer = Customer::factory()->createdBy($user)->assignedTo($assignee)->create();

        // Need a deal so the banner shows
        Deal::factory()->forCustomer($customer)->lead()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->assertSee('Mike Johnson');
    });

    it('flows customer assignment to new deals', function () {
        Event::fake([\App\Events\NewLeadCreated::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->assignedTo($assignee)->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'Flow')
            ->set('lastName', 'Test')
            ->set('email', 'flow.test@example.com')
            ->set('assignedTo', $assignee->id)
            ->set('sendInvite', false)
            ->set('createDeal', true)
            ->call('save')
            ->assertHasNoErrors();

        $createdCustomer = Customer::where('email', 'flow.test@example.com')->first();
        $deal = $createdCustomer->deals()->first();

        expect($deal)->not->toBeNull();
        expect($deal->assigned_to)->toBe($assignee->id);
    });

    it('prevents non-authorized users from updating assignment', function () {
        // Create user without people.edit permission
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('customers.show', ['customer' => $customer])
            ->call('updateAssignment', $user->id)
            ->assertForbidden();
    });
});
