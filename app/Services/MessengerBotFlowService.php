<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class MessengerBotFlowService
{
    public function __construct(
        private FacebookMessengerService $messengerService
    ) {}

    /**
     * Start a new bot conversation for a thread if an active flow exists.
     */
    public function startConversation(ChatThread $thread, string $psid, ?MessengerBotFlow $flowOverride = null, ?string $entrySource = null): ?MessengerConversation
    {
        $flow = $flowOverride ?? $this->getActiveFlow();
        if (! $flow) {
            return null;
        }

        // Fetch and store Facebook profile data for name fallback
        $fbProfile = $this->messengerService->getUserProfile($psid);

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => $psid,
            'fb_profile_data' => array_filter($fbProfile, fn ($v) => $v !== null) ?: null,
            'entry_source' => $entrySource,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Send greeting if flow has one
        if ($flow->greeting_message) {
            $greetingText = $this->sanitizeForSending($this->replaceMessageVariables($flow->greeting_message, $conversation));
            $this->messengerService->sendTextMessage($psid, $greetingText);
            $this->storeBotMessage($conversation, $greetingText);
        }

        // Advance to first step
        $this->advanceToNextStep($conversation);

        return $conversation;
    }

    /**
     * Process a user's response to the current bot step.
     */
    public function processResponse(MessengerConversation $conversation, string $messageText): void
    {
        $conversation->update(['last_activity_at' => now()]);

        $currentStep = $conversation->currentStep;
        if (! $currentStep) {
            return;
        }

        // Store the answer
        $this->storeAnswer($conversation, $currentStep, $messageText);

        // Advance to next step
        $this->advanceToNextStep($conversation);
    }

    /**
     * Advance the conversation to the next step.
     */
    public function advanceToNextStep(MessengerConversation $conversation): void
    {
        $flow = $conversation->flow()->with('steps')->first();
        if (! $flow) {
            return;
        }

        $steps = $flow->steps->sortBy('sort_order')->values();
        $currentStepId = $conversation->current_step_id;

        // Find next step (branching-aware resolution)
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

        $psid = $conversation->facebook_psid;

        $messageText = $this->sanitizeForSending($this->replaceMessageVariables($nextStep->message_text, $conversation));

        switch ($nextStep->step_type) {
            case MessengerBotStep::TYPE_GREETING:
                $this->messengerService->sendTextMessage($psid, $messageText);
                $this->storeBotMessage($conversation, $messageText);
                $this->advanceToNextStep($conversation);
                break;

            case MessengerBotStep::TYPE_TEXT_QUESTION:
                $this->messengerService->sendTextMessage($psid, $messageText);
                $this->storeBotMessage($conversation, $messageText);
                break;

            case MessengerBotStep::TYPE_QUICK_REPLY:
                $options = collect($nextStep->quick_reply_options ?? [])
                    ->map(fn (array $opt) => [
                        'title' => $opt['title'] ?? $opt['label'] ?? '',
                        'payload' => $opt['payload'] ?? $opt['value'] ?? '',
                    ])
                    ->filter(fn (array $opt) => $opt['title'] !== '' && $opt['payload'] !== '')
                    ->values()
                    ->all();

                if (! empty($options)) {
                    $this->messengerService->sendQuickReply($psid, $messageText, $options);
                } else {
                    $this->messengerService->sendTextMessage($psid, $messageText);
                }
                $this->storeBotMessage($conversation, $messageText);
                break;

            case MessengerBotStep::TYPE_DELAY:
                $this->messengerService->sendTypingIndicator($psid, true);
                $this->messengerService->sendTextMessage($psid, $messageText);
                $this->storeBotMessage($conversation, $messageText);
                $this->advanceToNextStep($conversation);
                break;

            case MessengerBotStep::TYPE_HANDOFF:
                $this->messengerService->sendTextMessage($psid, $messageText);
                $this->storeBotMessage($conversation, $messageText);
                $this->triggerHumanTakeover($conversation);
                break;

            case MessengerBotStep::TYPE_MEDIA_MESSAGE:
                if ($nextStep->media_url) {
                    $this->messengerService->sendImageMessage($psid, $nextStep->media_url);
                    $this->storeBotMessage($conversation, '[Image]');
                }
                if ($messageText) {
                    $this->messengerService->sendTextMessage($psid, $messageText);
                    $this->storeBotMessage($conversation, $messageText);
                }
                $this->advanceToNextStep($conversation);
                break;

            case MessengerBotStep::TYPE_PHOTO_PROMPT:
                $this->messengerService->sendTextMessage($psid, $messageText);
                $this->storeBotMessage($conversation, $messageText);
                // Wait for user to send a photo
                break;
        }
    }

    /**
     * Store a bot-sent message in the chat thread so it appears in the hub.
     */
    private function storeBotMessage(MessengerConversation $conversation, string $content): void
    {
        if (! $conversation->thread_id || $content === '') {
            return;
        }

        ChatMessage::create([
            'thread_id' => $conversation->thread_id,
            'user_id' => null,
            'content' => $content,
            'type' => ChatMessage::TYPE_TEXT,
            'is_internal' => false,
            'metadata' => [
                'source' => 'bot',
                'psid' => $conversation->facebook_psid,
            ],
        ]);
    }

    /**
     * Store an answer for a step in the conversation.
     */
    public function storeAnswer(MessengerConversation $conversation, MessengerBotStep $step, string $answer): void
    {
        $answers = $conversation->answers ?? [];
        $answers["step_{$step->id}"] = $answer;
        $conversation->update(['answers' => $answers]);
    }

    /**
     * Mark conversation as completed and trigger lead creation.
     */
    public function completeConversation(MessengerConversation $conversation): void
    {
        $conversation->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        try {
            app(MessengerLeadCreationService::class)->createFromConversation($conversation);
        } catch (\Exception $e) {
            Log::error('Failed to create lead from completed conversation', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark conversation as abandoned and trigger partial lead creation.
     */
    public function abandonConversation(MessengerConversation $conversation): void
    {
        $conversation->update([
            'status' => 'abandoned',
        ]);
    }

    /**
     * Trigger human takeover for a conversation.
     */
    public function triggerHumanTakeover(MessengerConversation $conversation): void
    {
        $conversation->update(['status' => 'human_takeover']);

        try {
            app(MessengerLeadCreationService::class)->createFromConversation($conversation);
        } catch (\Exception $e) {
            Log::error('Failed to create lead during human takeover', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Notify all active messenger responders
        $responders = \App\Models\MessengerResponder::query()->active()->with('user')->get();
        if ($responders->isNotEmpty()) {
            foreach ($responders as $responder) {
                if ($responder->user?->isInternal()) {
                    try {
                        $responder->user->notify(new \App\Notifications\MessengerHumanTakeoverNeeded($conversation));
                    } catch (\Exception $e) {
                        Log::warning('Failed to send human takeover notification', ['user_id' => $responder->user_id, 'error' => $e->getMessage()]);
                    }
                }
            }
        } else {
            // Fallback to default_assigned_to if no roster
            $settings = MessengerSetting::getInstance();
            if ($settings->default_assigned_to) {
                $user = User::find($settings->default_assigned_to);
                if ($user && $user->isInternal()) {
                    try {
                        $user->notify(new \App\Notifications\MessengerHumanTakeoverNeeded($conversation));
                    } catch (\Exception $e) {
                        Log::warning('Failed to send human takeover notification', ['error' => $e->getMessage()]);
                    }
                }
            }
        }
    }

    /**
     * Check for timed-out conversations and mark them as abandoned.
     */
    public function checkTimeouts(): int
    {
        $count = 0;

        $conversations = MessengerConversation::query()
            ->where('status', 'in_progress')
            ->get();

        foreach ($conversations as $conversation) {
            $timeout = $conversation->flow?->timeout_minutes ?? 30;

            if ($conversation->last_activity_at && $conversation->last_activity_at->lt(now()->subMinutes($timeout))) {
                $this->abandonConversation($conversation);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Resolve the next step with branching support.
     *
     * Priority: quick reply branch target → step next_step_id → linear sort_order
     */
    private function resolveNextStep(MessengerConversation $conversation, MessengerBotStep $currentStep, \Illuminate\Support\Collection $steps): ?MessengerBotStep
    {
        // 1. Check quick reply branching (answer-based routing)
        if ($currentStep->step_type === MessengerBotStep::TYPE_QUICK_REPLY) {
            $answer = $conversation->answers["step_{$currentStep->id}"] ?? null;
            if ($answer !== null) {
                $branchTargetId = $this->findBranchTarget($currentStep, $answer);
                if ($branchTargetId) {
                    $targetStep = $steps->first(fn ($s) => $s->id === $branchTargetId);
                    if ($targetStep) {
                        return $targetStep;
                    }
                    // Target was deleted — fall through to other resolution
                }
            }
        }

        // 2. Check explicit next_step_id on the step itself
        if ($currentStep->next_step_id) {
            $targetStep = $steps->first(fn ($s) => $s->id === $currentStep->next_step_id);
            if ($targetStep) {
                return $targetStep;
            }
            // Target was deleted — fall through to linear
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
    private function findBranchTarget(MessengerBotStep $step, string $answer): ?int
    {
        $options = collect($step->quick_reply_options ?? []);

        $matched = $options->first(function (array $opt) use ($answer) {
            $value = $opt['value'] ?? $opt['payload'] ?? '';
            $label = $opt['label'] ?? $opt['title'] ?? '';

            return strcasecmp($value, $answer) === 0 || strcasecmp($label, $answer) === 0;
        });

        return $matched['next_step_id'] ?? null;
    }

    /**
     * Strip any PHP/HTML tags from text before sending to Facebook.
     * Defense-in-depth against Blade double-compilation or other injection.
     */
    private function sanitizeForSending(string $text): string
    {
        return strip_tags($text);
    }

    /**
     * Replace {{ variable }} placeholders in a bot message with actual values.
     */
    public function replaceMessageVariables(string $text, MessengerConversation $conversation): string
    {
        if (! str_contains($text, '{{')) {
            return $text;
        }

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($conversation) {
            $variable = $matches[1];

            return match ($variable) {
                'booking_link' => $this->resolveBookingLink($conversation),
                'first_name' => $conversation->fb_profile_data['first_name'] ?? '',
                'last_name' => $conversation->fb_profile_data['last_name'] ?? '',
                default => $matches[0], // Leave unknown variables as-is
            };
        }, $text);
    }

    /**
     * Generate (or reuse) a booking link for the conversation.
     */
    private function resolveBookingLink(MessengerConversation $conversation): string
    {
        if (! $conversation->isBookingTokenValid()) {
            $conversation->generateBookingToken();
        }

        return $conversation->getBookingUrl();
    }

    /**
     * Get the list of available message variables for the flow builder UI.
     */
    public static function getAvailableVariables(): array
    {
        return [
            'first_name' => __('Messenger name (first)'),
            'last_name' => __('Messenger name (last)'),
            'booking_link' => __('Booking form link'),
        ];
    }

    /**
     * Get the currently active bot flow.
     */
    public function getActiveFlow(): ?MessengerBotFlow
    {
        return MessengerBotFlow::query()->active()->first();
    }

    /**
     * Get mappable fields for the flow builder UI.
     */
    public function getMappableFields(): array
    {
        return MessengerBotStep::getMappableFields();
    }
}
