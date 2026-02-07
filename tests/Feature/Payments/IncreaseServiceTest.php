<?php

use App\Models\CompanySetting;
use App\Services\IncreaseService;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
    $this->settings->update([
        'increase_enabled' => true,
        'increase_environment' => 'sandbox',
        'increase_access_token' => 'test_access_token',
    ]);

    config([
        'increase.client_id' => 'test_client_id',
        'increase.client_secret' => 'test_client_secret',
        'increase.webhook_secret' => 'test_webhook_secret',
        'increase.sandbox_url' => 'https://sandbox.increase.com/api',
        'increase.production_url' => 'https://api.increase.com',
    ]);
});

it('generates correct authorization URL', function () {
    $service = app(IncreaseService::class);

    $url = $service->getAuthorizationUrl(
        'https://example.com/callback',
        'random_state_123'
    );

    expect($url)->toContain('increase.com')
        ->and($url)->toContain('client_id=test_client_id')
        ->and($url)->toContain('redirect_uri=')
        ->and($url)->toContain('state=random_state_123');
});

it('exchanges code for token', function () {
    Http::fake([
        'sandbox.increase.com/api/oauth/tokens' => Http::response([
            'access_token' => 'new_access_token',
            'token_type' => 'bearer',
        ], 200),
    ]);

    $service = app(IncreaseService::class);
    $result = $service->exchangeCodeForToken('auth_code_123', 'https://example.com/callback');

    expect($result)->toHaveKey('access_token')
        ->and($result['access_token'])->toBe('new_access_token');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth/tokens')
            && $request['code'] === 'auth_code_123';
    });
});

it('lists accounts', function () {
    Http::fake([
        'sandbox.increase.com/api/accounts' => Http::response([
            'data' => [
                ['id' => 'account_1', 'name' => 'Operating', 'balance' => 50000],
                ['id' => 'account_2', 'name' => 'Owner Pay', 'balance' => 20000],
            ],
        ], 200),
    ]);

    $service = app(IncreaseService::class);
    $result = $service->listAccounts();

    expect($result)->toHaveKey('data')
        ->and($result['data'])->toHaveCount(2);
});

it('creates check deposit', function () {
    Http::fake([
        'sandbox.increase.com/api/check_deposits' => Http::response([
            'id' => 'check_deposit_1',
            'status' => 'submitted',
            'amount' => 50000,
        ], 200),
    ]);

    $service = app(IncreaseService::class);
    $result = $service->createCheckDeposit(
        'account_1',
        500.00,
        'file_front_123',
        'file_back_123'
    );

    expect($result)->toHaveKey('id')
        ->and($result['id'])->toBe('check_deposit_1');
});

it('creates ACH transfer', function () {
    Http::fake([
        'sandbox.increase.com/api/ach_transfers' => Http::response([
            'id' => 'ach_transfer_1',
            'status' => 'pending_submission',
            'amount' => 10000,
        ], 200),
    ]);

    $service = app(IncreaseService::class);
    $result = $service->createAchTransfer(
        'account_1',
        '021000021',
        '123456789',
        10000,
        'Test transfer'
    );

    expect($result)->toHaveKey('id')
        ->and($result['id'])->toBe('ach_transfer_1');
});

it('verifies webhook signature correctly', function () {
    $payload = '{"type":"test"}';
    $secret = 'test_webhook_secret';
    $signature = hash_hmac('sha256', $payload, $secret);

    $service = app(IncreaseService::class);

    expect($service->verifyWebhookSignature($payload, $signature))->toBeTrue()
        ->and($service->verifyWebhookSignature($payload, 'invalid_signature'))->toBeFalse();
});
