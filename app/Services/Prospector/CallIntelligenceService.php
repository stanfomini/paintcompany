<?php

namespace App\Services\Prospector;

use App\Events\Prospector\CallIntelligenceReady;
use App\Jobs\Prospector\ProcessCallIntelligenceJob;
use App\Models\Prospector\Call;
use App\Models\Prospector\CallIntelligence;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

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
            $analysis = $this->analyze($transcription, $call);
            $intelligence->update(array_merge($analysis, [
                'model_version' => 'claude-haiku-4-5-20251001',
                'processed_at' => now(),
            ]));
        } else {
            $intelligence->update([
                'processed_at' => now(),
            ]);
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
     * Analyze the transcription using Claude AI.
     *
     * @return array<string, mixed>
     */
    public function analyze(string $transcription, Call $call): array
    {
        $prompt = $this->getAnalysisPrompt($transcription, $call);

        try {
            $response = Prism::text()
                ->using(Provider::Anthropic, 'claude-haiku-4-5-20251001')
                ->withSystemPrompt('You are a sales call analyst for a painting company. Respond ONLY with valid JSON, no other text.')
                ->withPrompt($prompt)
                ->asText();

            $parsed = json_decode($response->text, true);

            if (! is_array($parsed)) {
                Log::warning('CallIntelligence: AI response was not valid JSON', [
                    'call_id' => $call->id,
                    'response' => $response->text,
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
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build the analysis prompt with call context.
     */
    public function getAnalysisPrompt(string $transcription, Call $call): string
    {
        $call->loadMissing(['agent.user', 'contact', 'company']);

        $agentName = $call->agent?->user?->name ?? 'Unknown Agent';
        $contactName = $call->contact
            ? $call->contact->getFullName()
            : 'Unknown Contact';
        $companyName = $call->company?->name ?? 'Unknown Company';
        $disposition = $call->disposition ?? 'none';

        return <<<PROMPT
        Analyze this sales call transcription from a painting company.

        Context:
        - Agent: {$agentName}
        - Contact: {$contactName}
        - Company: {$companyName}
        - Disposition: {$disposition}

        Transcription:
        {$transcription}

        Return a JSON object with these fields:
        {
            "sentiment_overall": <float between -1.0 and 1.0, where -1 is very negative and 1 is very positive>,
            "sentiment_by_segment": [{"segment": <index>, "sentiment": <float -1 to 1>}],
            "lead_score": <integer 0-100, where 0 is cold and 100 is hot>,
            "scoring_factors": [{"factor": "<description>", "weight": <integer points>}],
            "keywords": ["<relevant keyword>", ...],
            "objections": ["<objection raised by contact>", ...],
            "action_items": ["<follow-up action>", ...],
            "summary": "<2-3 sentence summary of the call>",
            "coaching_notes": "<1-2 suggestions for the agent to improve>",
            "speaker_labels": {"speaker_1": "<role>", "speaker_2": "<role>"}
        }

        Scoring guidelines:
        - 0-30 (cold): No interest, wrong number, do-not-call, hang up
        - 31-60 (warm): Some interest, needs follow-up, asked questions, wants quote
        - 61-100 (hot): Strong interest, ready to schedule, accepted estimate, referred others

        Respond ONLY with the JSON object.
        PROMPT;
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
