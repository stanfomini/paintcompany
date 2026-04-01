<?php

namespace App\Services\Prospector;

use App\Ai\Agents\CallAnalystAgent;
use App\Events\Prospector\CallIntelligenceReady;
use App\Jobs\Prospector\ProcessCallIntelligenceJob;
use App\Models\Prospector\AiSetting;
use App\Models\Prospector\Call;
use App\Models\Prospector\CallIntelligence;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Log;

class CallIntelligenceService
{
    /**
     * Run the full pipeline: transcribe -> analyze -> store.
     */
    public function processCall(Call $call): CallIntelligence
    {
        $intelligence = CallIntelligence::firstOrCreate(
            ['call_id' => $call->id],
        );

        $transcription = $this->transcribe($call, $intelligence);

        if ($transcription) {
            $settings = AiSetting::current();

            if ($settings->isConfigured()) {
                $analysis = $this->analyze($transcription, $call, $settings);
                $intelligence->update(array_merge($analysis, [
                    'model_version' => $settings->provider.'/'.$settings->model,
                    'processed_at' => now(),
                ]));
            } else {
                Log::warning('CallIntelligence: AI settings not configured, skipping analysis', [
                    'call_id' => $call->id,
                ]);
                $intelligence->update(['processed_at' => now()]);
            }
        } else {
            $intelligence->update(['processed_at' => now()]);
        }

        $intelligence->refresh();

        if ($call->agent_id) {
            CallIntelligenceReady::dispatch($intelligence, $call->agent_id);
        }

        return $intelligence;
    }

    /**
     * Transcribe the call recording via Python/Whisper service.
     */
    public function transcribe(Call $call, ?CallIntelligence $intelligence = null): ?string
    {
        if (! $call->recording_url) {
            Log::info('CallIntelligence: No recording URL for call', ['call_id' => $call->id]);

            return null;
        }

        $python = app(PythonBridgeService::class);

        if (! $python->isEnabled()) {
            Log::warning('CallIntelligence: Python service is not enabled');

            return null;
        }

        try {
            $result = $python->transcribeAudio($call->recording_url);

            if (isset($result['error']) || ! isset($result['text'])) {
                Log::error('CallIntelligence: Transcription failed', [
                    'call_id' => $call->id,
                    'result' => $result,
                ]);

                return null;
            }

            $transcription = $result['text'];
            $segments = $result['segments'] ?? null;

            if ($intelligence) {
                $intelligence->update([
                    'transcription_raw' => $transcription,
                    'transcription_segments' => $segments,
                ]);
            }

            return $transcription;
        } catch (\Exception $e) {
            Log::error('CallIntelligence: Transcription exception', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Analyze the transcription using Laravel AI SDK agent.
     *
     * @return array<string, mixed>
     */
    public function analyze(string $transcription, Call $call, ?AiSetting $settings = null): array
    {
        $settings ??= AiSetting::current();

        if (! $settings->isConfigured()) {
            return [];
        }

        // Inject DB credentials into the provider config at runtime
        config(["ai.providers.{$settings->provider}.key" => $settings->api_key]);

        try {
            $agent = new CallAnalystAgent($call);
            $prompt = $agent->buildPrompt($transcription);

            $response = $agent->prompt($prompt, provider: $settings->provider, model: $settings->model);

            $parsed = $response->structured;

            if (! is_array($parsed)) {
                Log::warning('CallIntelligence: AI response was not valid structured data', [
                    'call_id' => $call->id,
                ]);

                return [];
            }

            return [
                'sentiment_overall' => $this->clampDecimal($parsed['sentiment_overall'] ?? null, -1, 1),
                'sentiment_by_segment' => $parsed['sentiment_by_segment'] ?? null,
                'lead_score' => $this->clampInt($parsed['lead_score'] ?? null, 0, 100),
                'scoring_factors' => $parsed['scoring_factors'] ?? null,
                'keywords' => $parsed['keywords'] ?? null,
                'objections' => $parsed['objections'] ?? null,
                'action_items' => $parsed['action_items'] ?? null,
                'ai_summary' => $parsed['summary'] ?? null,
                'ai_coaching_notes' => $parsed['coaching_notes'] ?? null,
                'speaker_labels' => $parsed['speaker_labels'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('CallIntelligence: AI analysis failed', [
                'call_id' => $call->id,
                'provider' => $settings->provider,
                'model' => $settings->model,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Dispatch async processing for a call.
     */
    public function processCallAsync(Call $call): void
    {
        ProcessCallIntelligenceJob::dispatch($call);
    }

    private function clampDecimal(mixed $value, float $min, float $max): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return round(max($min, min($max, (float) $value)), 2);
    }

    private function clampInt(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (int) $value));
    }
}
