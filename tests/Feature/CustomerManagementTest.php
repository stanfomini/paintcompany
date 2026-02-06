<?php

use App\Models\Customer;
use App\Models\User;
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
        $customer = Customer::factory()->create();

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
