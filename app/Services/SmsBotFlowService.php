<?php

namespace App\Services;

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\ColorVisualization;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ProjectPreview;
use App\Models\ShowcaseMedia;
use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\SmsConversation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SmsBotFlowService
{
    public function __construct(
        private TwilioService $twilioService
    ) {}

    /**
     * Start a new bot conversation for a phone number.
     * Returns null if the flow requires marketing consent and the customer lacks it.
     */
    public function startConversation(string $phone, SmsBotFlow $flow, ?ChatThread $thread = null): ?SmsConversation
    {
        // Check marketing consent if required by the flow
        if ($flow->requires_marketing_consent) {
            $customer = $this->findCustomerByPhone($phone);
            if ($customer && ! $customer->hasSmsMarketingConsent()) {
                Log::info('SmsBotFlowService: skipped conversation, customer lacks marketing consent', [
                    'phone' => $phone,
                    'flow_id' => $flow->id,
                    'customer_id' => $customer->id,
                ]);

                return null;
            }
        }

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

        // Store inbound message on chat thread
        if ($conversation->chat_thread_id && $text !== '') {
            ChatMessage::create([
                'thread_id' => $conversation->chat_thread_id,
                'user_id' => null,
                'content' => $text,
                'type' => ChatMessage::TYPE_TEXT,
                'metadata' => [
                    'source' => 'sms',
                    'phone' => $conversation->phone,
                ],
            ]);
        }

        // Store answer
        $answers = $conversation->answers ?? [];
        $answers["step_{$currentStep->id}"] = $text;

        // Handle photo downloads for photo_prompt steps
        if ($currentStep->step_type === SmsBotStep::TYPE_PHOTO_PROMPT && ! empty($mediaUrls)) {
            $photoPath = $this->downloadAndStorePhoto($conversation, $mediaUrls[0]);
            if ($photoPath) {
                $answers["step_{$currentStep->id}_photo"] = $photoPath;
            }
        }

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
                $this->recordOutboundMessage($conversation, $messageText);
                $this->advanceToNextStep($conversation);
                break;

            case SmsBotStep::TYPE_QUESTION:
                $this->sendSms($conversation->phone, $messageText);
                $this->recordOutboundMessage($conversation, $messageText);
                break;

            case SmsBotStep::TYPE_QUICK_REPLY:
                $body = $this->formatQuickReplyForSms($messageText, $nextStep->quick_reply_options ?? []);
                $this->sendSms($conversation->phone, $body);
                $this->recordOutboundMessage($conversation, $body);
                break;

            case SmsBotStep::TYPE_DELAY:
                if ($messageText) {
                    $this->sendSms($conversation->phone, $messageText);
                    $this->recordOutboundMessage($conversation, $messageText);
                }
                $this->advanceToNextStep($conversation);
                break;

            case SmsBotStep::TYPE_HANDOFF:
                if ($messageText) {
                    $this->sendSms($conversation->phone, $messageText);
                    $this->recordOutboundMessage($conversation, $messageText);
                }
                $this->triggerHumanTakeover($conversation);
                break;

            case SmsBotStep::TYPE_MEDIA_MESSAGE:
                $mediaUrl = $this->resolveMediaUrl($nextStep->media_url);
                if ($mediaUrl) {
                    $this->sendMms($conversation->phone, $messageText, $mediaUrl);
                    $this->recordOutboundMediaMessage($conversation, $messageText, $mediaUrl);
                } else {
                    Log::warning('SmsBotFlowService: could not resolve media URL, skipping media_message step', [
                        'conversation_id' => $conversation->id,
                        'step_id' => $nextStep->id,
                        'media_url' => $nextStep->media_url,
                    ]);
                }
                $this->advanceToNextStep($conversation);
                break;

            case SmsBotStep::TYPE_PHOTO_PROMPT:
                $this->sendSms($conversation->phone, $messageText);
                $this->recordOutboundMessage($conversation, $messageText);
                // Wait for user to send a photo
                break;

            case SmsBotStep::TYPE_AI_VISUALIZATION:
                $this->handleAiVisualizationStep($conversation, $nextStep);
                // Wait for job callback — don't auto-advance
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
     * Handle AI visualization step — find prompt and photo, dispatch job.
     */
    private function handleAiVisualizationStep(SmsConversation $conversation, SmsBotStep $step): void
    {
        $answers = $conversation->answers ?? [];

        // Find the prompt from source step answer
        $prompt = null;
        if ($step->source_step_id) {
            $prompt = $answers["step_{$step->source_step_id}"] ?? null;
        }

        // Find the most recent photo path from answers
        $photoPath = null;
        foreach (array_reverse($answers, true) as $key => $value) {
            if (str_ends_with($key, '_photo')) {
                $photoPath = $value;
                break;
            }
        }

        if (! $prompt || ! $photoPath) {
            $this->sendSms($conversation->phone, 'Sorry, I could not process your visualization request. Let me connect you with our team.');
            $this->advanceToNextStep($conversation);

            return;
        }

        // Ensure conversation has a project preview for the visualization
        $this->ensureProjectPreview($conversation);

        // Create ColorVisualization record
        $visualization = ColorVisualization::create([
            'sms_conversation_id' => $conversation->id,
            'original_image_path' => $photoPath,
            'prompt' => $prompt,
            'status' => ColorVisualization::STATUS_PENDING,
            'disk' => config('filesystems.default'),
        ]);

        // Dispatch the AI job
        GenerateColorVisualizationJob::dispatch($visualization);

        Log::info('SmsBotFlowService: dispatched AI visualization', [
            'conversation_id' => $conversation->id,
            'visualization_id' => $visualization->id,
            'prompt' => $prompt,
        ]);
    }

    /**
     * Callback when AI visualization completes — send MMS and advance flow.
     */
    public function onVisualizationComplete(SmsConversation $conversation, string $resultImagePath): void
    {
        $resultUrl = Storage::url($resultImagePath);

        $this->sendMms($conversation->phone, 'Here is your color visualization!', $resultUrl);

        // Store result in answers
        $answers = $conversation->answers ?? [];
        $answers['visualization_result'] = $resultImagePath;
        $conversation->update(['answers' => $answers]);

        $this->advanceToNextStep($conversation);
    }

    /**
     * Download a photo from a URL and store it.
     */
    private function downloadAndStorePhoto(SmsConversation $conversation, string $mediaUrl): ?string
    {
        try {
            $response = Http::get($mediaUrl);
            if ($response->failed()) {
                Log::error('SmsBotFlowService: failed to download photo', ['url' => $mediaUrl, 'status' => $response->status()]);

                return null;
            }

            $contentType = $response->header('Content-Type', 'image/jpeg');
            $extension = match (true) {
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'heic') => 'heic',
                default => 'jpg',
            };

            $filename = 'chat-attachments/'.Str::uuid().'.'.$extension;
            Storage::put($filename, $response->body());

            // Create ChatAttachment on the thread if thread exists
            if ($conversation->chat_thread_id) {
                $message = ChatMessage::create([
                    'thread_id' => $conversation->chat_thread_id,
                    'content' => 'Photo received via SMS',
                    'type' => ChatMessage::TYPE_IMAGE,
                ]);

                ChatAttachment::create([
                    'message_id' => $message->id,
                    'path' => $filename,
                    'filename' => basename($filename),
                    'original_filename' => basename($mediaUrl),
                    'mime_type' => $contentType,
                    'file_size' => strlen($response->body()),
                ]);
            }

            return $filename;
        } catch (\Exception $e) {
            Log::error('SmsBotFlowService: photo download failed', ['url' => $mediaUrl, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Ensure conversation has a ProjectPreview (create one lazily if missing).
     */
    private function ensureProjectPreview(SmsConversation $conversation): void
    {
        if ($conversation->project_preview_id) {
            return;
        }

        $preview = ProjectPreview::create([
            'phone' => $conversation->phone,
        ]);

        $conversation->update(['project_preview_id' => $preview->id]);
    }

    /**
     * Resolve a media URL from a ShowcaseMedia slug or treat it as a raw URL.
     *
     * If the value matches an active ShowcaseMedia slug, use its public URL.
     * Otherwise, treat it as a direct URL. Returns null if unresolvable.
     */
    private function resolveMediaUrl(?string $mediaUrlOrSlug): ?string
    {
        if (! $mediaUrlOrSlug) {
            return null;
        }

        // Try resolving as a ShowcaseMedia slug first
        $showcaseMedia = ShowcaseMedia::findBySlug($mediaUrlOrSlug);
        if ($showcaseMedia) {
            return $showcaseMedia->getPublicUrl();
        }

        // Fall back to raw URL if it looks like one
        if (str_starts_with($mediaUrlOrSlug, 'http://') || str_starts_with($mediaUrlOrSlug, 'https://')) {
            return $mediaUrlOrSlug;
        }

        return null;
    }

    /**
     * Send MMS via TwilioService.
     */
    private function sendMms(string $to, string $body, string $mediaUrl): void
    {
        try {
            $this->twilioService->sendMms($to, $body, [$mediaUrl]);
        } catch (\Exception $e) {
            Log::error('SmsBotFlowService: failed to send MMS', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
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
     * Record an outbound text message on the conversation's chat thread.
     */
    private function recordOutboundMessage(SmsConversation $conversation, string $content): void
    {
        if (! $conversation->chat_thread_id || $content === '') {
            return;
        }

        ChatMessage::create([
            'thread_id' => $conversation->chat_thread_id,
            'user_id' => null,
            'content' => $content,
            'type' => ChatMessage::TYPE_TEXT,
            'metadata' => ['source' => 'sms_bot'],
        ]);
    }

    /**
     * Record an outbound media message on the conversation's chat thread.
     */
    private function recordOutboundMediaMessage(SmsConversation $conversation, string $content, ?string $mediaUrl): void
    {
        if (! $conversation->chat_thread_id) {
            return;
        }

        $chatMessage = ChatMessage::create([
            'thread_id' => $conversation->chat_thread_id,
            'user_id' => null,
            'content' => $content,
            'type' => ChatMessage::TYPE_IMAGE,
            'metadata' => [
                'source' => 'sms_bot',
                'media_url' => $mediaUrl,
            ],
        ]);

        if ($mediaUrl) {
            ChatAttachment::create([
                'message_id' => $chatMessage->id,
                'path' => $mediaUrl,
                'filename' => basename(parse_url($mediaUrl, PHP_URL_PATH) ?: 'image.jpg'),
                'original_filename' => basename(parse_url($mediaUrl, PHP_URL_PATH) ?: 'image.jpg'),
                'mime_type' => 'image/jpeg',
                'file_size' => 0,
            ]);
        }
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
     * Find a Customer record by phone number.
     */
    private function findCustomerByPhone(string $phone): ?Customer
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last10 = substr($digits, -10);

        if (strlen($last10) < 10) {
            return null;
        }

        return Customer::where('phone', 'LIKE', '%'.$last10)->first();
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
