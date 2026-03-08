<?php

use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Services\FacebookMessengerService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-verify-token',
        'app_secret' => 'test-app-secret',
        'page_access_token' => 'test-page-token',
        'page_id' => '123456',
    ]);
    MessengerSetting::clearInstanceCache();

    Http::fake([
        'graph.facebook.com/*' => Http::response(['message_id' => 'mid.test']),
    ]);
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

it('routes message to mapped flow when referral ad_id is present', function () {
    $defaultFlow = MessengerBotFlow::factory()->active()->create(['name' => 'Default Flow']);
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $defaultFlow->id,
        'sort_order' => 1,
        'message_text' => 'Default question?',
    ]);

    $adFlow = MessengerBotFlow::factory()->create(['name' => 'Ad Flow', 'status' => 'draft']);
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $adFlow->id,
        'sort_order' => 1,
        'message_text' => 'Ad-specific question?',
    ]);

    MessengerAdFlowMapping::factory()->create([
        'facebook_ad_id' => '999888',
        'flow_id' => $adFlow->id,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'psid_ad_user'],
            'message' => [
                'mid' => 'mid.abc',
                'text' => 'Hi',
            ],
            'referral' => [
                'source' => 'ADS',
                'ad_id' => '999888',
            ],
        ]],
    ]);

    $conversation = MessengerConversation::where('facebook_psid', 'psid_ad_user')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($adFlow->id);
});

it('falls back to default active flow when no ad mapping exists', function () {
    $defaultFlow = MessengerBotFlow::factory()->active()->create(['name' => 'Default Flow']);
    MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $defaultFlow->id,
        'sort_order' => 1,
        'message_text' => 'Default question?',
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'psid_organic'],
            'message' => [
                'mid' => 'mid.def',
                'text' => 'Hello',
            ],
        ]],
    ]);

    $conversation = MessengerConversation::where('facebook_psid', 'psid_organic')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($defaultFlow->id);
});

it('falls back to default when referral ad_id has no mapping', function () {
    $defaultFlow = MessengerBotFlow::factory()->active()->create(['name' => 'Default Flow']);
    MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $defaultFlow->id,
        'sort_order' => 1,
        'message_text' => 'Default question?',
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'psid_unknown_ad'],
            'message' => [
                'mid' => 'mid.ghi',
                'text' => 'Hi from ad',
            ],
            'referral' => [
                'source' => 'ADS',
                'ad_id' => 'unmapped_ad_id',
            ],
        ]],
    ]);

    $conversation = MessengerConversation::where('facebook_psid', 'psid_unknown_ad')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($defaultFlow->id);
});

it('routes postback with referral to mapped flow', function () {
    $adFlow = MessengerBotFlow::factory()->create(['name' => 'Ad Flow', 'status' => 'draft']);
    MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $adFlow->id,
        'sort_order' => 1,
        'message_text' => 'Ad question?',
    ]);

    MessengerBotFlow::factory()->active()->create(['name' => 'Default']);

    MessengerAdFlowMapping::factory()->create([
        'facebook_ad_id' => '777666',
        'flow_id' => $adFlow->id,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'psid_postback'],
            'postback' => [
                'title' => 'Get Started',
                'payload' => 'GET_STARTED',
            ],
            'referral' => [
                'source' => 'ADS',
                'ad_id' => '777666',
            ],
        ]],
    ]);

    $conversation = MessengerConversation::where('facebook_psid', 'psid_postback')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($adFlow->id);
});
