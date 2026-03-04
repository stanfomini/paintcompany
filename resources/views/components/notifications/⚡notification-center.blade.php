<?php

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $open = false;
    public bool $showHistory = false;

    public function boot(): void
    {
        if (! auth()->user()?->isInternal()) {
            abort(403);
        }
    }

    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            "echo-private:App.Models.User.{$userId},.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated" => 'onNewNotification',
            'toggle-notifications' => 'toggleOpen',
            'refresh-notification-counts' => 'onNewNotification',
        ];
    }

    public function onNewNotification(): void
    {
        $this->clearCache();
        $this->dispatch('play-notification-sound');
    }

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;
        $this->showHistory = false;

        if ($this->open) {
            $this->clearCache();
        }
    }

    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        $this->clearCache();
    }

    public function markAsRead(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->find($notificationId);

        if ($notification && ! $notification->read_at) {
            $notification->markAsRead();
        }

        $this->clearCache();
    }

    public function markAsReadAndNavigate(string $notificationId, ?string $url = null): void
    {
        $notification = auth()->user()->notifications()->find($notificationId);

        if ($notification && ! $notification->read_at) {
            $notification->markAsRead();
        }

        $this->clearCache();

        if ($url) {
            $this->redirect($url, navigate: true);
        }
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    #[Computed]
    public function unreadNotifications(): Collection
    {
        return auth()->user()
            ->unreadNotifications()
            ->latest()
            ->get()
            ->map(fn (DatabaseNotification $n) => $this->formatNotification($n));
    }

    #[Computed]
    public function historyNotifications(): Collection
    {
        return auth()->user()
            ->readNotifications()
            ->latest()
            ->take(15)
            ->get()
            ->map(fn (DatabaseNotification $n) => $this->formatNotification($n));
    }

    private function clearCache(): void
    {
        unset($this->unreadCount);
        unset($this->unreadNotifications);
        unset($this->historyNotifications);
    }

    private function formatNotification(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => class_basename($notification->type),
            'data' => $notification->data,
            'read' => ! is_null($notification->read_at),
            'time' => $notification->created_at->diffForHumans(),
            'url' => $this->getNotificationUrl($notification),
            'icon' => $this->getNotificationIcon($notification),
            'title' => $this->getNotificationTitle($notification),
            'description' => $this->getNotificationDescription($notification),
        ];
    }

    /**
     * Resolve the best URL for a notification based on its type and data.
     * Type-specific routes take priority over generic fallbacks.
     */
    private function getNotificationUrl(DatabaseNotification $notification): ?string
    {
        $data = $notification->data;
        $type = class_basename($notification->type);

        // Type-specific URL resolution
        $url = match ($type) {
            // Estimate notifications → estimate detail
            'EstimateAccepted', 'EstimateRejected', 'EstimateAmended',
            'EstimateProposalSent', 'EstimateReadyForAgent', 'EstimatePackageUpdated',
            'ProposalViewed' => isset($data['estimate_id']) ? route('estimates.show', $data['estimate_id']) : null,

            // Job notifications → job detail
            'JobCreated', 'JobCompleted', 'JobStageAdvanced', 'CrewJobDispatched',
            'CustomerConfirmedDate', 'CustomerConfirmedColors', 'CustomerProposedDate' => isset($data['job_id']) ? route('jobs.show', $data['job_id']) : null,

            // Payment → invoice detail
            'PaymentReceived' => isset($data['invoice_id']) ? route('invoices.show', $data['invoice_id']) : null,

            // Invoice → invoice detail
            'InvoiceSent' => isset($data['invoice_id']) ? route('invoices.show', $data['invoice_id']) : null,

            // Customer login → customer profile
            'CustomerPortalLogin' => isset($data['customer_id']) ? route('customers.show', $data['customer_id']) : null,

            // New account → dashboard
            'NewAccountCreated' => route('dashboard'),

            // Deal notifications → deal detail
            'NewProjectRequested', 'AgentListingSubmitted' => isset($data['deal_id']) ? route('deals.show', $data['deal_id']) : null,

            // Chat notifications → chat thread
            'UnreadChatMessage' => isset($data['chat_thread_id']) ? route('chat.show', $data['chat_thread_id']) : null,

            // Email notifications → communications hub
            'EmailReceivedNotification' => route('communications.index'),

            // Video call → job (calls happen in context of a job)
            'VideoCallInvitation' => isset($data['job_id']) ? route('jobs.show', $data['job_id']) : null,

            // Crew notifications → crew profile
            'CrewComplianceExpiring', 'CrewOnboardingCallRequested' => isset($data['crew_id']) ? route('crews.show', $data['crew_id']) : null,

            // Referral notifications → referrals list
            'ReferralLeadCreated', 'ReferralEstimateSigned', 'ReferralRewardEarned' => route('referrals.index'),

            // Task → planner
            'TaskAssigned' => route('planner.index'),

            // Meeting notifications → deal if available, otherwise planner
            'MeetingProposed', 'MeetingConfirmed', 'MeetingCancelled', 'MeetingReminder' => isset($data['deal_id']) ? route('deals.show', $data['deal_id']) : route('planner.index'),

            // Collaborator access → deal
            'CollaboratorAccessGranted' => isset($data['deal_id']) ? route('deals.show', $data['deal_id']) : null,

            // Invitation viewed → invitations list
            'InvitationViewed' => route('invitations'),

            // Workflow notification → workflow builder
            'WorkflowNotification' => isset($data['workflow_id']) ? route('workflows.builder', ['workflow' => $data['workflow_id']]) : null,

            default => null,
        };

        // Generic fallback chain if type-specific didn't resolve
        if (! $url) {
            $url = match (true) {
                isset($data['estimate_id']) => route('estimates.show', $data['estimate_id']),
                isset($data['job_id']) => route('jobs.show', $data['job_id']),
                isset($data['deal_id']) => route('deals.show', $data['deal_id']),
                isset($data['invoice_id']) => route('invoices.show', $data['invoice_id']),
                isset($data['customer_id']) => route('customers.show', $data['customer_id']),
                isset($data['crew_id']) => route('crews.show', $data['crew_id']),
                isset($data['agent_id']) => route('agents.show', $data['agent_id']),
                isset($data['chat_thread_id']) => route('chat.show', $data['chat_thread_id']),
                default => null,
            };
        }

        return $url;
    }

    private function getNotificationIcon(DatabaseNotification $notification): string
    {
        $type = class_basename($notification->type);

        return match ($type) {
            'EstimateAccepted' => 'check-circle',
            'EstimateRejected' => 'x-circle',
            'EstimateProposalSent' => 'paper-airplane',
            'EstimateAmended', 'EstimatePackageUpdated' => 'pencil-square',
            'EstimateReadyForAgent' => 'document-check',
            'ProposalViewed' => 'eye',
            'CustomerPortalLogin' => 'arrow-right-end-on-rectangle',
            'NewAccountCreated' => 'user-plus',
            'JobCreated' => 'clipboard-document-check',
            'JobCompleted' => 'trophy',
            'JobStageAdvanced' => 'arrow-right',
            'PaymentReceived' => 'banknotes',
            'InvoiceSent' => 'document-text',
            'NewProjectRequested' => 'plus-circle',
            'ReferralLeadCreated' => 'gift',
            'ReferralEstimateSigned' => 'document-check',
            'ReferralRewardEarned' => 'currency-dollar',
            'TaskAssigned' => 'clipboard-document-list',
            'CrewJobDispatched' => 'truck',
            'CrewComplianceExpiring' => 'exclamation-triangle',
            'CrewOnboardingCallRequested' => 'phone',
            'CustomerConfirmedDate', 'CustomerProposedDate' => 'calendar',
            'CustomerConfirmedColors' => 'swatch',
            'MeetingProposed', 'MeetingConfirmed' => 'video-camera',
            'MeetingCancelled' => 'x-circle',
            'MeetingReminder' => 'clock',
            'VideoCallInvitation' => 'video-camera',
            'AgentListingSubmitted' => 'home',
            'CollaboratorAccessGranted' => 'user-plus',
            'InvitationViewed' => 'envelope-open',
            'UnreadChatMessage' => 'chat-bubble-left',
            'EmailReceivedNotification' => 'envelope',
            'WorkflowNotification' => 'bolt',
            default => 'bell',
        };
    }

    private function getNotificationTitle(DatabaseNotification $notification): string
    {
        $type = class_basename($notification->type);

        return match ($type) {
            'EstimateAccepted' => __('Estimate Accepted'),
            'EstimateRejected' => __('Estimate Rejected'),
            'EstimateAmended' => __('Estimate Amended'),
            'EstimatePackageUpdated' => __('Package Updated'),
            'EstimateReadyForAgent' => __('Estimate Ready'),
            'EstimateProposalSent' => __('Proposal Sent'),
            'ProposalViewed' => __('Proposal Viewed'),
            'CustomerPortalLogin' => __('Customer Login'),
            'NewAccountCreated' => __('New Account'),
            'JobCreated' => __('New Job Created'),
            'JobCompleted' => __('Job Completed'),
            'JobStageAdvanced' => __('Job Stage Updated'),
            'PaymentReceived' => __('Payment Received'),
            'InvoiceSent' => __('Invoice Sent'),
            'NewProjectRequested' => __('New Project Request'),
            'ReferralLeadCreated' => __('New Referral Lead'),
            'ReferralEstimateSigned' => __('Referral Signed'),
            'ReferralRewardEarned' => __('Reward Earned'),
            'TaskAssigned' => __('Task Assigned'),
            'CrewJobDispatched' => __('Crew Dispatched'),
            'CrewComplianceExpiring' => __('Compliance Expiring'),
            'CrewOnboardingCallRequested' => __('Onboarding Call'),
            'CustomerConfirmedDate' => __('Date Confirmed'),
            'CustomerConfirmedColors' => __('Colors Confirmed'),
            'CustomerProposedDate' => __('Date Proposed'),
            'MeetingProposed' => __('Meeting Proposed'),
            'MeetingConfirmed' => __('Meeting Confirmed'),
            'MeetingCancelled' => __('Meeting Cancelled'),
            'MeetingReminder' => __('Meeting Reminder'),
            'VideoCallInvitation' => __('Video Call'),
            'AgentListingSubmitted' => __('Listing Submitted'),
            'CollaboratorAccessGranted' => __('Collaborator Added'),
            'InvitationViewed' => __('Invitation Opened'),
            'UnreadChatMessage' => __('New Message'),
            'EmailReceivedNotification' => __('New Email'),
            'WorkflowNotification' => __('Workflow Notification'),
            default => __('Notification'),
        };
    }

    private function getNotificationDescription(DatabaseNotification $notification): string
    {
        $data = $notification->data;
        $type = class_basename($notification->type);

        return match ($type) {
            'EstimateAccepted' => ($data['customer_name'] ?? __('Customer')) . ' - #' . ($data['estimate_number'] ?? ''),
            'EstimateRejected' => ($data['customer_name'] ?? __('Customer')) . ' - #' . ($data['estimate_number'] ?? '') . ($data['reason'] ? ' — ' . $data['reason'] : ''),
            'ProposalViewed' => ($data['customer_name'] ?? __('Customer')) . ' ' . __('viewed') . ' #' . ($data['estimate_number'] ?? ''),
            'CustomerPortalLogin' => ($data['customer_name'] ?? __('Customer')) . ' ' . __('logged in'),
            'PaymentReceived' => '$' . number_format($data['amount'] ?? 0, 2) . (isset($data['invoice_number']) ? ' — #' . $data['invoice_number'] : ''),
            'InvoiceSent' => '#' . ($data['invoice_number'] ?? '') . ' — $' . number_format($data['total'] ?? 0, 2),
            'JobCreated', 'JobCompleted' => ($data['title'] ?? '') . ' #' . ($data['job_number'] ?? ''),
            'JobStageAdvanced' => ($data['job_number'] ?? '') . ' → ' . ucfirst(str_replace('_', ' ', $data['to_stage'] ?? '')),
            'CustomerConfirmedDate', 'CustomerConfirmedColors', 'CustomerProposedDate' => $data['title'] ?? ($data['job_number'] ?? ''),
            'NewProjectRequested' => $data['customer_name'] ?? $data['message'] ?? '',
            'AgentListingSubmitted' => ($data['agent_name'] ?? __('Agent')) . ' — ' . ($data['property_address'] ?? ''),
            'CrewJobDispatched' => '#' . ($data['job_number'] ?? ''),
            'CrewComplianceExpiring' => $data['crew_name'] ?? '',
            'CrewOnboardingCallRequested' => $data['crew_name'] ?? '',
            'ReferralLeadCreated', 'ReferralEstimateSigned', 'ReferralRewardEarned' => $data['customer_name'] ?? '',
            'TaskAssigned' => $data['title'] ?? '',
            'VideoCallInvitation' => ($data['initiator_name'] ?? '') . ' ' . __('started a call'),
            'MeetingProposed', 'MeetingConfirmed', 'MeetingCancelled', 'MeetingReminder' => $data['title'] ?? '',
            'NewAccountCreated' => ucfirst($data['account_type'] ?? 'user') . ' — ' . ($data['user_name'] ?? ''),
            'InvitationViewed' => ($data['agent_email'] ?? '') . ' ' . __('clicked your invite'),
            'EmailReceivedNotification' => ($data['from_name'] ?? $data['from_address'] ?? __('Someone')) . ' — ' . ($data['subject'] ?? ''),
            'WorkflowNotification' => $data['message'] ?? '',
            default => $data['customer_name'] ?? $data['estimate_number'] ?? $data['title'] ?? $data['message'] ?? '',
        };
    }
}; ?>

<div>
    {{-- Notifications Panel (directly above the footer Notifications tab) --}}
    @if ($open)
        <div
            x-data
            @click.outside="$wire.set('open', false)"
            style="position: fixed; bottom: 48px; z-index: 50;"
            class="left-2 lg:left-[17rem] w-80 max-h-[calc(100vh-8rem)] flex flex-col bg-white dark:bg-zinc-800 rounded-2xl shadow-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden"
        >
            {{-- Header with tab toggle --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 shrink-0">
                <div class="flex items-center gap-2">
                    @if ($showHistory)
                        <button
                            wire:click="toggleHistory"
                            class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors"
                            aria-label="{{ __('Back to notifications') }}"
                        >
                            <flux:icon name="arrow-left" class="size-4" />
                        </button>
                        <flux:heading size="sm">{{ __('History') }}</flux:heading>
                    @else
                        <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>
                        @if ($this->unreadCount > 0)
                            <flux:badge size="sm" color="red">{{ $this->unreadCount }}</flux:badge>
                        @endif
                    @endif
                </div>
                <div class="flex items-center gap-1">
                    @if (! $showHistory)
                        <button
                            wire:click="toggleHistory"
                            class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors px-1.5 py-0.5 rounded hover:bg-zinc-100 dark:hover:bg-zinc-700"
                            title="{{ __('View history') }}"
                        >
                            {{ __('History') }}
                        </button>
                    @endif
                    <button
                        wire:click="toggleOpen"
                        class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors p-0.5"
                        aria-label="{{ __('Close notifications') }}"
                    >
                        <flux:icon name="x-mark" class="size-5" />
                    </button>
                </div>
            </div>

            @if ($showHistory)
                {{-- History List: Last 15 read notifications --}}
                <div class="flex-1 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @if ($this->historyNotifications->isNotEmpty())
                        @foreach ($this->historyNotifications as $notification)
                            <button
                                wire:key="history-{{ $notification['id'] }}"
                                wire:click="markAsReadAndNavigate('{{ $notification['id'] }}', '{{ $notification['url'] }}')"
                                class="w-full text-left px-4 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors flex items-start gap-3"
                            >
                                <div class="shrink-0 mt-0.5 p-1.5 rounded-full bg-zinc-100 text-zinc-400 dark:bg-zinc-700 dark:text-zinc-500">
                                    <flux:icon :name="$notification['icon']" class="size-3.5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400 truncate block">
                                        {{ $notification['title'] }}
                                    </span>
                                    <p class="text-xs text-zinc-400 dark:text-zinc-500 truncate mt-0.5">
                                        {{ $notification['description'] }}
                                    </p>
                                    <p class="text-xs text-zinc-300 dark:text-zinc-600 mt-0.5">
                                        {{ $notification['time'] }}
                                    </p>
                                </div>
                            </button>
                        @endforeach
                    @else
                        <div class="px-4 py-8 text-center">
                            <flux:icon name="clock" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No history yet') }}</p>
                        </div>
                    @endif
                </div>
            @else
                {{-- Unread Notification List --}}
                <div class="flex-1 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-700/50">
                    @if ($this->unreadNotifications->isNotEmpty())
                        @foreach ($this->unreadNotifications as $notification)
                            <div
                                wire:key="notification-{{ $notification['id'] }}"
                                class="flex items-start gap-3 px-4 py-3 bg-blue-50/50 dark:bg-blue-900/10 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                            >
                                {{-- Clickable notification body --}}
                                <button
                                    wire:click="markAsReadAndNavigate('{{ $notification['id'] }}', '{{ $notification['url'] }}')"
                                    class="flex items-start gap-3 min-w-0 flex-1 text-left"
                                >
                                    <div class="shrink-0 mt-0.5 p-1.5 rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                        <flux:icon :name="$notification['icon']" class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white truncate block">
                                            {{ $notification['title'] }}
                                        </span>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">
                                            {{ $notification['description'] }}
                                        </p>
                                        <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-0.5">
                                            {{ $notification['time'] }}
                                        </p>
                                    </div>
                                </button>

                                {{-- Mark Read button --}}
                                <button
                                    wire:click="markAsRead('{{ $notification['id'] }}')"
                                    class="shrink-0 mt-1 p-1 rounded-full text-zinc-300 hover:text-blue-600 hover:bg-blue-100 dark:text-zinc-600 dark:hover:text-blue-400 dark:hover:bg-blue-900/30 transition-colors"
                                    title="{{ __('Mark as read') }}"
                                >
                                    <flux:icon name="check" class="size-4" />
                                </button>
                            </div>
                        @endforeach
                    @else
                        <div class="px-4 py-8 text-center">
                            <flux:icon name="check-circle" class="mx-auto size-8 text-green-300 dark:text-green-700" />
                            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('All caught up!') }}</p>
                        </div>
                    @endif
                </div>

                {{-- Footer: Mark all as read --}}
                @if ($this->unreadNotifications->isNotEmpty())
                    <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-2.5 shrink-0">
                        <button
                            wire:click="markAllAsRead"
                            class="block w-full text-center text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors"
                        >
                            {{ __('Mark all as read') }}
                        </button>
                    </div>
                @endif
            @endif
        </div>
    @endif

    {{-- Bell button hidden - footer bar dispatches 'toggle-notifications' event instead --}}

    {{-- Notification Sound --}}
    <audio id="notification-sound" preload="auto">
        <source src="{{ asset('sounds/notification.mp3') }}" type="audio/mpeg">
    </audio>

    @script
    <script>
        $wire.on('play-notification-sound', () => {
            const audio = document.getElementById('notification-sound');
            if (audio) {
                audio.currentTime = 0;
                audio.play().catch(() => {});
            }
        });
    </script>
    @endscript
</div>
