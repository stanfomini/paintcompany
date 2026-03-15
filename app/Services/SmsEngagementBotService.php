<?php

namespace App\Services;

use App\Jobs\ProcessPreviewPhotoJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ProjectPreview;
use App\Models\ProjectPreviewPhoto;
use App\Models\ProjectPreviewVisualization;
use App\Models\SmsBotFlow;
use App\Models\SmsConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SmsEngagementBotService
{
    public function __construct(
        protected TwilioService $twilio,
        protected ProjectPreviewService $previewService,
    ) {}

    /**
     * Initiate a bot conversation for a new lead.
     * Called by InitiateSmsBotJob.
     */
    public function initiate(string $phone, ?int $customerId = null, ?int $leadSourceId = null): ?SmsConversation
    {
        $settings = CompanySetting::getInstance();

        if (! $settings->sms_bot_enabled) {
            Log::info('SMS bot disabled, skipping initiation', ['phone' => $phone]);

            return null;
        }

        $flow = SmsBotFlow::findForTrigger('new_lead', $leadSourceId);

        if (! $flow) {
            Log::info('No active SMS bot flow found for trigger', [
                'trigger' => 'new_lead',
                'lead_source_id' => $leadSourceId,
            ]);

            return null;
        }

        $formattedPhone = $this->twilio->formatE164($phone);

        if (! $formattedPhone) {
            Log::warning('Invalid phone number for SMS bot', ['phone' => $phone]);

            return null;
        }

        // Check if there's already an active conversation for this phone
        $existing = SmsConversation::findActiveByPhone($formattedPhone);

        if ($existing) {
            Log::info('Active SMS conversation already exists', [
                'phone' => $formattedPhone,
                'conversation_id' => $existing->id,
            ]);

            return $existing;
        }

        // Check opt-out status if we have a customer
        if ($customerId && $this->isOptedOut($customerId)) {
            Log::info('Customer opted out of SMS, skipping bot', [
                'customer_id' => $customerId,
                'phone' => $formattedPhone,
            ]);

            return null;
        }

        $preview = $this->previewService->createForPhone($formattedPhone, $customerId, $leadSourceId);

        $conversation = SmsConversation::create([
            'project_preview_id' => $preview->id,
            'flow_id' => $flow->id,
            'phone' => $formattedPhone,
            'current_state' => SmsConversation::STATE_INITIATED,
            'answers' => [],
            'message_count' => 0,
            'photo_count' => 0,
            'last_message_at' => now(),
            'expires_at' => $flow->timeout_minutes
                ? now()->addMinutes($flow->timeout_minutes)
                : null,
        ]);

        // Send the greeting message
        $greeting = $this->replacePlaceholders($flow->greeting_message, $preview);
        $result = $this->twilio->sendSms($formattedPhone, $greeting);

        if (! $result['success']) {
            Log::error('Failed to send SMS bot greeting', [
                'conversation_id' => $conversation->id,
                'phone' => $formattedPhone,
                'error' => $result['error'],
            ]);
        } else {
            Log::info('SMS bot conversation initiated', [
                'conversation_id' => $conversation->id,
                'phone' => $formattedPhone,
                'flow_id' => $flow->id,
            ]);
        }

        return $conversation;
    }

    /**
     * Handle an inbound message (text or photo) for an active bot conversation.
     * This is the main state machine router.
     */
    public function handleInbound(string $phone, string $body, array $mediaUrls = []): void
    {
        $formattedPhone = $this->twilio->formatE164($phone);

        if (! $formattedPhone) {
            return;
        }

        $conversation = SmsConversation::findActiveByPhone($formattedPhone);

        if (! $conversation) {
            return;
        }

        // Human takeover — do nothing, let the webhook handle normally
        if ($conversation->isHumanTakeover()) {
            return;
        }

        $hasPhotos = ! empty($mediaUrls);

        // Increment counters
        $conversation->increment('message_count');
        if ($hasPhotos) {
            $conversation->increment('photo_count', count($mediaUrls));
        }
        $conversation->update(['last_message_at' => now()]);

        $preview = $conversation->preview;

        if (! $preview) {
            Log::error('SMS conversation missing preview', ['conversation_id' => $conversation->id]);

            return;
        }

        $state = $conversation->current_state;

        match ($state) {
            SmsConversation::STATE_INITIATED => $this->handleInitiatedState($conversation, $preview, $body, $mediaUrls, $hasPhotos),
            SmsConversation::STATE_NUDGE_PHOTO => $this->handleNudgePhotoState($conversation, $preview, $body, $mediaUrls, $hasPhotos),
            SmsConversation::STATE_PHOTO_RECEIVED => $this->handlePhotoReceivedState($conversation),
            SmsConversation::STATE_VISUALIZATION_READY => $this->handleVisualizationReadyState($conversation, $preview, $body, $mediaUrls, $hasPhotos),
            SmsConversation::STATE_ROOM_LABELED => $this->handleRoomLabeledState($conversation, $preview, $body, $mediaUrls, $hasPhotos),
            SmsConversation::STATE_SCOPE_COLLECTED => $this->handleScopeCollectedState($conversation, $preview, $body, $mediaUrls, $hasPhotos),
            SmsConversation::STATE_TIMELINE_COLLECTED => $this->handleTimelineCollectedState($conversation, $preview, $body),
            SmsConversation::STATE_OPEN_CHAT => null, // Handled by webhook
            SmsConversation::STATE_DORMANT => $this->handleDormantState($conversation, $preview, $mediaUrls, $hasPhotos),
            default => Log::warning('SMS bot received message in unhandled state', [
                'conversation_id' => $conversation->id,
                'state' => $state,
            ]),
        };
    }

    /**
     * Trigger human takeover — bot stops, internal user controls conversation.
     */
    public function triggerHumanTakeover(SmsConversation $conversation): void
    {
        $conversation->transitionTo(SmsConversation::STATE_HUMAN_TAKEOVER);

        Log::info('SMS bot human takeover triggered', [
            'conversation_id' => $conversation->id,
            'phone' => $conversation->phone,
        ]);
    }

    /**
     * Called when visualizations are complete — sends MMS with results.
     */
    public function onVisualizationsReady(SmsConversation $conversation): void
    {
        $preview = $conversation->preview;

        if (! $preview) {
            Log::error('Cannot send visualizations — preview missing', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        // Gather completed visualization URLs
        $visualizations = ProjectPreviewVisualization::query()
            ->whereHas('photo', function ($q) use ($preview) {
                $q->where('project_preview_id', $preview->id);
            })
            ->completed()
            ->latest()
            ->limit(3)
            ->get();

        if ($visualizations->isEmpty()) {
            Log::warning('No completed visualizations found for conversation', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $mediaUrls = $visualizations->map(fn (ProjectPreviewVisualization $v) => $v->getResultUrl())->toArray();

        $flow = $conversation->flow;
        $message = $flow?->visualization_message ?? 'Here\'s what your room could look like! What do you think?';
        $message = $this->replacePlaceholders($message, $preview);

        $result = $this->twilio->sendMms($conversation->phone, $message, $mediaUrls);

        if (! $result['success']) {
            Log::error('Failed to send visualization MMS', [
                'conversation_id' => $conversation->id,
                'error' => $result['error'],
            ]);

            return;
        }

        $conversation->transitionTo(SmsConversation::STATE_VISUALIZATION_READY);

        Log::info('Visualization MMS sent', [
            'conversation_id' => $conversation->id,
            'visualization_count' => $visualizations->count(),
        ]);
    }

    /**
     * STATE_INITIATED: expecting a photo, but might get text first.
     */
    protected function handleInitiatedState(
        SmsConversation $conversation,
        ProjectPreview $preview,
        string $body,
        array $mediaUrls,
        bool $hasPhotos,
    ): void {
        if ($hasPhotos) {
            $this->processInboundPhotos($conversation, $preview, $mediaUrls);
            $this->sendProcessingMessage($conversation);
            $conversation->transitionTo(SmsConversation::STATE_PHOTO_RECEIVED);

            return;
        }

        // Text only — nudge them to send a photo
        $flow = $conversation->flow;
        $nudgeMessage = $flow?->photo_prompt_message
            ?? 'We\'d love to see your space! Send a photo of the room you\'re thinking about painting.';
        $nudgeMessage = $this->replacePlaceholders($nudgeMessage, $preview);

        $this->twilio->sendSms($conversation->phone, $nudgeMessage);
        $conversation->transitionTo(SmsConversation::STATE_NUDGE_PHOTO);
    }

    /**
     * STATE_NUDGE_PHOTO: still waiting for a photo.
     */
    protected function handleNudgePhotoState(
        SmsConversation $conversation,
        ProjectPreview $preview,
        string $body,
        array $mediaUrls,
        bool $hasPhotos,
    ): void {
        if ($hasPhotos) {
            $this->processInboundPhotos($conversation, $preview, $mediaUrls);
            $this->sendProcessingMessage($conversation);
            $conversation->transitionTo(SmsConversation::STATE_PHOTO_RECEIVED);

            return;
        }

        // Another text without photo — nudge again
        $this->twilio->sendSms(
            $conversation->phone,
            'Just snap a quick photo of the room and send it over — we\'ll show you color options!'
        );
    }

    /**
     * STATE_PHOTO_RECEIVED: waiting for AI processing, no bot action.
     */
    protected function handlePhotoReceivedState(SmsConversation $conversation): void
    {
        // Bot stays quiet while AI processes. No state change.
        Log::info('SMS bot received message while waiting for AI processing', [
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * STATE_VISUALIZATION_READY: ask what room this is.
     */
    protected function handleVisualizationReadyState(
        SmsConversation $conversation,
        ProjectPreview $preview,
        string $body,
        array $mediaUrls,
        bool $hasPhotos,
    ): void {
        if ($hasPhotos) {
            $this->processInboundPhotos($conversation, $preview, $mediaUrls);
            $this->sendProcessingMessage($conversation);
            $conversation->transitionTo(SmsConversation::STATE_PHOTO_RECEIVED);

            return;
        }

        // Text should be a room label
        $roomLabel = trim($body);

        if (empty($roomLabel)) {
            return;
        }

        $this->storeAnswer($conversation, 'room_label', $roomLabel);

        // Update the preview rooms_data
        $roomsData = $preview->rooms_data ?? [];
        $roomsData[] = ['label' => $roomLabel, 'collected_at' => now()->toIso8601String()];
        $preview->update(['rooms_data' => $roomsData]);

        $this->twilio->sendSms(
            $conversation->phone,
            "Got it — {$roomLabel}! Are you looking to paint the interior, exterior, or both?"
        );

        $conversation->transitionTo(SmsConversation::STATE_ROOM_LABELED);
    }

    /**
     * STATE_ROOM_LABELED: collect the scope (interior/exterior/both).
     */
    protected function handleRoomLabeledState(
        SmsConversation $conversation,
        ProjectPreview $preview,
        string $body,
        array $mediaUrls,
        bool $hasPhotos,
    ): void {
        if ($hasPhotos) {
            $this->processInboundPhotos($conversation, $preview, $mediaUrls);
            $this->sendProcessingMessage($conversation);
            $conversation->transitionTo(SmsConversation::STATE_PHOTO_RECEIVED);

            return;
        }

        $scope = trim($body);

        if (empty($scope)) {
            return;
        }

        $this->storeAnswer($conversation, 'scope', $scope);
        $preview->update(['scope_type' => $this->normalizeScopeType($scope)]);

        $this->twilio->sendSms(
            $conversation->phone,
            'When are you hoping to get started? (e.g., ASAP, next month, just exploring)'
        );

        $conversation->transitionTo(SmsConversation::STATE_SCOPE_COLLECTED);
    }

    /**
     * STATE_SCOPE_COLLECTED: collect the timeline.
     */
    protected function handleScopeCollectedState(
        SmsConversation $conversation,
        ProjectPreview $preview,
        string $body,
        array $mediaUrls,
        bool $hasPhotos,
    ): void {
        if ($hasPhotos) {
            $this->processInboundPhotos($conversation, $preview, $mediaUrls);
            $this->sendProcessingMessage($conversation);
            $conversation->transitionTo(SmsConversation::STATE_PHOTO_RECEIVED);

            return;
        }

        $timeline = trim($body);

        if (empty($timeline)) {
            return;
        }

        $this->storeAnswer($conversation, 'timeline', $timeline);
        $preview->update(['timeline' => $timeline]);

        // Send portal link
        $flow = $conversation->flow;
        $portalMessage = $flow?->portal_link_message
            ?? 'Awesome! Check out your personalized project preview here: {portal_link}';
        $portalMessage = $this->replacePlaceholders($portalMessage, $preview);

        $this->twilio->sendSms($conversation->phone, $portalMessage);

        $conversation->transitionTo(SmsConversation::STATE_TIMELINE_COLLECTED);

        Log::info('SMS bot conversation collected all answers', [
            'conversation_id' => $conversation->id,
            'answers' => $conversation->fresh()->answers,
        ]);
    }

    /**
     * STATE_TIMELINE_COLLECTED: transition to open chat.
     */
    protected function handleTimelineCollectedState(
        SmsConversation $conversation,
        ProjectPreview $preview,
        string $body,
    ): void {
        $conversation->transitionTo(SmsConversation::STATE_OPEN_CHAT);

        Log::info('SMS bot conversation moved to open chat', [
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * STATE_DORMANT: re-engage if they send a photo.
     */
    protected function handleDormantState(
        SmsConversation $conversation,
        ProjectPreview $preview,
        array $mediaUrls,
        bool $hasPhotos,
    ): void {
        if (! $hasPhotos) {
            return;
        }

        $this->processInboundPhotos($conversation, $preview, $mediaUrls);
        $this->sendProcessingMessage($conversation);
        $conversation->transitionTo(SmsConversation::STATE_PHOTO_RECEIVED);

        Log::info('Dormant SMS conversation re-engaged via photo', [
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Download photos from Twilio media URLs and dispatch processing jobs.
     */
    protected function processInboundPhotos(SmsConversation $conversation, ProjectPreview $preview, array $mediaUrls): void
    {
        foreach ($mediaUrls as $mediaUrl) {
            try {
                $photo = $this->downloadAndStorePhoto($mediaUrl, $preview);

                ProcessPreviewPhotoJob::dispatch($photo, $preview);

                Log::info('SMS photo received and queued for processing', [
                    'conversation_id' => $conversation->id,
                    'photo_id' => $photo->id,
                    'media_url' => $mediaUrl,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to process SMS photo', [
                    'conversation_id' => $conversation->id,
                    'media_url' => $mediaUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Download a photo from a Twilio media URL and store it.
     */
    protected function downloadAndStorePhoto(string $mediaUrl, ProjectPreview $preview): ProjectPreviewPhoto
    {
        $response = Http::get($mediaUrl);

        if ($response->failed()) {
            throw new \RuntimeException("Failed to download photo from {$mediaUrl}: HTTP {$response->status()}");
        }

        $extension = 'jpg';
        $contentType = $response->header('Content-Type', 'image/jpeg');

        if (str_contains($contentType, 'png')) {
            $extension = 'png';
        } elseif (str_contains($contentType, 'webp')) {
            $extension = 'webp';
        } elseif (str_contains($contentType, 'heic')) {
            $extension = 'heic';
        }

        $filename = 'previews/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($filename, $response->body());

        return $this->previewService->storePhoto(
            $preview,
            $filename,
            'public',
            $contentType,
            strlen($response->body())
        );
    }

    /**
     * Send a "processing your photo" confirmation message.
     */
    protected function sendProcessingMessage(SmsConversation $conversation): void
    {
        $this->twilio->sendSms(
            $conversation->phone,
            'Got your photo! Give us a moment to work some magic... ✨'
        );
    }

    /**
     * Replace {placeholder} tokens in a message with actual values.
     */
    protected function replacePlaceholders(string $message, ProjectPreview $preview): string
    {
        if (! str_contains($message, '{')) {
            return $message;
        }

        $settings = CompanySetting::getInstance();

        $replacements = [
            '{name}' => $preview->first_name ?? 'there',
            '{company}' => $settings->company_name ?? config('app.name'),
            '{estimator}' => $preview->estimator?->name ?? 'your estimator',
            '{portal_link}' => $preview->getPortalUrl(),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $message
        );
    }

    /**
     * Store an answer in the conversation's JSON answers column.
     */
    protected function storeAnswer(SmsConversation $conversation, string $key, string $value): void
    {
        $answers = $conversation->answers ?? [];
        $answers[$key] = $value;
        $conversation->update(['answers' => $answers]);
    }

    /**
     * Normalize a free-text scope answer to a standard scope type.
     */
    protected function normalizeScopeType(string $input): string
    {
        $lower = strtolower(trim($input));

        if (str_contains($lower, 'both') || (str_contains($lower, 'interior') && str_contains($lower, 'exterior'))) {
            return 'both';
        }

        if (str_contains($lower, 'exterior') || str_contains($lower, 'outside')) {
            return 'exterior';
        }

        return 'interior';
    }

    /**
     * Check if a customer's linked user has opted out of SMS.
     */
    protected function isOptedOut(int $customerId): bool
    {
        $customer = Customer::with('user')->find($customerId);

        if (! $customer?->user) {
            return false;
        }

        return $customer->user->isSmsOptedOut();
    }
}
