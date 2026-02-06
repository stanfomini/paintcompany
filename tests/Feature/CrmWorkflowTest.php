<?php

use App\Events\NewLeadCreated;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Customer creation auto-creates property', function () {
    it('creates property when address is provided', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john@example.com')
            ->set('address', '123 Main St')
            ->set('city', 'Phoenix')
            ->set('state', 'AZ')
            ->set('zip', '85001')
            ->set('createProperty', true)
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('email', 'john@example.com')->first();

        expect($customer)->not->toBeNull();
        expect($customer->properties)->toHaveCount(1);

        $property = $customer->properties->first();
        expect($property->address)->toBe('123 Main St');
        expect($property->city)->toBe('Phoenix');
        expect($property->state)->toBe('AZ');
        expect($property->zip)->toBe('85001');
        expect($property->type)->toBe('residential');
    });

    it('does not create property when address is missing', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john2@example.com')
            ->set('createProperty', true)
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('email', 'john2@example.com')->first();
        expect($customer->properties)->toHaveCount(0);
    });

    it('does not create property when city is missing', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john3@example.com')
            ->set('address', '123 Main St')
            ->set('createProperty', true)
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('email', 'john3@example.com')->first();
        expect($customer->properties)->toHaveCount(0);
    });

    it('does not create property when option is disabled', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john4@example.com')
            ->set('address', '123 Main St')
            ->set('city', 'Phoenix')
            ->set('createProperty', false)
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('email', 'john4@example.com')->first();
        expect($customer->properties)->toHaveCount(0);
    });
});

describe('Customer creation auto-creates lead deal', function () {
    it('creates lead deal when option is enabled', function () {
        Event::fake([NewLeadCreated::class]);
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'Jane')
            ->set('lastName', 'Smith')
            ->set('email', 'jane@example.com')
            ->set('createDeal', true)
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('email', 'jane@example.com')->first();

        expect($customer->deals)->toHaveCount(1);

        $deal = $customer->deals->first();
        expect($deal->stage)->toBe('lead');
        expect($deal->title)->toBe('Jane Smith - New Lead');
        expect($deal->created_by)->toBe($user->id);
        expect($deal->assigned_to)->toBe($user->id);

        Event::assertDispatched(NewLeadCreated::class, function ($event) use ($deal) {
            return $event->deal->id === $deal->id;
        });
    });

    it('creates deal with property when both address and deal enabled', function () {
        Event::fake([NewLeadCreated::class]);
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'Bob')
            ->set('lastName', 'Builder')
            ->set('email', 'bob@example.com')
            ->set('address', '456 Oak Ave')
            ->set('city', 'Tempe')
            ->set('state', 'AZ')
            ->set('createProperty', true)
            ->set('createDeal', true)
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('email', 'bob@example.com')->first();

        $property = $customer->properties->first();
        $deal = $customer->deals->first();

        expect($deal->property_id)->toBe($property->id);
    });

    it('does not create deal when option is disabled', function () {
        Event::fake([NewLeadCreated::class]);
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('firstName', 'Charlie')
            ->set('lastName', 'NoLead')
            ->set('email', 'charlie@example.com')
            ->set('createDeal', false)
            ->set('sendInvite', false)
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('email', 'charlie@example.com')->first();
        expect($customer->deals)->toHaveCount(0);

        Event::assertNotDispatched(NewLeadCreated::class);
    });
});

describe('Duplicate customer detection', function () {
    it('detects duplicate when entering existing email', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $existing = Customer::factory()->create([
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'email' => 'duplicate@example.com',
            'status' => 'active',
        ]);

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('email', 'duplicate@example.com')
            ->assertSet('showDuplicateWarning', true)
            ->assertSet('existingCustomer.id', $existing->id);
    });

    it('detects soft-deleted customers', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deleted = Customer::factory()->create([
            'email' => 'deleted@example.com',
        ]);
        $deleted->delete();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('email', 'deleted@example.com')
            ->assertSet('showDuplicateWarning', true)
            ->assertSet('existingCustomer.id', $deleted->id);
    });

    it('can reactivate inactive customer and create new lead', function () {
        Event::fake([NewLeadCreated::class]);
        $user = User::factory()->internal()->withFullAccess()->create();
        $inactive = Customer::factory()->create([
            'first_name' => 'Inactive',
            'last_name' => 'User',
            'email' => 'inactive@example.com',
            'status' => 'inactive',
        ]);

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('email', 'inactive@example.com')
            ->assertSet('showDuplicateWarning', true)
            ->call('linkToExisting')
            ->assertDispatched('customer-created');

        $inactive->refresh();
        expect($inactive->status)->toBe('active');
        expect($inactive->deals)->toHaveCount(1);
        expect($inactive->deals->first()->stage)->toBe('lead');

        Event::assertDispatched(NewLeadCreated::class);
    });

    it('can restore deleted customer and create new lead', function () {
        Event::fake([NewLeadCreated::class]);
        $user = User::factory()->internal()->withFullAccess()->create();
        $deleted = Customer::factory()->create([
            'first_name' => 'Deleted',
            'last_name' => 'User',
            'email' => 'restore@example.com',
        ]);
        $deleted->delete();

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('email', 'restore@example.com')
            ->assertSet('showDuplicateWarning', true)
            ->call('linkToExisting')
            ->assertDispatched('customer-created');

        $restored = Customer::where('email', 'restore@example.com')->first();
        expect($restored)->not->toBeNull();
        expect($restored->trashed())->toBeFalse();
        expect($restored->deals)->toHaveCount(1);

        Event::assertDispatched(NewLeadCreated::class);
    });

    it('clears duplicate warning when email is cleared', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        Customer::factory()->create(['email' => 'test@example.com']);

        Livewire::actingAs($user)
            ->test('customers.create-modal')
            ->set('email', 'test@example.com')
            ->assertSet('showDuplicateWarning', true)
            ->set('email', '')
            ->assertSet('showDuplicateWarning', false);
    });
});

describe('NewLeadCreated event', function () {
    it('broadcasts to internal-team channel', function () {
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'lead',
        ]);

        $event = new NewLeadCreated($deal);

        expect($event->broadcastOn())->toHaveCount(1);
        expect($event->broadcastOn()[0]->name)->toBe('private-internal-team');
    });

    it('broadcasts with correct data structure', function () {
        $customer = Customer::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'test@test.com',
        ]);
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Test St',
            'city' => 'Test City',
        ]);
        $creator = User::factory()->internal()->withFullAccess()->create(['name' => 'Creator']);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'created_by' => $creator->id,
            'title' => 'Test Lead',
            'stage' => 'lead',
        ]);

        $event = new NewLeadCreated($deal);
        $data = $event->broadcastWith();

        expect($data)->toHaveKey('deal');
        expect($data['deal']['id'])->toBe($deal->id);
        expect($data['deal']['title'])->toBe('Test Lead');
        expect($data['deal']['stage'])->toBe('lead');
        expect($data['deal']['customer']['full_name'])->toBe('Test Customer');
        expect($data['deal']['property']['address'])->toBe('123 Test St');
        expect($data['deal']['created_by']['name'])->toBe('Creator');
    });

    it('has correct broadcast name', function () {
        $deal = Deal::factory()->create();
        $event = new NewLeadCreated($deal);

        expect($event->broadcastAs())->toBe('lead.created');
    });
});
