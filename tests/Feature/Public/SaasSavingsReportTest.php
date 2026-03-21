<?php

use App\Models\SaasComparisonScenario;
use Livewire\Livewire;

describe('Public SaaS Savings Report', function () {
    it('shows the report page for a valid token', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create();

        $this->get(route('saas-report.public', ['token' => $scenario->public_token]))
            ->assertOk()
            ->assertSeeLivewire('public.saas-savings-report');
    });

    it('returns 404 for an invalid token', function () {
        $this->get(route('saas-report.public', ['token' => 'invalid-token-abc123']))
            ->assertNotFound();
    });

    it('returns 410 for an expired token', function () {
        $scenario = SaasComparisonScenario::factory()->expired()->create();

        $this->get(route('saas-report.public', ['token' => $scenario->public_token]))
            ->assertGone();
    });

    it('renders without authentication', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create();

        $this->assertGuest();

        $this->get(route('saas-report.public', ['token' => $scenario->public_token]))
            ->assertOk();
    });

    it('displays the scenario name', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create([
            'name' => 'My Custom Scenario',
        ]);

        Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token])
            ->assertSee('My Custom Scenario');
    });

    it('displays the creation date', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create();

        Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token])
            ->assertSee($scenario->created_at->format('F j, Y'));
    });

    it('displays the user count from settings', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create([
            'settings' => [
                'userCount' => 15,
            ],
        ]);

        Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token])
            ->assertSee('15');
    });

    it('hydrates self-host cost settings from scenario', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create([
            'settings' => [
                'selfHostServer' => 150,
                'selfHostForge' => 25,
            ],
        ]);

        Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token])
            ->assertSet('selfHostServer', 150)
            ->assertSet('selfHostForge', 25);
    });

    it('hydrates time savings settings from scenario', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create([
            'settings' => [
                'hoursConsolidation' => 8,
                'employeeHourValue' => 50,
            ],
        ]);

        Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token])
            ->assertSet('hoursConsolidation', 8)
            ->assertSet('employeeHourValue', 50);
    });

    it('defaults enabled tools to true when not set', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create([
            'settings' => [
                'userCount' => 10,
            ],
        ]);

        $component = Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token]);

        $enabledTools = $component->get('enabledTools');
        expect($enabledTools['estimating'])->toBeTrue();
        expect($enabledTools['crm'])->toBeTrue();
        expect($enabledTools['accounting'])->toBeTrue();
    });

    it('respects enabled tools from scenario settings', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create([
            'settings' => [
                'enabledTools' => [
                    'estimating' => true,
                    'crm' => false,
                    'accounting' => true,
                ],
            ],
        ]);

        $component = Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token]);

        $enabledTools = $component->get('enabledTools');
        expect($enabledTools['estimating'])->toBeTrue();
        expect($enabledTools['crm'])->toBeFalse();
        expect($enabledTools['accounting'])->toBeTrue();
    });

    it('shows report sections', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create();

        Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token])
            ->assertSee('Platform Value Report')
            ->assertSee('Cost Comparison')
            ->assertSee('Software Replaced')
            ->assertSee('Time Savings')
            ->assertSee('Combined Annual Value')
            ->assertSee('Total Annual Platform Value');
    });

    it('shows disclaimer text', function () {
        $scenario = SaasComparisonScenario::factory()->withPublicToken()->create();

        Livewire::test('public.saas-savings-report', ['token' => $scenario->public_token])
            ->assertSee('Savings estimates are based on published pricing');
    });
});
