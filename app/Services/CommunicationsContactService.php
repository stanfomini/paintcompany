<?php

namespace App\Services;

use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmailCrmLink;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CommunicationsContactService
{
    /**
     * Get contacts for the viewer, aggregated across chat and email channels.
     *
     * @return Collection<int, array{id: int, name: string, type: string, avatar_url: ?string, initials: string, last_activity_at: ?\Carbon\Carbon, last_message_preview: ?string, unread_count: int, has_unread: bool}>
     */
    public function getContacts(User $viewer, ?string $search = null, ?string $typeFilter = 'all'): Collection
    {
        // Step 1: Find user IDs who share chat threads with the viewer (active participants)
        $contactUserIds = ChatParticipant::query()
            ->whereIn('thread_id', function ($query) use ($viewer) {
                $query->select('thread_id')
                    ->from('chat_participants')
                    ->where('user_id', $viewer->id)
                    ->whereNull('left_at');
            })
            ->whereNull('left_at')
            ->where('user_id', '!=', $viewer->id)
            ->distinct()
            ->pluck('user_id');

        // Step 2: Load users with basic info
        $contacts = collect();
        $users = collect();

        if ($contactUserIds->isNotEmpty()) {
            $usersQuery = User::query()->whereIn('id', $contactUserIds);

            // Apply search filter
            if ($search) {
                $usersQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Apply type filter (only for user-based contacts)
            if ($typeFilter && $typeFilter !== 'all') {
                $usersQuery->where('type', $typeFilter);
            }

            $users = $usersQuery->get();
        }

        if ($users->isNotEmpty()) {
            // Step 3: Preload shared threads for all contacts at once
            $sharedThreads = ChatThread::query()
                ->forUser($viewer)
                ->where(function ($q) use ($users) {
                    $q->whereHas('participants', function ($pq) use ($users) {
                        $pq->whereIn('user_id', $users->pluck('id'))
                            ->whereNull('left_at');
                    });
                })
                ->with(['latestMessage.user'])
                ->get();

            // Group threads by contact user ID
            $threadsByContact = collect();
            foreach ($sharedThreads as $thread) {
                $participantIds = $thread->activeParticipants()
                    ->pluck('user_id')
                    ->filter(fn ($id) => $id !== $viewer->id);

                foreach ($participantIds as $userId) {
                    if (! $threadsByContact->has($userId)) {
                        $threadsByContact[$userId] = collect();
                    }
                    $threadsByContact[$userId]->push($thread);
                }
            }

            // Step 4: Batch-load email data for all contacts upfront to avoid N+1
            $customerUserIds = $users->where('type', 'customer')->pluck('id');
            $customersMap = $customerUserIds->isNotEmpty()
                ? Customer::whereIn('user_id', $customerUserIds)->get()->keyBy('user_id')
                : collect();

            // Batch-load linked email message IDs for all customers
            $customerIds = $customersMap->pluck('id');
            $emailLinksMap = $customerIds->isNotEmpty()
                ? EmailCrmLink::where('linkable_type', Customer::class)
                    ->whereIn('linkable_id', $customerIds)
                    ->get()
                    ->groupBy('linkable_id')
                : collect();

            // Batch-load active deal existence for support-only detection
            $activeDealsSet = $customerIds->isNotEmpty()
                ? Deal::whereIn('customer_id', $customerIds)
                    ->whereNotIn('stage', Deal::TERMINAL_STAGES)
                    ->distinct()
                    ->pluck('customer_id')
                    ->flip()
                : collect();

            // Batch email unread counts and latest dates for linked customers
            $allLinkedMessageIds = $emailLinksMap->flatten()->pluck('email_message_id')->unique();
            $emailUnreadByCustomerId = collect();
            $emailLatestByCustomerId = collect();
            if ($allLinkedMessageIds->isNotEmpty()) {
                $emailUnreadByCustomerId = EmailMessage::query()
                    ->whereIn('email_messages.id', $allLinkedMessageIds)
                    ->unread()
                    ->withLabel('inbox')
                    ->join('email_crm_links', 'email_messages.id', '=', 'email_crm_links.email_message_id')
                    ->where('email_crm_links.linkable_type', Customer::class)
                    ->whereIn('email_crm_links.linkable_id', $customerIds)
                    ->selectRaw('email_crm_links.linkable_id, count(*) as cnt')
                    ->groupBy('email_crm_links.linkable_id')
                    ->pluck('cnt', 'linkable_id');

                $emailLatestByCustomerId = EmailMessage::query()
                    ->whereIn('email_messages.id', $allLinkedMessageIds)
                    ->join('email_crm_links', 'email_messages.id', '=', 'email_crm_links.email_message_id')
                    ->where('email_crm_links.linkable_type', Customer::class)
                    ->whereIn('email_crm_links.linkable_id', $customerIds)
                    ->selectRaw('email_crm_links.linkable_id, max(email_messages.sent_at) as latest')
                    ->groupBy('email_crm_links.linkable_id')
                    ->pluck('latest', 'linkable_id')
                    ->map(fn ($d) => $d ? \Carbon\Carbon::parse($d) : null);
            }

            // Build contact array for each user
            $contacts = $users->map(function (User $user) use ($viewer, $threadsByContact, $customersMap, $activeDealsSet, $emailUnreadByCustomerId, $emailLatestByCustomerId) {
                $contactThreads = $threadsByContact->get($user->id, collect());

                // Find most recent chat activity across threads
                $lastChatActivity = $contactThreads
                    ->pluck('last_message_at')
                    ->filter()
                    ->sortDesc()
                    ->first();

                // Find the most recent email activity for this contact (from batched data)
                $lastEmailActivity = null;
                $emailUnread = 0;
                $customer = $customersMap->get($user->id);
                if ($customer) {
                    $lastEmailActivity = $emailLatestByCustomerId->get($customer->id);
                    $emailUnread = (int) $emailUnreadByCustomerId->get($customer->id, 0);
                } elseif ($user->email) {
                    // Fallback for non-customer contacts: single query (rare case)
                    $lastEmailActivity = $this->getContactLatestEmailDate($user);
                    $emailUnread = $this->getContactUnreadEmailCount($user);
                }

                // Use whichever is more recent: chat or email
                $lastActivity = $lastChatActivity;
                if ($lastEmailActivity && (! $lastActivity || $lastEmailActivity->gt($lastActivity))) {
                    $lastActivity = $lastEmailActivity;
                }

                // Find the most recent message preview
                $latestThread = $contactThreads
                    ->filter(fn ($t) => $t->latestMessage !== null)
                    ->sortByDesc(fn ($t) => $t->last_message_at)
                    ->first();

                $preview = null;
                if ($latestThread?->latestMessage) {
                    $msg = $latestThread->latestMessage;
                    $senderName = $msg->user?->name ?? 'Unknown';
                    $preview = $senderName.': '.Str::limit($msg->content, 80);
                }

                // Calculate combined unread count from chat threads and emails
                $chatUnread = $contactThreads->sum(
                    fn (ChatThread $thread) => $thread->getUnreadCountFor($viewer)
                );
                $unreadCount = $chatUnread + $emailUnread;

                // Determine if this is a support-only contact (no active deal/estimate)
                $isSupportOnly = false;
                if ($user->type === 'customer' && $customer) {
                    $hasSupportThread = $contactThreads->contains(fn ($t) => $t->type === 'support');
                    if ($hasSupportThread) {
                        $isSupportOnly = ! $activeDealsSet->has($customer->id);
                    }
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'type' => $user->type,
                    'avatar_url' => $user->getAvatarUrl(),
                    'initials' => $user->initials(),
                    'last_activity_at' => $lastActivity,
                    'last_message_preview' => $preview,
                    'unread_count' => $unreadCount,
                    'has_unread' => $unreadCount > 0,
                    'is_support_only' => $isSupportOnly,
                ];
            });

            // Step 4b: Filter contacts by viewer's permissions
            $contacts = $this->filterContactsByPermissions($contacts, $viewer, $threadsByContact);
        }

        // Step 5: Sort by last_activity_at descending (nulls last)
        return $contacts
            ->sortByDesc(fn ($c) => $c['last_activity_at'] ?? '1970-01-01')
            ->values();
    }

    /**
     * Filter contacts based on viewer's chat permissions.
     * A contact is visible if they share at least one thread the viewer can see.
     */
    private function filterContactsByPermissions(
        Collection $contacts,
        User $viewer,
        Collection $threadsByContact
    ): Collection {
        // Only apply granular filtering to internal users with roles
        if (! $viewer->isInternal() || $viewer->roles->isEmpty()) {
            return $contacts;
        }

        $canViewDeals = $viewer->can('chat.view_deal_chats');
        $canViewWebsiteLeads = $viewer->can('chat.view_website_leads');
        $canViewSetterSupport = $viewer->can('chat.view_setter_support');
        $canChatInternal = $viewer->can('chat.with_internal');
        $canChatCustomers = $viewer->can('chat.with_customers');
        $canChatAgents = $viewer->can('chat.with_agents');
        $canChatCrew = $viewer->can('chat.with_crew');

        return $contacts->filter(function (array $contact) use (
            $threadsByContact, $canViewDeals, $canViewWebsiteLeads, $canViewSetterSupport,
            $canChatInternal, $canChatCustomers, $canChatAgents, $canChatCrew
        ) {
            $contactId = $contact['id'];
            $contactType = $contact['type'];
            $threads = $threadsByContact->get($contactId, collect());

            if ($threads->isEmpty()) {
                return false;
            }

            // Check type-level permission
            $typeAllowed = match ($contactType) {
                'internal' => $canChatInternal,
                'customer' => $canChatCustomers,
                'agent' => $canChatAgents,
                'crew' => $canChatCrew,
                null => true, // null-type users (setters) pass type check; filtered by thread source
                default => true,
            };

            if (! $typeAllowed) {
                return false;
            }

            // Check if they have at least one visible thread
            return $threads->contains(function (ChatThread $thread) use (
                $canViewDeals, $canViewWebsiteLeads, $canViewSetterSupport
            ) {
                return match ($thread->type) {
                    ChatThread::TYPE_DEAL => $canViewDeals,
                    ChatThread::TYPE_SUPPORT => match ($thread->source) {
                        ChatThread::SOURCE_WEBSITE_LEAD => $canViewWebsiteLeads,
                        ChatThread::SOURCE_SETTER_SUPPORT => $canViewSetterSupport,
                        default => true,
                    },
                    ChatThread::TYPE_DIRECT => true,
                    default => true,
                };
            });
        })->values();
    }

    /**
     * Get combined unread total for a user across chat and email channels.
     */
    public function getUnreadTotalForUser(User $user): int
    {
        // Chat unread count
        $chatUnread = app(ChatService::class)->getUnreadCountForUser($user);

        // Email unread count across all accounts the user can access
        $emailUnread = 0;
        $accounts = $user->allEmailAccounts();

        foreach ($accounts as $account) {
            $emailUnread += EmailMessage::query()
                ->where('email_account_id', $account->id)
                ->withLabel('inbox')
                ->unread()
                ->count();
        }

        return $chatUnread + $emailUnread;
    }

    /**
     * Get unread counts per tab for a specific contact.
     *
     * @return array<string, int>
     */
    public function getTabUnreadCounts(User $viewer, User $contact): array
    {
        $counts = ['all' => 0, 'messenger' => 0, 'deals' => 0, 'direct' => 0, 'emails' => 0];

        // Chat thread unread counts by type
        $threads = $this->getSharedChatThreads($viewer, $contact);
        foreach ($threads as $thread) {
            $unread = $thread->getUnreadCountFor($viewer);
            if ($unread <= 0) {
                continue;
            }

            $counts['all'] += $unread;

            if ($thread->channel === 'facebook') {
                $counts['messenger'] += $unread;
            } elseif ($thread->type === 'deal') {
                $counts['deals'] += $unread;
            } else {
                $counts['direct'] += $unread;
            }
        }

        // Email unread count
        $emailUnread = $this->getContactUnreadEmailCount($contact);
        $counts['emails'] = $emailUnread;
        $counts['all'] += $emailUnread;

        return $counts;
    }

    /**
     * Get unread email count for a contact.
     */
    private function getContactUnreadEmailCount(User $contact): int
    {
        $customer = Customer::where('user_id', $contact->id)->first();

        if ($customer) {
            $linkedMessageIds = EmailCrmLink::where('linkable_type', Customer::class)
                ->where('linkable_id', $customer->id)
                ->pluck('email_message_id');

            if ($linkedMessageIds->isEmpty()) {
                return 0;
            }

            return EmailMessage::query()
                ->whereIn('id', $linkedMessageIds)
                ->unread()
                ->withLabel('inbox')
                ->count();
        }

        $contactEmail = $contact->email;
        if (! $contactEmail) {
            return 0;
        }

        return EmailMessage::query()
            ->where('from_address', $contactEmail)
            ->unread()
            ->withLabel('inbox')
            ->count();
    }

    /**
     * Get the most recent email date for a contact.
     */
    private function getContactLatestEmailDate(User $contact): \Carbon\Carbon|\Carbon\CarbonImmutable|null
    {
        $customer = Customer::where('user_id', $contact->id)->first();

        if ($customer) {
            $linkedMessageIds = EmailCrmLink::where('linkable_type', Customer::class)
                ->where('linkable_id', $customer->id)
                ->pluck('email_message_id');

            if ($linkedMessageIds->isEmpty()) {
                return null;
            }

            return EmailMessage::query()
                ->whereIn('id', $linkedMessageIds)
                ->latest('sent_at')
                ->value('sent_at');
        }

        $contactEmail = $contact->email;
        if (! $contactEmail) {
            return null;
        }

        return EmailMessage::query()
            ->where(function ($q) use ($contactEmail) {
                $q->where('from_address', $contactEmail)
                    ->orWhereJsonContains('to_addresses', [['address' => $contactEmail]]);
            })
            ->latest('sent_at')
            ->value('sent_at');
    }

    /**
     * Get all communications for a specific contact, used by the right panel.
     *
     * @param  string  $tab  One of: 'all', 'messenger', 'deals', 'direct', 'emails'
     * @return Collection<int, array{type: string, channel: ?string, thread_type: ?string, icon: string, label: string, preview: string, sender: ?string, date: ?\Carbon\Carbon, thread_id: ?int, url: string, message_id: ?int, direction: ?string}>
     */
    public function getContactCommunications(User $viewer, User $contact, string $tab = 'all'): Collection
    {
        $items = collect();

        $shouldIncludeChat = in_array($tab, ['all', 'messenger', 'deals', 'direct']);
        $shouldIncludeEmail = in_array($tab, ['all', 'emails']);

        // --- Chat threads ---
        if ($shouldIncludeChat) {
            $threads = $this->getSharedChatThreads($viewer, $contact);

            foreach ($threads as $thread) {
                $item = $this->buildChatItem($thread);

                // Apply tab filter
                if ($this->chatItemMatchesTab($item, $tab)) {
                    $items->push($item);
                }
            }
        }

        // --- Email communications ---
        if ($shouldIncludeEmail) {
            $emailItems = $this->getContactEmailItems($contact);
            $items = $items->merge($emailItems);
        }

        // Sort by date descending
        return $items
            ->sortByDesc(fn ($item) => $item['date'] ?? '1970-01-01')
            ->values();
    }

    /**
     * Find all ChatThreads shared between viewer and contact.
     * For customer contacts, includes threads via customer_id and deal_id.
     */
    private function getSharedChatThreads(User $viewer, User $contact): Collection
    {
        // Direct participant-based threads (both viewer and contact are participants)
        $query = ChatThread::query()
            ->whereHas('participants', function ($q) use ($viewer) {
                $q->where('user_id', $viewer->id)->whereNull('left_at');
            })
            ->whereHas('participants', function ($q) use ($contact) {
                $q->where('user_id', $contact->id)->whereNull('left_at');
            });

        // For customer contacts, also include threads via customer_id and deal_id
        if ($contact->isCustomer()) {
            $customer = Customer::where('user_id', $contact->id)->first();

            if ($customer) {
                $dealIds = $customer->deals()->pluck('id')->toArray();

                $query = ChatThread::query()
                    ->where(function ($outerQuery) use ($viewer, $contact, $customer, $dealIds) {
                        // Threads where both are participants
                        $outerQuery->where(function ($q) use ($viewer, $contact) {
                            $q->whereHas('participants', function ($pq) use ($viewer) {
                                $pq->where('user_id', $viewer->id)->whereNull('left_at');
                            })->whereHas('participants', function ($pq) use ($contact) {
                                $pq->where('user_id', $contact->id)->whereNull('left_at');
                            });
                        });

                        // Threads linked via customer_id
                        $outerQuery->orWhere('customer_id', $customer->id);

                        // Threads linked via deals
                        if (! empty($dealIds)) {
                            $outerQuery->orWhereIn('deal_id', $dealIds);
                        }
                    });
            }
        }

        return $query
            ->with(['latestMessage.user', 'deal', 'customer', 'messengerConversation'])
            ->latestActivity()
            ->get();
    }

    /**
     * Build a standardized communication item from a chat thread.
     *
     * @return array{type: string, channel: ?string, thread_type: ?string, icon: string, label: string, preview: string, sender: ?string, date: ?\Carbon\Carbon, thread_id: int, url: string, message_id: ?int, direction: ?string}
     */
    private function buildChatItem(ChatThread $thread): array
    {
        return [
            'type' => 'chat',
            'channel' => $thread->channel,
            'thread_type' => $thread->type,
            'icon' => $this->getThreadIcon($thread),
            'label' => $this->getThreadLabel($thread),
            'preview' => $thread->latestMessage
                ? ($thread->latestMessage->user?->name ?? 'Unknown').': '.Str::limit($thread->latestMessage->content, 80)
                : 'No messages yet',
            'sender' => $thread->latestMessage?->user?->name,
            'date' => $thread->last_message_at ?? $thread->created_at,
            'thread_id' => $thread->id,
            'url' => route('chat.show', $thread),
            'message_id' => null,
            'direction' => null,
        ];
    }

    /**
     * Get email communication items for a contact.
     * Groups by thread to avoid duplicates.
     */
    private function getContactEmailItems(User $contact): Collection
    {
        $items = collect();
        $seenThreads = [];

        // Check if contact has a customer profile with email CRM links
        $customer = Customer::where('user_id', $contact->id)->first();

        if ($customer) {
            $emailLinks = $customer->emailLinks()
                ->with('message.thread')
                ->latest()
                ->get();

            foreach ($emailLinks as $link) {
                $email = $link->message;
                if (! $email) {
                    continue;
                }

                // Group by email thread to avoid duplicates
                $threadId = $email->email_thread_id;
                if ($threadId && isset($seenThreads[$threadId])) {
                    continue;
                }
                if ($threadId) {
                    $seenThreads[$threadId] = true;
                }

                $items->push($this->buildEmailItem($email));
            }
        } else {
            // Fallback: match by email address
            $contactEmail = $contact->email;
            if ($contactEmail) {
                $emails = EmailMessage::query()
                    ->where(function ($q) use ($contactEmail) {
                        $q->where('from_address', $contactEmail)
                            ->orWhereJsonContains('to_addresses', [['address' => $contactEmail]]);
                    })
                    ->with('thread')
                    ->latest('sent_at')
                    ->get();

                foreach ($emails as $email) {
                    $threadId = $email->email_thread_id;
                    if ($threadId && isset($seenThreads[$threadId])) {
                        continue;
                    }
                    if ($threadId) {
                        $seenThreads[$threadId] = true;
                    }

                    $items->push($this->buildEmailItem($email));
                }
            }
        }

        return $items;
    }

    /**
     * Build a standardized communication item from an email message.
     *
     * @return array{type: string, channel: string, thread_type: ?string, icon: string, label: string, preview: string, sender: ?string, date: ?\Carbon\Carbon, thread_id: ?int, url: string, message_id: int, direction: ?string}
     */
    private function buildEmailItem(EmailMessage $email): array
    {
        $threadId = $email->email_thread_id;

        return [
            'type' => 'email',
            'channel' => 'email',
            'thread_type' => null,
            'icon' => 'envelope',
            'label' => $email->subject ?? '(No subject)',
            'preview' => Str::limit($email->snippet ?? $email->body_text ?? '', 80),
            'sender' => $email->from_name ?? $email->from_address,
            'date' => $email->sent_at ?? $email->created_at,
            'thread_id' => null,
            'email_thread_id' => $threadId,
            'url' => $threadId
                ? route('email.inbox', ['threadId' => $threadId])
                : route('email.inbox'),
            'message_id' => $email->id,
            'direction' => $email->direction,
            'is_read' => $email->is_read,
        ];
    }

    /**
     * Determine if a chat item matches the active tab filter.
     */
    private function chatItemMatchesTab(array $item, string $tab): bool
    {
        return match ($tab) {
            'messenger' => $item['channel'] === 'facebook',
            'deals' => $item['thread_type'] === 'deal',
            'direct' => in_array($item['thread_type'], ['direct', 'support']),
            default => true, // 'all' shows everything
        };
    }

    /**
     * Get the appropriate heroicon name for a thread.
     */
    private function getThreadIcon(ChatThread $thread): string
    {
        if ($thread->channel === 'facebook') {
            return 'chat-bubble-oval-left';
        }

        return match ($thread->type) {
            'deal' => 'briefcase',
            'direct' => 'chat-bubble-left-right',
            'support' => 'lifebuoy',
            default => 'chat-bubble-left',
        };
    }

    /**
     * Get the display label for a thread.
     */
    private function getThreadLabel(ChatThread $thread): string
    {
        if ($thread->channel === 'facebook') {
            return 'Messenger';
        }

        return match ($thread->type) {
            'deal' => $thread->deal?->title ?? 'Deal Chat',
            'direct' => $thread->subject ?? 'Direct Message',
            'support' => $thread->subject ?? 'Support',
            default => $thread->subject ?? 'Chat',
        };
    }
}
