<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\FacebookMessengerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $this->assignedUser = User::factory()->internal()->create();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'default_assigned_to' => $this->assignedUser->id,
        'page_access_token' => 'test-token',
    ]);
    MessengerSetting::clearInstanceCache();

    Http::fake(['*' => Http::response(['message_id' => 'mid.test'])]);
});

afterEach(fn () => MessengerSetting::clearInstanceCache());

it('does not start new bot flow when PSID has completed conversation with customer', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $thread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_RETURNING',
        'customer_id' => $customer->id,
    ]);

    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_RETURNING',
        'customer_id' => $customer->id,
        'thread_id' => $thread->id,
    ]);

    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_RETURNING'],
            'message' => ['text' => 'Hey I have another question', 'mid' => 'mid.return'],
        ]],
    ]);

    expect(MessengerConversation::where('facebook_psid', 'PSID_RETURNING')->count())->toBe(1);
    expect(ChatMessage::where('thread_id', $thread->id)->count())->toBe(1);
    expect(Customer::count())->toBe(1);
    expect(Deal::count())->toBe(0);
});

it('does not start new bot flow for returning postback user', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $thread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_POSTBACK',
        'customer_id' => $customer->id,
    ]);

    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_POSTBACK',
        'customer_id' => $customer->id,
        'thread_id' => $thread->id,
    ]);

    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_POSTBACK'],
            'postback' => ['payload' => 'GET_STARTED', 'title' => 'Get Started'],
        ]],
    ]);

    expect(MessengerConversation::where('facebook_psid', 'PSID_POSTBACK')->count())->toBe(1);
});

it('still starts bot flow for brand new PSID', function () {
    Notification::fake();

    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_BRAND_NEW'],
            'message' => ['text' => 'Hi there', 'mid' => 'mid.new'],
        ]],
    ]);

    expect(MessengerConversation::where('facebook_psid', 'PSID_BRAND_NEW')->count())->toBe(1);
});

it('bypasses returning customer check when bot_test_mode is enabled', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $thread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_TEST_MODE',
        'customer_id' => $customer->id,
    ]);

    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_TEST_MODE',
        'customer_id' => $customer->id,
        'thread_id' => $thread->id,
    ]);

    // Enable test mode
    MessengerSetting::getInstance()->update(['bot_test_mode' => true]);
    MessengerSetting::clearInstanceCache();

    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_TEST_MODE'],
            'message' => ['text' => 'Testing the bot again', 'mid' => 'mid.testmode'],
        ]],
    ]);

    // Should have created a second conversation (bot started fresh)
    expect(MessengerConversation::where('facebook_psid', 'PSID_TEST_MODE')->count())->toBe(2);
});

it('still starts bot flow for PSID with completed conversation but no customer', function () {
    Notification::fake();

    $thread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_NO_CUSTOMER',
    ]);

    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_NO_CUSTOMER',
        'customer_id' => null,
        'thread_id' => $thread->id,
    ]);

    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_NO_CUSTOMER'],
            'message' => ['text' => 'Hello', 'mid' => 'mid.nocust'],
        ]],
    ]);

    expect(MessengerConversation::where('facebook_psid', 'PSID_NO_CUSTOMER')->count())->toBe(2);
});
