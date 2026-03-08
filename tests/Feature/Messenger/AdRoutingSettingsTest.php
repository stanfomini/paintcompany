<?php

use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerBotFlow;
use App\Models\MessengerSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-token',
        'page_access_token' => 'test-page-token',
        'ad_account_id' => 'act_123456',
    ]);
    MessengerSetting::clearInstanceCache();

    $this->internalUser = User::factory()->internal()->create();
    $this->internalUser->givePermissionTo('messenger.manage');
    $this->actingAs($this->internalUser);
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

it('displays ad account id field', function () {
    Livewire::test('pages::settings.messenger-settings')
        ->assertSet('adAccountId', 'act_123456')
        ->assertSee('Ad Routing');
});

it('saves ad account id', function () {
    Livewire::test('pages::settings.messenger-settings')
        ->set('adAccountId', 'act_999888')
        ->call('saveAdAccountId')
        ->assertDispatched('messenger-settings-saved');

    MessengerSetting::clearInstanceCache();
    expect(MessengerSetting::getInstance()->ad_account_id)->toBe('act_999888');
});

it('syncs ads from the Marketing API', function () {
    Http::fake([
        'graph.facebook.com/v19.0/act_123456/ads*' => Http::response([
            'data' => [
                [
                    'id' => '111',
                    'name' => 'Test Ad',
                    'status' => 'ACTIVE',
                    'campaign' => ['id' => 'c1', 'name' => 'Test Campaign'],
                    'adset' => ['id' => 'as1', 'name' => 'Test AdSet'],
                ],
            ],
        ]),
    ]);

    Livewire::test('pages::settings.messenger-settings')
        ->call('syncAds');

    expect(MessengerAdFlowMapping::count())->toBe(1);
    expect(MessengerAdFlowMapping::first()->facebook_ad_name)->toBe('Test Ad');
});

it('can assign a flow to an ad', function () {
    $flow = MessengerBotFlow::factory()->create(['name' => 'Interior Flow']);
    $mapping = MessengerAdFlowMapping::factory()->create([
        'facebook_ad_id' => '555',
        'flow_id' => null,
    ]);

    Livewire::test('pages::settings.messenger-settings')
        ->call('updateAdFlowMapping', $mapping->id, (string) $flow->id);

    expect($mapping->fresh()->flow_id)->toBe($flow->id);
});

it('can unassign a flow from an ad (set to default)', function () {
    $flow = MessengerBotFlow::factory()->create();
    $mapping = MessengerAdFlowMapping::factory()->create([
        'facebook_ad_id' => '666',
        'flow_id' => $flow->id,
    ]);

    Livewire::test('pages::settings.messenger-settings')
        ->call('updateAdFlowMapping', $mapping->id, '');

    expect($mapping->fresh()->flow_id)->toBeNull();
});
