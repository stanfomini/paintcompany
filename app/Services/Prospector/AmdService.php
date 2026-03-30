<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Call;
use Illuminate\Support\Facades\Log;

class AmdService
{
    /** AMD result values indicating a human answered. */
    private const HUMAN_RESULTS = ['human'];

    /** AMD result values indicating a machine answered. */
    private const MACHINE_RESULTS = ['machine_start', 'machine_end_beep', 'machine_end_silence', 'machine_end_other'];

    /**
     * Get Twilio AMD parameters for outbound call creation.
     *
     * These parameters are passed to the Twilio Calls API
     * to enable answering machine detection.
     *
     * @return array<string, mixed>
     */
    public function getAmdParams(): array
    {
        if (! config('prospector.defaults.amd_enabled', true)) {
            return [];
        }

        return [
            'MachineDetection' => 'DetectMessageEnd',
            'MachineDetectionTimeout' => 5,
            'MachineDetectionSpeechThreshold' => 2400,
            'MachineDetectionSpeechEndThreshold' => 1200,
            'MachineDetectionSilenceTimeout' => 5000,
            'AsyncAmd' => true,
            'AsyncAmdStatusCallback' => route('prospector.webhook.twilio.amd'),
            'AsyncAmdStatusCallbackMethod' => 'POST',
        ];
    }

    /**
     * Handle the AMD result callback from Twilio.
     *
     * Updates the call record with the AMD result and determines
     * the appropriate action (connect to agent or play voicemail).
     *
     * @param  string  $answeredBy  Twilio's AnsweredBy value: human, machine_start, machine_end_beep, machine_end_silence, machine_end_other, fax, unknown
     */
    public function handleAmdResult(Call $call, string $answeredBy): void
    {
        $normalizedResult = $this->normalizeResult($answeredBy);

        $call->update(['amd_result' => $normalizedResult]);

        Log::info('AMD result received', [
            'call_id' => $call->id,
            'twilio_call_sid' => $call->twilio_call_sid,
            'raw_answered_by' => $answeredBy,
            'normalized_result' => $normalizedResult,
            'is_human' => $this->isHuman($answeredBy),
        ]);
    }

    /**
     * Check if the AMD result indicates a human answered.
     */
    public function isHuman(string $answeredBy): bool
    {
        return in_array($answeredBy, self::HUMAN_RESULTS, true);
    }

    /**
     * Check if the AMD result indicates a machine answered.
     */
    public function isMachine(string $answeredBy): bool
    {
        return in_array($answeredBy, self::MACHINE_RESULTS, true);
    }

    /**
     * Normalize Twilio's various AMD result strings to simple categories.
     *
     * Twilio returns granular values (machine_start, machine_end_beep, etc.)
     * but we store a simplified version: human, machine, fax, unknown.
     */
    private function normalizeResult(string $answeredBy): string
    {
        if ($this->isHuman($answeredBy)) {
            return 'human';
        }

        if ($this->isMachine($answeredBy)) {
            return 'machine';
        }

        if ($answeredBy === 'fax') {
            return 'fax';
        }

        return 'unknown';
    }
}
