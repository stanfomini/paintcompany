<?php

use App\Models\User;
use Livewire\Livewire;

describe('Crew Show component', function () {
    it('renders the crew show component for internal users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create(['name' => 'Test Crew']);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->assertStatus(200)
            ->assertSee('Test Crew');
    });

    it('returns 404 for non-crew users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $agent])
            ->assertStatus(404);
    });

    it('can activate a crew from the show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->inactive()->create(['name' => 'Inactive Crew']);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('activate')
            ->assertSee('Crew activated successfully');

        expect($crew->fresh()->is_active)->toBeTrue();
    });

    it('can deactivate a crew from the show page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create([
            'name' => 'Active Crew',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('deactivate')
            ->assertSee('Crew deactivated successfully');

        expect($crew->fresh()->is_active)->toBeFalse();
    });

    it('shows activate button when crew is inactive', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->inactive()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->assertSee('Activate')
            ->assertDontSee('Deactivate');
    });

    it('shows deactivate button when crew is active', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create(['is_active' => true]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->assertSee('Deactivate');
    });

    it('displays workers comp information on compliance tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'workers_comp_carrier' => 'State Farm Insurance',
            'workers_comp_policy_number' => 'WC-12345',
            'workers_comp_start_date' => now()->subMonths(6),
            'workers_comp_expiration_date' => now()->addMonths(6),
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('setTab', 'compliance')
            ->assertSee('State Farm Insurance')
            ->assertSee('WC-12345');
    });

    it('shows valid badge when workers comp is current', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'workers_comp_expiration_date' => now()->addMonths(6),
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('setTab', 'compliance')
            ->assertSee("Workers' Compensation", escape: false)
            ->assertSee('Valid');
    });

    it('shows expiring soon badge when workers comp expires within 30 days', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'workers_comp_expiration_date' => now()->addDays(15),
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('setTab', 'compliance')
            ->assertSee('Expiring Soon');
    });

    it('shows expired badge when workers comp is expired', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update([
            'workers_comp_expiration_date' => now()->subDays(5),
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('setTab', 'compliance')
            ->assertSee('Expired');
    });

    it('can save workers comp information via compliance modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('openComplianceModal')
            ->set('editWorkersCompCarrier', 'Liberty Mutual')
            ->set('editWorkersCompPolicyNumber', 'WC-99999')
            ->set('editWorkersCompStartDate', '2026-01-01')
            ->set('editWorkersCompExpirationDate', '2027-01-01')
            ->call('saveCompliance')
            ->assertSee('Compliance information updated successfully');

        $crew->refresh();
        expect($crew->crewProfile->workers_comp_carrier)->toBe('Liberty Mutual');
        expect($crew->crewProfile->workers_comp_policy_number)->toBe('WC-99999');
        expect($crew->crewProfile->workers_comp_start_date->format('Y-m-d'))->toBe('2026-01-01');
        expect($crew->crewProfile->workers_comp_expiration_date->format('Y-m-d'))->toBe('2027-01-01');
    });

    it('can save workers comp document as url', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('openComplianceModal')
            ->set('editWorkersCompDocumentType', 'url')
            ->set('editWorkersCompDocumentUrl', 'https://example.com/workers-comp.pdf')
            ->call('saveCompliance')
            ->assertSee('Compliance information updated successfully');

        $crew->refresh();
        expect($crew->crewProfile->workers_comp_document)->toBe('https://example.com/workers-comp.pdf');
        expect($crew->crewProfile->workers_comp_document_type)->toBe('url');
    });

    it('can save workers comp document as file upload', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        $file = \Illuminate\Http\UploadedFile::fake()->create('workers-comp.pdf', 500, 'application/pdf');

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('openComplianceModal')
            ->set('editWorkersCompDocumentType', 'file')
            ->set('editWorkersCompDocumentFile', $file)
            ->call('saveCompliance')
            ->assertSee('Compliance information updated successfully');

        $crew->refresh();
        expect($crew->crewProfile->workers_comp_document)->not->toBeNull();
        expect($crew->crewProfile->workers_comp_document_type)->toBe('file');
    });
});
