<?php

namespace App\Services;

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
    public function startConversation(ChatThread $thread, string $psid): ?MessengerConversation
    {
        $flow = $this->getActiveFlow();
        if (! $flow) {
            return null;
        }

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => $psid,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Send greeting if flow has one
        if ($flow->greeting_message) {
            $this->messengerService->sendTextMessage($psid, $flow->greeting_message);
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

        $psid = $conversation->facebook_psid;

        switch ($nextStep->step_type) {
            case MessengerBotStep::TYPE_GREETING:
                $this->messengerService->sendTextMessage($psid, $nextStep->message_text);
                $this->advanceToNextStep($conversation);
                break;

            case MessengerBotStep::TYPE_TEXT_QUESTION:
                $this->messengerService->sendTextMessage($psid, $nextStep->message_text);
                break;

            case MessengerBotStep::TYPE_QUICK_REPLY:
                $options = $nextStep->quick_reply_options ?? [];
                if (! empty($options)) {
                    $this->messengerService->sendQuickReply($psid, $nextStep->message_text, $options);
                } else {
                    $this->messengerService->sendTextMessage($psid, $nextStep->message_text);
                }
                break;

            case MessengerBotStep::TYPE_DELAY:
                $this->messengerService->sendTypingIndicator($psid, true);
                $this->messengerService->sendTextMessage($psid, $nextStep->message_text);
                $this->advanceToNextStep($conversation);
                break;

            case MessengerBotStep::TYPE_HANDOFF:
                $this->messengerService->sendTextMessage($psid, $nextStep->message_text);
                $this->triggerHumanTakeover($conversation);
                break;
        }
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

        try {
            app(MessengerLeadCreationService::class)->createPartialLead($conversation);
        } catch (\Exception $e) {
            Log::error('Failed to create partial lead from abandoned conversation', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
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

        // Notify assigned user
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
