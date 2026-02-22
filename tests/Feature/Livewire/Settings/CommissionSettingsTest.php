<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

it('requires payroll.manage permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.commissions')
        ->assertForbidden();
});

it('loads for authorized users', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($admin)
        ->test('pages::settings.commissions')
        ->assertSuccessful()
        ->assertSee('Door Knocker')
        ->assertSee('Estimator')
        ->assertSee('Territory Manager Override')
        ->assertSee('Tiered Brackets');
});

it('loads existing commission defaults', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    CompanySetting::getInstance()->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
        ],
        'estimator' => [
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.06, 'auto_trigger' => true],
        ],
    ]]);

    $component = Livewire::actingAs($admin)
        ->test('pages::settings.commissions');

    $component->assertSet('knockerRules.0.source', 'door_knock')
        ->assertSet('knockerRules.0.amount', 25)
        ->assertSet('estimatorRules.0.source', 'estimate')
        ->assertSet('estimatorRules.0.rate', 0.06);
});

it('saves commission defaults for all roles', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($admin)
        ->test('pages::settings.commissions')
        ->set('knockerRules', [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 30, 'rate' => 0, 'auto_trigger' => true],
        ])
        ->set('estimatorRules', [
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'amount' => 0, 'rate' => 0.08, 'auto_trigger' => true],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $defaults = CompanySetting::getInstance()->fresh()->commission_defaults;

    expect($defaults['knocker'][0]['amount'])->toBe(30)
        ->and($defaults['estimator'][0]['rate'])->toBe(0.08);
});

it('adds and removes rules for a role', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::settings.commissions')
        ->assertSet('knockerRules', []);

    $component->call('addRule', 'knocker')
        ->assertCount('knockerRules', 1);

    $component->call('addRule', 'knocker')
        ->assertCount('knockerRules', 2);

    $component->call('removeRule', 'knocker', 0)
        ->assertCount('knockerRules', 1);
});

it('adds and removes tier brackets', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::settings.commissions')
        ->assertSet('tierBrackets', []);

    $component->call('addBracket')
        ->assertCount('tierBrackets', 1);

    $component->call('addBracket')
        ->assertCount('tierBrackets', 2);

    $component->call('removeBracket', 0)
        ->assertCount('tierBrackets', 1);
});

it('validates required fields before saving', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($admin)
        ->test('pages::settings.commissions')
        ->set('knockerRules', [
            ['source' => '', 'type' => 'flat', 'amount' => 25, 'rate' => 0, 'auto_trigger' => true],
        ])
        ->call('save')
        ->assertHasErrors(['knockerRules.0.source']);
});
