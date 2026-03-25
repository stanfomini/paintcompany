<?php

use App\Models\CallGroup;
use App\Models\CallGroupMember;
use App\Models\CallLog;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\TwilioVoiceService;

beforeEach(function () {
    CompanySetting::clearInstanceCache();
    config([
        'services.twilio.enabled' => true,
        'services.twilio.sid' => 'test-sid',
        'services.twilio.token' => 'test-token',
        'services.twilio.from' => '+15551234567',
        'services.twilio.voice_enabled' => true,
    ]);
});

afterEach(function () {
    CompanySetting::clearInstanceCache();
});

it('generates ring group twiml with valid xml', function () {
    $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
    $group = CallGroup::factory()->create(['ring_timeout_seconds' => 25]);
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateRingGroupTwiml($group, $callLog);

    $xml = simplexml_load_string($twiml);
    expect($xml)->not->toBeFalse('TwiML should be valid XML')
        ->and($twiml)->toContain('<Dial')
        ->and($twiml)->toContain('<Number');
});

it('uses company number as caller id for ring group', function () {
    $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
    $group = CallGroup::factory()->create();
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $callLog = CallLog::factory()->create(['from_number' => '+17205559999']);

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateRingGroupTwiml($group, $callLog);

    expect($twiml)->toContain('callerId="+15551234567"')
        ->and($twiml)->not->toContain('callerId="+17205559999"');
});

it('includes whisper url on number elements in ring group', function () {
    $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
    $group = CallGroup::factory()->create();
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateRingGroupTwiml($group, $callLog);

    expect($twiml)->toContain('url=')
        ->and($twiml)->toContain('whisper')
        ->and($twiml)->toContain($callLog->ulid);
});

it('includes correct forwarding numbers from group members', function () {
    $user1 = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
    $user2 = User::factory()->internal()->create(['forwarding_number' => '+13035552222']);
    $group = CallGroup::factory()->create();
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user1->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user2->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateRingGroupTwiml($group, $callLog);

    expect($twiml)->toContain('+13035551111')
        ->and($twiml)->toContain('+13035552222');
});

it('sets correct timeout from group settings', function () {
    $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
    $group = CallGroup::factory()->create(['ring_timeout_seconds' => 30]);
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateRingGroupTwiml($group, $callLog);

    expect($twiml)->toContain('timeout="30"');
});

it('sets action url for dial-status callback', function () {
    $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
    $group = CallGroup::factory()->create();
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateRingGroupTwiml($group, $callLog);

    expect($twiml)->toContain('dial-status')
        ->and($twiml)->toContain($callLog->ulid);
});

it('falls back to voicemail when group has no active numbers', function () {
    $group = CallGroup::factory()->create();
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateRingGroupTwiml($group, $callLog);

    // Should produce voicemail TwiML instead (Say + Record)
    expect($twiml)->toContain('<Say')
        ->and($twiml)->toContain('<Record');
});

it('generates voicemail twiml with say and record elements', function () {
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateVoicemailTwiml($callLog);

    $xml = simplexml_load_string($twiml);
    expect($xml)->not->toBeFalse('TwiML should be valid XML')
        ->and($twiml)->toContain('<Say')
        ->and($twiml)->toContain('<Record')
        ->and($twiml)->toContain('<Hangup');
});

it('uses custom greeting text from company setting', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'voicemail_greeting_type' => 'tts',
        'voicemail_greeting_text' => 'Hello from Apex Painting!',
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateVoicemailTwiml($callLog);

    expect($twiml)->toContain('Hello from Apex Painting!');
});

it('plays uploaded audio file when greeting type is upload', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'voicemail_greeting_type' => 'upload',
        'voicemail_greeting_path' => 'voicemail-greetings/custom-greeting.mp3',
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateVoicemailTwiml($callLog);

    expect($twiml)->toContain('<Play')
        ->and($twiml)->toContain('custom-greeting.mp3')
        ->and($twiml)->not->toContain('We are unable to take your call');
});

it('uses default greeting when none configured', function () {
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateVoicemailTwiml($callLog);

    expect($twiml)->toContain('We are unable to take your call right now');
});

it('generates ivr menu twiml with gather and menu options', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'ivr_enabled' => true,
        'ivr_menu' => [
            'options' => [
                ['digit' => '1', 'label' => 'Sales', 'call_group_id' => 1],
                ['digit' => '2', 'label' => 'Support', 'call_group_id' => 2],
            ],
            'extension_prompt' => false,
            'timeout_action' => 'ring_default',
        ],
    ]);
    CompanySetting::clearInstanceCache();

    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateIvrMenuTwiml($callLog);

    $xml = simplexml_load_string($twiml);
    expect($xml)->not->toBeFalse('TwiML should be valid XML')
        ->and($twiml)->toContain('<Gather')
        ->and($twiml)->toContain('Press 1 for Sales')
        ->and($twiml)->toContain('Press 2 for Support');
});

it('reports enabled correctly when both twilio and voice are enabled', function () {
    $service = app(TwilioVoiceService::class);

    expect($service->isEnabled())->toBeTrue();
});

it('reports disabled when voice is disabled', function () {
    config(['services.twilio.voice_enabled' => false]);
    $service = app(TwilioVoiceService::class);

    expect($service->isEnabled())->toBeFalse();
});

it('excludes inactive members from ring group twiml', function () {
    $activeUser = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
    $inactiveUser = User::factory()->internal()->create(['forwarding_number' => '+13035553333']);
    $group = CallGroup::factory()->create();
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $activeUser->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $inactiveUser->id,
        'is_active' => false,
        'sort_order' => 1,
    ]);
    $callLog = CallLog::factory()->create();

    $service = app(TwilioVoiceService::class);
    $twiml = $service->generateRingGroupTwiml($group, $callLog);

    expect($twiml)->toContain('+13035551111')
        ->and($twiml)->not->toContain('+13035553333');
});
