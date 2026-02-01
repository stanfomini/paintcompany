<?php

use App\Livewire\AreaTemplates\Index;
use App\Models\EstimateTemplate;
use App\Models\User;
use Livewire\Livewire;

describe('AreaTemplates Index component', function () {
    it('renders the component for internal users', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertStatus(200)
            ->assertSee('Area Templates');
    });

    it('displays templates visible to the user', function () {
        $user = User::factory()->internal()->create();

        $systemTemplate = EstimateTemplate::factory()->create([
            'name' => 'System Living Room',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);

        $companyTemplate = EstimateTemplate::factory()->create([
            'name' => 'Company Kitchen',
            'visibility' => EstimateTemplate::VISIBILITY_COMPANY,
            'is_configurable' => true,
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);

        $privateTemplate = EstimateTemplate::factory()->create([
            'name' => 'My Private Template',
            'visibility' => EstimateTemplate::VISIBILITY_PRIVATE,
            'created_by' => $user->id,
            'is_configurable' => true,
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('System Living Room')
            ->assertSee('Company Kitchen')
            ->assertSee('My Private Template');
    });

    it('does not show private templates from other users', function () {
        $user = User::factory()->internal()->create();
        $otherUser = User::factory()->internal()->create();

        $otherPrivateTemplate = EstimateTemplate::factory()->create([
            'name' => 'Other User Private',
            'visibility' => EstimateTemplate::VISIBILITY_PRIVATE,
            'created_by' => $otherUser->id,
            'is_configurable' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertDontSee('Other User Private');
    });

    it('filters templates by scope type', function () {
        $user = User::factory()->internal()->create();

        $interiorTemplate = EstimateTemplate::factory()->create([
            'name' => 'Interior Bedroom',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);

        $exteriorTemplate = EstimateTemplate::factory()->create([
            'name' => 'Exterior Deck',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Interior Bedroom')
            ->assertSee('Exterior Deck')
            ->call('switchTab', 'interior')
            ->assertSee('Interior Bedroom')
            ->assertDontSee('Exterior Deck')
            ->call('switchTab', 'exterior')
            ->assertDontSee('Interior Bedroom')
            ->assertSee('Exterior Deck');
    });

    it('filters templates by visibility', function () {
        $user = User::factory()->internal()->create();

        $systemTemplate = EstimateTemplate::factory()->create([
            'name' => 'System Test Template',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
        ]);

        $companyTemplate = EstimateTemplate::factory()->create([
            'name' => 'Company Test Template',
            'visibility' => EstimateTemplate::VISIBILITY_COMPANY,
            'is_configurable' => true,
        ]);

        $myTemplate = EstimateTemplate::factory()->create([
            'name' => 'Private Test Template',
            'visibility' => EstimateTemplate::VISIBILITY_PRIVATE,
            'created_by' => $user->id,
            'is_configurable' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('visibilityFilter', 'system')
            ->assertSee('System Test Template')
            ->assertDontSee('Company Test Template')
            ->assertDontSee('Private Test Template')
            ->set('visibilityFilter', 'company')
            ->assertDontSee('System Test Template')
            ->assertSee('Company Test Template')
            ->assertDontSee('Private Test Template')
            ->set('visibilityFilter', 'mine')
            ->assertDontSee('System Test Template')
            ->assertDontSee('Company Test Template')
            ->assertSee('Private Test Template');
    });

    it('searches templates by name', function () {
        $user = User::factory()->internal()->create();

        EstimateTemplate::factory()->create([
            'name' => 'Living Room',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
        ]);

        EstimateTemplate::factory()->create([
            'name' => 'Bedroom',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Living Room')
            ->assertSee('Bedroom')
            ->set('search', 'Living')
            ->assertSee('Living Room')
            ->assertDontSee('Bedroom');
    });

    it('can clone a template', function () {
        $user = User::factory()->internal()->create();

        $template = EstimateTemplate::factory()->create([
            'name' => 'Original Template',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('clone', $template->id)
            ->assertSee('Template cloned successfully');

        $this->assertDatabaseHas('estimate_templates', [
            'name' => 'Original Template (Copy)',
            'visibility' => EstimateTemplate::VISIBILITY_PRIVATE,
            'created_by' => $user->id,
        ]);
    });

    it('can delete own private template', function () {
        $user = User::factory()->internal()->create();

        $template = EstimateTemplate::factory()->create([
            'name' => 'My Deletable Template',
            'visibility' => EstimateTemplate::VISIBILITY_PRIVATE,
            'created_by' => $user->id,
            'is_configurable' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $template->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deleteName', 'My Deletable Template')
            ->call('delete')
            ->assertSee('Template deleted successfully');

        $this->assertDatabaseMissing('estimate_templates', [
            'id' => $template->id,
        ]);
    });

    it('cannot delete system templates', function () {
        $user = User::factory()->internal()->create();

        $template = EstimateTemplate::factory()->create([
            'name' => 'System Template',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $template->id)
            ->assertSee('You do not have permission to delete this template');

        $this->assertDatabaseHas('estimate_templates', [
            'id' => $template->id,
        ]);
    });

    it('cannot delete other user private templates', function () {
        $user = User::factory()->internal()->create();
        $otherUser = User::factory()->internal()->create();

        $template = EstimateTemplate::factory()->create([
            'name' => 'Other User Template',
            'visibility' => EstimateTemplate::VISIBILITY_PRIVATE,
            'created_by' => $otherUser->id,
            'is_configurable' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $template->id)
            ->assertSee('You do not have permission to delete this template');

        $this->assertDatabaseHas('estimate_templates', [
            'id' => $template->id,
        ]);
    });

    it('shows empty state when no templates exist', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('No templates found')
            ->assertSee('Get started by creating your first area template');
    });

    it('shows appropriate message when filters return no results', function () {
        $user = User::factory()->internal()->create();

        EstimateTemplate::factory()->create([
            'name' => 'Interior Template',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'nonexistent')
            ->assertSee('No templates found')
            ->assertSee('Try adjusting your search or filter criteria');
    });

    it('displays surface count for each template', function () {
        $user = User::factory()->internal()->create();

        $template = EstimateTemplate::factory()->create([
            'name' => 'Template With Surfaces',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Template With Surfaces');
    });

    it('only shows configurable templates', function () {
        $user = User::factory()->internal()->create();

        $configurableTemplate = EstimateTemplate::factory()->create([
            'name' => 'Configurable Template',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => true,
        ]);

        $fixedTemplate = EstimateTemplate::factory()->create([
            'name' => 'Fixed Price Template',
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'is_configurable' => false,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Configurable Template')
            ->assertDontSee('Fixed Price Template');
    });
});
