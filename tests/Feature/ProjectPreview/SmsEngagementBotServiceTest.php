<?php

use App\Models\CompanySetting;
use App\Models\ProjectPreview;
use App\Models\SmsBotFlow;
use App\Models\SmsConversation;
use App\Services\SmsEngagementBotService;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Mock TwilioService to prevent actual SMS sending
    $this->twilio = Mockery::mock(TwilioService::class);
    $this->twilio->shouldReceive('formatE164')->andReturnUsing(function ($phone) {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return null;
    });
    $this->twilio->shouldReceive('sendSms')->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    $this->twilio->shouldReceive('sendMms')->andReturn(['success' => true, 'sid' => 'SM456', 'error' => null]);
    app()->instance(TwilioService::class, $this->twilio);
});

it('returns null when bot is disabled', function () {
    CompanySetting::getInstance()->update(['sms_bot_enabled' => false]);

    $service = app(SmsEngagementBotService::class);
    $result = $service->initiate('+13035551234');

    expect($result)->toBeNull();
});

it('returns null when no active flow exists', function () {
    CompanySetting::getInstance()->update(['sms_bot_enabled' => true]);
    // No flows created

    $service = app(SmsEngagementBotService::class);
    $result = $service->initiate('+13035551234');

    expect($result)->toBeNull();
});

it('initiates conversation with greeting message', function () {
    CompanySetting::getInstance()->update(['sms_bot_enabled' => true]);
    $flow = SmsBotFlow::factory()->active()->create([
        'trigger_type' => 'new_lead',
        'greeting_message' => 'Hey {name}! Welcome from {company}!',
    ]);

    $service = app(SmsEngagementBotService::class);
    $convo = $service->initiate('+13035551234');

    expect($convo)->toBeInstanceOf(SmsConversation::class)
        ->and($convo->current_state)->toBe(SmsConversation::STATE_INITIATED)
        ->and($convo->flow_id)->toBe($flow->id)
        ->and($convo->phone)->toBe('+13035551234');
});

it('returns existing conversation instead of creating duplicate', function () {
    CompanySetting::getInstance()->update(['sms_bot_enabled' => true]);
    SmsBotFlow::factory()->active()->create(['trigger_type' => 'new_lead']);

    $existing = SmsConversation::factory()->create([
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_INITIATED,
        'expires_at' => now()->addDays(7),
    ]);

    $service = app(SmsEngagementBotService::class);
    $result = $service->initiate('+13035551234');

    expect($result->id)->toBe($existing->id);
});

// State machine tests

it('transitions from INITIATED to NUDGE_PHOTO on text-only message', function () {
    CompanySetting::getInstance()->update(['sms_bot_enabled' => true]);
    $flow = SmsBotFlow::factory()->active()->create(['trigger_type' => 'new_lead']);

    $preview = ProjectPreview::factory()->create(['phone' => '+13035551234']);
    $convo = SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'flow_id' => $flow->id,
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_INITIATED,
        'expires_at' => now()->addDays(7),
    ]);

    $service = app(SmsEngagementBotService::class);
    $service->handleInbound('+13035551234', 'Hello');

    expect($convo->fresh()->current_state)->toBe(SmsConversation::STATE_NUDGE_PHOTO);
});

it('transitions from INITIATED to PHOTO_RECEIVED on photo', function () {
    CompanySetting::getInstance()->update(['sms_bot_enabled' => true]);
    $flow = SmsBotFlow::factory()->active()->create(['trigger_type' => 'new_lead']);

    $preview = ProjectPreview::factory()->create(['phone' => '+13035551234']);
    $convo = SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'flow_id' => $flow->id,
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_INITIATED,
        'expires_at' => now()->addDays(7),
    ]);

    Http::fake(['*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/jpeg'])]);
    Storage::fake('public');

    $service = app(SmsEngagementBotService::class);
    $service->handleInbound('+13035551234', '', ['https://api.twilio.com/media/123.jpg']);

    expect($convo->fresh()->current_state)->toBe(SmsConversation::STATE_PHOTO_RECEIVED)
        ->and($convo->fresh()->photo_count)->toBeGreaterThan(0);
});

it('does nothing when in HUMAN_TAKEOVER state', function () {
    $preview = ProjectPreview::factory()->create(['phone' => '+13035551234']);
    $convo = SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_HUMAN_TAKEOVER,
        'expires_at' => now()->addDays(7),
    ]);

    $service = app(SmsEngagementBotService::class);
    $service->handleInbound('+13035551234', 'Hello');

    // State should not change
    expect($convo->fresh()->current_state)->toBe(SmsConversation::STATE_HUMAN_TAKEOVER);
});

it('triggers human takeover', function () {
    $preview = ProjectPreview::factory()->create();
    $convo = SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'current_state' => SmsConversation::STATE_INITIATED,
    ]);

    $service = app(SmsEngagementBotService::class);
    $service->triggerHumanTakeover($convo);

    expect($convo->fresh()->current_state)->toBe(SmsConversation::STATE_HUMAN_TAKEOVER);
});

it('stores room label and transitions from VISUALIZATION_READY to ROOM_LABELED', function () {
    $flow = SmsBotFlow::factory()->active()->create(['trigger_type' => 'new_lead']);
    $preview = ProjectPreview::factory()->create(['phone' => '+13035551234', 'rooms_data' => []]);
    $convo = SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'flow_id' => $flow->id,
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_VISUALIZATION_READY,
        'answers' => [],
        'expires_at' => now()->addDays(7),
    ]);

    $service = app(SmsEngagementBotService::class);
    $service->handleInbound('+13035551234', 'Living Room');

    $freshConvo = $convo->fresh();
    expect($freshConvo->current_state)->toBe(SmsConversation::STATE_ROOM_LABELED)
        ->and($freshConvo->answers['room_label'])->toBe('Living Room');
});

it('normalizes scope type from free text', function () {
    $flow = SmsBotFlow::factory()->active()->create(['trigger_type' => 'new_lead']);
    $preview = ProjectPreview::factory()->create(['phone' => '+13035551234']);
    $convo = SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'flow_id' => $flow->id,
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_ROOM_LABELED,
        'answers' => [],
        'expires_at' => now()->addDays(7),
    ]);

    $service = app(SmsEngagementBotService::class);
    $service->handleInbound('+13035551234', 'Just the inside');

    expect($preview->fresh()->scope_type)->toBe('interior');
});
