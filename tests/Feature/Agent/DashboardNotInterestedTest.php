<?php

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Agent Dashboard - Not Interested', function () {
    it('marks a deal as lost when not interested is clicked', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('markNotInterested', $deal->id);

        expect($deal->fresh()->stage)->toBe('lost');
    });

    it('creates an activity record for internal team', function () {
        $internal = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => '123 Main St',
        ]);
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('markNotInterested', $deal->id);

        expect(Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->exists())->toBeTrue();
    });

    it('cannot mark another agents deal as not interested', function () {
        $agent1 = User::factory()->agent()->create();
        $agent2 = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent2)->create();
        $deal = Deal::factory()->referredByAgent($agent2)->forProperty($property)->lead()->create();

        // agent1 has their own deal to mount component
        $agent1Property = Property::factory()->agentListing()->forAgent($agent1)->create();
        Deal::factory()->referredByAgent($agent1)->forProperty($agent1Property)->lead()->create();

        Livewire::actingAs($agent1, 'agent')
            ->test('agent.dashboard')
            ->call('markNotInterested', $deal->id);

        expect($deal->fresh()->stage)->toBe('lead');
    });

    it('cannot mark already past deals as not interested', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->create(['stage' => 'completed']);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('markNotInterested', $deal->id);

        expect($deal->fresh()->stage)->toBe('completed');
    });

    it('deal moves from active to past deals', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal1 = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();
        $deal2 = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard');

        $component->call('markNotInterested', $deal1->id);

        // deal1 should now be lost/past
        expect($deal1->fresh()->stage)->toBe('lost');
        // Selected deal should auto-switch to remaining active deal
        expect($component->get('selectedDealId'))->toBe($deal2->id);
    });
});
