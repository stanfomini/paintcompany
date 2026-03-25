<?php

use App\Models\CallLog;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Customer;
use App\Models\GuestChatSession;
use App\Models\MessengerConversation;
use App\Models\ProjectPreview;
use App\Models\User;
use App\Services\ChatService;
use App\Services\CommunicationsContactService;
use App\Services\FacebookMessengerService;
use App\Services\MessengerLeadCreationService;
use App\Services\MissedCallVoicemailService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Stichoza\GoogleTranslate\GoogleTranslate;

new class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('chat.access') && ! auth()->user()?->can('email.access')) {
            abort(403);
        }
    }

    #[Url]
    public ?string $contactId = null;

    public string $search = '';

    public string $typeFilter = 'all';

    public string $activeTab = 'all';

    // Inline chat state
    public ?int $expandedThreadId = null;

    // Setter support contact state
    public ?int $setterSessionId = null;
    public ?int $setterThreadId = null;

    // Messenger contact state
    public ?int $messengerThreadId = null;

    // SMS contact state
    public ?int $smsThreadId = null;
    public string $smsActiveTab = 'texts';

    // Email thread viewer state
    public ?int $viewingEmailThreadId = null;

    public bool $composingReply = false;

    // Convert to Customer modal state
    public bool $showConvertModal = false;
    public string $convertFirstName = '';
    public string $convertLastName = '';
    public string $convertEmail = '';
    public string $convertPhone = '';
    public string $convertAddress = '';
    public string $convertCity = '';
    public string $convertState = '';
    public string $convertZip = '';
    public string $convertNotes = '';

    // Reply state (used for both modal and inline)
    public bool $showReplyModal = false;

    public ?int $replyToMessageId = null;

    public ?int $replyAccountId = null;

    public string $replyTo = '';

    public string $replySubject = '';

    public string $replyBody = '';

    public function getListeners(): array
    {
        $userId = auth()->id();

        $listeners = [
            "echo-private:chat.user.{$userId},.message.sent" => 'onNewMessage',
            "echo-private:chat.user.{$userId},.thread.created" => 'refreshData',
            "echo-private:email.user.{$userId},.email.received" => 'refreshData',
            "echo-private:call.user.{$userId},.voicemail.received" => 'refreshData',
            'message-sent' => 'onChildMessageSent',
        ];

        // Listen to the specific thread channel for instant message updates
        if ($this->expandedThreadId) {
            $listeners["echo-private:chat.thread.{$this->expandedThreadId},.message.sent"] = 'onThreadMessage';
            $listeners["echo-private:chat.thread.{$this->expandedThreadId},.message.translated"] = 'onThreadMessage';
        }

        return $listeners;
    }

    public function onNewMessage(): void
    {
        unset($this->contacts, $this->contactItems, $this->selectedContact, $this->selectedSetterData, $this->selectedMessengerData, $this->selectedSmsData, $this->tabUnreadCounts, $this->threadMessages, $this->emailThreadMessages);
    }

    public function onThreadMessage(): void
    {
        unset($this->threadMessages);

        // Mark as read since user is viewing this thread
        if ($this->expandedThreadId) {
            $thread = ChatThread::find($this->expandedThreadId);
            if ($thread) {
                app(ChatService::class)->markThreadAsRead($thread, auth()->user());
            }
        }

        unset($this->contacts, $this->tabUnreadCounts);
    }

    public function onChildMessageSent(): void
    {
        unset($this->threadMessages, $this->contactItems, $this->contacts, $this->tabUnreadCounts);
    }

    public function refreshData(): void
    {
        unset($this->contacts, $this->contactItems, $this->selectedContact, $this->selectedSetterData, $this->selectedMessengerData, $this->selectedSmsData, $this->tabUnreadCounts, $this->threadMessages, $this->emailThreadMessages);
    }

    #[Computed]
    public function contacts()
    {
        $service = app(CommunicationsContactService::class);

        $contacts = $service->getContacts(
            auth()->user(),
            $this->search ?: null,
            $this->typeFilter
        );

        // Merge messenger contacts (unless filtered to a non-messenger type)
        if (in_array($this->typeFilter, ['all', 'messenger'])) {
            $messengerContacts = $service->getMessengerContacts(
                auth()->user(),
                $this->search ?: null
            );
            $contacts = $contacts->merge($messengerContacts);
        }

        // Merge SMS inbound contacts (support threads from guest SMS senders)
        if (in_array($this->typeFilter, ['all', 'customer'])) {
            $smsContacts = $service->getSmsContacts(
                auth()->user(),
                $this->search ?: null
            );
            $contacts = $contacts->merge($smsContacts);
        }

        return $contacts
            ->sortByDesc(fn ($c) => $c['last_activity_at'] ?? '1970-01-01')
            ->values();
    }

    /**
     * Whether the currently selected contact is a setter support contact.
     */
    public function isSetterContact(): bool
    {
        return $this->contactId && str_starts_with($this->contactId, 'setter-');
    }

    /**
     * Get the selected setter session's data from the contacts list.
     */
    #[Computed]
    public function selectedSetterData(): ?array
    {
        if (! $this->isSetterContact()) {
            return null;
        }

        return $this->contacts->firstWhere('id', $this->contactId);
    }

    /**
     * Whether the currently selected view is the global phone system.
     */
    public function isPhoneView(): bool
    {
        return $this->contactId === 'phone';
    }

    /**
     * Get the customer ID for the currently selected contact (for scoping calls).
     */
    public function getContactCustomerId(): ?int
    {
        if (! $this->selectedContact) {
            return null;
        }

        return Customer::where('user_id', $this->selectedContact->id)->value('id');
    }

    /**
     * Whether the currently selected contact is an SMS inbound contact.
     */
    public function isSmsContact(): bool
    {
        return $this->contactId && str_starts_with($this->contactId, 'sms-');
    }

    /**
     * Get the phone number for the currently selected SMS contact.
     */
    public function getSmsContactPhoneNumber(): ?string
    {
        if (! $this->isSmsContact() || ! $this->smsThreadId) {
            return null;
        }
        $thread = ChatThread::find($this->smsThreadId);
        if (! $thread) {
            return null;
        }
        // Extract from subject "SMS from +1XXXXXXXXXX"
        $phone = str_replace('SMS from ', '', $thread->subject ?? '');
        if (! empty($phone) && str_starts_with($phone, '+')) {
            return $phone;
        }
        // Fallback to thread's customer phone
        if ($thread->customer_id) {
            return Customer::where('id', $thread->customer_id)->value('phone');
        }
        return null;
    }

    /**
     * Set the active tab for SMS contacts.
     */
    public function setSmsTab(string $tab): void
    {
        $this->smsActiveTab = $tab;
    }

    /**
     * Get the phone number for the currently selected regular contact.
     */
    public function getContactPhoneNumber(): ?string
    {
        if (! $this->selectedContact) {
            return null;
        }
        $customer = Customer::where('user_id', $this->selectedContact->id)->first();
        if ($customer?->phone) {
            return $customer->phone;
        }
        return $this->selectedContact->phone ?? null;
    }

    /**
     * Get the selected SMS contact's data from the contacts list.
     */
    #[Computed]
    public function selectedSmsData(): ?array
    {
        if (! $this->isSmsContact()) {
            return null;
        }

        return $this->contacts->firstWhere('id', $this->contactId);
    }

    /**
     * Whether the currently selected contact is a Facebook Messenger contact.
     */
    public function isMessengerContact(): bool
    {
        return $this->contactId && str_starts_with($this->contactId, 'messenger-');
    }

    /**
     * Get the selected messenger contact's data from the contacts list.
     */
    #[Computed]
    public function selectedMessengerData(): ?array
    {
        if (! $this->isMessengerContact()) {
            return null;
        }

        return $this->contacts->firstWhere('id', $this->contactId);
    }

    #[Computed]
    public function selectedContact(): ?User
    {
        if (! $this->contactId || $this->isSetterContact() || $this->isMessengerContact() || $this->isSmsContact()) {
            return null;
        }

        return User::find($this->contactId);
    }

    /**
     * Get the active project preview for the currently selected contact.
     * Only shows when a deal is active (not lost/completed/cancelled).
     */
    #[Computed]
    public function contactProjectPreview(): ?ProjectPreview
    {
        // Regular customer contact
        if ($this->selectedContact?->isCustomer()) {
            $customer = Customer::where('user_id', $this->selectedContact->id)->first();
            if ($customer) {
                return ProjectPreview::where('customer_id', $customer->id)
                    ->where('status', 'active')
                    ->latest()
                    ->first();
            }
        }

        // SMS contact — look up via thread's customer
        if ($this->isSmsContact() && $this->smsThreadId) {
            $thread = ChatThread::find($this->smsThreadId);
            if ($thread?->customer_id) {
                return ProjectPreview::where('customer_id', $thread->customer_id)
                    ->where('status', 'active')
                    ->latest()
                    ->first();
            }
        }

        return null;
    }

    #[Computed]
    public function contactItems()
    {
        if ($this->isSetterContact()) {
            // For setter contacts, show their single support thread
            if (! $this->setterThreadId) {
                return collect();
            }
            $thread = ChatThread::with(['latestMessage.user'])->find($this->setterThreadId);
            if (! $thread) {
                return collect();
            }
            return collect([[
                'type' => 'chat',
                'channel' => null,
                'thread_type' => 'support',
                'icon' => 'lifebuoy',
                'label' => $thread->subject ?? 'Setter Support',
                'preview' => $thread->latestMessage
                    ? ($thread->latestMessage->user?->name ?? 'Guest').': '.\Illuminate\Support\Str::limit($thread->latestMessage->content, 80)
                    : 'No messages yet',
                'sender' => $thread->latestMessage?->user?->name,
                'date' => $thread->last_message_at ?? $thread->created_at,
                'thread_id' => $thread->id,
                'url' => route('chat.show', $thread),
                'message_id' => null,
                'direction' => null,
            ]]);
        }

        if ($this->isSmsContact()) {
            // For SMS contacts, show their single SMS thread
            if (! $this->smsThreadId) {
                return collect();
            }
            $thread = ChatThread::with(['latestMessage.user'])->find($this->smsThreadId);
            if (! $thread) {
                return collect();
            }
            return collect([[
                'type' => 'chat',
                'channel' => null,
                'thread_type' => 'support',
                'icon' => 'device-phone-mobile',
                'label' => $thread->subject ?? 'SMS Chat',
                'preview' => $thread->latestMessage
                    ? ($thread->latestMessage->user?->name ?? 'SMS').': '.\Illuminate\Support\Str::limit($thread->latestMessage->content, 80)
                    : 'No messages yet',
                'sender' => $thread->latestMessage?->user?->name,
                'date' => $thread->last_message_at ?? $thread->created_at,
                'thread_id' => $thread->id,
                'url' => route('chat.show', $thread),
                'message_id' => null,
                'direction' => null,
            ]]);
        }

        if ($this->isMessengerContact()) {
            // For messenger contacts, show their single Facebook thread
            if (! $this->messengerThreadId) {
                return collect();
            }
            $thread = ChatThread::with(['latestMessage.user'])->find($this->messengerThreadId);
            if (! $thread) {
                return collect();
            }
            return collect([[
                'type' => 'chat',
                'channel' => 'facebook',
                'thread_type' => 'support',
                'icon' => 'chat-bubble-oval-left',
                'label' => 'Messenger',
                'preview' => $thread->latestMessage
                    ? ($thread->latestMessage->user?->name ?? ($thread->subject ?? 'Messenger User')).': '.\Illuminate\Support\Str::limit($thread->latestMessage->content, 80)
                    : 'No messages yet',
                'sender' => $thread->latestMessage?->user?->name,
                'date' => $thread->last_message_at ?? $thread->created_at,
                'thread_id' => $thread->id,
                'url' => route('chat.show', $thread),
                'message_id' => null,
                'direction' => null,
            ]]);
        }

        if (! $this->selectedContact) {
            return collect();
        }

        return app(CommunicationsContactService::class)->getContactCommunications(
            auth()->user(),
            $this->selectedContact,
            $this->activeTab
        );
    }

    #[Computed]
    public function tabUnreadCounts(): array
    {
        if ($this->isMessengerContact()) {
            $unread = 0;
            if ($this->messengerThreadId) {
                $thread = ChatThread::find($this->messengerThreadId);
                if ($thread) {
                    $unread = $thread->getUnreadCountFor(auth()->user());
                }
            }
            return ['all' => $unread, 'messenger' => $unread, 'direct' => 0, 'deals' => 0, 'emails' => 0, 'calls' => 0];
        }

        if ($this->isSmsContact()) {
            $unread = 0;
            if ($this->smsThreadId) {
                $thread = ChatThread::find($this->smsThreadId);
                if ($thread) {
                    $unread = $thread->getUnreadCountFor(auth()->user());
                }
            }
            return ['all' => $unread, 'direct' => $unread, 'messenger' => 0, 'deals' => 0, 'emails' => 0, 'calls' => 0];
        }

        if ($this->isSetterContact()) {
            $unread = 0;
            if ($this->setterThreadId) {
                $thread = ChatThread::find($this->setterThreadId);
                if ($thread) {
                    $unread = $thread->getUnreadCountFor(auth()->user());
                }
            }
            return ['all' => $unread, 'direct' => $unread, 'messenger' => 0, 'deals' => 0, 'emails' => 0, 'calls' => 0];
        }

        if (! $this->selectedContact) {
            return [];
        }

        $counts = app(CommunicationsContactService::class)->getTabUnreadCounts(
            auth()->user(),
            $this->selectedContact
        );

        // Add calls count scoped to this contact's customer or phone number
        if (auth()->user()->can('phone.view_call_history')) {
            $customerId = $this->getContactCustomerId();
            if ($customerId) {
                $counts['calls'] = CallLog::where('customer_id', $customerId)->missed()->unhandled()->count();
            } else {
                $phone = $this->getContactPhoneNumber();
                $counts['calls'] = $phone
                    ? CallLog::where(fn ($q) => $q->where('from_number', $phone)->orWhere('to_number', $phone))->missed()->unhandled()->count()
                    : 0;
            }
        } else {
            $counts['calls'] = 0;
        }

        return $counts;
    }

    #[Computed]
    public function threadMessages()
    {
        if (! $this->expandedThreadId) {
            return collect();
        }

        return ChatMessage::where('thread_id', $this->expandedThreadId)
            ->where('is_internal', false)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Determine if the current tab is a chat-specific tab with exactly one thread,
     * which should auto-expand as a messenger view.
     */
    #[Computed]
    public function isSingleThreadTab(): bool
    {
        if (! in_array($this->activeTab, ['messenger', 'deals', 'direct'])) {
            return false;
        }

        $chatItems = $this->contactItems->where('type', 'chat');

        return $chatItems->count() === 1;
    }

    public function selectContact(string $contactId): void
    {
        $this->contactId = $contactId;
        $this->activeTab = 'all';
        $this->expandedThreadId = null;
        $this->setterSessionId = null;
        $this->setterThreadId = null;
        $this->messengerThreadId = null;
        $this->smsThreadId = null;
        $this->smsActiveTab = 'texts';

        // If this is an SMS contact, extract the thread ID
        if (str_starts_with($contactId, 'sms-')) {
            $threadId = (int) str_replace('sms-', '', $contactId);
            $this->smsThreadId = $threadId;
            $this->expandedThreadId = $threadId;
        }

        // If this is a setter contact, extract the session/thread info
        if (str_starts_with($contactId, 'setter-')) {
            $sessionId = (int) str_replace('setter-', '', $contactId);
            $session = GuestChatSession::find($sessionId);
            if ($session) {
                $this->setterSessionId = $session->id;
                $this->setterThreadId = $session->chat_thread_id;
                // Auto-expand the thread
                $this->expandedThreadId = $session->chat_thread_id;
            }
        }

        // If this is a messenger contact, extract the thread ID
        if (str_starts_with($contactId, 'messenger-')) {
            $threadId = (int) str_replace('messenger-', '', $contactId);
            $this->messengerThreadId = $threadId;
            $this->expandedThreadId = $threadId;
        }

        $this->viewingEmailThreadId = null;
        $this->composingReply = false;
        $this->resetReplyState();
        unset($this->contactItems, $this->selectedContact, $this->selectedSetterData, $this->selectedMessengerData, $this->selectedSmsData, $this->tabUnreadCounts, $this->threadMessages, $this->emailThreadMessages);
    }

    public function clearContact(): void
    {
        $this->contactId = null;
        $this->activeTab = 'all';
        $this->expandedThreadId = null;
        $this->setterSessionId = null;
        $this->setterThreadId = null;
        $this->messengerThreadId = null;
        $this->smsThreadId = null;

        $this->viewingEmailThreadId = null;
        $this->composingReply = false;
        $this->resetReplyState();
        unset($this->contactItems, $this->selectedContact, $this->selectedSetterData, $this->selectedMessengerData, $this->selectedSmsData, $this->tabUnreadCounts, $this->threadMessages, $this->emailThreadMessages);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->expandedThreadId = null;

        $this->viewingEmailThreadId = null;
        $this->composingReply = false;
        $this->resetReplyState();
        unset($this->contactItems, $this->threadMessages, $this->emailThreadMessages);

        // Auto-expand the single thread on chat-specific tabs
        if (in_array($tab, ['messenger', 'deals', 'direct'])) {
            $chatItems = $this->contactItems->where('type', 'chat');
            if ($chatItems->count() === 1 && $chatItems->first()['thread_id']) {
                $this->expandedThreadId = $chatItems->first()['thread_id'];
                unset($this->threadMessages);

                $thread = ChatThread::find($this->expandedThreadId);
                if ($thread) {
                    app(ChatService::class)->markThreadAsRead($thread, auth()->user());
                    unset($this->contacts, $this->tabUnreadCounts);
                }
            }
        }
    }

    public function startDirectChat(): void
    {
        if (! $this->selectedContact) {
            return;
        }

        $thread = app(ChatService::class)->getOrCreateDirectThread(auth()->user(), $this->selectedContact);
        $this->dispatch('open-chat-thread', threadId: $thread->id);
    }

    public function expandThread(int $threadId): void
    {
        if ($this->expandedThreadId === $threadId) {
            $this->expandedThreadId = null;
            unset($this->threadMessages);

            return;
        }

        $this->expandedThreadId = $threadId;

        unset($this->threadMessages);

        $thread = ChatThread::find($threadId);
        if ($thread) {
            app(ChatService::class)->markThreadAsRead($thread, auth()->user());
            unset($this->contacts, $this->tabUnreadCounts);
        }
    }

    #[Computed]
    public function emailThreadMessages()
    {
        if (! $this->viewingEmailThreadId) {
            return collect();
        }

        return EmailMessage::where('email_thread_id', $this->viewingEmailThreadId)
            ->with('account')
            ->orderBy('sent_at', 'asc')
            ->get();
    }

    public function viewEmailThread(int $emailThreadId): void
    {
        $this->viewingEmailThreadId = $emailThreadId;
        $this->composingReply = false;
        $this->resetReplyState();
        unset($this->emailThreadMessages);

        // Mark all messages in this thread as read
        EmailMessage::where('email_thread_id', $emailThreadId)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        unset($this->contacts, $this->contactItems, $this->tabUnreadCounts);
    }

    public function closeEmailView(): void
    {
        $this->viewingEmailThreadId = null;
        $this->composingReply = false;
        $this->resetReplyState();
        unset($this->emailThreadMessages, $this->contactItems);
    }

    public function startEmailReply(int $messageId): void
    {
        $message = EmailMessage::find($messageId);
        if (! $message) {
            return;
        }

        $this->replyToMessageId = $messageId;
        $this->replyTo = $message->from_address;
        $this->replySubject = str_starts_with(strtolower($message->subject ?? ''), 're:')
            ? $message->subject
            : 'Re: '.$message->subject;

        $account = $message->account;
        if ($account) {
            $this->replyAccountId = $account->id;
        } else {
            $personal = auth()->user()->emailAccount;
            $this->replyAccountId = $personal?->id;
        }

        $date = $message->sent_at?->format('M j, Y \a\t g:i A') ?? '';
        $from = $message->from_name ?? $message->from_address;
        $this->replyBody = '<br><br><div class="gmail_quote">'
            .'<p>On '.$date.', '.$from.' wrote:</p>'
            .'<blockquote style="border-left: 2px solid #ccc; padding-left: 12px; color: #666;">'
            .($message->body_html ?? '')
            .'</blockquote></div>';

        $replyAccount = $this->replyAccountId ? EmailAccount::find($this->replyAccountId) : null;
        if ($replyAccount?->signature_html) {
            $sig = $replyAccount->signature_html;
            if (strip_tags($sig) === $sig) {
                $sig = nl2br(e($sig));
            }
            $this->replyBody = '<br><br><div class="signature">--<br>'.$sig.'</div>'.$this->replyBody;
        }

        $this->composingReply = true;
    }

    public function sendEmailReply(): void
    {
        if (! $this->replyTo || ! $this->replyAccountId) {
            return;
        }

        $account = EmailAccount::find($this->replyAccountId);
        if (! $account) {
            return;
        }

        $replyToMessage = $this->replyToMessageId ? EmailMessage::find($this->replyToMessageId) : null;

        app(\App\Services\EmailService::class)->send(
            account: $account,
            to: [['address' => $this->replyTo, 'name' => null]],
            subject: $this->replySubject,
            bodyHtml: $this->replyBody,
            replyTo: $replyToMessage,
        );

        $this->composingReply = false;
        $this->resetReplyState();
        unset($this->emailThreadMessages, $this->contactItems, $this->contacts);
    }

    public function cancelEmailReply(): void
    {
        $this->composingReply = false;
        $this->resetReplyState();
    }

    /**
     * Translate a chat message.
     */
    public function translateMessage(int $messageId): void
    {
        $message = ChatMessage::findOrFail($messageId);

        $text = trim($message->content);
        if (mb_strlen($text) < 5) {
            return;
        }

        $tr = new GoogleTranslate;
        $tr->setSource(null);
        $tr->setTarget('en');
        $translated = $tr->translate($text);
        $detectedLang = $tr->getLastDetectedSource();

        $targetLang = $detectedLang === 'en' ? 'es' : 'en';

        if ($detectedLang === $targetLang) {
            $metadata = $message->metadata ?? [];
            $metadata['detected_lang'] = $detectedLang;
            $message->update(['metadata' => $metadata]);
            unset($this->threadMessages);

            return;
        }

        if ($targetLang !== 'en') {
            $tr->setSource(null);
            $tr->setTarget($targetLang);
            $translated = $tr->translate($text);
        }

        $metadata = $message->metadata ?? [];
        $metadata['detected_lang'] = $detectedLang;
        $metadata['translations'][$targetLang] = $translated;
        $message->update(['metadata' => $metadata]);

        unset($this->threadMessages);
    }

    private function resetReplyState(): void
    {
        $this->replyToMessageId = null;
        $this->replyTo = '';
        $this->replySubject = '';
        $this->replyBody = '';
        $this->replyAccountId = null;
    }

    /**
     * Get the MessengerConversation for the currently selected messenger thread.
     */
    private function getSelectedMessengerConversation(): ?MessengerConversation
    {
        if (! $this->messengerThreadId) {
            return null;
        }

        return MessengerConversation::where('thread_id', $this->messengerThreadId)->latest()->first();
    }

    /**
     * Check if the currently selected messenger conversation can be converted (no customer yet).
     */
    #[Computed]
    public function canConvertMessenger(): bool
    {
        if (! $this->isMessengerContact() || ! $this->messengerThreadId) {
            return false;
        }

        $conversation = $this->getSelectedMessengerConversation();

        return $conversation && ! $conversation->customer_id;
    }

    /**
     * Open the convert-to-customer modal pre-filled with conversation data.
     */
    public function openConvertModal(): void
    {
        $conversation = $this->getSelectedMessengerConversation();
        if (! $conversation) {
            return;
        }

        $collected = $conversation->getCollectedData();
        $fbName = $conversation->getFbName();

        $this->convertFirstName = $collected['customer.first_name'] ?? $fbName['first_name'] ?? '';
        $this->convertLastName = $collected['customer.last_name'] ?? $fbName['last_name'] ?? '';
        $this->convertEmail = $collected['customer.email'] ?? '';
        $this->convertPhone = $collected['customer.phone'] ?? '';
        $this->convertAddress = $collected['customer.address'] ?? '';
        $this->convertCity = $collected['customer.city'] ?? '';
        $this->convertState = $collected['customer.state'] ?? '';
        $this->convertZip = $collected['customer.zip'] ?? '';
        $this->convertNotes = $collected['deal.description'] ?? '';

        $this->showConvertModal = true;
    }

    /**
     * Submit the convert-to-customer form.
     */
    public function submitConvertToCustomer(): void
    {
        $this->validate([
            'convertFirstName' => 'required|string|max:100',
            'convertLastName' => 'nullable|string|max:100',
            'convertEmail' => 'nullable|email|max:255',
            'convertPhone' => 'nullable|string|max:30',
            'convertAddress' => 'nullable|string|max:255',
            'convertCity' => 'nullable|string|max:100',
            'convertState' => 'nullable|string|max:50',
            'convertZip' => 'nullable|string|max:20',
            'convertNotes' => 'nullable|string|max:2000',
        ]);

        $conversation = $this->getSelectedMessengerConversation();
        if (! $conversation || $conversation->customer_id) {
            $this->showConvertModal = false;

            return;
        }

        $service = app(MessengerLeadCreationService::class);
        $service->createFromManualConversion($conversation, [
            'first_name' => $this->convertFirstName,
            'last_name' => $this->convertLastName,
            'email' => $this->convertEmail,
            'phone' => $this->convertPhone,
            'address' => $this->convertAddress,
            'city' => $this->convertCity,
            'state' => $this->convertState,
            'zip' => $this->convertZip,
            'notes' => $this->convertNotes,
        ]);

        $this->showConvertModal = false;
        unset($this->contacts, $this->canConvertMessenger);
        session()->flash('message', __('Customer created successfully.'));
    }

    /**
     * Send a booking link to the messenger user via Facebook.
     */
    public function sendBookingLink(): void
    {
        $conversation = $this->getSelectedMessengerConversation();
        if (! $conversation || $conversation->customer_id) {
            return;
        }

        $conversation->generateBookingToken();
        $url = $conversation->getBookingUrl();

        $messenger = app(FacebookMessengerService::class);
        $messenger->sendTextMessage(
            $conversation->facebook_psid,
            "We'd love to help you with your painting project! Please fill out this quick form so we can prepare your free estimate:\n\n{$url}"
        );

        // Store a record in the chat thread
        if ($conversation->thread_id) {
            ChatMessage::create([
                'thread_id' => $conversation->thread_id,
                'user_id' => auth()->id(),
                'content' => "Booking link sent: {$url}",
                'type' => ChatMessage::TYPE_SYSTEM,
            ]);

            ChatThread::where('id', $conversation->thread_id)->update(['last_message_at' => now()]);
        }

        unset($this->threadMessages);
        session()->flash('message', __('Booking link sent via Messenger.'));
    }
};
?>

<div class="relative flex h-[calc(100vh-4rem)] flex-col overflow-hidden md:h-[calc(100vh-5rem)] md:flex-row" wire:poll.15s.visible="refreshData">
    {{-- Left Sidebar: Contact List --}}
    <div class="{{ $contactId ? 'hidden md:flex' : 'flex' }} w-full md:w-80 shrink-0 border-r border-zinc-200 dark:border-zinc-700 flex-col h-full">
        {{-- Header --}}
        <div class="p-3 sm:p-4 border-b border-zinc-200 dark:border-zinc-700">
            <flux:heading size="lg" class="mb-3">{{ __('Communications') }}</flux:heading>
            <flux:input wire:model.live.debounce.300ms="search" type="search" :placeholder="__('Search contacts...')" icon="magnifying-glass" size="sm" />
            <div class="mt-2">
                <flux:select wire:model.live="typeFilter" size="sm">
                    <option value="all">{{ __('All Types') }}</option>
                    <option value="customer">{{ __('Customers') }}</option>
                    <option value="agent">{{ __('Agents') }}</option>
                    <option value="crew">{{ __('Crew') }}</option>
                    <option value="internal">{{ __('Internal') }}</option>
                    <option value="messenger">{{ __('Messenger') }}</option>
                </flux:select>
            </div>
        </div>

        {{-- Contact List --}}
        <div class="flex-1 overflow-y-auto">
            {{-- Pinned Phone System entry --}}
            @can('phone.view_call_history')
                @php $phoneUnhandled = app(MissedCallVoicemailService::class)->getUnhandledCountForUser(auth()->user()); @endphp
                <button
                    wire:click="selectContact('phone')"
                    class="w-full text-left px-3 sm:px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors {{ $contactId === 'phone' ? 'bg-blue-50 dark:bg-blue-900/20 border-l-2 border-l-blue-500' : '' }}"
                >
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center shrink-0">
                            <flux:icon name="phone" class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Phone System') }}</span>
                            <p class="text-xs text-zinc-500 mt-0.5">{{ __('Call history & voicemails') }}</p>
                        </div>
                        @if ($phoneUnhandled > 0)
                            <span class="bg-red-500 text-white text-[10px] rounded-full min-w-5 h-5 flex items-center justify-center px-1.5 font-bold">
                                {{ $phoneUnhandled > 99 ? '99+' : $phoneUnhandled }}
                            </span>
                        @endif
                    </div>
                </button>
            @endcan

            @forelse ($this->contacts as $contact)
                <button
                    wire:key="contact-{{ $contact['id'] }}"
                    wire:click="selectContact('{{ $contact['id'] }}')"
                    class="w-full text-left px-3 sm:px-4 py-3 border-b border-zinc-100 dark:border-zinc-700/50 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors {{ $contactId == $contact['id'] ? 'bg-blue-50 dark:bg-blue-900/20 border-l-2 border-l-blue-500' : '' }}"
                >
                    <div class="flex items-start gap-3">
                        <div class="relative shrink-0">
                            <flux:avatar name="{{ $contact['name'] }}" size="sm" />
                            @if ($contact['has_unread'])
                                <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white dark:border-zinc-900"></span>
                            @endif
                            @if ($contact['has_duplicate_flag'] ?? false)
                                <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-amber-500 rounded-full border-2 border-white dark:border-zinc-900" title="{{ __('Possible duplicate') }}"></span>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium truncate {{ $contact['has_unread'] ? 'text-zinc-900 dark:text-white' : 'text-zinc-700 dark:text-zinc-300' }}">
                                    {{ $contact['name'] }}
                                </span>
                                @if ($contact['last_activity_at'])
                                    <span class="text-xs text-zinc-400 shrink-0">{{ $contact['last_activity_at']->diffForHumans(short: true) }}</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                @if ($contact['is_support_only'] ?? false)
                                    <flux:badge size="sm" color="amber">{{ __('Support Chat') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="{{ match($contact['type']) { 'customer' => 'green', 'agent' => 'blue', 'crew' => 'purple', 'sms' => 'green', 'messenger' => 'indigo', default => 'zinc' } }}">
                                        {{ match($contact['type']) { 'sms' => 'SMS', default => ucfirst($contact['type']) } }}
                                    </flux:badge>
                                @endif
                                @if ($contact['unread_count'] > 0)
                                    <span class="bg-red-500 text-white text-[10px] rounded-full min-w-4 h-4 flex items-center justify-center px-1 font-bold">
                                        {{ $contact['unread_count'] > 99 ? '99+' : $contact['unread_count'] }}
                                    </span>
                                @endif
                            </div>
                            @if ($contact['last_message_preview'])
                                <p class="text-xs text-zinc-500 truncate mt-1 {{ $contact['has_unread'] ? 'font-medium' : '' }}">
                                    {{ $contact['last_message_preview'] }}
                                </p>
                            @endif
                        </div>
                    </div>
                </button>
            @empty
                <div class="px-4 py-12 text-center">
                    <flux:icon name="chat-bubble-left-right" class="mx-auto size-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:text size="sm" class="mt-3 text-zinc-500">
                        @if ($search)
                            {{ __('No contacts matching') }} "{{ $search }}"
                        @else
                            {{ __('No conversations yet') }}
                        @endif
                    </flux:text>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Right Panel --}}
    <div class="{{ $contactId ? 'flex' : 'hidden md:flex' }} flex-1 flex-col min-w-0 h-full">
        @if ($this->isPhoneView())
            {{-- PHONE SYSTEM VIEW --}}
            <div class="px-3 sm:px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center gap-3 shrink-0">
                <button wire:click="clearContact" class="md:hidden shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <flux:icon name="arrow-left" class="size-5" />
                </button>
                <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center shrink-0">
                    <flux:icon name="phone" class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:heading size="sm">{{ __('Phone System') }}</flux:heading>
                    <flux:text size="xs" class="text-zinc-500">{{ __('Call history, missed calls & voicemails') }}</flux:text>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-3 sm:p-4">
                @livewire('communications.call-history', key('phone-system'))
            </div>
        @elseif ($this->isSmsContact() && $this->selectedSmsData)
            {{-- SMS INBOUND CONTACT --}}
            @php $smsContact = $this->selectedSmsData; @endphp
            <div class="px-3 sm:px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center gap-3 shrink-0">
                <button wire:click="clearContact" class="md:hidden shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <flux:icon name="arrow-left" class="size-5" />
                </button>
                <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center shrink-0">
                    <flux:icon name="device-phone-mobile" class="w-4 h-4 text-green-600 dark:text-green-400" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <flux:heading size="sm" class="truncate">{{ $smsContact['name'] }}</flux:heading>
                        <flux:badge size="sm" class="hidden sm:inline-flex" color="green">{{ __('SMS') }}</flux:badge>
                    </div>
                    <flux:text size="xs" class="text-zinc-500 truncate">{{ __('Inbound SMS conversation') }}</flux:text>
                </div>
                @if ($this->contactProjectPreview)
                    <div class="flex items-center gap-1 shrink-0">
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="clipboard-document"
                            x-on:click="navigator.clipboard.writeText('{{ $this->contactProjectPreview->getPortalUrl() }}'); $flux.toast('Preview link copied!')"
                            title="{{ __('Copy preview link') }}"
                        />
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="eye"
                            :href="$this->contactProjectPreview->getPortalUrl()"
                            target="_blank"
                            title="{{ __('Open preview') }}"
                        />
                    </div>
                @endif
            </div>

            {{-- SMS Tab Bar --}}
            @can('phone.view_call_history')
                <div class="flex items-center gap-0.5 px-2 sm:px-4 border-b border-zinc-200 dark:border-zinc-700 shrink-0">
                    @foreach (['texts' => __('Texts'), 'calls' => __('Calls')] as $tabKey => $tabLabel)
                        <button
                            wire:click="setSmsTab('{{ $tabKey }}')"
                            wire:key="sms-tab-{{ $tabKey }}"
                            class="px-2.5 sm:px-3 py-2.5 text-xs sm:text-sm font-medium whitespace-nowrap border-b-2 transition-colors {{ $smsActiveTab === $tabKey ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                        >
                            {{ $tabLabel }}
                        </button>
                    @endforeach
                </div>
            @endcan

            @if ($smsActiveTab === 'calls' && auth()->user()?->can('phone.view_call_history'))
                {{-- SMS Calls Tab --}}
                <div class="flex-1 overflow-y-auto p-3 sm:p-4">
                    @livewire('communications.call-history', ['phoneNumber' => $this->getSmsContactPhoneNumber()], key('calls-sms-' . $smsThreadId))
                </div>
            @elseif ($smsThreadId)
                {{-- SMS Texts Tab (messenger view) --}}
                <div class="flex flex-col flex-1 min-h-0">
                    <div
                        x-data="{
                            scrollToBottom() { this.$el.scrollTop = this.$el.scrollHeight },
                            init() {
                                this.$nextTick(() => this.scrollToBottom());
                                new MutationObserver(() => this.scrollToBottom()).observe(this.$el, { childList: true, subtree: true });
                            }
                        }"
                        class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-3"
                    >
                        @forelse ($this->threadMessages as $msg)
                            @php $isOwn = $msg->user_id === auth()->id(); @endphp
                            <div wire:key="msg-sms-{{ $msg->id }}" class="flex {{ $isOwn ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[80%] sm:max-w-[70%]">
                                    @if (! $isOwn)
                                        <p class="text-[11px] text-zinc-400 mb-0.5 px-1">{{ $msg->user?->name ?? ($smsContact['name'] ?? 'SMS') }}</p>
                                    @endif
                                    <div class="rounded-2xl px-3.5 py-2 text-sm {{ $isOwn ? 'bg-blue-600 text-white rounded-br-md' : 'bg-white dark:bg-zinc-700 text-zinc-800 dark:text-zinc-200 border border-zinc-200 dark:border-zinc-600 rounded-bl-md' }}">
                                        <span class="whitespace-pre-wrap break-words">{{ $msg->content }}</span>
                                    </div>
                                    <p class="text-[10px] text-zinc-400 mt-0.5 px-1 {{ $isOwn ? 'text-right' : '' }}">
                                        {{ $msg->created_at->format('M j, g:i A') }}
                                    </p>
                                </div>
                            </div>
                        @empty
                            <div class="flex items-center justify-center h-full">
                                <flux:text size="sm" class="text-zinc-400">{{ __('No messages in this SMS thread yet.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    {{-- Composer --}}
                    <div class="border-t border-zinc-200 dark:border-zinc-700 shrink-0">
                        @php $smsThread = ChatThread::find($smsThreadId); @endphp
                        @if ($smsThread)
                            <div class="p-2">
                                @livewire('chat.message-composer', ['thread' => $smsThread], key('composer-sms-' . $smsThread->id))
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @elseif ($this->isSetterContact() && $this->selectedSetterData)
            {{-- SETTER SUPPORT CONTACT --}}
            @php $setter = $this->selectedSetterData; @endphp
            <div class="px-3 sm:px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center gap-3 shrink-0">
                <button wire:click="clearContact" class="md:hidden shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <flux:icon name="arrow-left" class="size-5" />
                </button>
                <div class="w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
                    <flux:icon name="lifebuoy" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <flux:heading size="sm" class="truncate">{{ $setter['name'] }}</flux:heading>
                        <flux:badge size="sm" class="hidden sm:inline-flex" color="amber">{{ __('Setter Support') }}</flux:badge>
                    </div>
                    <flux:text size="xs" class="text-zinc-500 truncate">{{ __('Appointment setter support chat') }}</flux:text>
                </div>
            </div>

            {{-- Setter thread as full messenger view --}}
            @if ($setterThreadId)
                <div class="flex flex-col flex-1 min-h-0">
                    <div
                        x-data="{
                            scrollToBottom() { this.$el.scrollTop = this.$el.scrollHeight },
                            init() {
                                this.$nextTick(() => this.scrollToBottom());
                                new MutationObserver(() => this.scrollToBottom()).observe(this.$el, { childList: true, subtree: true });
                            }
                        }"
                        class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-3"
                    >
                        @forelse ($this->threadMessages as $msg)
                            @php $isOwn = $msg->user_id === auth()->id(); @endphp
                            <div wire:key="msg-setter-{{ $msg->id }}" class="flex {{ $isOwn ? 'justify-end' : ($msg->user_id ? 'justify-start' : 'justify-start') }}">
                                <div class="max-w-[80%] sm:max-w-[70%]">
                                    @if (! $isOwn)
                                        <p class="text-[11px] text-zinc-400 mb-0.5 px-1">{{ $msg->user?->name ?? ($setter['name'] ?? 'Guest') }}</p>
                                    @endif
                                    <div class="rounded-2xl px-3.5 py-2 text-sm {{ $isOwn ? 'bg-blue-600 text-white rounded-br-md' : 'bg-white dark:bg-zinc-700 text-zinc-800 dark:text-zinc-200 border border-zinc-200 dark:border-zinc-600 rounded-bl-md' }}">
                                        @if ($msg->isAudio())
                                            @include('components.communications._audio-message', ['message' => $msg, 'isOwn' => $isOwn])
                                        @elseif ($msg->needsTranslationFor(app()->getLocale()))
                                            <span class="whitespace-pre-wrap break-words">{{ $msg->getTranslation(app()->getLocale()) }}</span>
                                        @else
                                            <span class="whitespace-pre-wrap break-words">{{ $msg->content }}</span>
                                        @endif
                                    </div>
                                    @if (! $msg->isAudio() && $msg->needsTranslationFor(app()->getLocale()))
                                        <div x-data="{ showOriginal: false }" class="px-1 mt-0.5">
                                            <button @click="showOriginal = !showOriginal" class="text-[10px] underline opacity-70 hover:opacity-100 text-zinc-400">
                                                <span x-show="!showOriginal">{{ __('Show original') }}</span>
                                                <span x-show="showOriginal" x-cloak>{{ __('Hide original') }}</span>
                                            </button>
                                            <div x-show="showOriginal" x-cloak class="mt-0.5 text-xs italic text-zinc-500">
                                                {{ $msg->content }}
                                                <span class="opacity-60">({{ strtoupper($msg->getDetectedLanguage()) }})</span>
                                            </div>
                                        </div>
                                    @endif
                                    <div class="flex items-center gap-1 mt-0.5 px-1 {{ $isOwn ? 'justify-end' : '' }}">
                                        <p class="text-[10px] text-zinc-400">
                                            {{ $msg->created_at->format('M j, g:i A') }}
                                        </p>
                                        @if (! $msg->isSystem() && ! $msg->getDetectedLanguage())
                                            <button wire:click="translateMessage({{ $msg->id }})" class="text-[10px] text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="{{ __('Translate') }}">
                                                <flux:icon name="language" class="size-3" />
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-8">
                                <flux:text size="sm" class="text-zinc-400">{{ __('No messages in this support thread yet.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    {{-- Reply input --}}
                    @php $setterThread = ChatThread::find($setterThreadId); @endphp
                    @if ($setterThread)
                        <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 sm:p-4 shrink-0 bg-white dark:bg-zinc-900">
                            @livewire('chat.message-composer', ['thread' => $setterThread], key('composer-setter-' . $setterThread->id))
                        </div>
                    @endif
                </div>
            @endif
        @elseif ($this->isMessengerContact() && $this->selectedMessengerData)
            {{-- MESSENGER CONTACT --}}
            @php $messenger = $this->selectedMessengerData; @endphp
            <div class="px-3 sm:px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center gap-3 shrink-0">
                <button wire:click="clearContact" class="md:hidden shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <flux:icon name="arrow-left" class="size-5" />
                </button>
                <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
                    <flux:icon name="chat-bubble-oval-left" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <flux:heading size="sm" class="truncate">{{ $messenger['name'] }}</flux:heading>
                        <flux:badge size="sm" class="hidden sm:inline-flex" color="blue">{{ __('Messenger') }}</flux:badge>
                    </div>
                    <flux:text size="xs" class="text-zinc-500 truncate">{{ __('Facebook Messenger conversation') }}</flux:text>
                </div>
                @if ($this->canConvertMessenger)
                    <div class="flex items-center gap-1.5 shrink-0">
                        <flux:button size="sm" variant="primary" wire:click="openConvertModal" icon="user-plus">
                            <span class="hidden sm:inline">{{ __('Convert') }}</span>
                        </flux:button>
                        <flux:button size="sm" wire:click="sendBookingLink" icon="link" wire:confirm="{{ __('Send a booking form link to this Messenger user?') }}">
                            <span class="hidden sm:inline">{{ __('Send Link') }}</span>
                        </flux:button>
                    </div>
                @endif
            </div>

            {{-- Messenger thread as full messenger view --}}
            @if ($messengerThreadId)
                <div class="flex flex-col flex-1 min-h-0">
                    <div
                        x-data="{
                            scrollToBottom() { this.$el.scrollTop = this.$el.scrollHeight },
                            init() {
                                this.$nextTick(() => this.scrollToBottom());
                                new MutationObserver(() => this.scrollToBottom()).observe(this.$el, { childList: true, subtree: true });
                            }
                        }"
                        class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-3"
                    >
                        @forelse ($this->threadMessages as $msg)
                            @php $isOwn = $msg->user_id === auth()->id(); @endphp
                            <div wire:key="msg-messenger-{{ $msg->id }}" class="flex {{ $isOwn ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[80%] sm:max-w-[70%]">
                                    @if (! $isOwn)
                                        <p class="text-[11px] text-zinc-400 mb-0.5 px-1">{{ $msg->user?->name ?? ($messenger['name'] ?? 'Messenger User') }}</p>
                                    @endif
                                    <div class="rounded-2xl px-3.5 py-2 text-sm {{ $isOwn ? 'bg-blue-600 text-white rounded-br-md' : 'bg-white dark:bg-zinc-700 text-zinc-800 dark:text-zinc-200 border border-zinc-200 dark:border-zinc-600 rounded-bl-md' }}">
                                        @if ($msg->isAudio())
                                            @include('components.communications._audio-message', ['message' => $msg, 'isOwn' => $isOwn])
                                        @elseif ($msg->needsTranslationFor(app()->getLocale()))
                                            <span class="whitespace-pre-wrap break-words">{{ $msg->getTranslation(app()->getLocale()) }}</span>
                                        @else
                                            <span class="whitespace-pre-wrap break-words">{{ $msg->content }}</span>
                                        @endif
                                    </div>
                                    @if (! $msg->isAudio() && $msg->needsTranslationFor(app()->getLocale()))
                                        <div x-data="{ showOriginal: false }" class="px-1 mt-0.5">
                                            <button @click="showOriginal = !showOriginal" class="text-[10px] underline opacity-70 hover:opacity-100 text-zinc-400">
                                                <span x-show="!showOriginal">{{ __('Show original') }}</span>
                                                <span x-show="showOriginal" x-cloak>{{ __('Hide original') }}</span>
                                            </button>
                                            <div x-show="showOriginal" x-cloak class="mt-0.5 text-xs italic text-zinc-500">
                                                {{ $msg->content }}
                                                <span class="opacity-60">({{ strtoupper($msg->getDetectedLanguage()) }})</span>
                                            </div>
                                        </div>
                                    @endif
                                    <div class="flex items-center gap-1 mt-0.5 px-1 {{ $isOwn ? 'justify-end' : '' }}">
                                        <p class="text-[10px] text-zinc-400">
                                            {{ $msg->created_at->format('M j, g:i A') }}
                                        </p>
                                        @if (! $msg->isSystem() && ! $msg->getDetectedLanguage())
                                            <button wire:click="translateMessage({{ $msg->id }})" class="text-[10px] text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="{{ __('Translate') }}">
                                                <flux:icon name="language" class="size-3" />
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-8">
                                <flux:text size="sm" class="text-zinc-400">{{ __('No messages in this conversation yet.') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    {{-- Reply input --}}
                    @php $messengerThread = ChatThread::find($messengerThreadId); @endphp
                    @if ($messengerThread)
                        <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 sm:p-4 shrink-0 bg-white dark:bg-zinc-900">
                            @livewire('chat.message-composer', ['thread' => $messengerThread], key('composer-messenger-' . $messengerThread->id))
                        </div>
                    @endif
                </div>
            @endif
        @elseif ($this->selectedContact)
            {{-- Contact Header --}}
            <div class="px-3 sm:px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center gap-3 shrink-0">
                <button wire:click="clearContact" class="md:hidden shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <flux:icon name="arrow-left" class="size-5" />
                </button>
                <flux:avatar name="{{ $this->selectedContact->name }}" size="sm" />
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <flux:heading size="sm" class="truncate">{{ $this->selectedContact->name }}</flux:heading>
                        <flux:badge size="sm" class="hidden sm:inline-flex" color="{{ match($this->selectedContact->type) { 'customer' => 'green', 'agent' => 'blue', 'crew' => 'purple', default => 'zinc' } }}">
                            {{ ucfirst($this->selectedContact->type) }}
                        </flux:badge>
                    </div>
                    <flux:text size="xs" class="text-zinc-500 truncate">{{ $this->selectedContact->email }}</flux:text>
                </div>
                <div class="flex items-center gap-1 sm:gap-2 shrink-0">
                    @can('chat.access')
                        <flux:button size="sm" icon="chat-bubble-left" wire:click="startDirectChat" class="hidden sm:inline-flex">
                            {{ __('Message') }}
                        </flux:button>
                        <flux:button size="sm" icon="chat-bubble-left" wire:click="startDirectChat" class="sm:hidden" />
                    @endcan
                    @if ($this->selectedContact->isCustomer())
                        @php $customer = Customer::where('user_id', $this->selectedContact->id)->first(); @endphp
                        @if ($customer)
                            <flux:button size="sm" variant="ghost" icon="user" :href="route('customers.show', $customer)" wire:navigate />
                        @endif
                        @if ($this->contactProjectPreview)
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="clipboard-document"
                                x-on:click="navigator.clipboard.writeText('{{ $this->contactProjectPreview->getPortalUrl() }}'); $flux.toast('Preview link copied!')"
                                title="{{ __('Copy preview link') }}"
                            />
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="eye"
                                :href="$this->contactProjectPreview->getPortalUrl()"
                                target="_blank"
                                title="{{ __('Open preview') }}"
                            />
                        @endif
                    @elseif ($this->selectedContact->isAgent())
                        <flux:button size="sm" variant="ghost" icon="user" :href="route('agents.show', $this->selectedContact)" wire:navigate />
                    @elseif ($this->selectedContact->isCrew())
                        <flux:button size="sm" variant="ghost" icon="user" :href="route('crews.show', $this->selectedContact)" wire:navigate />
                    @endif
                </div>
            </div>

            {{-- Tabs --}}
            <div class="flex items-center gap-0.5 sm:gap-1 px-2 sm:px-4 border-b border-zinc-200 dark:border-zinc-700 overflow-x-auto shrink-0">
                @php
                    $tabs = ['all' => __('All')];
                    if ($this->selectedContact->isCustomer()) {
                        $tabs['messenger'] = __('Messenger');
                        $tabs['deals'] = __('Deals');
                    }
                    $tabs['direct'] = __('Direct');
                    $tabs['emails'] = __('Emails');
                    if (auth()->user()?->can('phone.view_call_history')) {
                        $tabs['calls'] = __('Calls');
                    }
                    $tabCounts = $this->tabUnreadCounts;
                @endphp
                @foreach ($tabs as $key => $label)
                    @php $tabUnread = $tabCounts[$key] ?? 0; @endphp
                    <button
                        wire:click="setTab('{{ $key }}')"
                        wire:key="tab-{{ $key }}"
                        class="relative px-2.5 sm:px-3 py-2.5 text-xs sm:text-sm font-medium whitespace-nowrap border-b-2 transition-colors {{ $activeTab === $key ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
                    >
                        {{ $label }}
                        @if ($tabUnread > 0)
                            <span class="ml-1 inline-flex items-center justify-center bg-red-500 text-white text-[10px] rounded-full min-w-4 h-4 px-1 font-bold">
                                {{ $tabUnread > 99 ? '99+' : $tabUnread }}
                            </span>
                        @endif
                    </button>
                @endforeach
                @if ($activeTab === 'emails')
                    <div class="ml-auto shrink-0">
                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="route('email.inbox')" wire:navigate class="hidden sm:inline-flex">
                            {{ __('Full Email Client') }}
                        </flux:button>
                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="route('email.inbox')" wire:navigate class="sm:hidden" />
                    </div>
                @endif
            </div>

            {{-- Communication Items / Email Viewer --}}
            <div class="flex-1 overflow-hidden flex flex-col">
                @if ($viewingEmailThreadId)
                    {{-- EMAIL THREAD VIEWER: Takes over the panel --}}
                    <div class="flex flex-col h-full">
                        {{-- Thread header with back button --}}
                        <div class="px-3 sm:px-4 py-2 bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700 flex items-center gap-2 shrink-0">
                            <flux:button size="xs" variant="ghost" icon="arrow-left" wire:click="closeEmailView" />
                            @php $emailThread = \App\Models\EmailThread::find($viewingEmailThreadId); @endphp
                            <div class="flex items-center gap-2 min-w-0 flex-1">
                                <div class="w-6 h-6 rounded-full flex items-center justify-center bg-amber-100 dark:bg-amber-900/30">
                                    <flux:icon name="envelope" class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" />
                                </div>
                                <flux:text class="font-medium text-sm truncate">{{ $emailThread?->subject ?? __('Email Thread') }}</flux:text>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="route('email.inbox', ['threadId' => $viewingEmailThreadId])" wire:navigate title="{{ __('Open in full email client') }}" />
                        </div>

                        @if ($composingReply)
                            {{-- INLINE REPLY COMPOSER --}}
                            <div class="flex-1 overflow-y-auto p-3 sm:p-4">
                                <div class="space-y-3 max-w-3xl">
                                    <div class="flex items-center gap-2">
                                        <label class="w-16 shrink-0 text-sm text-zinc-500">{{ __('To') }}</label>
                                        <flux:input wire:model="replyTo" size="sm" readonly />
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="w-16 shrink-0 text-sm text-zinc-500">{{ __('Subject') }}</label>
                                        <flux:input wire:model="replySubject" size="sm" />
                                    </div>

                                    <div
                                        x-data="{
                                            content: $wire.entangle('replyBody'),
                                            updateIframe() {
                                                const iframe = this.$refs.emailFrame;
                                                if (!iframe) return;
                                                const doc = iframe.contentDocument || iframe.contentWindow.document;
                                                doc.open();
                                                doc.write(`<!DOCTYPE html><html><head><style>
                                                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; line-height: 1.5; color: #333; margin: 0; padding: 12px; word-wrap: break-word; overflow-wrap: break-word; }
                                                    img { max-width: 100%; height: auto; }
                                                    table { max-width: 100%; }
                                                    blockquote { border-left: 2px solid #ccc; padding-left: 12px; margin-left: 0; color: #666; }
                                                    a { color: #2563eb; }
                                                </style></head><body contenteditable='true'>` + (this.content || '') + `</body></html>`);
                                                doc.close();
                                                doc.body.addEventListener('input', () => {
                                                    this.content = doc.body.innerHTML;
                                                });
                                            },
                                            init() {
                                                this.$nextTick(() => this.updateIframe());
                                                this.$watch('content', () => {
                                                    const iframe = this.$refs.emailFrame;
                                                    if (!iframe) return;
                                                    const doc = iframe.contentDocument || iframe.contentWindow.document;
                                                    if (doc.body && doc.body.innerHTML !== this.content) {
                                                        this.updateIframe();
                                                    }
                                                });
                                            }
                                        }"
                                        class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden"
                                    >
                                        <iframe
                                            x-ref="emailFrame"
                                            class="w-full bg-white"
                                            style="min-height: 200px; max-height: 50vh; height: 300px; border: none;"
                                        ></iframe>
                                    </div>
                                </div>
                            </div>

                            {{-- Compose actions --}}
                            <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 sm:p-4 shrink-0 bg-white dark:bg-zinc-900 flex justify-end gap-2">
                                <flux:button wire:click="cancelEmailReply" variant="ghost">{{ __('Cancel') }}</flux:button>
                                <flux:button wire:click="sendEmailReply" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="sendEmailReply">
                                    <span wire:loading.remove wire:target="sendEmailReply">{{ __('Send Reply') }}</span>
                                    <span wire:loading wire:target="sendEmailReply">{{ __('Sending...') }}</span>
                                </flux:button>
                            </div>
                        @else
                            {{-- EMAIL MESSAGES --}}
                            <div class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-4">
                                @forelse ($this->emailThreadMessages as $email)
                                    <div wire:key="email-{{ $email->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                                        {{-- Email header --}}
                                        <div class="px-4 py-3 bg-zinc-50 dark:bg-zinc-800/50 flex items-start gap-3">
                                            <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 {{ $email->direction === 'outbound' ? 'bg-blue-100 dark:bg-blue-900/30' : 'bg-zinc-200 dark:bg-zinc-700' }}">
                                                <span class="text-xs font-bold {{ $email->direction === 'outbound' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-600 dark:text-zinc-400' }}">
                                                    {{ strtoupper(substr($email->from_name ?? $email->from_address ?? '?', 0, 1)) }}
                                                </span>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <flux:text class="font-medium text-sm">{{ $email->from_name ?? $email->from_address }}</flux:text>
                                                    @if ($email->direction === 'outbound')
                                                        <flux:badge size="sm" color="blue">{{ __('Sent') }}</flux:badge>
                                                    @endif
                                                </div>
                                                @if ($email->to_addresses)
                                                    <flux:text size="xs" class="text-zinc-400 truncate">
                                                        {{ __('To') }}: {{ collect($email->to_addresses)->pluck('address')->implode(', ') }}
                                                    </flux:text>
                                                @endif
                                            </div>
                                            <div class="shrink-0 flex items-center gap-1">
                                                <flux:text size="xs" class="text-zinc-400">{{ ($email->sent_at ?? $email->created_at)?->format('M j, g:i A') }}</flux:text>
                                                @if ($email->direction === 'inbound')
                                                    <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="startEmailReply({{ $email->id }})" title="{{ __('Reply') }}" />
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Email body --}}
                                        <div class="p-4">
                                            @if ($email->body_html)
                                                <div
                                                    x-data="{
                                                        init() {
                                                            const iframe = this.$refs.body;
                                                            const doc = iframe.contentDocument || iframe.contentWindow.document;
                                                            doc.open();
                                                            doc.write(`<!DOCTYPE html><html><head><style>
                                                                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; line-height: 1.6; color: #333; margin: 0; padding: 0; word-wrap: break-word; overflow-wrap: break-word; }
                                                                img { max-width: 100%; height: auto; }
                                                                table { max-width: 100%; }
                                                                blockquote { border-left: 2px solid #ccc; padding-left: 12px; margin-left: 0; color: #666; }
                                                                a { color: #2563eb; }
                                                            </style></head><body>` + @js($email->body_html) + `</body></html>`);
                                                            doc.close();
                                                            // Auto-resize iframe to fit content
                                                            const resize = () => { iframe.style.height = (doc.body.scrollHeight + 16) + 'px'; };
                                                            iframe.onload = resize;
                                                            setTimeout(resize, 200);
                                                        }
                                                    }"
                                                >
                                                    <iframe x-ref="body" class="w-full bg-white border-none" style="min-height: 60px; max-height: 400px;"></iframe>
                                                </div>
                                            @elseif ($email->body_text)
                                                <pre class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap font-sans">{{ $email->body_text }}</pre>
                                            @else
                                                <flux:text size="sm" class="text-zinc-400 italic">{{ __('No content') }}</flux:text>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-center py-8">
                                        <flux:text size="sm" class="text-zinc-400">{{ __('No messages in this thread.') }}</flux:text>
                                    </div>
                                @endforelse
                            </div>

                            {{-- Quick reply bar --}}
                            @php $lastInbound = $this->emailThreadMessages->where('direction', 'inbound')->last(); @endphp
                            @if ($lastInbound)
                                <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 shrink-0 bg-white dark:bg-zinc-900">
                                    <flux:button wire:click="startEmailReply({{ $lastInbound->id }})" variant="primary" size="sm" icon="arrow-uturn-left" class="w-full sm:w-auto">
                                        {{ __('Reply') }}
                                    </flux:button>
                                </div>
                            @endif
                        @endif
                    </div>
                @elseif ($activeTab === 'calls')
                    {{-- CALL HISTORY TAB: Scoped to this contact's customer or phone number --}}
                    <div class="flex-1 overflow-y-auto p-3 sm:p-4">
                        @livewire('communications.call-history', [
                            'customerId' => $this->getContactCustomerId(),
                            'phoneNumber' => $this->getContactPhoneNumber(),
                        ], key('calls-' . $contactId))
                    </div>
                @elseif ($this->contactItems->isEmpty())
                    <div class="text-center py-12 px-4">
                        <flux:icon name="chat-bubble-left-right" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                        <flux:text size="sm" class="text-zinc-500">{{ __('No communications found.') }}</flux:text>
                    </div>
                @else
                    @php
                        $chatSpecificTab = in_array($activeTab, ['messenger', 'deals', 'direct']);
                        $chatItems = $this->contactItems->where('type', 'chat');
                        $singleThread = $chatSpecificTab && $chatItems->count() === 1;
                    @endphp

                    @if ($singleThread)
                        {{-- MESSENGER VIEW: Single thread on a specific tab = full messenger --}}
                        @php $theItem = $chatItems->first(); @endphp
                        <div class="flex flex-col h-full">
                            <div class="px-3 sm:px-4 py-2 bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between shrink-0">
                                <div class="flex items-center gap-2 min-w-0">
                                    <div class="w-6 h-6 rounded-full flex items-center justify-center {{ $theItem['channel'] === 'facebook' ? 'bg-blue-100 dark:bg-blue-900/30' : 'bg-zinc-200 dark:bg-zinc-700' }}">
                                        <flux:icon :name="$theItem['icon']" class="w-3.5 h-3.5 {{ $theItem['channel'] === 'facebook' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-500' }}" />
                                    </div>
                                    <flux:text class="font-medium text-sm truncate">{{ $theItem['label'] }}</flux:text>
                                </div>
                                <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$theItem['url']" wire:navigate title="{{ __('Open in full view') }}" />
                            </div>

                            @php
                                if ($expandedThreadId !== $theItem['thread_id']) {
                                    $this->expandedThreadId = $theItem['thread_id'];
                                    unset($this->threadMessages);
                                }
                            @endphp
                            <div
                                x-data="{
                                    scrollToBottom() { this.$el.scrollTop = this.$el.scrollHeight },
                                    init() {
                                        this.$nextTick(() => this.scrollToBottom());
                                        new MutationObserver(() => this.scrollToBottom()).observe(this.$el, { childList: true, subtree: true });
                                    }
                                }"
                                class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-3"
                            >
                                @forelse ($this->threadMessages as $msg)
                                    @php $isOwn = $msg->user_id === auth()->id(); @endphp
                                    <div wire:key="msg-full-{{ $msg->id }}" class="flex {{ $isOwn ? 'justify-end' : 'justify-start' }}">
                                        <div class="max-w-[80%] sm:max-w-[70%]">
                                            @if (! $isOwn)
                                                <p class="text-[11px] text-zinc-400 mb-0.5 px-1">{{ $msg->user?->name ?? 'Guest' }}</p>
                                            @endif
                                            <div class="rounded-2xl px-3.5 py-2 text-sm {{ $isOwn ? 'bg-blue-600 text-white rounded-br-md' : 'bg-white dark:bg-zinc-700 text-zinc-800 dark:text-zinc-200 border border-zinc-200 dark:border-zinc-600 rounded-bl-md' }}">
                                                @if ($msg->isAudio())
                                                    @include('components.communications._audio-message', ['message' => $msg, 'isOwn' => $isOwn])
                                                @elseif ($msg->needsTranslationFor(app()->getLocale()))
                                                    <span class="whitespace-pre-wrap break-words">{{ $msg->getTranslation(app()->getLocale()) }}</span>
                                                @else
                                                    <span class="whitespace-pre-wrap break-words">{{ $msg->content }}</span>
                                                @endif
                                            </div>
                                            @if (! $msg->isAudio() && $msg->needsTranslationFor(app()->getLocale()))
                                                <div x-data="{ showOriginal: false }" class="px-1 mt-0.5">
                                                    <button @click="showOriginal = !showOriginal" class="text-[10px] underline opacity-70 hover:opacity-100 text-zinc-400">
                                                        <span x-show="!showOriginal">{{ __('Show original') }}</span>
                                                        <span x-show="showOriginal" x-cloak>{{ __('Hide original') }}</span>
                                                    </button>
                                                    <div x-show="showOriginal" x-cloak class="mt-0.5 text-xs italic text-zinc-500">
                                                        {{ $msg->content }}
                                                        <span class="opacity-60">({{ strtoupper($msg->getDetectedLanguage()) }})</span>
                                                    </div>
                                                </div>
                                            @endif
                                            <div class="flex items-center gap-1 mt-0.5 px-1 {{ $isOwn ? 'justify-end' : '' }}">
                                                <p class="text-[10px] text-zinc-400">
                                                    {{ $msg->created_at->format('M j, g:i A') }}
                                                </p>
                                                @if (! $msg->isSystem() && ! $msg->getDetectedLanguage())
                                                    <button wire:click="translateMessage({{ $msg->id }})" class="text-[10px] text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="{{ __('Translate') }}">
                                                        <flux:icon name="language" class="size-3" />
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="flex items-center justify-center h-full">
                                        <p class="text-sm text-zinc-400">{{ __('No messages yet. Send the first message!') }}</p>
                                    </div>
                                @endforelse
                            </div>

                            @php $composerThread = ChatThread::find($theItem['thread_id']); @endphp
                            @if ($composerThread)
                                <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 sm:p-4 shrink-0 bg-white dark:bg-zinc-900">
                                    @livewire('chat.message-composer', ['thread' => $composerThread], key('composer-full-' . $composerThread->id))
                                </div>
                            @endif
                        </div>
                    @else
                        {{-- CARD VIEW: All tab, emails tab, or multiple threads --}}
                        <div class="space-y-2 p-3 sm:p-4 overflow-y-auto flex-1">
                            @foreach ($this->contactItems as $index => $item)
                                <div wire:key="item-{{ $index }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden transition-colors">
                                    {{-- Item Header --}}
                                    <div class="flex items-start gap-2 sm:gap-3 p-3 sm:p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                        <div class="shrink-0 mt-0.5">
                                            @if ($item['type'] === 'call')
                                                @php
                                                    $callBg = match ($item['call_status'] ?? '') {
                                                        'no-answer' => 'bg-red-100 dark:bg-red-900/30',
                                                        'voicemail' => 'bg-amber-100 dark:bg-amber-900/30',
                                                        default => ($item['direction'] ?? '') === 'inbound' ? 'bg-green-100 dark:bg-green-900/30' : 'bg-blue-100 dark:bg-blue-900/30',
                                                    };
                                                    $callText = match ($item['call_status'] ?? '') {
                                                        'no-answer' => 'text-red-600 dark:text-red-400',
                                                        'voicemail' => 'text-amber-600 dark:text-amber-400',
                                                        default => ($item['direction'] ?? '') === 'inbound' ? 'text-green-600 dark:text-green-400' : 'text-blue-600 dark:text-blue-400',
                                                    };
                                                @endphp
                                                <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center {{ $callBg }}">
                                                    <flux:icon :name="$item['icon']" class="w-3.5 h-3.5 sm:w-4 sm:h-4 {{ $callText }}" />
                                                </div>
                                            @else
                                                <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center {{ $item['type'] === 'email' ? 'bg-amber-100 dark:bg-amber-900/30' : ($item['channel'] === 'facebook' ? 'bg-blue-100 dark:bg-blue-900/30' : 'bg-zinc-100 dark:bg-zinc-800') }}">
                                                    <flux:icon :name="$item['icon']" class="w-3.5 h-3.5 sm:w-4 sm:h-4 {{ $item['type'] === 'email' ? 'text-amber-600 dark:text-amber-400' : ($item['channel'] === 'facebook' ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-500') }}" />
                                                </div>
                                            @endif
                                        </div>
                                        @if ($item['type'] === 'call')
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <flux:text class="font-medium text-sm">{{ $item['label'] }}</flux:text>
                                                    <flux:badge size="sm" color="{{ match($item['call_status'] ?? '') { 'no-answer' => 'red', 'voicemail' => 'yellow', default => 'zinc' } }}">{{ __('Phone') }}</flux:badge>
                                                </div>
                                                @if ($item['preview'])
                                                    <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">{{ $item['preview'] }}</flux:text>
                                                @endif
                                            </div>
                                        @elseif ($item['type'] === 'chat' && $item['thread_id'])
                                            <button wire:click="expandThread({{ $item['thread_id'] }})" class="flex-1 min-w-0 text-left">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <flux:text class="font-medium text-sm">{{ $item['label'] }}</flux:text>
                                                    <flux:badge size="sm" color="{{ $item['channel'] === 'facebook' ? 'blue' : 'zinc' }}">
                                                        {{ $item['channel'] === 'facebook' ? __('Messenger') : ucfirst($item['thread_type'] ?? 'Chat') }}
                                                    </flux:badge>
                                                </div>
                                                <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">{{ $item['preview'] }}</flux:text>
                                            </button>
                                        @elseif ($item['type'] === 'email' && ($item['email_thread_id'] ?? null))
                                            {{-- Email items open inline thread viewer --}}
                                            <button wire:click="viewEmailThread({{ $item['email_thread_id'] }})" class="flex-1 min-w-0 text-left">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <flux:text class="font-medium text-sm {{ ! ($item['is_read'] ?? true) ? 'font-bold' : '' }}">{{ $item['label'] }}</flux:text>
                                                    <flux:badge size="sm" color="amber">{{ __('Email') }}</flux:badge>
                                                    @if (! ($item['is_read'] ?? true))
                                                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                                                    @endif
                                                </div>
                                                <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">{{ $item['preview'] }}</flux:text>
                                            </button>
                                        @else
                                            {{-- Fallback: link to email inbox --}}
                                            <a href="{{ $item['url'] }}" wire:navigate class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <flux:text class="font-medium text-sm">{{ $item['label'] }}</flux:text>
                                                    <flux:badge size="sm" color="amber">{{ __('Email') }}</flux:badge>
                                                </div>
                                                <flux:text size="sm" class="text-zinc-500 truncate mt-0.5">{{ $item['preview'] }}</flux:text>
                                            </a>
                                        @endif
                                        <div class="shrink-0 text-right">
                                            @if ($item['date'])
                                                <flux:text size="xs" class="text-zinc-400">{{ $item['date']->diffForHumans() }}</flux:text>
                                            @endif
                                            @if ($item['type'] === 'call' && ($item['call_duration'] ?? 0) > 0)
                                                <flux:text size="xs" class="text-zinc-400">{{ gmdate($item['call_duration'] >= 3600 ? 'G:i:s' : 'i:s', $item['call_duration']) }}</flux:text>
                                            @elseif ($item['type'] === 'chat' && $item['thread_id'])
                                                <div class="flex items-center gap-1 mt-1">
                                                    <flux:button size="xs" variant="ghost" icon="{{ $expandedThreadId === $item['thread_id'] ? 'chevron-up' : 'chevron-down' }}" wire:click="expandThread({{ $item['thread_id'] }})" />
                                                    <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$item['url']" wire:navigate title="{{ __('Open in full view') }}" />
                                                </div>
                                            @elseif ($item['type'] === 'email' && ($item['email_thread_id'] ?? null))
                                                <flux:button size="xs" variant="ghost" icon="chevron-right" wire:click="viewEmailThread({{ $item['email_thread_id'] }})" class="mt-1" />
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Inline Chat Messages (expanded) --}}
                                    @if ($item['type'] === 'chat' && $item['thread_id'] && $expandedThreadId === $item['thread_id'])
                                        <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/30">
                                            <div
                                                x-data="{
                                                    scrollToBottom() { this.$el.scrollTop = this.$el.scrollHeight },
                                                    init() {
                                                        this.$nextTick(() => this.scrollToBottom());
                                                        new MutationObserver(() => this.scrollToBottom()).observe(this.$el, { childList: true, subtree: true });
                                                    }
                                                }"
                                                class="max-h-72 overflow-y-auto p-3 space-y-2"
                                            >
                                                @forelse ($this->threadMessages as $msg)
                                                    @php $isOwn = $msg->user_id === auth()->id(); @endphp
                                                    <div wire:key="msg-{{ $msg->id }}" class="flex {{ $isOwn ? 'justify-end' : 'justify-start' }}">
                                                        <div class="max-w-[75%]">
                                                            @if (! $isOwn)
                                                                <p class="text-[10px] text-zinc-400 mb-0.5 px-1">{{ $msg->user?->name ?? 'Guest' }}</p>
                                                            @endif
                                                            <div class="rounded-lg px-3 py-2 text-sm {{ $isOwn ? 'bg-blue-600 text-white' : 'bg-white dark:bg-zinc-700 text-zinc-800 dark:text-zinc-200 border border-zinc-200 dark:border-zinc-600' }}">
                                                                @if ($msg->isAudio())
                                                                    @include('components.communications._audio-message', ['message' => $msg, 'isOwn' => $isOwn])
                                                                @elseif ($msg->needsTranslationFor(app()->getLocale()))
                                                                    <span class="whitespace-pre-wrap break-words">{{ $msg->getTranslation(app()->getLocale()) }}</span>
                                                                @else
                                                                    <span class="whitespace-pre-wrap break-words">{{ $msg->content }}</span>
                                                                @endif
                                                            </div>
                                                            @if (! $msg->isAudio() && $msg->needsTranslationFor(app()->getLocale()))
                                                                <div x-data="{ showOriginal: false }" class="px-1 mt-0.5">
                                                                    <button @click="showOriginal = !showOriginal" class="text-[10px] underline opacity-70 hover:opacity-100 text-zinc-400">
                                                                        <span x-show="!showOriginal">{{ __('Show original') }}</span>
                                                                        <span x-show="showOriginal" x-cloak>{{ __('Hide original') }}</span>
                                                                    </button>
                                                                    <div x-show="showOriginal" x-cloak class="mt-0.5 text-xs italic text-zinc-500">
                                                                        {{ $msg->content }}
                                                                        <span class="opacity-60">({{ strtoupper($msg->getDetectedLanguage()) }})</span>
                                                                    </div>
                                                                </div>
                                                            @endif
                                                            <div class="flex items-center gap-1 mt-0.5 px-1 {{ $isOwn ? 'justify-end' : '' }}">
                                                                <p class="text-[10px] text-zinc-400">
                                                                    {{ $msg->created_at->format('M j, g:i A') }}
                                                                </p>
                                                                @if (! $msg->isSystem() && ! $msg->getDetectedLanguage())
                                                                    <button wire:click="translateMessage({{ $msg->id }})" class="text-[10px] text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="{{ __('Translate') }}">
                                                                        <flux:icon name="language" class="size-3" />
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                @empty
                                                    <p class="text-center text-xs text-zinc-400 py-4">{{ __('No messages yet') }}</p>
                                                @endforelse
                                            </div>

                                            @php $inlineThread = ChatThread::find($item['thread_id']); @endphp
                                            @if ($inlineThread)
                                                <div class="border-t border-zinc-200 dark:border-zinc-700 p-3">
                                                    @livewire('chat.message-composer', ['thread' => $inlineThread], key('composer-inline-' . $inlineThread->id))
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        @else
            {{-- Empty state: no contact selected --}}
            <div class="hidden md:flex flex-1 items-center justify-center">
                <div class="text-center px-4">
                    <flux:icon name="chat-bubble-left-right" class="mx-auto size-16 text-zinc-200 dark:text-zinc-700" />
                    <flux:heading size="lg" class="mt-4 text-zinc-400 dark:text-zinc-500">{{ __('Select a conversation') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-400 dark:text-zinc-500">{{ __('Choose a contact from the sidebar to view their communications') }}</flux:text>
                </div>
            </div>
        @endif
    </div>

    {{-- Convert to Customer Modal --}}
    <flux:modal wire:model="showConvertModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Convert to Customer') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ __('Create a customer record, deal, and draft estimate from this Messenger conversation.') }}</flux:text>

            <form wire:submit="submitConvertToCustomer" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>{{ __('First Name') }} *</flux:label>
                        <flux:input wire:model="convertFirstName" required />
                        <flux:error name="convertFirstName" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Last Name') }}</flux:label>
                        <flux:input wire:model="convertLastName" />
                        <flux:error name="convertLastName" />
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:label>{{ __('Email') }}</flux:label>
                        <flux:input wire:model="convertEmail" type="email" />
                        <flux:error name="convertEmail" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Phone') }}</flux:label>
                        <flux:input wire:model="convertPhone" type="tel" />
                        <flux:error name="convertPhone" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Address') }}</flux:label>
                    <flux:input wire:model="convertAddress" />
                    <flux:error name="convertAddress" />
                </flux:field>

                <div class="grid grid-cols-3 gap-3">
                    <flux:field>
                        <flux:label>{{ __('City') }}</flux:label>
                        <flux:input wire:model="convertCity" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('State') }}</flux:label>
                        <flux:input wire:model="convertState" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Zip') }}</flux:label>
                        <flux:input wire:model="convertZip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="convertNotes" rows="2" />
                    <flux:error name="convertNotes" />
                </flux:field>

                <div class="flex justify-end gap-2 pt-2">
                    <flux:button wire:click="$set('showConvertModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submitConvertToCustomer">{{ __('Create Customer') }}</span>
                        <span wire:loading wire:target="submitConvertToCustomer">{{ __('Creating...') }}</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
