<?php

use App\Models\MessengerSetting;

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
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('Webhook Verification (GET)', function () {
    it('verifies webhook with correct token', function () {
        $response = $this->get('/webhook/facebook/receive?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge' => 'challenge-string-123',
        ]));

        $response->assertOk();
        $response->assertSee('challenge-string-123');
    });

    it('rejects webhook verification with wrong token', function () {
        $response = $this->get('/webhook/facebook/receive?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong-token',
            'hub_challenge' => 'challenge-string-123',
        ]));

        $response->assertForbidden();
    });

    it('rejects webhook verification without required params', function () {
        $response = $this->get('/webhook/facebook/receive');

        $response->assertForbidden();
    });

    it('rejects webhook verification with wrong mode', function () {
        $response = $this->get('/webhook/facebook/receive?'.http_build_query([
            'hub_mode' => 'unsubscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge' => 'challenge-string-123',
        ]));

        $response->assertForbidden();
    });

    it('returns the challenge string as the response body', function () {
        $response = $this->get('/webhook/facebook/receive?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge' => 'my-unique-challenge-42',
        ]));

        expect($response->getContent())->toBe('my-unique-challenge-42');
    });
});

describe('Webhook Receive (POST)', function () {
    it('accepts messages with valid signature and returns EVENT_RECEIVED', function () {
        Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => Illuminate\Support\Facades\Http::response([
                'first_name' => 'Test',
                'last_name' => 'User',
                'profile_pic' => 'https://example.com/pic.jpg',
            ]),
        ]);

        $payload = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'id' => '123456',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => '9999999'],
                            'recipient' => ['id' => '123456'],
                            'timestamp' => time() * 1000,
                            'message' => [
                                'mid' => 'mid.test123',
                                'text' => 'Hello!',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-app-secret');

        $response = $this->call('POST', '/webhook/facebook/receive', [], [], [], [
            'HTTP_X_Hub_Signature_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertOk();
        expect($response->getContent())->toBe('EVENT_RECEIVED');
    });

    it('creates a chat thread and message from incoming webhook', function () {
        Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => Illuminate\Support\Facades\Http::response([
                'first_name' => 'John',
                'last_name' => 'Doe',
                'profile_pic' => 'https://example.com/john.jpg',
            ]),
        ]);

        $payload = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'id' => '123456',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => '5551234'],
                            'recipient' => ['id' => '123456'],
                            'timestamp' => time() * 1000,
                            'message' => [
                                'mid' => 'mid.abc123',
                                'text' => 'I need a quote',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-app-secret');

        $this->call('POST', '/webhook/facebook/receive', [], [], [], [
            'HTTP_X_Hub_Signature_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $thread = App\Models\ChatThread::where('external_contact_id', '5551234')
            ->where('channel', App\Models\ChatThread::CHANNEL_FACEBOOK)
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->type)->toBe(App\Models\ChatThread::TYPE_SUPPORT);
        expect($thread->subject)->toBe('John Doe');

        $message = $thread->messages()->first();
        expect($message)->not->toBeNull();
        expect($message->content)->toBe('I need a quote');
        expect($message->metadata['source'])->toBe('facebook_messenger');
        expect($message->metadata['psid'])->toBe('5551234');
    });

    it('rejects messages with invalid signature', function () {
        $payload = json_encode(['object' => 'page', 'entry' => []]);

        $response = $this->call('POST', '/webhook/facebook/receive', [], [], [], [
            'HTTP_X_Hub_Signature_256' => 'sha256=invalidsignature',
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertForbidden();
    });

    it('rejects messages without signature header', function () {
        $payload = json_encode(['object' => 'page', 'entry' => []]);

        $response = $this->call('POST', '/webhook/facebook/receive', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertForbidden();
    });

    it('handles multiple messaging events in a single entry', function () {
        Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => Illuminate\Support\Facades\Http::response([
                'first_name' => 'Multi',
                'last_name' => 'User',
            ]),
        ]);

        $payload = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'id' => '123456',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => '1111111'],
                            'recipient' => ['id' => '123456'],
                            'message' => ['mid' => 'mid.1', 'text' => 'First message'],
                        ],
                        [
                            'sender' => ['id' => '1111111'],
                            'recipient' => ['id' => '123456'],
                            'message' => ['mid' => 'mid.2', 'text' => 'Second message'],
                        ],
                    ],
                ],
            ],
        ]);

        $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-app-secret');

        $this->call('POST', '/webhook/facebook/receive', [], [], [], [
            'HTTP_X_Hub_Signature_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $thread = App\Models\ChatThread::where('external_contact_id', '1111111')
            ->where('channel', App\Models\ChatThread::CHANNEL_FACEBOOK)
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->messages()->count())->toBe(2);
    });
});
