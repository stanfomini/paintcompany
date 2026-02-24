<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerSetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-token',
    ]);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

it('loads settings page for internal user with permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $this->actingAs($user)
        ->get('/settings/messenger')
        ->assertSuccessful();
});

it('forbids settings page for non-internal user', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user)
        ->get('/settings/messenger')
        ->assertForbidden();
});

it('forbids settings page for internal user without permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/settings/messenger')
        ->assertForbidden();
});

it('can save messenger connection settings', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->set('pageId', '999888777')
        ->set('pageAccessToken', 'EAA-new-token')
        ->set('appSecret', 'new-secret')
        ->call('saveConnectionSettings')
        ->assertDispatched('messenger-settings-saved');

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    expect($settings->page_id)->toBe('999888777');
    expect($settings->page_access_token)->toBe('EAA-new-token');
});

it('can save widget settings', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->set('websiteWidgetEnabled', true)
        ->set('widgetGreetingLoggedIn', 'Hello!')
        ->set('widgetThemeColor', '#ff0000')
        ->call('saveWidgetSettings')
        ->assertDispatched('messenger-settings-saved');

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    expect($settings->website_widget_enabled)->toBeTrue();
    expect($settings->widget_greeting_logged_in)->toBe('Hello!');
    expect($settings->widget_theme_color)->toBe('#ff0000');
});

it('lists existing bot flows', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::factory()->create(['name' => 'Lead Qualification']);

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->assertSee('Lead Qualification');
});

it('can delete a bot flow', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::factory()->create(['name' => 'Old Flow']);

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->call('deleteFlow', $flow->id);

    expect(MessengerBotFlow::find($flow->id))->toBeNull();
});
