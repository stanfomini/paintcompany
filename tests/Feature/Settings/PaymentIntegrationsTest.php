<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->ownerUser = User::factory()->internal()->withFullAccess()->create();
    $this->adminUser = User::factory()->internal()->create();
    $this->agentUser = User::factory()->agent()->create();
    $this->customerUser = User::factory()->customer()->create();
});

// --- Access Control Tests ---

it('owner can access payment integrations page', function () {
    $this->actingAs($this->ownerUser)
        ->get(route('payment-integrations.edit'))
        ->assertSuccessful()
        ->assertSee('Payment Integrations');
});

it('admin without permission gets 403', function () {
    $this->actingAs($this->adminUser)
        ->get(route('payment-integrations.edit'))
        ->assertForbidden();
});

it('agent users cannot access payment integrations page', function () {
    $this->actingAs($this->agentUser)
        ->get(route('payment-integrations.edit'))
        ->assertForbidden();
});

it('customer users cannot access payment integrations page', function () {
    $this->actingAs($this->customerUser)
        ->get(route('payment-integrations.edit'))
        ->assertForbidden();
});

it('guests are redirected to login', function () {
    $this->get(route('payment-integrations.edit'))
        ->assertRedirect(route('login'));
});

// --- Split Configuration Tests ---

it('can save split config with valid percentages', function () {
    Livewire::actingAs($this->ownerUser)
        ->test('pages::settings.payment-integrations')
        ->set('payment_split_enabled', true)
        ->set('split_destinations', [
            ['name' => 'Operating', 'percentage' => '50', 'stripe_account_id' => null, 'increase_account_id' => null],
            ['name' => 'Owner Pay', 'percentage' => '30', 'stripe_account_id' => null, 'increase_account_id' => null],
            ['name' => 'Tax Reserve', 'percentage' => '20', 'stripe_account_id' => null, 'increase_account_id' => null],
        ])
        ->call('saveSplitConfig')
        ->assertDispatched('split-config-saved');

    $settings = CompanySetting::getInstance();
    expect($settings->payment_split_enabled)->toBeTrue()
        ->and($settings->payment_split_config)->toHaveCount(3)
        ->and($settings->payment_split_config[0]['name'])->toBe('Operating')
        ->and((float) $settings->payment_split_config[0]['percentage'])->toBe(50.0);
});

it('validation fails when percentages do not sum to 100', function () {
    Livewire::actingAs($this->ownerUser)
        ->test('pages::settings.payment-integrations')
        ->set('payment_split_enabled', true)
        ->set('split_destinations', [
            ['name' => 'Operating', 'percentage' => '50', 'stripe_account_id' => null, 'increase_account_id' => null],
            ['name' => 'Owner Pay', 'percentage' => '30', 'stripe_account_id' => null, 'increase_account_id' => null],
        ])
        ->call('saveSplitConfig')
        ->assertHasErrors();
});

it('validation fails when destination names are empty', function () {
    Livewire::actingAs($this->ownerUser)
        ->test('pages::settings.payment-integrations')
        ->set('payment_split_enabled', true)
        ->set('split_destinations', [
            ['name' => '', 'percentage' => '50', 'stripe_account_id' => null, 'increase_account_id' => null],
            ['name' => 'Owner Pay', 'percentage' => '50', 'stripe_account_id' => null, 'increase_account_id' => null],
        ])
        ->call('saveSplitConfig')
        ->assertHasErrors();
});

it('can toggle stripe connect enabled', function () {
    Livewire::actingAs($this->ownerUser)
        ->test('pages::settings.payment-integrations')
        ->set('stripe_connect_enabled', true)
        ->call('saveStripeSettings')
        ->assertDispatched('stripe-settings-saved');

    expect(CompanySetting::getInstance()->stripe_connect_enabled)->toBeTrue();
});

it('can toggle stripe ach enabled', function () {
    Livewire::actingAs($this->ownerUser)
        ->test('pages::settings.payment-integrations')
        ->set('stripe_ach_enabled', true)
        ->call('saveStripeSettings')
        ->assertDispatched('stripe-settings-saved');

    expect(CompanySetting::getInstance()->stripe_ach_enabled)->toBeTrue();
});

it('can toggle increase enabled', function () {
    Livewire::actingAs($this->ownerUser)
        ->test('pages::settings.payment-integrations')
        ->set('increase_enabled', true)
        ->set('increase_environment', 'sandbox')
        ->call('saveIncreaseSettings')
        ->assertDispatched('increase-settings-saved');

    expect(CompanySetting::getInstance()->increase_enabled)->toBeTrue();
});

it('can toggle payment split enabled', function () {
    Livewire::actingAs($this->ownerUser)
        ->test('pages::settings.payment-integrations')
        ->set('payment_split_enabled', true)
        ->set('split_destinations', [
            ['name' => 'Operating', 'percentage' => '100', 'stripe_account_id' => null, 'increase_account_id' => null],
        ])
        ->call('saveSplitConfig')
        ->assertDispatched('split-config-saved');

    expect(CompanySetting::getInstance()->payment_split_enabled)->toBeTrue();
});

it('can add and remove split destinations', function () {
    $component = Livewire::actingAs($this->ownerUser)
        ->test('pages::settings.payment-integrations')
        ->set('payment_split_enabled', true)
        ->assertSet('split_destinations', []);

    $component->call('addDestination')
        ->assertCount('split_destinations', 1);

    $component->call('addDestination')
        ->assertCount('split_destinations', 2);

    $component->call('removeDestination', 0)
        ->assertCount('split_destinations', 1);
});
