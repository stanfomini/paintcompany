<?php

use App\Models\Prospector\Call;
use App\Models\Prospector\CallIntelligence;
use App\Models\Prospector\Company;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\CallIntelligenceService;
use App\Services\PythonBridgeService;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->service = app(CallIntelligenceService::class);
});

// ── Model Tests ──

it('creates call intelligence with factory', function () {
    $intel = CallIntelligence::factory()->create();

    expect($intel)->toBeInstanceOf(CallIntelligence::class);
    expect($intel->call_id)->not->toBeNull();
});

it('creates processed intelligence with all fields', function () {
    $intel = CallIntelligence::factory()->processed()->create();

    expect($intel->isProcessed())->toBeTrue();
    expect($intel->hasTranscription())->toBeTrue();
    expect($intel->transcription_raw)->not->toBeNull();
    expect($intel->lead_score)->toBeInt();
    expect($intel->keywords)->toBeArray();
    expect($intel->model_version)->toBe('claude-haiku-4-5-20251001');
});

it('has correct call relation', function () {
    $call = Call::factory()->completed()->create(['company_id' => $this->company->id]);
    $intel = CallIntelligence::factory()->processed()->create(['call_id' => $call->id]);

    expect($intel->call->id)->toBe($call->id);
});

it('returns correct score labels', function () {
    $cold = CallIntelligence::factory()->cold()->create();
    expect($cold->getScoreLabel())->toBe('cold');

    $warm = CallIntelligence::factory()->warm()->create();
    expect($warm->getScoreLabel())->toBe('warm');

    $hot = CallIntelligence::factory()->hot()->create();
    expect($hot->getScoreLabel())->toBe('hot');

    $unknown = CallIntelligence::factory()->create(['lead_score' => null]);
    expect($unknown->getScoreLabel())->toBe('unknown');
});

it('reports isProcessed correctly', function () {
    $unprocessed = CallIntelligence::factory()->create();
    expect($unprocessed->isProcessed())->toBeFalse();

    $processed = CallIntelligence::factory()->processed()->create();
    expect($processed->isProcessed())->toBeTrue();
});

// ── Service Tests ──

it('returns null transcription when no recording URL', function () {
    $call = Call::factory()->completed()->create([
        'company_id' => $this->company->id,
        'recording_url' => null,
    ]);

    $result = $this->service->transcribe($call);

    expect($result)->toBeNull();
});

it('returns null transcription when Python service is disabled', function () {
    $call = Call::factory()->completed()->create([
        'company_id' => $this->company->id,
        'recording_url' => 'https://api.twilio.com/recording.wav',
    ]);

    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CallIntelligenceService::class);
    $result = $service->transcribe($call);

    expect($result)->toBeNull();
});

it('builds analysis prompt with call context', function () {
    $call = Call::factory()->completed()->create([
        'company_id' => $this->company->id,
        'disposition' => 'interested',
    ]);
    $call->loadMissing(['agent.user', 'contact', 'company']);

    $prompt = $this->service->getAnalysisPrompt('Hello, I need my house painted.', $call);

    expect($prompt)->toContain('interested');
    expect($prompt)->toContain('Transcription:');
    expect($prompt)->toContain('Hello, I need my house painted.');
    expect($prompt)->toContain($this->company->name);
});

it('handles processCall creating intelligence record', function () {
    $call = Call::factory()->completed()->create([
        'company_id' => $this->company->id,
        'recording_url' => null,
    ]);

    $intel = $this->service->processCall($call);

    expect($intel)->toBeInstanceOf(CallIntelligence::class);
    expect($intel->call_id)->toBe($call->id);
    expect($intel->isProcessed())->toBeTrue();
});
