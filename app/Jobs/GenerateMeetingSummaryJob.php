<?php

namespace App\Jobs;

use App\Models\MeetingMinutes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;

use function Laravel\Ai\agent;

class GenerateMeetingSummaryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public MeetingMinutes $minutes
    ) {
        $this->onConnection('redis');
    }

    public function handle(): void
    {
        if (empty($this->minutes->transcript)) {
            $this->minutes->update(['summary_status' => 'failed']);

            return;
        }

        $this->minutes->update(['summary_status' => 'processing']);

        try {
            $response = (string) agent(
                instructions: $this->buildSystemPrompt(),
            )->prompt(
                prompt: $this->buildPrompt(),
                provider: Lab::xAI,
                model: 'grok-3-mini-fast',
                timeout: 120,
            );

            $this->minutes->update([
                'executive_summary' => $response,
                'summary_status' => 'completed',
            ]);
        } catch (\Exception $e) {
            Log::error('GenerateMeetingSummaryJob failed', [
                'minutes_id' => $this->minutes->id,
                'error' => $e->getMessage(),
            ]);

            $this->minutes->update(['summary_status' => 'failed']);

            throw $e;
        }
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a corporate secretary generating an executive summary of meeting minutes.

Format the summary with these sections:
- **Meeting Overview**: Date, type, attendees
- **Key Topics Discussed**: Bullet points of main topics
- **Decisions Made**: Any formal decisions or votes
- **Action Items**: Tasks assigned with responsible parties
- **Next Steps**: Follow-up items and next meeting date if mentioned

Keep the summary concise but comprehensive. Use professional corporate language.
PROMPT;
    }

    private function buildPrompt(): string
    {
        $attendees = $this->minutes->attendees
            ? implode(', ', $this->minutes->attendees)
            : 'Not recorded';

        return <<<PROMPT
Please generate an executive summary for the following meeting:

**Title:** {$this->minutes->title}
**Date:** {$this->minutes->meeting_date->format('F j, Y')}
**Type:** {$this->minutes->meeting_type}
**Attendees:** {$attendees}

**Transcript:**
{$this->minutes->transcript}
PROMPT;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateMeetingSummaryJob permanently failed', [
            'minutes_id' => $this->minutes->id,
            'error' => $exception->getMessage(),
        ]);

        $this->minutes->update(['summary_status' => 'failed']);
    }
}
