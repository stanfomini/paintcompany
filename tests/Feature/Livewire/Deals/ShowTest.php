<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Show component', function () {
    it('renders the show component with deal data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Exterior Painting Project',
            'stage' => 'quoted',
            'estimated_value' => 15000.00,
        ]);

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertStatus(200)
            ->assertSee('Exterior Painting Project')
            ->assertSee('John Doe')
            ->assertSee('$15,000.00');
    });

    it('displays deal stage with badge', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['stage' => 'quoted']);

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertSee('Quoted');
    });

    it('displays customer with link', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith']);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertSee('Jane Smith');
    });

    it('displays property when set', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Main Residence',
        ]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
        ]);

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertSee('Main Residence');
    });

    it('displays description when available', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create([
            'description' => 'Complete exterior repaint including trim work.',
        ]);

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertSee('Complete exterior repaint including trim work.');
    });

    it('displays financial details', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->signed()->create([
            'estimated_value' => 20000.00,
            'actual_value' => 18500.00,
        ]);

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertSee('$20,000.00')
            ->assertSee('$18,500.00');
    });

    it('displays timeline dates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create([
            'expected_start_date' => '2025-06-01',
            'expected_end_date' => '2025-06-15',
            'actual_start_date' => '2025-06-02',
            'actual_end_date' => '2025-06-14',
        ]);

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertSee('Jun 1, 2025')
            ->assertSee('Jun 15, 2025')
            ->assertSee('Jun 2, 2025')
            ->assertSee('Jun 14, 2025');
    });

    it('displays assigned user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee = User::factory()->internal()->withFullAccess()->create(['name' => 'Mike Painter']);
        $deal = Deal::factory()->assignedTo($assignee)->create();

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertSee('Mike Painter');
    });

    it('shows unassigned when no assignee', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['assigned_to' => null]);

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertSee('Unassigned');
    });

    it('loads relationships correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $creator = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $creator->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal]);

        expect($component->get('deal')->relationLoaded('customer'))->toBeTrue();
        expect($component->get('deal')->relationLoaded('createdBy'))->toBeTrue();
        expect($component->get('deal')->relationLoaded('notes'))->toBeTrue();
    });

    it('uses the app layout', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal])
            ->assertStatus(200);
    });

    it('refreshes on deal-updated event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['title' => 'Original Title']);

        $component = Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal]);

        $deal->update(['title' => 'Updated Title']);

        $component->dispatch('deal-updated')
            ->assertSee('Updated Title');
    });

    it('refreshes notes on note events', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('deals.show', ['deal' => $deal]);

        $component->dispatch('note-created');
        $component->dispatch('note-updated');
        $component->dispatch('note-deleted');

        expect($component->get('deal')->relationLoaded('notes'))->toBeTrue();
    });

    it('returns correct stage color', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $testCases = [
            'lead' => 'blue',
            'not_contacted' => 'zinc',
            'follow_up' => 'amber',
            'quote_scheduled' => 'purple',
            'quoted' => 'yellow',
            'signed' => 'green',
            'lost' => 'orange',
            'cancelled' => 'red',
        ];

        foreach ($testCases as $stage => $expectedColor) {
            $deal = Deal::factory()->stage($stage)->create();

            $component = Livewire::actingAs($user)
                ->test('deals.show', ['deal' => $deal]);

            expect($component->get('stageColor'))->toBe($expectedColor);
        }
    });
});
