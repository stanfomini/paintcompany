<?php

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $open = false;

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
        ];
    }

    public function onNewNotification(): void
    {
        unset($this->unreadCount);
        unset($this->notifications);

        $this->dispatch('play-notification-sound');
    }

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;

        if ($this->open) {
            unset($this->unreadCount);
            unset($this->notifications);
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->unreadCount);
        unset($this->notifications);
    }

    public function markAsReadAndNavigate(string $notificationId, ?string $url = null): void
    {
        $notification = auth()->user()->notifications()->find($notificationId);

        if ($notification && ! $notification->read_at) {
            $notification->markAsRead();
        }

        unset($this->unreadCount);
        unset($this->notifications);

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
    public function notifications(): Collection
    {
        return auth()->user()
            ->notifications()
            ->latest()
            ->take(15)
            ->get()
            ->map(function (DatabaseNotification $notification) {
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
            });
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

            // Deal notifications → deal detail
            'NewProjectRequested', 'AgentListingSubmitted' => isset($data['deal_id']) ? route('deals.show', $data['deal_id']) : null,

            // Chat notifications → chat thread
            'UnreadChatMessage' => isset($data['chat_thread_id']) ? route('chat.show', $data['chat_thread_id']) : null,

            // Video call → job (calls happen in context of a job)
            'VideoCallInvitation' => isset($data['job_id']) ? route('jobs.show', $data['job_id']) : null,

            // Crew notifications → crew profile
            'CrewComplianceExpiring', 'CrewOnboardingCallRequested' => isset($data['crew_id']) ? route('crews.show', $data['crew_id']) : null,

            // Referral notifications → referrals list
            'ReferralLeadCreated', 'ReferralEstimateSigned', 'ReferralRewardEarned' => route('referrals.index'),

            // Task → planner
            'TaskAssigned' => route('planner.index'),

            // Meeting notifications → job if available, otherwise planner
            'MeetingProposed', 'MeetingConfirmed', 'MeetingCancelled', 'MeetingReminder' => isset($data['job_id']) ? route('jobs.show', $data['job_id']) : route('planner.index'),

            // Collaborator access → deal
            'CollaboratorAccessGranted' => isset($data['deal_id']) ? route('deals.show', $data['deal_id']) : null,

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
            'UnreadChatMessage' => 'chat-bubble-left',
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
            'UnreadChatMessage' => __('New Message'),
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
            default => $data['customer_name'] ?? $data['estimate_number'] ?? $data['title'] ?? $data['message'] ?? '',
        };
    }
}; ?>

<div class="relative" x-data="{ }" @click.outside="$wire.set('open', false)">
    {{-- Bell Button --}}
    <button
        wire:click="toggleOpen"
        class="relative p-2 text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 transition-colors rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800"
        aria-label="{{ __('Notifications') }}"
    >
        <flux:icon name="bell" class="size-5" />

        @if ($this->unreadCount > 0)
            <span class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-xs rounded-full min-w-4 h-4 flex items-center justify-center px-0.5 font-medium pointer-events-none text-[10px]">
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    {{-- Dropdown Panel --}}
    @if ($open)
        <div class="absolute right-0 top-full mt-2 w-80 max-h-[28rem] flex flex-col bg-white dark:bg-zinc-800 rounded-xl shadow-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden z-50">
            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 shrink-0">
                <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>
                @if ($this->unreadCount > 0)
                    <flux:badge size="sm" color="red">{{ $this->unreadCount }} {{ __('new') }}</flux:badge>
                @endif
            </div>

            {{-- Notification List --}}
            <div class="flex-1 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-700/50">
                @forelse ($this->notifications as $notification)
                    <button
                        wire:key="notification-{{ $notification['id'] }}"
                        wire:click="markAsReadAndNavigate('{{ $notification['id'] }}', '{{ $notification['url'] }}')"
                        @class([
                            'w-full text-left px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors flex items-start gap-3',
                            'bg-blue-50/50 dark:bg-blue-900/10' => !$notification['read'],
                        ])
                    >
                        <div @class([
                            'shrink-0 mt-0.5 p-1.5 rounded-full',
                            'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400' => !$notification['read'],
                            'bg-zinc-100 text-zinc-400 dark:bg-zinc-700 dark:text-zinc-500' => $notification['read'],
                        ])>
                            <flux:icon :name="$notification['icon']" class="size-4" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span @class([
                                    'text-sm truncate',
                                    'font-semibold text-zinc-900 dark:text-white' => !$notification['read'],
                                    'font-medium text-zinc-600 dark:text-zinc-400' => $notification['read'],
                                ])>
                                    {{ $notification['title'] }}
                                </span>
                                @if (!$notification['read'])
                                    <span class="shrink-0 w-2 h-2 bg-blue-500 rounded-full"></span>
                                @endif
                            </div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">
                                {{ $notification['description'] }}
                            </p>
                            <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-0.5">
                                {{ $notification['time'] }}
                            </p>
                        </div>
                    </button>
                @empty
                    <div class="px-4 py-8 text-center">
                        <flux:icon name="bell-slash" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No notifications yet') }}</p>
                    </div>
                @endforelse
            </div>

            {{-- Footer --}}
            @if ($this->notifications->isNotEmpty())
                <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-2.5 shrink-0">
                    @if ($this->unreadCount > 0)
                        <button
                            wire:click="markAllAsRead"
                            class="block w-full text-center text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors"
                        >
                            {{ __('Mark all as read') }}
                        </button>
                    @else
                        <p class="text-center text-sm text-zinc-400 dark:text-zinc-500">{{ __('All caught up!') }}</p>
                    @endif
                </div>
            @endif
        </div>
    @endif

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
