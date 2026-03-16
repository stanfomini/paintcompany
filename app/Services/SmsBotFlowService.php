<?php

namespace App\Services;

use App\Models\ChatThread;
use App\Models\CompanySetting;
use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\SmsConversation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SmsBotFlowService
{
    public function __construct(
        private TwilioService $twilioService
    ) {}

    /**
     * Start a new bot conversation for a phone number.
     */
    public function startConversation(string $phone, SmsBotFlow $flow, ?ChatThread $thread = null): SmsConversation
    {
        if (! $thread) {
            $thread = $this->createSmsBotThread($phone);
        }

        $conversation = SmsConversation::create([
            'flow_id' => $flow->id,
            'phone' => $phone,
            'status' => 'in_progress',
            'chat_thread_id' => $thread->id,
            'answers' => [],
            'message_count' => 0,
            'photo_count' => 0,
            'last_message_at' => now(),
            'expires_at' => now()->addMinutes($flow->timeout_minutes ?? 10080),
        ]);

        $this->advanceToNextStep($conversation);

        return $conversation;
    }

    /**
     * Process a user's response to the current bot step.
     */
    public function processResponse(SmsConversation $conversation, string $text, array $mediaUrls = []): void
    {
        $currentStep = $conversation->currentStep;
        if (! $currentStep) {
            return;
        }

        // Store answer
        $answers = $conversation->answers ?? [];
        $answers["step_{$currentStep->id}"] = $text;

        $conversation->update([
            'answers' => $answers,
            'message_count' => $conversation->message_count + 1,
            'photo_count' => $conversation->photo_count + count($mediaUrls),
            'last_message_at' => now(),
        ]);

        $this->advanceToNextStep($conversation);
    }

    /**
     * Advance the conversation to the next step.
     */
    public function advanceToNextStep(SmsConversation $conversation): void
    {
        $flow = $conversation->flow()->with('steps')->first();
        if (! $flow) {
            return;
        }

        $steps = $flow->steps->sortBy('sort_order')->values();
        $currentStepId = $conversation->current_step_id;

        // Find next step
        $nextStep = null;
        if ($currentStepId === null) {
            $nextStep = $steps->first();
        } else {
            $currentIndex = $steps->search(fn ($s) => $s->id === $currentStepId);
            if ($currentIndex !== false && $currentIndex < $steps->count() - 1) {
                $nextStep = $steps[$currentIndex + 1];
            }
        }

        if (! $nextStep) {
            $this->completeConversation($conversation);

            return;
        }

        $conversation->update(['current_step_id' => $nextStep->id]);

        $messageText = $this->replaceMessageVariables($nextStep->message_text ?? '', $conversation);

        switch ($nextStep->step_type) {
            case SmsBotStep::TYPE_GREETING:
            case SmsBotStep::TYPE_MESSAGE:
                $this->sendSms($conversation->phone, $messageText);
                $this->advanceToNextStep($conversation);
                break;

            case SmsBotStep::TYPE_QUESTION:
                $this->sendSms($conversation->phone, $messageText);
                // Wait for response — don't auto-advance
                break;
        }
    }

    /**
     * Mark conversation as completed.
     */
    public function completeConversation(SmsConversation $conversation): void
    {
        $conversation->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Replace {{ variable }} placeholders in a bot message.
     */
    public function replaceMessageVariables(string $text, SmsConversation $conversation): string
    {
        if (! str_contains($text, '{{')) {
            return $text;
        }

        $settings = CompanySetting::getInstance();

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($settings) {
            $variable = $matches[1];

            return match ($variable) {
                'company' => $settings->company_name ?? config('app.name', 'Our Company'),
                'booking_link' => config('app.url').'/book',
                'portal_link' => config('app.url'),
                default => $matches[0],
            };
        }, $text);
    }

    /**
     * Send SMS via TwilioService.
     */
    private function sendSms(string $to, string $body): void
    {
        try {
            $this->twilioService->sendSms($to, $body);
        } catch (\Exception $e) {
            Log::error('SmsBotFlowService: failed to send SMS', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a support thread for SMS bot and add internal users.
     */
    private function createSmsBotThread(string $phone): ChatThread
    {
        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => 'sms_bot',
            'subject' => "SMS Bot: {$phone}",
            'last_message_at' => now(),
        ]);

        $internalUsers = User::where('type', 'internal')
            ->where('is_active', true)
            ->permission('chat.view_website_leads')
            ->get();

        if ($internalUsers->isEmpty()) {
            $internalUsers = User::where('type', 'internal')
                ->where('is_active', true)
                ->get();
        }

        foreach ($internalUsers as $user) {
            $thread->addParticipant($user);
        }

        return $thread;
    }
}
