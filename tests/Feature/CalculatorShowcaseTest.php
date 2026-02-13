<?php

use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Calculator Showcase Page', function () {
    it('renders the calculator page', function () {
        $this->get(route('calculator'))
            ->assertOk()
            ->assertSeeLivewire('public.calculator');
    });

    it('shows disclaimer text on calculator page', function () {
        $this->get(route('calculator'))
            ->assertOk()
            ->assertSee('Estimates vary by area, property condition, and listing strategy');
    });

    it('validates agent signup form fields', function () {
        Livewire::test('public.calculator')
            ->set('showAgentModal', true)
            ->call('submitAgentSignup')
            ->assertHasErrors(['agentEmail', 'agentName', 'agentCompany', 'agentPhone']);
    });

    it('creates activity on valid agent signup submission', function () {
        $internal = User::factory()->internal()->create();

        Livewire::test('public.calculator')
            ->set('showAgentModal', true)
            ->set('agentEmail', 'agent@example.com')
            ->set('agentName', 'Jane Agent')
            ->set('agentCompany', 'RE/MAX')
            ->set('agentPhone', '303-555-1234')
            ->call('submitAgentSignup')
            ->assertRedirect(route('next-steps'));

        expect(Activity::where('title', 'like', '%Jane Agent%')->exists())->toBeTrue();
    });

    it('redirects to next-steps after agent signup', function () {
        User::factory()->internal()->create();

        Livewire::test('public.calculator')
            ->set('agentEmail', 'test@example.com')
            ->set('agentName', 'Test Agent')
            ->set('agentCompany', 'Test Realty')
            ->set('agentPhone', '303-555-0000')
            ->call('submitAgentSignup')
            ->assertRedirect(route('next-steps'));
    });
});
