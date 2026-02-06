<?php

use App\Models\User;
use Livewire\Livewire;

describe('Crew Index component', function () {
    it('renders the crew index component for internal users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertStatus(200)
            ->assertSee('Crews');
    });

    it('does not allow access without authentication', function () {
        $this->get(route('crews.index'))
            ->assertRedirect(route('login'));
    });

    it('redirects non-internal users to their dashboard', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)
            ->get(route('crews.index'))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('displays crews in the list', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create([
            'name' => 'John Painter',
            'email' => 'john.painter@example.com',
        ]);
        $crew->crewProfile->update([
            'company_name' => 'Apex Painters',
            'crew_size' => 3,
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertSee('John Painter')
            ->assertSee('john.painter@example.com')
            ->assertSee('Apex Painters');
    });

    it('can search crews by name', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $john = User::factory()->crew()->create(['name' => 'John Painter']);
        $jane = User::factory()->crew()->create(['name' => 'Jane Painter']);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->set('search', 'John')
            ->assertSee('John Painter')
            ->assertDontSee('Jane Painter');
    });

    it('can search crews by company name', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew1 = User::factory()->crew()->create(['name' => 'Crew One']);
        $crew1->crewProfile->update(['company_name' => 'Apex Painters']);

        $crew2 = User::factory()->crew()->create(['name' => 'Crew Two']);
        $crew2->crewProfile->update(['company_name' => 'Other Company']);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->set('search', 'Apex')
            ->assertSee('Crew One')
            ->assertDontSee('Crew Two');
    });

    it('can filter crews by active status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $activeCrew = User::factory()->crew()->create([
            'name' => 'Active Crew',
            'is_active' => true,
        ]);
        $inactiveCrew = User::factory()->crew()->inactive()->create([
            'name' => 'Inactive Crew',
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->set('statusFilter', 'active')
            ->assertSee('Active Crew')
            ->assertDontSee('Inactive Crew');
    });

    it('can filter crews by inactive status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $activeCrew = User::factory()->crew()->create([
            'name' => 'Active Crew',
            'is_active' => true,
        ]);
        $inactiveCrew = User::factory()->crew()->inactive()->create([
            'name' => 'Inactive Crew',
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->set('statusFilter', 'inactive')
            ->assertSee('Inactive Crew')
            ->assertDontSee('Active Crew');
    });

    it('shows all statuses by default', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $activeCrew = User::factory()->crew()->create([
            'name' => 'Active Crew',
            'is_active' => true,
        ]);
        $inactiveCrew = User::factory()->crew()->inactive()->create([
            'name' => 'Inactive Crew',
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertSet('statusFilter', 'all')
            ->assertSee('Active Crew')
            ->assertSee('Inactive Crew');
    });

    it('can activate a crew', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->inactive()->create([
            'name' => 'Inactive Crew',
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->call('activate', $crew->id)
            ->assertSee('Crew activated successfully');

        expect($crew->fresh()->is_active)->toBeTrue();
    });

    it('can deactivate a crew', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create([
            'name' => 'Active Crew',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->call('deactivate', $crew->id)
            ->assertSee('Crew deactivated successfully');

        expect($crew->fresh()->is_active)->toBeFalse();
    });

    it('shows compliance status badges', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $compliantCrew = User::factory()->crew()->create(['name' => 'Compliant Crew']);
        $compliantCrew->crewProfile->update([
            'insurance_expiration_date' => now()->addMonths(6),
            'license_expiration_date' => now()->addYear(),
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertSee('Compliant Crew')
            ->assertSee('Compliant');
    });

    it('shows warning badge for expiring compliance', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create(['name' => 'Expiring Crew']);
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addDays(15),
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertSee('Expiring Crew')
            ->assertSee('Expiring Soon');
    });

    it('shows expired badge for expired compliance', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create(['name' => 'Expired Crew']);
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->subDays(5),
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertSee('Expired Crew')
            ->assertSee('Expired');
    });

    it('shows empty state when no crews exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertSee('No crews found');
    });

    it('shows empty state with appropriate message when search yields no results', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        User::factory()->crew()->create(['name' => 'John Painter']);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->set('search', 'NonexistentCrew')
            ->assertSee('No crews found')
            ->assertSee('Try adjusting your search or filter criteria');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertSet('search', '')
            ->assertSet('statusFilter', 'all')
            ->assertSet('complianceFilter', 'all')
            ->assertSet('perPage', 15);
    });

    it('shows expired compliance when workers comp is expired', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create(['name' => 'WC Expired Crew']);
        $crew->crewProfile->update([
            'insurance_expiration_date' => now()->addMonths(6),
            'workers_comp_expiration_date' => now()->subDays(5),
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->assertSee('WC Expired Crew')
            ->assertSee('Expired');
    });

    it('filters by workers comp expiring soon in compliance filter', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $expiring = User::factory()->crew()->create(['name' => 'WC Expiring']);
        $expiring->crewProfile->update([
            'insurance_expiration_date' => now()->addMonths(6),
            'license_expiration_date' => now()->addYear(),
            'workers_comp_expiration_date' => now()->addDays(15),
        ]);

        $compliant = User::factory()->crew()->create(['name' => 'WC Compliant']);
        $compliant->crewProfile->update([
            'insurance_expiration_date' => now()->addMonths(6),
            'license_expiration_date' => now()->addYear(),
            'workers_comp_expiration_date' => now()->addMonths(6),
        ]);

        Livewire::actingAs($user)
            ->test('crews.index')
            ->set('complianceFilter', 'warning')
            ->assertSee('WC Expiring')
            ->assertDontSee('WC Compliant');
    });

    it('only shows crews, not internal users or agents', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create(['name' => 'Internal User']);
        $crew = User::factory()->crew()->create(['name' => 'Crew User']);
        $agent = User::factory()->agent()->create(['name' => 'Agent User']);

        Livewire::actingAs($internalUser)
            ->test('crews.index')
            ->assertSee('Crew User')
            ->assertDontSee('Agent User');
    });
});
