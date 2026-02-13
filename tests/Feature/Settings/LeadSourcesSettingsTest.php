<?php

use App\Models\LeadSource;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

describe('Lead Sources Settings Page Access', function () {

    it('internal user with full access can view the page', function () {
        Livewire::test('pages::settings.lead-sources')
            ->assertSuccessful()
            ->assertSee('Lead Sources');
    });

    it('customer user cannot access the page', function () {
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer);

        Livewire::test('pages::settings.lead-sources')
            ->assertForbidden();
    });

    it('agent user cannot access the page', function () {
        $agent = User::factory()->agent()->create();
        $this->actingAs($agent);

        Livewire::test('pages::settings.lead-sources')
            ->assertForbidden();
    });

    it('crew user cannot access the page', function () {
        $crew = User::factory()->crew()->create();
        $this->actingAs($crew);

        Livewire::test('pages::settings.lead-sources')
            ->assertForbidden();
    });

    it('settings route is accessible for internal user', function () {
        $this->get(route('lead-sources.edit'))
            ->assertSuccessful();
    });
});

describe('Lead Sources CRUD', function () {

    it('displays existing lead sources', function () {
        $source = LeadSource::factory()->create(['name' => 'Google Ads', 'sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->assertSee('Google Ads');
    });

    it('can create a new custom lead source', function () {
        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->assertSet('showModal', true)
            ->set('name', 'Door Hangers')
            ->set('type', 'custom')
            ->set('icon', 'megaphone')
            ->set('color', 'green')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false)
            ->assertDispatched('lead-sources-updated');

        expect(LeadSource::where('name', 'Door Hangers')->exists())->toBeTrue();

        $source = LeadSource::where('name', 'Door Hangers')->first();
        expect($source->slug)->toBe('door_hangers');
        expect($source->type)->toBe('custom');
        expect($source->icon)->toBe('megaphone');
        expect($source->color)->toBe('green');
        expect($source->is_system)->toBeFalse();
    });

    it('auto-generates slug from name when slug is empty', function () {
        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', 'Facebook Marketplace')
            ->call('save')
            ->assertHasNoErrors();

        $source = LeadSource::where('name', 'Facebook Marketplace')->first();
        expect($source->slug)->toBe('facebook_marketplace');
    });

    it('uses custom slug when provided', function () {
        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', 'Facebook Marketplace')
            ->set('slug', 'fb_market')
            ->call('save')
            ->assertHasNoErrors();

        $source = LeadSource::where('name', 'Facebook Marketplace')->first();
        expect($source->slug)->toBe('fb_market');
    });

    it('can edit an existing lead source', function () {
        $source = LeadSource::factory()->create(['name' => 'Old Name', 'sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->call('openEditModal', $source->id)
            ->assertSet('editingId', $source->id)
            ->assertSet('name', 'Old Name')
            ->set('name', 'New Name')
            ->set('color', 'red')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('lead-sources-updated');

        $source->refresh();
        expect($source->name)->toBe('New Name');
        expect($source->color)->toBe('red');
    });

    it('validates name is required', function () {
        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    });

    it('validates name is unique', function () {
        LeadSource::factory()->create(['name' => 'Google Ads', 'sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', 'Google Ads')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);
    });

    it('allows same name when editing the same source', function () {
        $source = LeadSource::factory()->create(['name' => 'Google Ads', 'sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->call('openEditModal', $source->id)
            ->set('color', 'red')
            ->call('save')
            ->assertHasNoErrors();
    });

    it('sets sort_order to max+1 on create', function () {
        LeadSource::factory()->create(['sort_order' => 5]);
        LeadSource::factory()->create(['sort_order' => 10]);

        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', 'New Source')
            ->call('save')
            ->assertHasNoErrors();

        $newSource = LeadSource::where('name', 'New Source')->first();
        expect($newSource->sort_order)->toBe(11);
    });
});

describe('Lead Sources Toggle Active', function () {

    it('can toggle a source active/inactive', function () {
        $source = LeadSource::factory()->create(['is_active' => true, 'sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->call('toggleActive', $source->id);

        expect($source->fresh()->is_active)->toBeFalse();
    });
});

describe('Lead Sources Delete', function () {

    it('can delete a custom source with no references', function () {
        $source = LeadSource::factory()->create(['sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->call('confirmDelete', $source->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deletingName', $source->name)
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertDispatched('lead-sources-updated');

        expect(LeadSource::find($source->id))->toBeNull();
    });

    it('cannot delete a system source', function () {
        $source = LeadSource::factory()->system()->create(['sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->call('confirmDelete', $source->id)
            ->assertSet('showDeleteModal', false);

        expect(LeadSource::find($source->id))->not->toBeNull();
    });

    it('cannot delete a source with references', function () {
        $source = LeadSource::factory()->create(['sort_order' => 1]);
        // Create a customer referencing this source
        \App\Models\Customer::factory()->create(['lead_source_id' => $source->id]);

        Livewire::test('pages::settings.lead-sources')
            ->call('confirmDelete', $source->id)
            ->assertSet('showDeleteModal', false);

        expect(LeadSource::find($source->id))->not->toBeNull();
    });

    it('can cancel delete', function () {
        $source = LeadSource::factory()->create(['sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->call('confirmDelete', $source->id)
            ->assertSet('showDeleteModal', true)
            ->call('cancelDelete')
            ->assertSet('showDeleteModal', false)
            ->assertSet('deletingId', null);

        expect(LeadSource::find($source->id))->not->toBeNull();
    });
});

describe('Lead Sources Reorder', function () {

    it('can move a source up', function () {
        $first = LeadSource::factory()->create(['sort_order' => 1, 'name' => 'First']);
        $second = LeadSource::factory()->create(['sort_order' => 2, 'name' => 'Second']);

        Livewire::test('pages::settings.lead-sources')
            ->call('moveUp', $second->id);

        expect($second->fresh()->sort_order)->toBe(1);
        expect($first->fresh()->sort_order)->toBe(2);
    });

    it('can move a source down', function () {
        $first = LeadSource::factory()->create(['sort_order' => 1, 'name' => 'First']);
        $second = LeadSource::factory()->create(['sort_order' => 2, 'name' => 'Second']);

        Livewire::test('pages::settings.lead-sources')
            ->call('moveDown', $first->id);

        expect($first->fresh()->sort_order)->toBe(2);
        expect($second->fresh()->sort_order)->toBe(1);
    });

    it('does not move the first source up', function () {
        $first = LeadSource::factory()->create(['sort_order' => 1, 'name' => 'First']);

        Livewire::test('pages::settings.lead-sources')
            ->call('moveUp', $first->id);

        expect($first->fresh()->sort_order)->toBe(1);
    });

    it('does not move the last source down', function () {
        $last = LeadSource::factory()->create(['sort_order' => 1, 'name' => 'Last']);

        Livewire::test('pages::settings.lead-sources')
            ->call('moveDown', $last->id);

        expect($last->fresh()->sort_order)->toBe(1);
    });
});

describe('Lead Sources System Protection', function () {

    it('does not allow type change on system sources', function () {
        $source = LeadSource::factory()->system()->create(['sort_order' => 1]);

        Livewire::test('pages::settings.lead-sources')
            ->call('openEditModal', $source->id)
            ->assertSet('editingSystem', true)
            ->set('name', 'Updated System Source')
            ->set('color', 'green')
            ->call('save')
            ->assertHasNoErrors();

        $source->refresh();
        expect($source->name)->toBe('Updated System Source');
        expect($source->color)->toBe('green');
        expect($source->type)->toBe('built_in');
    });
});
