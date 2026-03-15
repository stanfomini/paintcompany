<?php

use App\Models\ProjectPreview;
use App\Models\SmsConversation;
use App\Services\SmsEngagementBotService;
use App\Services\TwilioService;

beforeEach(function () {
    // Mock TwilioService for webhook signature validation
    $this->twilio = Mockery::mock(TwilioService::class);
    $this->twilio->shouldReceive('validateWebhookSignature')->andReturn(true);
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

it('extracts MMS media URLs from webhook request', function () {
    $preview = ProjectPreview::factory()->create(['phone' => '+13035551234']);
    $convo = SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_INITIATED,
        'expires_at' => now()->addDays(7),
    ]);

    // Mock the bot service to verify it receives media URLs
    $botService = Mockery::mock(SmsEngagementBotService::class);
    $botService->shouldReceive('handleInbound')
        ->once()
        ->withArgs(function ($phone, $body, $mediaUrls) {
            return $phone === '+13035551234'
                && count($mediaUrls) === 1
                && str_contains($mediaUrls[0], 'twilio.com');
        });
    app()->instance(SmsEngagementBotService::class, $botService);

    $response = $this->post('/webhooks/twilio/sms', [
        'From' => '+13035551234',
        'Body' => '',
        'NumMedia' => '1',
        'MediaUrl0' => 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages/MM123/Media/ME123',
    ]);

    $response->assertOk();
});

it('does not reject MMS with empty body', function () {
    $preview = ProjectPreview::factory()->create(['phone' => '+13035551234']);
    SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_INITIATED,
        'expires_at' => now()->addDays(7),
    ]);

    $botService = Mockery::mock(SmsEngagementBotService::class);
    $botService->shouldReceive('handleInbound')->once();
    app()->instance(SmsEngagementBotService::class, $botService);

    $response = $this->post('/webhooks/twilio/sms', [
        'From' => '+13035551234',
        'Body' => '',
        'NumMedia' => '1',
        'MediaUrl0' => 'https://api.twilio.com/media/123',
    ]);

    $response->assertOk();
});

it('routes to bot when active conversation exists', function () {
    $preview = ProjectPreview::factory()->create(['phone' => '+13035551234']);
    SmsConversation::factory()->create([
        'project_preview_id' => $preview->id,
        'phone' => '+13035551234',
        'current_state' => SmsConversation::STATE_INITIATED,
        'expires_at' => now()->addDays(7),
    ]);

    $botService = Mockery::mock(SmsEngagementBotService::class);
    $botService->shouldReceive('handleInbound')->once();
    app()->instance(SmsEngagementBotService::class, $botService);

    $response = $this->post('/webhooks/twilio/sms', [
        'From' => '+13035551234',
        'Body' => 'Hello!',
        'NumMedia' => '0',
    ]);

    $response->assertOk();
});

it('falls through to normal routing when no active bot conversation', function () {
    // No SmsConversation exists for this phone
    $response = $this->post('/webhooks/twilio/sms', [
        'From' => '+13039999999',
        'Body' => 'Hello!',
        'NumMedia' => '0',
    ]);

    // Should not error — falls through to existing routing logic
    $response->assertOk();
});
