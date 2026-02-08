<?php

use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->internal()->withFullAccess()->create());
    $this->painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
});

describe('Send Invitation - Service Category', function () {
    it('shows service category dropdown when invite type is crew', function () {
        Livewire::test('invitations.send-invitation')
            ->set('invitedUserType', 'crew')
            ->assertSee('Service Category');
    });

    it('hides service category dropdown when invite type is not crew', function () {
        Livewire::test('invitations.send-invitation')
            ->set('invitedUserType', 'customer')
            ->assertDontSee('Service Category');
    });

    it('validates service category is required for crew invitations', function () {
        Livewire::test('invitations.send-invitation')
            ->set('invitedUserType', 'crew')
            ->set('email', 'crew@test.com')
            ->set('serviceCategoryId', null)
            ->call('sendInvitation')
            ->assertHasErrors(['serviceCategoryId']);
    });

    it('does not require service category for non-crew invitations', function () {
        Livewire::test('invitations.send-invitation')
            ->set('invitedUserType', 'customer')
            ->set('email', 'customer@test.com')
            ->set('serviceCategoryId', null)
            ->call('sendInvitation')
            ->assertHasNoErrors(['serviceCategoryId']);
    });
});

describe('Crew Show - Service Category', function () {
    it('displays service category badge on crew show page', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['service_category_id' => $this->painting->id]);

        Livewire::test('crews.show', ['user' => $crew])
            ->assertSee('Painting');
    });

    it('can update service category via edit profile', function () {
        $crew = User::factory()->crew()->create();

        Livewire::test('crews.show', ['user' => $crew])
            ->call('openEditProfileModal')
            ->set('editServiceCategoryId', $this->painting->id)
            ->call('saveProfile');

        expect($crew->crewProfile->fresh()->service_category_id)->toBe($this->painting->id);
    });
});

describe('Crew Index - Service Category', function () {
    it('shows service filter dropdown', function () {
        Livewire::test('crews.index')
            ->assertSee('All Services');
    });

    it('filters crew index by service category', function () {
        $paintCrew = User::factory()->crew()->create(['name' => 'Paint Crew Alpha']);
        $paintCrew->crewProfile->update(['service_category_id' => $this->painting->id]);

        $unassignedCrew = User::factory()->crew()->create(['name' => 'Unassigned Crew Beta']);

        Livewire::test('crews.index')
            ->set('serviceCategoryFilter', (string) $this->painting->id)
            ->assertSee('Paint Crew Alpha')
            ->assertDontSee('Unassigned Crew Beta');
    });

    it('filters crew index by unassigned service category', function () {
        $paintCrew = User::factory()->crew()->create(['name' => 'Paint Crew Alpha']);
        $paintCrew->crewProfile->update(['service_category_id' => $this->painting->id]);

        $unassignedCrew = User::factory()->crew()->create(['name' => 'Unassigned Crew Beta']);

        Livewire::test('crews.index')
            ->set('serviceCategoryFilter', 'unassigned')
            ->assertSee('Unassigned Crew Beta')
            ->assertDontSee('Paint Crew Alpha');
    });

    it('displays service category badge in crew table', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['service_category_id' => $this->painting->id]);

        Livewire::test('crews.index')
            ->assertSee('Painting');
    });
});
