<?php

use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\SmsConversation;
use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone);
        $mock->shouldReceive('sendSms')->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
        $mock->shouldReceive('sendMms')->andReturn(['success' => true, 'sid' => 'SM456', 'error' => null]);
    });
});

it('routes keyword message to matching SmsBotFlow', function () {
    $flow = SmsBotFlow::factory()->active()->keyword('COLOR')->create();
    SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What color do you want?',
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035551234',
        'Body' => 'COLOR',
    ]);

    $response->assertStatus(200);

    $conversation = SmsConversation::where('phone', '+13035551234')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($flow->id);
    expect($conversation->status)->toBe('in_progress');
});

it('routes keyword case-insensitively', function () {
    $flow = SmsBotFlow::factory()->active()->keyword('COLOR')->create();
    SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What color?',
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035552222',
        'Body' => 'color',
    ]);

    $response->assertStatus(200);

    $conversation = SmsConversation::where('phone', '+13035552222')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($flow->id);
});

it('does not match partial keywords', function () {
    SmsBotFlow::factory()->active()->keyword('COLOR')->create();

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035553333',
        'Body' => 'COLORFUL',
    ]);

    $response->assertStatus(200);

    // Should NOT create a bot conversation
    $conversation = SmsConversation::where('phone', '+13035553333')->first();
    expect($conversation)->toBeNull();
});

it('falls back to cold inbound routing when no keyword match', function () {
    SmsBotFlow::factory()->active()->keyword('COLOR')->create();

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035554444',
        'Body' => 'hello',
    ]);

    $response->assertStatus(200);

    // Should NOT create a bot conversation
    $conversation = SmsConversation::where('phone', '+13035554444')->first();
    expect($conversation)->toBeNull();
});

it('skips keyword routing when active conversation exists', function () {
    $flow = SmsBotFlow::factory()->active()->keyword('COLOR')->create();
    $questionStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What color?',
    ]);

    // Create an active conversation
    SmsConversation::factory()->create([
        'flow_id' => $flow->id,
        'phone' => '+13035555555',
        'status' => 'in_progress',
        'current_step_id' => $questionStep->id,
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035555555',
        'Body' => 'COLOR',
    ]);

    $response->assertStatus(200);

    // Should still have just 1 conversation (the existing one)
    expect(SmsConversation::where('phone', '+13035555555')->count())->toBe(1);
});

it('passes media URLs to bot flow service', function () {
    $flow = SmsBotFlow::factory()->active()->keyword('COLOR')->create();
    SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What color?',
    ]);

    // Start a conversation first
    $convo = SmsConversation::factory()->create([
        'flow_id' => $flow->id,
        'phone' => '+13035556666',
        'status' => 'in_progress',
        'current_step_id' => SmsBotStep::where('flow_id', $flow->id)->first()->id,
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => '+13035556666',
        'Body' => 'Blue walls',
        'NumMedia' => '1',
        'MediaUrl0' => 'https://api.twilio.com/media/photo.jpg',
    ]);

    $response->assertStatus(200);

    // The response was processed (answer stored)
    $convo->refresh();
    expect($convo->answers)->not->toBeEmpty();
});
