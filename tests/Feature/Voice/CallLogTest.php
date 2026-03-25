<?php

use App\Models\CallLog;
use App\Models\Customer;
use App\Models\User;

it('creates a call log via factory', function () {
    $callLog = CallLog::factory()->create();

    expect($callLog)->toBeInstanceOf(CallLog::class)
        ->and($callLog->ulid)->not->toBeEmpty()
        ->and($callLog->twilio_call_sid)->not->toBeEmpty()
        ->and($callLog->direction)->toBe('inbound')
        ->and($callLog->status)->toBe('completed');
});

it('auto-generates ulid on creation', function () {
    $callLog = CallLog::factory()->create(['ulid' => null]);

    expect($callLog->ulid)->not->toBeEmpty()
        ->and(strlen($callLog->ulid))->toBe(26);
});

it('has customer relationship', function () {
    $customer = Customer::factory()->create();
    $callLog = CallLog::factory()->create(['customer_id' => $customer->id]);

    expect($callLog->customer->id)->toBe($customer->id);
});

it('has caller user relationship', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->create(['caller_user_id' => $user->id]);

    expect($callLog->callerUser->id)->toBe($user->id);
});

it('has answered by user relationship', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->create(['answered_by_user_id' => $user->id]);

    expect($callLog->answeredByUser->id)->toBe($user->id);
});

it('filters inbound calls with scope', function () {
    CallLog::factory()->create(['direction' => 'inbound']);
    CallLog::factory()->create(['direction' => 'outbound']);

    expect(CallLog::inbound()->count())->toBe(1);
});

it('filters outbound calls with scope', function () {
    CallLog::factory()->create(['direction' => 'inbound']);
    CallLog::factory()->create(['direction' => 'outbound']);

    expect(CallLog::outbound()->count())->toBe(1);
});

it('filters missed calls with scope', function () {
    CallLog::factory()->create(['status' => 'completed']);
    CallLog::factory()->missed()->create();
    CallLog::factory()->voicemail()->create();

    expect(CallLog::missed()->count())->toBe(2);
});

it('filters by customer with scope', function () {
    $customer = Customer::factory()->create();
    CallLog::factory()->create(['customer_id' => $customer->id]);
    CallLog::factory()->create(['customer_id' => null]);

    expect(CallLog::forCustomer($customer->id)->count())->toBe(1);
});

it('detects voicemail', function () {
    $voicemail = CallLog::factory()->voicemail()->create();
    $completed = CallLog::factory()->completed()->create();

    expect($voicemail->isVoicemail())->toBeTrue()
        ->and($completed->isVoicemail())->toBeFalse();
});

it('detects recording', function () {
    $withRecording = CallLog::factory()->withRecording()->create();
    $withoutRecording = CallLog::factory()->create(['recording_url' => null]);

    expect($withRecording->hasRecording())->toBeTrue()
        ->and($withoutRecording->hasRecording())->toBeFalse();
});

it('formats duration as mm:ss', function () {
    $callLog = CallLog::factory()->create(['duration_seconds' => 125]);

    expect($callLog->getFormattedDuration())->toBe('2:05');
});

it('formats duration as hh:mm:ss for long calls', function () {
    $callLog = CallLog::factory()->create(['duration_seconds' => 3725]);

    expect($callLog->getFormattedDuration())->toBe('1:02:05');
});

it('formats zero duration', function () {
    $callLog = CallLog::factory()->create(['duration_seconds' => 0]);

    expect($callLog->getFormattedDuration())->toBe('0:00');
});

it('formats null duration as zero', function () {
    $callLog = CallLog::factory()->create(['duration_seconds' => null]);

    expect($callLog->getFormattedDuration())->toBe('0:00');
});

it('casts metadata as array', function () {
    $callLog = CallLog::factory()->create(['metadata' => ['key' => 'value']]);

    expect($callLog->metadata)->toBeArray()
        ->and($callLog->metadata['key'])->toBe('value');
});

it('casts timestamps as datetime', function () {
    $callLog = CallLog::factory()->create();

    expect($callLog->started_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

it('returns raw recording URL with .mp3 for twilio URLs', function () {
    $callLog = CallLog::factory()->create([
        'recording_url' => 'https://api.twilio.com/2010-04-01/Accounts/ACtest/Recordings/REtest',
    ]);

    expect($callLog->getRawRecordingUrl())->toBe(
        'https://api.twilio.com/2010-04-01/Accounts/ACtest/Recordings/REtest.mp3'
    );
});

it('does not double-append .mp3 to raw recording URL', function () {
    $callLog = CallLog::factory()->create([
        'recording_url' => 'https://api.twilio.com/recordings/REtest.mp3',
    ]);

    expect($callLog->getRawRecordingUrl())->toBe(
        'https://api.twilio.com/recordings/REtest.mp3'
    );
});

it('returns null for empty recording URL', function () {
    $callLog = CallLog::factory()->create(['recording_url' => null]);

    expect($callLog->getPlayableRecordingUrl())->toBeNull();
    expect($callLog->getRawRecordingUrl())->toBeNull();
});

it('returns non-twilio raw URLs unchanged', function () {
    $callLog = CallLog::factory()->create([
        'recording_url' => 'https://example.com/recording.wav',
    ]);

    expect($callLog->getRawRecordingUrl())->toBe('https://example.com/recording.wav');
});

it('returns proxy route URL for playable recording', function () {
    $callLog = CallLog::factory()->create([
        'recording_url' => 'https://api.twilio.com/recordings/REtest.mp3',
    ]);

    expect($callLog->getPlayableRecordingUrl())->toBe(
        route('calls.recording', $callLog)
    );
});
