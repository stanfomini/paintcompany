<?php

use App\Models\LeadSource;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WebhookEndpoint;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
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

describe('Lead Source Workflow Integration', function () {

    it('auto-creates workflow and webhook endpoint when creating lead source', function () {
        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', 'Angi Leads')
            ->set('type', 'webhook')
            ->set('icon', 'signal')
            ->set('color', 'purple')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('lead-sources-updated');

        $source = LeadSource::where('name', 'Angi Leads')->first();
        expect($source)->not->toBeNull();
        expect($source->type)->toBe('webhook');
        expect($source->workflow_id)->not->toBeNull();

        // Verify webhook endpoint auto-created
        $source->refresh();
        expect($source->webhookEndpoint)->not->toBeNull();
        expect($source->webhookEndpoint->is_active)->toBeTrue();

        $workflow = Workflow::find($source->workflow_id);
        expect($workflow)->not->toBeNull();
        expect($workflow->name)->toBe('Lead: Angi Leads');

        $nodes = WorkflowNode::where('workflow_id', $workflow->id)->orderBy('position_y')->get();
        expect($nodes)->toHaveCount(5);
        expect($nodes[0]->type)->toBe('trigger');
        expect($nodes[0]->node_class)->toBe('App\\Automation\\Triggers\\InboundWebhook');
        expect($nodes[1]->node_class)->toBe('App\\Automation\\Actions\\CreateCustomer');
        expect($nodes[2]->node_class)->toBe('App\\Automation\\Actions\\CreateProperty');
        expect($nodes[3]->node_class)->toBe('App\\Automation\\Actions\\CreateDeal');
        expect($nodes[4]->node_class)->toBe('App\\Automation\\Actions\\SendNotification');

        $edges = WorkflowEdge::where('workflow_id', $workflow->id)->orderBy('sort_order')->get();
        expect($edges)->toHaveCount(4);
        expect($edges[0]->from_node_id)->toBe($nodes[0]->id);
        expect($edges[0]->to_node_id)->toBe($nodes[1]->id);
    });

    it('auto-creates workflow with commission when vendor is linked', function () {
        $vendor = Vendor::factory()->create();

        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', 'Vendor Leads')
            ->set('type', 'webhook')
            ->set('icon', 'signal')
            ->set('color', 'green')
            ->set('vendor_id', $vendor->id)
            ->call('save')
            ->assertHasNoErrors();

        $source = LeadSource::where('name', 'Vendor Leads')->first();
        expect($source->vendor_id)->toBe($vendor->id);
        expect($source->workflow_id)->not->toBeNull();

        $workflow = Workflow::find($source->workflow_id);
        $nodes = WorkflowNode::where('workflow_id', $workflow->id)->orderBy('position_y')->get();
        expect($nodes)->toHaveCount(6);

        $nodeClasses = $nodes->pluck('node_class')->toArray();
        expect($nodeClasses)->toContain('App\\Automation\\Actions\\CreateLeadSourceCommission');
    });

    it('displays webhook URL for webhook sources', function () {
        $source = LeadSource::factory()->webhook()->create(['sort_order' => 1]);
        $endpoint = WebhookEndpoint::factory()->create(['lead_source_id' => $source->id]);

        Livewire::test('pages::settings.lead-sources')
            ->assertSee($endpoint->getWebhookUrl());
    });

    it('can link vendor to webhook lead source', function () {
        $vendor = Vendor::factory()->create();

        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', 'Vendor Source')
            ->set('type', 'webhook')
            ->set('vendor_id', $vendor->id)
            ->call('save')
            ->assertHasNoErrors();

        $source = LeadSource::where('name', 'Vendor Source')->first();
        expect($source->vendor_id)->toBe($vendor->id);
    });

    it('does not show custom type option', function () {
        $component = Livewire::test('pages::settings.lead-sources');
        $instance = $component->instance();
        $typeOptions = $instance->typeOptions;
        expect($typeOptions)->not->toHaveKey('custom');
        expect($typeOptions)->toHaveKey('built_in');
        expect($typeOptions)->toHaveKey('webhook');
    });

    it('creates workflow for all source types including built_in', function () {
        Livewire::test('pages::settings.lead-sources')
            ->call('openAddModal')
            ->set('name', 'System-Like Source')
            ->set('type', 'built_in')
            ->call('save')
            ->assertHasNoErrors();

        $source = LeadSource::where('name', 'System-Like Source')->first();
        expect($source->workflow_id)->not->toBeNull();
        expect($source->webhookEndpoint)->not->toBeNull();
    });

    it('shows vendor badge for sources with vendor', function () {
        $vendor = Vendor::factory()->create(['name' => 'Acme Corp']);
        $source = LeadSource::factory()->webhook()->create([
            'sort_order' => 1,
            'vendor_id' => $vendor->id,
        ]);

        Livewire::test('pages::settings.lead-sources')
            ->assertSee('Acme Corp');
    });
});
