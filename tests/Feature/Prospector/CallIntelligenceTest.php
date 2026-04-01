<?php

use App\Ai\Agents\CallAnalystAgent;
use App\Jobs\Prospector\ProcessCallIntelligenceJob;
use App\Models\Prospector\AiSetting;
use App\Models\Prospector\Call;
use App\Models\Prospector\CallIntelligence;
use App\Models\Prospector\Company;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\CallIntelligenceService;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Queue;

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

// ── AiSetting Model Tests ──

it('creates AiSetting singleton with defaults', function () {
    AiSetting::query()->delete();

    $setting = AiSetting::current();

    expect($setting->provider)->toBe('xai');
    expect($setting->model)->toBe('grok-3-mini-fast');
    expect($setting->enabled)->toBeFalse();
    expect($setting->isConfigured())->toBeFalse();
});

it('reports configured when all fields are set', function () {
    $setting = AiSetting::factory()->create();

    expect($setting->isConfigured())->toBeTrue();
    expect($setting->getMaskedKey())->toContain('••••••••');
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

    $agent = new CallAnalystAgent($call);
    $prompt = $agent->buildPrompt('Hello, I need my house painted.');

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

it('skips analysis when AI settings are not configured', function () {
    AiSetting::query()->delete();
    AiSetting::factory()->unconfigured()->create();

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'recording_url' => 'https://example.com/recording.mp3',
    ]);

    $python = Mockery::mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('transcribeAudio')->andReturn([
        'text' => 'Hello this is a test call.',
        'segments' => [],
    ]);
    app()->instance(PythonBridgeService::class, $python);

    $service = app(CallIntelligenceService::class);
    $intelligence = $service->processCall($call);

    expect($intelligence->processed_at)->not->toBeNull();
    expect($intelligence->transcription_raw)->toBe('Hello this is a test call.');
    expect($intelligence->lead_score)->toBeNull();
});

it('analyzes call with AI agent when configured', function () {
    AiSetting::factory()->create();
    CallAnalystAgent::fake();

    $call = Call::factory()->completed()->create([
        'company_id' => $this->company->id,
        'recording_url' => 'https://example.com/recording.mp3',
    ]);

    $python = Mockery::mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->andReturn(true);
    $python->shouldReceive('transcribeAudio')->andReturn([
        'text' => 'Hi, I need my house painted. Can you give me a quote?',
        'segments' => [],
    ]);
    app()->instance(PythonBridgeService::class, $python);

    $service = app(CallIntelligenceService::class);
    $intelligence = $service->processCall($call);

    expect($intelligence->processed_at)->not->toBeNull();
    expect($intelligence->transcription_raw)->toBe('Hi, I need my house painted. Can you give me a quote?');
    expect($intelligence->model_version)->toStartWith('xai/');
});

// ── Webhook Dispatch Tests ──

it('dispatches ProcessCallIntelligenceJob when call completes', function () {
    Queue::fake();
    AiSetting::factory()->create();

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'twilio_call_sid' => 'CA_intelligence_trigger_1',
        'status' => 'in_progress',
        'recording_url' => 'https://example.com/recording.mp3',
    ]);

    $this->post(route('prospector.webhook.twilio.voice.status'), [
        'CallSid' => 'CA_intelligence_trigger_1',
        'CallStatus' => 'completed',
        'CallDuration' => '120',
    ]);

    Queue::assertPushed(ProcessCallIntelligenceJob::class, function ($job) use ($call) {
        return $job->call->id === $call->id;
    });
});

it('does not dispatch job when AI settings are not configured', function () {
    Queue::fake();
    AiSetting::query()->delete();
    AiSetting::factory()->unconfigured()->create();

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'twilio_call_sid' => 'CA_no_ai_trigger_1',
        'status' => 'in_progress',
    ]);

    $this->post(route('prospector.webhook.twilio.voice.status'), [
        'CallSid' => 'CA_no_ai_trigger_1',
        'CallStatus' => 'completed',
    ]);

    Queue::assertNotPushed(ProcessCallIntelligenceJob::class);
});
