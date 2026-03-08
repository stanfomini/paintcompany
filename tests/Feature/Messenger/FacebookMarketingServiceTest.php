<?php

use App\Models\MessengerSetting;
use App\Services\FacebookMarketingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'page_access_token' => 'test-token',
        'ad_account_id' => 'act_123456',
    ]);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

it('fetches ads from the Marketing API', function () {
    Http::fake([
        'graph.facebook.com/v19.0/act_123456/ads*' => Http::response([
            'data' => [
                [
                    'id' => '111',
                    'name' => 'Kitchen Ad',
                    'status' => 'ACTIVE',
                    'campaign' => ['id' => 'c1', 'name' => 'Interior Campaign'],
                    'adset' => ['id' => 'as1', 'name' => 'Denver Homeowners'],
                ],
                [
                    'id' => '222',
                    'name' => 'Deck Ad',
                    'status' => 'PAUSED',
                    'campaign' => ['id' => 'c2', 'name' => 'Exterior Campaign'],
                    'adset' => ['id' => 'as2', 'name' => 'Suburban'],
                ],
            ],
        ]),
    ]);

    $service = app(FacebookMarketingService::class);
    $ads = $service->fetchAds();

    expect($ads)->toHaveCount(2);
    expect($ads[0]['id'])->toBe('111');
    expect($ads[0]['name'])->toBe('Kitchen Ad');
    expect($ads[0]['campaign']['name'])->toBe('Interior Campaign');
});

it('returns empty array when ad account is not configured', function () {
    MessengerSetting::getInstance()->update(['ad_account_id' => null]);
    MessengerSetting::clearInstanceCache();

    $service = app(FacebookMarketingService::class);
    $ads = $service->fetchAds();

    expect($ads)->toBeEmpty();
});

it('returns empty array on API error', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401),
    ]);

    $service = app(FacebookMarketingService::class);
    $ads = $service->fetchAds();

    expect($ads)->toBeEmpty();
});

it('handles pagination', function () {
    Http::fake([
        'graph.facebook.com/v19.0/act_123456/ads*' => Http::sequence()
            ->push([
                'data' => [
                    ['id' => '111', 'name' => 'Ad 1', 'status' => 'ACTIVE', 'campaign' => ['id' => 'c1', 'name' => 'C1'], 'adset' => ['id' => 'as1', 'name' => 'AS1']],
                ],
                'paging' => ['next' => 'https://graph.facebook.com/v19.0/act_123456/ads?after=cursor1'],
            ])
            ->push([
                'data' => [
                    ['id' => '222', 'name' => 'Ad 2', 'status' => 'ACTIVE', 'campaign' => ['id' => 'c1', 'name' => 'C1'], 'adset' => ['id' => 'as1', 'name' => 'AS1']],
                ],
            ]),
    ]);

    $service = app(FacebookMarketingService::class);
    $ads = $service->fetchAds();

    expect($ads)->toHaveCount(2);
});
