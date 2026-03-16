<?php

namespace App\Services;

use App\Models\ChatThread;
use App\Models\CompanySetting;
use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\SmsConversation;
use App\Models\User;
use Illuminate\Support\Collection;
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

        // Find next step (branching-aware)
        $nextStep = null;
        if ($currentStepId === null) {
            $nextStep = $steps->first();
        } else {
            $currentStep = $steps->first(fn ($s) => $s->id === $currentStepId);
            $nextStep = $currentStep
                ? $this->resolveNextStep($conversation, $currentStep, $steps)
                : null;
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
                break;

            case SmsBotStep::TYPE_QUICK_REPLY:
                $body = $this->formatQuickReplyForSms($messageText, $nextStep->quick_reply_options ?? []);
                $this->sendSms($conversation->phone, $body);
                break;

            case SmsBotStep::TYPE_DELAY:
                if ($messageText) {
                    $this->sendSms($conversation->phone, $messageText);
                }
                $this->advanceToNextStep($conversation);
                break;

            case SmsBotStep::TYPE_HANDOFF:
                if ($messageText) {
                    $this->sendSms($conversation->phone, $messageText);
                }
                $this->triggerHumanTakeover($conversation);
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
     * Mark conversation as abandoned.
     */
    public function abandonConversation(SmsConversation $conversation): void
    {
        $conversation->update(['status' => 'abandoned']);
    }

    /**
     * Trigger human takeover for a conversation.
     */
    public function triggerHumanTakeover(SmsConversation $conversation): void
    {
        $conversation->update(['status' => 'human_takeover']);
    }

    /**
     * Check for timed-out conversations and mark them as abandoned.
     */
    public function checkTimeouts(): int
    {
        $count = 0;

        $conversations = SmsConversation::query()
            ->where('status', 'in_progress')
            ->get();

        foreach ($conversations as $conversation) {
            $timeout = $conversation->flow?->timeout_minutes ?? 10080;

            if ($conversation->last_message_at && $conversation->last_message_at->lt(now()->subMinutes($timeout))) {
                $this->abandonConversation($conversation);
                $count++;
            }
        }

        return $count;
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
     * Resolve the next step with branching support.
     *
     * Priority: quick reply branch target -> step next_step_id -> linear sort_order
     *
     * @param  Collection<int, SmsBotStep>  $steps
     */
    private function resolveNextStep(SmsConversation $conversation, SmsBotStep $currentStep, Collection $steps): ?SmsBotStep
    {
        // 1. Check quick reply branching (answer-based routing)
        if ($currentStep->step_type === SmsBotStep::TYPE_QUICK_REPLY) {
            $answer = $conversation->answers["step_{$currentStep->id}"] ?? null;
            if ($answer !== null) {
                $branchTargetId = $this->findBranchTarget($currentStep, $answer);
                if ($branchTargetId) {
                    $targetStep = $steps->first(fn ($s) => $s->id === $branchTargetId);
                    if ($targetStep) {
                        return $targetStep;
                    }
                }
            }
        }

        // 2. Check explicit next_step_id on the step itself
        if ($currentStep->next_step_id) {
            $targetStep = $steps->first(fn ($s) => $s->id === $currentStep->next_step_id);
            if ($targetStep) {
                return $targetStep;
            }
        }

        // 3. Fall through to linear sort_order
        $currentIndex = $steps->search(fn ($s) => $s->id === $currentStep->id);
        if ($currentIndex !== false && $currentIndex < $steps->count() - 1) {
            return $steps[$currentIndex + 1];
        }

        return null;
    }

    /**
     * Find a branch target step ID from quick reply options based on the user's answer.
     */
    private function findBranchTarget(SmsBotStep $step, string $answer): ?int
    {
        $options = collect($step->quick_reply_options ?? []);

        // Match by numeric reply (e.g., "1" matches first option)
        if (is_numeric($answer)) {
            $index = (int) $answer - 1;
            $matched = $options->values()->get($index);
            if ($matched && ! empty($matched['next_step_id'])) {
                return (int) $matched['next_step_id'];
            }
        }

        // Match by value or label
        $matched = $options->first(function (array $opt) use ($answer) {
            $value = $opt['value'] ?? $opt['payload'] ?? '';
            $label = $opt['label'] ?? $opt['title'] ?? '';

            return strcasecmp($value, $answer) === 0 || strcasecmp($label, $answer) === 0;
        });

        return isset($matched['next_step_id']) ? (int) $matched['next_step_id'] : null;
    }

    /**
     * Format quick reply options as numbered list for SMS.
     *
     * @param  array<int, array{label?: string, value?: string, title?: string, payload?: string}>  $options
     */
    private function formatQuickReplyForSms(string $messageText, array $options): string
    {
        $lines = [$messageText];

        foreach ($options as $i => $opt) {
            $label = $opt['label'] ?? $opt['title'] ?? '';
            $number = $i + 1;
            $lines[] = "Reply {$number} for {$label}";
        }

        return implode("\n", $lines);
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
