<?php

use App\Events\AgentContributionAdded;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createAgentWithEstimate(array $estimateOverrides = []): array
{
    $agent = User::factory()->agent()->create();
    $property = Property::factory()->agentListing()->forAgent($agent)->create();
    $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();
    $estimate = Estimate::factory()->for($deal)->create(array_merge([
        'status' => 'draft',
        'project_type' => 'interior',
    ], $estimateOverrides));

    return compact('agent', 'property', 'deal', 'estimate');
}

describe('Agent Estimate Collaboration', function () {
    it('allows agent to access estimate on their deal', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.estimates.show', $estimate))
            ->assertSuccessful()
            ->assertSeeLivewire('agent.estimates.show');
    });

    it('denies access to another agents estimate', function () {
        ['estimate' => $estimate] = createAgentWithEstimate();
        $otherAgent = User::factory()->agent()->create();

        $this->actingAs($otherAgent, 'agent')
            ->get(route('agent.estimates.show', $estimate))
            ->assertNotFound();
    });

    it('saves agent notes to estimate', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        Event::fake([AgentContributionAdded::class]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set('agentNotes', 'Property needs attention on the east wall')
            ->call('saveNotes');

        expect($estimate->fresh()->agent_notes)->toBe('Property needs attention on the east wall');
    });

    it('saves agent checklist to estimate', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        Event::fake([AgentContributionAdded::class]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set('agentChecklist', ['vacant_property', 'timeline_urgent'])
            ->call('saveChecklist');

        expect($estimate->fresh()->agent_checklist)->toBe(['vacant_property', 'timeline_urgent']);
    });

    it('saves per-area agent notes', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        $area = EstimateArea::factory()->for($estimate)->create(['name' => 'Living Room']);

        Event::fake([AgentContributionAdded::class]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set("areaAgentNotes.{$area->id}", 'High ceilings in this room')
            ->call('saveNotes');

        expect($area->fresh()->agent_notes)->toBe('High ceilings in this room');
    });

    it('saves per-area agent checklist', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        $area = EstimateArea::factory()->for($estimate)->create([
            'name' => 'Kitchen',
            'scope_type' => 'interior',
        ]);

        Event::fake([AgentContributionAdded::class]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set("areaAgentChecklists.{$area->id}", ['water_damage', 'dark_to_light'])
            ->call('saveAreaChecklist', $area->id);

        expect($area->fresh()->agent_checklist)->toBe(['water_damage', 'dark_to_light']);
    });

    it('fires AgentContributionAdded event on notes save', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        Event::fake([AgentContributionAdded::class]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set('agentNotes', 'Some notes')
            ->call('saveNotes');

        Event::assertDispatched(AgentContributionAdded::class, function ($event) use ($estimate) {
            return $event->estimateId === $estimate->id && $event->type === 'notes';
        });
    });

    it('filters checklist by scope for interior', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate(['project_type' => 'interior']);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate]);

        // Interior scope should show interior items but not exterior
        $component->assertSee('Active showings')
            ->assertDontSee('Curb appeal is top priority');
    });

    it('shows proposal tab for sent estimates', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate([
            'status' => 'sent',
            'sent_at' => now(),
            'public_token' => 'test-token-123',
            'public_token_expires_at' => now()->addDays(30),
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertSee('Proposal');
    });

    it('hides proposal tab for draft estimates', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate(['status' => 'draft']);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertDontSee('Proposal');
    });

    it('shows chat section with thread', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertSee('Project Conversation');
    });

    it('shows listing link form on observations tab', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set('activeTab', 'input')
            ->assertSee('Property Listing Link');
    });

    it('saves listing URL to deal', function () {
        ['agent' => $agent, 'estimate' => $estimate, 'deal' => $deal] = createAgentWithEstimate();

        Event::fake([AgentContributionAdded::class]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set('listingUrl', 'https://www.zillow.com/homedetails/123-main-st')
            ->call('saveListing')
            ->assertHasNoErrors();

        expect($deal->fresh()->listing_url)->toBe('https://www.zillow.com/homedetails/123-main-st');
    });

    it('fires AgentContributionAdded event on listing import', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        Event::fake([AgentContributionAdded::class]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set('listingUrl', 'https://www.redfin.com/CO/Denver/123-Main-St')
            ->call('saveListing');

        Event::assertDispatched(AgentContributionAdded::class, function ($event) use ($estimate) {
            return $event->estimateId === $estimate->id && $event->type === 'listing';
        });
    });

    it('validates listing URL format', function () {
        ['agent' => $agent, 'estimate' => $estimate] = createAgentWithEstimate();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->set('listingUrl', 'not-a-url')
            ->call('saveListing')
            ->assertHasErrors(['listingUrl']);
    });
});
