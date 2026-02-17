<?php

use App\Models\Activity;
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

describe('Crew Show - Activities', function () {
    it('shows crew activities on activities tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Activity::factory()->task()->forUser($crew)->byUser($user)->create([
            'title' => 'Check crew insurance',
            'is_completed' => false,
            'due_at' => today(),
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('setTab', 'activities')
            ->assertSee('Check crew insurance');
    });

    it('can toggle activity completion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        $activity = Activity::factory()->task()->forUser($crew)->byUser($user)->create([
            'title' => 'Complete crew setup',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('toggleActivityComplete', $activity->id);

        expect($activity->fresh()->is_completed)->toBeTrue();
    });

    it('can toggle completed activity back to incomplete', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        $activity = Activity::factory()->task()->forUser($crew)->byUser($user)->completed()->create([
            'title' => 'Setup task',
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('toggleActivityComplete', $activity->id);

        expect($activity->fresh()->is_completed)->toBeFalse();
    });

    it('can add new activity via form', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('openAddActivity')
            ->assertSet('showAddActivity', true)
            ->set('activityTitle', 'Follow up with crew')
            ->set('activityType', 'call')
            ->set('activityDueAt', today()->format('Y-m-d'))
            ->call('saveActivity');

        $activity = Activity::where('subject_type', User::class)
            ->where('subject_id', $crew->id)
            ->where('title', 'Follow up with crew')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('call')
            ->and($activity->user_id)->toBe($user->id)
            ->and($activity->subject_type)->toBe(User::class)
            ->and($activity->subject_id)->toBe($crew->id);
    });

    it('validates activity title is required', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('openAddActivity')
            ->set('activityTitle', '')
            ->call('saveActivity')
            ->assertHasErrors('activityTitle');
    });

    it('shows empty state for activities when none exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('setTab', 'activities')
            ->assertSee('No activities')
            ->assertSee('No activities for this crew yet');
    });

    it('displays open tasks count in stats', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Activity::factory()->task()->forUser($crew)->byUser($user)->create([
            'is_completed' => false,
        ]);
        Activity::factory()->task()->forUser($crew)->byUser($user)->completed()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->assertSee('Open Tasks');
    });

    it('shows activities count in tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Activity::factory()->task()->forUser($crew)->byUser($user)->count(3)->create([
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->assertSee('Activities (3)');
    });

    it('closes and resets add activity form', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crew])
            ->call('openAddActivity')
            ->set('activityTitle', 'Test title')
            ->set('activityType', 'meeting')
            ->call('closeAddActivity')
            ->assertSet('showAddActivity', false)
            ->assertSet('activityTitle', '')
            ->assertSet('activityType', 'task');
    });
});
