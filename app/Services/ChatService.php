<?php

namespace App\Services;

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Events\NewThreadNotification;
use App\Jobs\SendFacebookMessageJob;
use App\Jobs\SendSmsThreadReplyJob;
use App\Jobs\SendUnreadChatEmailJob;
use App\Jobs\SendUnreadChatSmsJob;
use App\Jobs\TranslateChatMessageJob;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatService
{
    /**
     * Chat permission matrix: who can chat with whom
     * Internal users can chat with: internal, agent, customer
     * Agents can chat with: internal, their customers (via deals they referred)
     * Customers can chat with: internal (via deal threads only)
     */
    private const CHAT_PERMISSIONS = [
        'internal' => ['internal', 'agent', 'customer', 'crew'],
        'agent' => ['internal', 'customer'],
        'customer' => ['internal'],
        'crew' => ['internal'],
    ];

    /**
     * Check if a user can initiate chat with another user.
     */
    public function canChatWith(User $user, User $target): bool
    {
        // Step 1: Check base type matrix (maximum allowed connections)
        $allowedTypes = self::CHAT_PERMISSIONS[$user->type] ?? [];

        if (! in_array($target->type, $allowedTypes, true)) {
            return false;
        }

        // Step 2: For internal users with roles, check granular chat.with_* permissions
        if ($user->isInternal() && $user->roles->isNotEmpty()) {
            // Map user type to permission name (types are singular, permissions use plural)
            $typeToPermission = [
                'customer' => 'chat.with_customers',
                'agent' => 'chat.with_agents',
                'crew' => 'chat.with_crew',
                'internal' => 'chat.with_internal',
            ];

            $permissionKey = $typeToPermission[$target->type] ?? null;

            if ($permissionKey && ! $user->can($permissionKey)) {
                return false;
            }
        }

        // Step 3: Additional checks for agents - can only chat with their referred customers
        if ($user->isAgent() && $target->isCustomer()) {
            return $this->agentCanChatWithCustomer($user, $target);
        }

        return true;
    }

    /**
     * Check if an agent can chat with a specific customer.
     */
    private function agentCanChatWithCustomer(User $agent, User $customerUser): bool
    {
        // Check if the customer is associated with any deal referred by this agent
        return Deal::query()
            ->where('agent_id', $agent->id)
            ->whereHas('customer', function ($query) use ($customerUser) {
                $query->where('user_id', $customerUser->id);
            })
            ->exists();
    }

    /**
     * Get or create a chat thread for a deal.
     */
    public function getOrCreateDealThread(Deal $deal, User $initiator): ChatThread
    {
        $thread = $deal->chatThread;

        if (! $thread) {
            $thread = $deal->chatThread()->create([
                'type' => ChatThread::TYPE_DEAL,
                'subject' => $deal->title,
                'last_message_at' => now(),
            ]);

            // Add initiator as participant
            $thread->addParticipant($initiator);

            // Auto-add deal stakeholders so all parties see the thread
            $this->addDealStakeholders($thread, $deal, $initiator);

            // Notify about new thread
            $this->safeBroadcastToOthers(new NewThreadNotification($thread, $initiator));
        } elseif (! $thread->hasParticipant($initiator)) {
            $thread->addParticipant($initiator);
        }

        return $thread;
    }

    /**
     * Add all relevant stakeholders to a deal chat thread.
     * Ensures the deal creator, customer, and agent are all participants.
     * Optionally excludes a user (e.g., the initiator who was already added).
     */
    public function addDealStakeholders(ChatThread $thread, Deal $deal, ?User $excludeUser = null): void
    {
        $excludeId = $excludeUser?->id;

        // Add deal creator (internal user who created the deal)
        if ($deal->created_by && $deal->created_by !== $excludeId) {
            $creator = User::find($deal->created_by);
            if ($creator && ! $thread->hasParticipant($creator)) {
                $thread->addParticipant($creator);
            }
        }

        // Add deal's customer user
        $customerUser = $deal->customer?->user;
        if ($customerUser && $customerUser->id !== $excludeId && ! $thread->hasParticipant($customerUser)) {
            $thread->addParticipant($customerUser);
        }

        // Add deal's agent
        if ($deal->agent_id && $deal->agent_id !== $excludeId) {
            $agent = User::find($deal->agent_id);
            if ($agent && ! $thread->hasParticipant($agent)) {
                $thread->addParticipant($agent);
            }
        }
    }

    /**
     * Get or create a direct chat thread between two users.
     */
    public function getOrCreateDirectThread(User $user1, User $user2, ?string $subject = null): ChatThread
    {
        // Check if a direct thread already exists between these users
        $thread = ChatThread::query()
            ->ofType(ChatThread::TYPE_DIRECT)
            ->whereHas('participants', function ($query) use ($user1) {
                $query->where('user_id', $user1->id)->whereNull('left_at');
            })
            ->whereHas('participants', function ($query) use ($user2) {
                $query->where('user_id', $user2->id)->whereNull('left_at');
            })
            ->first();

        if (! $thread) {
            $thread = ChatThread::create([
                'type' => ChatThread::TYPE_DIRECT,
                'subject' => $subject,
                'last_message_at' => now(),
            ]);

            $thread->addParticipant($user1);
            $thread->addParticipant($user2);

            // Notify user2 about new thread
            $this->safeBroadcastToOthers(new NewThreadNotification($thread, $user1));
        }

        return $thread;
    }

    /**
     * Send a message in a thread.
     *
     * @param  array{is_internal?: bool, parent_id?: int, metadata?: array}  $options
     */
    public function sendMessage(
        ChatThread $thread,
        User $sender,
        string $content,
        array $options = []
    ): ChatMessage {
        // Ensure user is a participant
        if (! $thread->hasParticipant($sender)) {
            $thread->addParticipant($sender);
        }

        // Non-internal users cannot send internal messages
        $isInternal = ($options['is_internal'] ?? false) && $sender->isInternal();

        $message = $thread->messages()->create([
            'user_id' => $sender->id,
            'content' => $content,
            'type' => ChatMessage::TYPE_TEXT,
            'is_internal' => $isInternal,
            'parent_id' => $options['parent_id'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);

        // Update thread's last message timestamp
        $thread->touchLastMessage();

        // Mark as read by sender
        $participant = $thread->getParticipant($sender);
        $participant?->markAsRead();

        // Broadcast message to thread
        $this->safeBroadcastToOthers(new MessageSent($message));

        // Dispatch delayed email for offline participants
        $this->dispatchUnreadEmailJobs($thread, $message, $sender, $isInternal);

        // Queue translation (non-blocking)
        TranslateChatMessageJob::dispatch($message);

        // Sync to Facebook Messenger if applicable
        $this->syncToFacebookMessenger($thread, $sender, $message, $content);

        // If this is an SMS inbound thread and an internal user is replying,
        // send the reply back to the originating phone via Twilio
        if ($sender->isInternal() && $thread->source === ChatThread::SOURCE_SMS_INBOUND) {
            $this->dispatchSmsThreadReply($thread, $message);
        }

        return $message;
    }

    /**
     * Send a message with attachments.
     *
     * @param  array<UploadedFile>  $files
     * @param  array{is_internal?: bool, parent_id?: int, metadata?: array}  $options
     */
    public function sendMessageWithAttachments(
        ChatThread $thread,
        User $sender,
        string $content,
        array $files,
        array $options = []
    ): ChatMessage {
        return DB::transaction(function () use ($thread, $sender, $content, $files, $options) {
            $isImage = count($files) === 1 && str_starts_with($files[0]->getMimeType(), 'image/');
            $messageType = $isImage ? ChatMessage::TYPE_IMAGE : ChatMessage::TYPE_FILE;

            $isInternal = ($options['is_internal'] ?? false) && $sender->isInternal();

            $message = $thread->messages()->create([
                'user_id' => $sender->id,
                'content' => $content,
                'type' => $messageType,
                'is_internal' => $isInternal,
                'parent_id' => $options['parent_id'] ?? null,
                'metadata' => $options['metadata'] ?? null,
            ]);

            foreach ($files as $file) {
                $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
                $path = $file->storeAs('chat-attachments', $filename);

                $message->attachments()->create([
                    'path' => $path,
                    'filename' => $filename,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            $thread->touchLastMessage();

            $participant = $thread->getParticipant($sender);
            $participant?->markAsRead();

            $this->safeBroadcastToOthers(new MessageSent($message->load('attachments')));

            $this->dispatchUnreadEmailJobs($thread, $message, $sender, $isInternal);

            // Queue translation (non-blocking)
            TranslateChatMessageJob::dispatch($message);

            // Sync text content to Facebook Messenger if applicable
            if (! empty($content)) {
                $this->syncToFacebookMessenger($thread, $sender, $message, $content);
            }

            return $message;
        });
    }

    /**
     * Send a system message in a thread.
     */
    public function sendSystemMessage(ChatThread $thread, string $content): ChatMessage
    {
        $message = $thread->messages()->create([
            'user_id' => $thread->participants()->first()?->user_id,
            'content' => $content,
            'type' => ChatMessage::TYPE_SYSTEM,
            'is_internal' => false,
        ]);

        $thread->touchLastMessage();

        $this->safeBroadcastToOthers(new MessageSent($message));

        return $message;
    }

    /**
     * Send an audio message in a thread.
     *
     * @param  array{audio_url: string, audio_path: string, audio_duration: float|null, transcription: string|null, transcription_status: string}  $audioData
     * @param  array{is_internal?: bool, parent_id?: int}  $options
     */
    public function sendAudioMessage(
        ChatThread $thread,
        User $sender,
        array $audioData,
        array $options = []
    ): ChatMessage {
        // Ensure user is a participant
        if (! $thread->hasParticipant($sender)) {
            $thread->addParticipant($sender);
        }

        // Non-internal users cannot send internal messages
        $isInternal = ($options['is_internal'] ?? false) && $sender->isInternal();

        $message = $thread->messages()->create([
            'user_id' => $sender->id,
            'content' => $audioData['transcription'] ?? '',
            'type' => ChatMessage::TYPE_AUDIO,
            'is_internal' => $isInternal,
            'parent_id' => $options['parent_id'] ?? null,
            'metadata' => [
                'audio_url' => $audioData['audio_url'],
                'audio_path' => $audioData['audio_path'],
                'audio_duration' => $audioData['audio_duration'],
                'transcription' => $audioData['transcription'],
                'transcription_status' => $audioData['transcription_status'],
            ],
        ]);

        // Update thread's last message timestamp
        $thread->touchLastMessage();

        // Mark as read by sender
        $participant = $thread->getParticipant($sender);
        $participant?->markAsRead();

        // Broadcast message to thread
        $this->safeBroadcastToOthers(new MessageSent($message));

        // Dispatch delayed email for offline participants
        $this->dispatchUnreadEmailJobs($thread, $message, $sender, $isInternal);

        // Queue translation (non-blocking)
        TranslateChatMessageJob::dispatch($message);

        // Sync transcription to Facebook Messenger if applicable
        $transcription = $audioData['transcription'] ?? '';
        if (! empty($transcription)) {
            $this->syncToFacebookMessenger($thread, $sender, $message, $transcription);
        }

        return $message;
    }

    /**
     * Edit a message.
     */
    public function editMessage(ChatMessage $message, User $user, string $newContent): ChatMessage
    {
        if ($message->user_id !== $user->id) {
            throw new \InvalidArgumentException('You can only edit your own messages.');
        }

        if ($message->isSystem()) {
            throw new \InvalidArgumentException('System messages cannot be edited.');
        }

        $message->update([
            'content' => $newContent,
            'edited_at' => now(),
        ]);

        $this->safeBroadcastToOthers(new MessageSent($message->fresh()));

        // Clear old translation and re-translate via queue
        $metadata = $message->metadata ?? [];
        unset($metadata['detected_lang'], $metadata['translations'], $metadata['translation_error']);
        $message->update(['metadata' => $metadata ?: null]);

        // Queue re-translation (non-blocking)
        TranslateChatMessageJob::dispatch($message->fresh());

        return $message;
    }

    /**
     * Delete a message.
     */
    public function deleteMessage(ChatMessage $message, User $user): bool
    {
        if ($message->user_id !== $user->id && ! $user->isInternal()) {
            throw new \InvalidArgumentException('You can only delete your own messages.');
        }

        return $message->delete();
    }

    /**
     * Mark a thread as read for a user.
     */
    public function markThreadAsRead(ChatThread $thread, User $user): void
    {
        $participant = $thread->getParticipant($user);

        if ($participant) {
            $participant->markAsRead();

            $this->safeBroadcastToOthers(new MessageRead($thread->id, $user->id, now()));
        }
    }

    /**
     * Get threads for a user with unread counts (excludes per-user archived).
     */
    public function getThreadsForUser(User $user): Collection
    {
        return ChatThread::query()
            ->forUser($user)
            ->visibleTo($user)
            ->notArchivedBy($user)
            ->with(['latestMessage.user', 'deal', 'activeParticipants.user'])
            ->latestActivity()
            ->get()
            ->map(function (ChatThread $thread) use ($user) {
                $thread->unread_count = $thread->getUnreadCountFor($user);
                $thread->display_name = $thread->getDisplayNameFor($user);

                return $thread;
            });
    }

    /**
     * Get total unread count for a user (excludes per-user archived).
     * Uses a single SQL query with JOINs instead of loading all threads.
     */
    public function getUnreadCountForUser(User $user): int
    {
        $query = DB::table('chat_messages')
            ->join('chat_participants', function ($join) use ($user) {
                $join->on('chat_messages.thread_id', '=', 'chat_participants.thread_id')
                    ->where('chat_participants.user_id', '=', $user->id)
                    ->whereNull('chat_participants.left_at')
                    ->whereNull('chat_participants.archived_at');
            })
            ->where(function ($q) use ($user) {
                $q->where('chat_messages.user_id', '!=', $user->id)
                    ->orWhereNull('chat_messages.user_id');
            })
            ->whereNull('chat_messages.deleted_at')
            ->whereColumn('chat_messages.created_at', '>', DB::raw('COALESCE(chat_participants.last_read_at, \'1970-01-01\')'));

        // Non-internal users can't see internal messages
        if (! $user->isInternal()) {
            $query->where('chat_messages.is_internal', false);
        }

        // Permission-based thread filtering for internal users with roles
        if ($user->isInternal() && $user->roles->isNotEmpty()) {
            $needsThreadJoin = ! $user->can('chat.view_deal_chats')
                || ! $user->can('chat.view_website_leads')
                || ! $user->can('chat.view_setter_support');

            if ($needsThreadJoin) {
                $query->join('chat_threads', 'chat_messages.thread_id', '=', 'chat_threads.id');

                if (! $user->can('chat.view_deal_chats')) {
                    $query->where('chat_threads.type', '!=', ChatThread::TYPE_DEAL);
                }

                if (! $user->can('chat.view_website_leads')) {
                    $query->where(function ($q) {
                        $q->where('chat_threads.type', '!=', ChatThread::TYPE_SUPPORT)
                            ->orWhere(function ($q2) {
                                $q2->where('chat_threads.source', '!=', ChatThread::SOURCE_WEBSITE_LEAD)
                                    ->orWhereNull('chat_threads.source');
                            });
                    });
                }

                if (! $user->can('chat.view_setter_support')) {
                    $query->where(function ($q) {
                        $q->where('chat_threads.type', '!=', ChatThread::TYPE_SUPPORT)
                            ->orWhere(function ($q2) {
                                $q2->where('chat_threads.source', '!=', ChatThread::SOURCE_SETTER_SUPPORT)
                                    ->orWhereNull('chat_threads.source');
                            });
                    });
                }
            }
        }

        return (int) $query->count();
    }

    /**
     * Get messages for a thread visible to a user.
     */
    public function getMessagesForThread(ChatThread $thread, User $user, int $limit = 50, ?int $beforeId = null): Collection
    {
        $query = $thread->messages()
            ->visibleTo($user)
            ->with(['user', 'attachments', 'parent.user'])
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        return $query->get()->reverse()->values();
    }

    /**
     * Add a participant to a thread.
     */
    public function addParticipant(ChatThread $thread, User $user, User $addedBy): void
    {
        if ($thread->hasParticipant($user)) {
            return;
        }

        $thread->addParticipant($user);

        $this->sendSystemMessage(
            $thread,
            "{$addedBy->name} added {$user->name} to the conversation"
        );
    }

    /**
     * Remove a participant from a thread.
     */
    public function removeParticipant(ChatThread $thread, User $user, User $removedBy): void
    {
        if (! $thread->hasParticipant($user)) {
            return;
        }

        $thread->removeParticipant($user);

        $this->sendSystemMessage(
            $thread,
            "{$removedBy->name} removed {$user->name} from the conversation"
        );
    }

    /**
     * Archive a thread for a specific user.
     */
    public function archiveThread(ChatThread $thread, ?User $user = null): bool
    {
        if ($user) {
            $participant = $thread->getParticipant($user);

            return $participant ? $participant->archive() : false;
        }

        // Fallback: global archive (legacy)
        return $thread->update(['is_archived' => true]);
    }

    /**
     * Unarchive a thread for a specific user.
     */
    public function unarchiveThread(ChatThread $thread, ?User $user = null): bool
    {
        if ($user) {
            $participant = $thread->getParticipant($user);

            return $participant ? $participant->unarchive() : false;
        }

        // Fallback: global unarchive (legacy)
        return $thread->update(['is_archived' => false]);
    }

    /**
     * Send a guest message in a support thread (no authenticated user).
     * Broadcasts to internal users and auto-unarchives the thread.
     *
     * @param  array{guest_email?: string, guest_name?: string}  $metadata
     */
    public function sendGuestMessage(ChatThread $thread, string $content, array $metadata = []): ChatMessage
    {
        $message = $thread->messages()->create([
            'user_id' => null,
            'content' => $content,
            'type' => ChatMessage::TYPE_TEXT,
            'is_internal' => false,
            'metadata' => $metadata ?: null,
        ]);

        $thread->touchLastMessage();

        // Auto-unarchive for all participants so the thread resurfaces
        $thread->activeParticipants()
            ->whereNotNull('archived_at')
            ->each(fn (ChatParticipant $p) => $p->unarchive());

        // Broadcast to thread channel so internal users see it in real-time
        $this->safeBroadcast(new MessageSent($message));

        // Dispatch unread email notifications
        $this->dispatchGuestUnreadEmails($thread, $message);

        // Queue translation (non-blocking)
        TranslateChatMessageJob::dispatch($message);

        return $message;
    }

    /**
     * Send a guest audio message in a support thread (no authenticated user).
     *
     * @param  array{audio_url: string, audio_path: string, audio_duration: float|null, transcription: string|null, transcription_status: string}  $audioData
     * @param  array{guest_email?: string, guest_name?: string}  $metadata
     */
    public function sendGuestAudioMessage(ChatThread $thread, array $audioData, array $metadata = []): ChatMessage
    {
        $message = $thread->messages()->create([
            'user_id' => null,
            'content' => $audioData['transcription'] ?? '',
            'type' => ChatMessage::TYPE_AUDIO,
            'is_internal' => false,
            'metadata' => array_merge($metadata ?: [], [
                'audio_url' => $audioData['audio_url'],
                'audio_path' => $audioData['audio_path'],
                'audio_duration' => $audioData['audio_duration'],
                'transcription' => $audioData['transcription'],
                'transcription_status' => $audioData['transcription_status'],
            ]),
        ]);

        $thread->touchLastMessage();

        // Auto-unarchive for all participants
        $thread->activeParticipants()
            ->whereNotNull('archived_at')
            ->each(fn (ChatParticipant $p) => $p->unarchive());

        // Broadcast to thread channel so internal users see it in real-time
        $this->safeBroadcast(new MessageSent($message));

        // Dispatch unread email notifications
        $this->dispatchGuestUnreadEmails($thread, $message);

        // Queue translation (non-blocking)
        TranslateChatMessageJob::dispatch($message);

        return $message;
    }

    /**
     * Dispatch unread email jobs for guest messages (notify all active participants).
     */
    private function dispatchGuestUnreadEmails(ChatThread $thread, ChatMessage $message): void
    {
        $participants = $thread->activeParticipants()->with('user')->get();

        foreach ($participants as $participant) {
            $user = $participant->user;

            if (! $user) {
                continue;
            }

            // Skip if user lacks permission to view this support thread type
            if (! $this->canViewSupportThread($user, $thread)) {
                continue;
            }

            SendUnreadChatEmailJob::dispatch($message, $user);

            // Delay SMS so online users have time to read before we text them
            try {
                if ($user->wantsSms('chat')) {
                    SendUnreadChatSmsJob::dispatch($message, $user)->delay(now()->addMinutes(2));
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch SMS notification', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Check if a user has permission to view a support thread based on its source.
     */
    private function canViewSupportThread(User $user, ChatThread $thread): bool
    {
        // Non-internal users or users without roles: no restriction
        if (! $user->isInternal() || $user->roles->isEmpty()) {
            return true;
        }

        // Only apply to support threads
        if ($thread->type !== ChatThread::TYPE_SUPPORT) {
            return true;
        }

        return match ($thread->source) {
            ChatThread::SOURCE_WEBSITE_LEAD => $user->can('chat.view_website_leads'),
            ChatThread::SOURCE_SETTER_SUPPORT => $user->can('chat.view_setter_support'),
            ChatThread::SOURCE_SMS_INBOUND => $user->can('chat.view_website_leads'),
            default => true,
        };
    }

    /**
     * Dispatch delayed email notifications for offline participants.
     */
    private function dispatchUnreadEmailJobs(ChatThread $thread, ChatMessage $message, User $sender, bool $isInternal): void
    {
        $participants = $thread->activeParticipants()
            ->where('user_id', '!=', $sender->id)
            ->with('user')
            ->get();

        foreach ($participants as $participant) {
            if (! $participant->user) {
                continue;
            }

            if ($isInternal && ! $participant->user->isInternal()) {
                continue;
            }

            SendUnreadChatEmailJob::dispatch($message, $participant->user);

            // Delay SMS so online users have time to read before we text them
            try {
                if ($participant->user->wantsSms('chat')) {
                    SendUnreadChatSmsJob::dispatch($message, $participant->user)->delay(now()->addMinutes(2));
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch SMS notification', ['user_id' => $participant->user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Dispatch an SMS reply to the originating phone for SMS inbound threads.
     */
    private function dispatchSmsThreadReply(ChatThread $thread, ChatMessage $message): void
    {
        try {
            SendSmsThreadReplyJob::dispatch($message, $thread);
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch SMS thread reply', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Safely broadcast an event to others, catching invalid socket ID errors.
     *
     * When Echo/Reverb isn't connected, Livewire may pass "undefined" as the
     * socket ID, causing Pusher to throw. Broadcasting is non-critical, so
     * we fall back to broadcasting to everyone (including the sender).
     */
    private function safeBroadcastToOthers(object $event): void
    {
        try {
            broadcast($event)->toOthers();
        } catch (\Pusher\PusherException $e) {
            // Socket ID invalid (e.g., "undefined" when Echo not connected).
            // Clear the contaminated socket before retrying, since toOthers()
            // already set $event->socket to the invalid value.
            if (property_exists($event, 'socket')) {
                $event->socket = null;
            }

            // Fall back to broadcasting to everyone instead of crashing.
            try {
                broadcast($event);
            } catch (\Throwable) {
                // Broadcasting completely failed - log but don't crash.
                Log::warning('Chat broadcast failed', [
                    'event' => get_class($event),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Safely broadcast an event (to everyone), catching any broadcast errors.
     */
    private function safeBroadcast(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('Chat broadcast failed', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete attachment file from storage.
     */
    public function deleteAttachment(int $attachmentId): bool
    {
        $attachment = \App\Models\ChatAttachment::find($attachmentId);

        if (! $attachment) {
            return false;
        }

        Storage::delete($attachment->path);

        return $attachment->delete();
    }

    /**
     * Sync a message to Facebook Messenger if the thread is a Facebook channel.
     * Also triggers human takeover if an internal user replies to an active bot conversation.
     */
    private function syncToFacebookMessenger(ChatThread $thread, User $sender, ChatMessage $message, string $content): void
    {
        if ($thread->channel !== ChatThread::CHANNEL_FACEBOOK || empty($thread->external_contact_id)) {
            return;
        }

        // Only sync outbound messages from internal users
        if (! $sender->isInternal()) {
            return;
        }

        SendFacebookMessageJob::dispatch(
            $thread->external_contact_id,
            $content,
            null,
            $message->id,
        );

        // If there's an active bot conversation, trigger human takeover
        $activeConversation = $thread->messengerConversation()
            ->where('status', 'in_progress')
            ->first();

        if ($activeConversation) {
            $activeConversation->update([
                'status' => 'human_takeover',
                'taken_over_by' => $sender->id,
            ]);
        }

        // Handle messenger responder thread claiming and deal assignment
        $assignmentService = app(\App\Services\MessengerLeadAssignmentService::class);
        if ($assignmentService->isActiveResponder($sender)) {
            $assignmentService->claimThread($thread, $sender);

            // Check if this responder is an estimator and can self-claim the deal
            $role = $assignmentService->getResponderRole($sender);
            if (in_array($role, ['estimator', 'both'])) {
                $conversation = $thread->messengerConversation;
                if ($conversation?->deal_id) {
                    $deal = \App\Models\Deal::find($conversation->deal_id);
                    if ($deal && $deal->assigned_to === null) {
                        $assignmentService->assignToEstimator($deal, $sender);
                    }
                }
            }
        }
    }
}
