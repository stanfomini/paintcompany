<?php

use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Agent Onboarding', function () {
    it('redirects unonboarded agent to onboarding page', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => true]);

        $this->actingAs($agent, 'agent')
            ->get(route('agent.dashboard'))
            ->assertRedirect(route('agent.onboarding'));
    });

    it('allows onboarded agent to access dashboard normally', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => false]);

        $this->actingAs($agent, 'agent')
            ->get(route('agent.dashboard'))
            ->assertSuccessful();
    });

    it('renders onboarding page for unonboarded agent', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => true]);

        $this->actingAs($agent, 'agent')
            ->get(route('agent.onboarding'))
            ->assertSuccessful()
            ->assertSeeLivewire('agent.onboarding');
    });

    it('redirects already-onboarded agent away from onboarding page', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => false]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->assertRedirect(route('agent.dashboard'));
    });

    it('saves profile info on step 1', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => true]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->assertSet('step', 1)
            ->set('companyName', 'RE/MAX Premier')
            ->set('phone', '555-123-4567')
            ->set('licenseNumber', 'FA.100012345')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertHasNoErrors();

        $agent->refresh();
        expect($agent->agentProfile->company_name)->toBe('RE/MAX Premier');
        expect($agent->agentProfile->phone)->toBe('555-123-4567');
        expect($agent->agentProfile->license_number)->toBe('FA.100012345');
    });

    it('can create a listing on step 2', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => true]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->set('step', 2)
            ->set('address', '456 Oak Ave')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('listingPrice', 350000)
            ->set('intent', 'selling')
            ->call('saveListing')
            ->assertSet('step', 3)
            ->assertHasNoErrors();

        $property = Property::where('address', '456 Oak Ave')->first();
        expect($property)->not->toBeNull();
        expect($property->agent_id)->toBe($agent->id);
        expect($property->city)->toBe('Denver');

        $deal = Deal::where('property_id', $property->id)->first();
        expect($deal)->not->toBeNull();
        expect($deal->agent_id)->toBe($agent->id);
        expect($deal->stage)->toBe('lead');
    });

    it('can skip listing on step 2', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => true]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->set('step', 2)
            ->call('skipListing')
            ->assertSet('step', 3);

        expect(Property::where('agent_id', $agent->id)->count())->toBe(0);
    });

    it('completes onboarding on step 3', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => true]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->set('step', 3)
            ->call('complete')
            ->assertRedirect(route('agent.dashboard'));

        $agent->refresh();
        expect($agent->needs_onboarding)->toBeFalse();
    });

    it('validates listing fields on step 2', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => true]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->set('step', 2)
            ->set('address', '')
            ->set('city', '')
            ->set('state', '')
            ->set('zip', '')
            ->call('saveListing')
            ->assertHasErrors(['address', 'city', 'state', 'zip']);
    });
});
