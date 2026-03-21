<?php

use App\Models\SaasComparisonScenario;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

it('renders the saas savings component for authorized users', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->assertOk()
        ->assertSee('SaaS Cost Comparison Calculator')
        ->assertSee('Team Size')
        ->assertSee('SaaS Tool Breakdown')
        ->assertSee('Saved Scenarios');
});

it('denies access to unauthorized users', function () {
    $user = User::factory()->customer()->create();

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->assertForbidden();
});

it('initializes with all tools enabled', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($user)
        ->test('business-plan.saas-savings');

    $enabledTools = $component->get('enabledTools');
    expect($enabledTools)->toHaveCount(26);
    expect(array_unique(array_values($enabledTools)))->toBe([true]);
});

it('can toggle a tool on and off', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->assertSet('enabledTools.estimating', true)
        ->call('toggleTool', 'estimating')
        ->assertSet('enabledTools.estimating', false)
        ->call('toggleTool', 'estimating')
        ->assertSet('enabledTools.estimating', true);
});

it('can adjust user count', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->assertSet('userCount', 10)
        ->set('userCount', 25)
        ->assertSet('userCount', 25);
});

it('can adjust self-hosting costs', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->assertSet('selfHostServer', 96)
        ->set('selfHostServer', 150)
        ->assertSet('selfHostServer', 150)
        ->set('selfHostMisc', 1000)
        ->assertSet('selfHostMisc', 1000);
});

it('can adjust time savings sliders', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->set('hoursConsolidation', 10)
        ->assertSet('hoursConsolidation', 10)
        ->set('hoursPerEstimate', 3)
        ->assertSet('hoursPerEstimate', 3)
        ->set('estimatesPerWeek', 15)
        ->assertSet('estimatesPerWeek', 15)
        ->set('employeeHourValue', 50)
        ->assertSet('employeeHourValue', 50);
});

it('saves a named scenario', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->set('scenarioName', 'Growth Plan')
        ->set('userCount', 20)
        ->call('saveScenario')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('saas_comparison_scenarios', [
        'name' => 'Growth Plan',
        'created_by' => $user->id,
    ]);

    $scenario = SaasComparisonScenario::where('name', 'Growth Plan')->first();
    expect($scenario->settings['userCount'])->toBe(20);
});

it('validates scenario name is required', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->set('scenarioName', '')
        ->call('saveScenario')
        ->assertHasErrors(['scenarioName' => 'required']);
});

it('loads a saved scenario', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $scenario = SaasComparisonScenario::factory()->create([
        'created_by' => $user->id,
        'settings' => [
            'userCount' => 30,
            'selfHostServer' => 200,
            'selfHostForge' => 19,
            'selfHostDomain' => 1,
            'selfHostStorage' => 50,
            'selfHostTwilio' => 20,
            'selfHostMessaging' => 100,
            'selfHostPython' => 0,
            'selfHostMisc' => 800,
            'hoursConsolidation' => 8,
            'hoursPerEstimate' => 3,
            'estimatesPerWeek' => 10,
            'hoursSwitching' => 2,
            'trainingHoursSaved' => 80,
            'employeeHourValue' => 50,
            'workWeeksPerYear' => 50,
        ],
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->call('loadScenario', $scenario->id)
        ->assertSet('userCount', 30)
        ->assertSet('selfHostServer', 200)
        ->assertSet('hoursConsolidation', 8)
        ->assertSet('employeeHourValue', 50);
});

it('deletes a scenario owned by the user', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $scenario = SaasComparisonScenario::factory()->create([
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->call('deleteScenario', $scenario->id);

    $this->assertDatabaseMissing('saas_comparison_scenarios', [
        'id' => $scenario->id,
    ]);
});

it('does not delete a scenario owned by another user', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $otherUser = User::factory()->internal()->withFullAccess()->create();

    $scenario = SaasComparisonScenario::factory()->create([
        'created_by' => $otherUser->id,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->call('deleteScenario', $scenario->id);

    $this->assertDatabaseHas('saas_comparison_scenarios', [
        'id' => $scenario->id,
    ]);
});

it('generates a share link with a public token', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->set('userCount', 15)
        ->call('generateShareLink')
        ->assertSet('showShareModal', true);

    $shareUrl = $component->get('shareUrl');
    expect($shareUrl)->toContain('/saas-report/');

    $scenario = SaasComparisonScenario::whereNotNull('public_token')->first();
    expect($scenario)->not->toBeNull();
    expect($scenario->public_token_expires_at)->toBeGreaterThan(now());
    expect($scenario->settings['userCount'])->toBe(15);
});

it('only shows scenarios without public tokens in the list', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    // Scenario without public token (should appear)
    SaasComparisonScenario::factory()->create([
        'name' => 'Regular Scenario',
        'created_by' => $user->id,
    ]);

    // Scenario with public token (should not appear)
    SaasComparisonScenario::factory()->withPublicToken()->create([
        'name' => 'Shared Scenario',
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.saas-savings')
        ->assertSee('Regular Scenario')
        ->assertDontSee('Shared Scenario');
});

it('provides correct tool defaults with all expected tools', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($user)
        ->test('business-plan.saas-savings');

    $enabledTools = $component->get('enabledTools');
    expect($enabledTools)->toHaveKeys([
        'estimating', 'crm', 'video', 'photos', 'field-service',
        'd2d-sales', 'accounting', 'payroll', 'hr', 'training',
        'scheduling', 'booking', 'sms-bots', 'contracts', 'business-plan',
        'website', 'visualizer', 'blueprints', 'project-mgmt',
        'email-marketing', 'support-chat', 'recruiting', 'video-calling',
        'flyers', 'vendor-mgmt', 'automation',
    ]);
});
