<?php

use App\Events\VideoCallStarted;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Models\VideoCall;
use App\Notifications\MeetingProposed;
use App\Notifications\VideoCallInvitation;
use App\Services\MeetingService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Job $job;

    public ?int $activeCallId = null;

    public ?string $activeRoomId = null;

    public bool $showCallPanel = false;

    public string $echoChannel = '';

    // Scheduling properties
    public bool $showScheduleForm = false;

    public string $meetingTitle = '';

    public string $meetingDateTime = '';

    public string $meetingType = 'virtual';

    public string $meetingDescription = '';

    public function mount(Job $job): void
    {
        $this->job = $job;
        $this->echoChannel = $this->resolveEchoChannel();

        // Check if there's already an active call for this job
        $activeCall = VideoCall::where('job_id', $job->id)
            ->whereIn('status', ['waiting', 'active'])
            ->first();

        if ($activeCall) {
            $this->activeCallId = $activeCall->id;
            $this->activeRoomId = $activeCall->room_id;
        }
    }

    public function startCall(): void
    {
        $user = $this->resolveUser();

        if (! $user) {
            return;
        }

        // Create a new video call record
        $videoCall = VideoCall::create([
            'job_id' => $this->job->id,
            'initiated_by' => $user->id,
            'room_id' => 'job-'.$this->job->id.'-'.Str::random(8),
            'status' => 'active',
            'started_at' => now(),
            'participants' => [
                [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'type' => $user->type,
                    'joined_at' => now()->toISOString(),
                ],
            ],
        ]);

        $this->activeCallId = $videoCall->id;
        $this->activeRoomId = $videoCall->room_id;
        $this->showCallPanel = true;

        // Broadcast to other participants on this job
        VideoCallStarted::dispatch($videoCall, $user->name);

        // Send email notifications to all job participants (except caller)
        $this->notifyJobParticipants($videoCall, $user);
    }

    public function joinCall(): void
    {
        $this->showCallPanel = true;
    }

    public function endCall(): void
    {
        if ($this->activeCallId) {
            $call = VideoCall::find($this->activeCallId);
            if ($call) {
                $call->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                    'duration_seconds' => $call->started_at
                        ? now()->diffInSeconds($call->started_at)
                        : null,
                ]);
            }
        }

        $this->activeCallId = null;
        $this->activeRoomId = null;
        $this->showCallPanel = false;
    }

    public function dismissCall(): void
    {
        $this->showCallPanel = false;
    }

    public function openScheduleForm(): void
    {
        $this->meetingTitle = __('Video Call - :title', ['title' => $this->job->title]);
        $this->meetingDateTime = '';
        $this->meetingType = 'virtual';
        $this->meetingDescription = '';
        $this->showScheduleForm = true;
    }

    public function closeScheduleForm(): void
    {
        $this->showScheduleForm = false;
        $this->reset(['meetingTitle', 'meetingDateTime', 'meetingType', 'meetingDescription']);
    }

    public function scheduleMeeting(): void
    {
        $user = $this->resolveUser();
        if (! $user) {
            return;
        }

        $this->validate([
            'meetingTitle' => 'required|string|max:255',
            'meetingDateTime' => 'required|date|after:now',
            'meetingType' => 'required|in:virtual,in_person',
            'meetingDescription' => 'nullable|string|max:1000',
        ]);

        $deal = $this->job->deal;
        if (! $deal) {
            return;
        }

        $activity = app(MeetingService::class)->proposeMeeting(
            deal: $deal,
            proposer: $user,
            title: $this->meetingTitle,
            dateTime: Carbon::parse($this->meetingDateTime),
            meetingType: $this->meetingType,
            description: $this->meetingDescription ?: null,
        );

        // In the job context, also notify all internal users with jobs.view
        // who aren't already deal parties. This ensures the team always knows
        // when a customer or crew member proposes a meeting from a job.
        $this->notifyInternalTeamAboutMeeting($activity, $user);

        $this->closeScheduleForm();
        unset($this->meetings);
    }

    public function confirmMeeting(int $activityId): void
    {
        $user = $this->resolveUser();
        if (! $user) {
            return;
        }

        $activity = Activity::find($activityId);
        if (! $activity) {
            return;
        }

        app(MeetingService::class)->confirmMeeting($activity, $user);
        unset($this->meetings);
    }

    public function declineMeeting(int $activityId): void
    {
        $user = $this->resolveUser();
        if (! $user) {
            return;
        }

        $activity = Activity::find($activityId);
        if (! $activity) {
            return;
        }

        app(MeetingService::class)->declineMeeting($activity, $user);
        unset($this->meetings);
    }

    public function cancelMeeting(int $activityId): void
    {
        $user = $this->resolveUser();
        if (! $user) {
            return;
        }

        $activity = Activity::find($activityId);
        if (! $activity) {
            return;
        }

        app(MeetingService::class)->cancelMeeting($activity, $user);
        unset($this->meetings);
    }

    public function completeMeeting(int $activityId): void
    {
        $activity = Activity::find($activityId);
        if (! $activity) {
            return;
        }

        $activity->markAsCompleted();

        // Also end the video call if it's still active
        if ($activity->video_call_id) {
            $videoCall = VideoCall::find($activity->video_call_id);
            if ($videoCall && in_array($videoCall->status, ['pending', 'waiting', 'active'])) {
                $videoCall->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                ]);
            }
        }

        unset($this->meetings);
    }

    public function joinScheduledCall(int $activityId): void
    {
        $activity = Activity::with('videoCall')->find($activityId);
        if (! $activity?->videoCall) {
            return;
        }

        $videoCall = $activity->videoCall;
        if ($videoCall->status === 'pending') {
            $videoCall->update([
                'status' => 'active',
                'started_at' => now(),
            ]);
        }

        $this->activeCallId = $videoCall->id;
        $this->activeRoomId = $videoCall->room_id;
        $this->showCallPanel = true;
    }

    #[Computed]
    public function meetings()
    {
        $deal = $this->job->deal;
        if (! $deal) {
            return collect();
        }

        return Activity::with(['proposedBy', 'videoCall'])
            ->where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->where('type', 'meeting')
            ->where('is_completed', false)
            ->whereIn('meeting_status', ['proposed', 'confirmed'])
            ->orderBy('due_at')
            ->get();
    }

    #[Computed]
    public function meetingCount()
    {
        return $this->meetings->count();
    }

    #[Computed]
    public function recordings()
    {
        return VideoCall::where('job_id', $this->job->id)
            ->where('status', 'ended')
            ->whereNotNull('recording_path')
            ->orderByDesc('ended_at')
            ->get();
    }

    private function resolveUser(): ?User
    {
        return auth()->user()
            ?? auth('customer')->user()
            ?? auth('crew')->user()
            ?? auth('agent')->user();
    }

    private function resolveEchoChannel(): string
    {
        if (auth('customer')->check()) {
            return 'customer.job.'.$this->job->id;
        }

        if (auth('crew')->check()) {
            return 'crew.job.'.$this->job->id;
        }

        return 'job.'.$this->job->id;
    }

    private function notifyJobParticipants(VideoCall $videoCall, User $caller): void
    {
        $notification = new VideoCallInvitation($videoCall, $caller->name);
        $notifiedIds = [$caller->id];

        // Notify internal users with jobs.view permission
        User::where('type', 'internal')
            ->whereNot('id', $caller->id)
            ->get()
            ->filter(fn (User $user) => $user->can('jobs.view'))
            ->each(function (User $user) use ($notification, &$notifiedIds) {
                $user->notify($notification);
                $notifiedIds[] = $user->id;
            });

        // Notify accepted crew members
        $this->job->crews()
            ->wherePivot('status', 'accepted')
            ->get()
            ->reject(fn (User $user) => in_array($user->id, $notifiedIds))
            ->each(function (User $user) use ($notification, &$notifiedIds) {
                $user->notify($notification);
                $notifiedIds[] = $user->id;
            });

        // Notify the customer
        $customerUser = $this->job->deal?->customer?->user;
        if ($customerUser && ! in_array($customerUser->id, $notifiedIds)) {
            $customerUser->notify($notification);
        }
    }

    /**
     * Notify all internal users with jobs.view permission about a new meeting.
     * MeetingService only notifies deal parties (assigned_to, customer, agent),
     * but in a job context the whole internal team should know.
     */
    private function notifyInternalTeamAboutMeeting(Activity $activity, User $proposer): void
    {
        $deal = $this->job->deal;
        if (! $deal) {
            return;
        }

        // Collect IDs already notified by MeetingService (deal parties + proposer)
        $meetingService = app(MeetingService::class);
        $dealParties = $meetingService->getDealParties($deal);
        $alreadyNotifiedIds = array_merge(
            [$proposer->id],
            array_map(fn ($u) => $u->id, array_values($dealParties)),
        );

        // Notify internal users who weren't already notified
        User::where('type', 'internal')
            ->whereNotIn('id', $alreadyNotifiedIds)
            ->get()
            ->filter(fn (User $user) => $user->can('jobs.view'))
            ->each(fn (User $user) => $user->notify(new MeetingProposed($activity, $proposer)));
    }

    private function needsMyConfirmation(Activity $meeting): bool
    {
        $user = $this->resolveUser();
        if (! $user || $meeting->meeting_status !== 'proposed') {
            return false;
        }

        $deal = $this->job->deal;
        if (! $deal) {
            return false;
        }

        $party = app(MeetingService::class)->getUserParty($user, $deal);

        return $meeting->needsConfirmationFrom($party);
    }
};
?>

<div>
    {{-- Start / Join / Return to Call Button --}}
    @if (! $showCallPanel)
        <div class="flex items-center gap-2">
            @if ($activeCallId)
                {{-- Active call exists - show join/return button --}}
                <flux:button
                    wire:click="joinCall"
                    variant="primary"
                    size="sm"
                    icon="video-camera"
                >
                    {{ __('Return to Call') }}
                </flux:button>
            @else
                {{-- Schedule (opens modal with meetings + form) --}}
                <div class="relative">
                    <flux:button
                        wire:click="openScheduleForm"
                        variant="ghost"
                        size="sm"
                        icon="calendar-days"
                    >
                        {{ __('Schedule Call') }}
                    </flux:button>
                    @if ($this->meetingCount > 0)
                        <span class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-blue-600 text-[10px] font-bold text-white">
                            {{ $this->meetingCount }}
                        </span>
                    @endif
                </div>
                <flux:button
                    wire:click="startCall"
                    variant="ghost"
                    size="sm"
                    icon="video-camera"
                >
                    {{ __('Start Now') }}
                </flux:button>
            @endif
        </div>
    @endif

    {{-- Schedule Meeting Modal (contains BOTH form and meetings list) --}}
    @if ($showScheduleForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="closeScheduleForm">
            <div class="w-full max-w-lg mx-4 bg-white dark:bg-zinc-800 rounded-xl shadow-2xl max-h-[85vh] flex flex-col">
                <div class="p-6 overflow-y-auto">
                    {{-- Header --}}
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="lg">{{ __('Meetings & Calls') }}</flux:heading>
                        <button type="button" wire:click="closeScheduleForm" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>

                    {{-- Upcoming Meetings --}}
                    @if ($this->meetings->isNotEmpty())
                        <div class="space-y-2 mb-6">
                            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Upcoming') }}</flux:text>
                            @foreach ($this->meetings as $meeting)
                                <div wire:key="meeting-{{ $meeting->id }}" class="rounded-lg border p-3 text-sm {{ $meeting->meeting_status === 'confirmed' ? 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20' : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50' }}">
                                    <div class="space-y-0.5">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $meeting->title }}</span>
                                            <flux:badge size="sm" color="{{ $meeting->meeting_type === 'virtual' ? 'blue' : 'zinc' }}">
                                                {{ $meeting->meeting_type === 'virtual' ? __('Virtual') : __('In Person') }}
                                            </flux:badge>
                                            <flux:badge size="sm" color="{{ $meeting->meeting_status === 'confirmed' ? 'green' : 'yellow' }}">
                                                {{ ucfirst($meeting->meeting_status) }}
                                            </flux:badge>
                                        </div>
                                        @if ($meeting->due_at)
                                            <div class="text-zinc-500 dark:text-zinc-400 flex items-center gap-1">
                                                <flux:icon name="calendar" class="w-3.5 h-3.5" />
                                                {{ $meeting->due_at->format('M j, g:i A') }}
                                            </div>
                                        @endif
                                        @if ($meeting->proposedBy)
                                            <div class="text-xs text-zinc-400">{{ __('By :name', ['name' => $meeting->proposedBy->name]) }}</div>
                                        @endif
                                    </div>

                                    {{-- Confirmation indicators --}}
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                        <div class="flex items-center gap-0.5 {{ $meeting->team_confirmed_at ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                                            <flux:icon name="{{ $meeting->team_confirmed_at ? 'check-circle' : 'clock' }}" class="w-3.5 h-3.5" />
                                            <span>{{ __('Team') }}</span>
                                        </div>
                                        @if ($this->job->deal?->customer_id)
                                            <div class="flex items-center gap-0.5 {{ $meeting->customer_confirmed_at ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                                                <flux:icon name="{{ $meeting->customer_confirmed_at ? 'check-circle' : 'clock' }}" class="w-3.5 h-3.5" />
                                                <span>{{ __('Customer') }}</span>
                                            </div>
                                        @endif
                                        @if ($this->job->deal?->agent_id)
                                            <div class="flex items-center gap-0.5 {{ $meeting->agent_confirmed_at ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                                                <flux:icon name="{{ $meeting->agent_confirmed_at ? 'check-circle' : 'clock' }}" class="w-3.5 h-3.5" />
                                                <span>{{ __('Agent') }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Actions --}}
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @if ($this->needsMyConfirmation($meeting))
                                            <flux:button wire:click="confirmMeeting({{ $meeting->id }})" size="sm" variant="primary">{{ __('Confirm') }}</flux:button>
                                            <flux:button wire:click="declineMeeting({{ $meeting->id }})" size="sm" variant="danger">{{ __('Decline') }}</flux:button>
                                        @endif
                                        @if ($meeting->meeting_status === 'confirmed')
                                            <flux:button wire:click="completeMeeting({{ $meeting->id }})" size="sm" variant="filled" icon="check">{{ __('Complete') }}</flux:button>
                                        @endif
                                        @if ($meeting->meeting_type === 'virtual' && $meeting->meeting_status === 'confirmed' && $meeting->videoCall)
                                            <flux:button wire:click="joinScheduledCall({{ $meeting->id }})" size="sm" variant="primary" icon="video-camera">
                                                {{ __('Join Call') }}
                                            </flux:button>
                                        @endif
                                        @if ($meeting->meeting_status !== 'declined')
                                            <flux:button wire:click="cancelMeeting({{ $meeting->id }})" size="sm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Schedule New Meeting Form --}}
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                        <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Schedule a New Meeting') }}</flux:text>
                        <form wire:submit="scheduleMeeting" class="space-y-4">
                            <flux:input wire:model="meetingTitle" label="{{ __('Title') }}" required />
                            <flux:input wire:model="meetingDateTime" type="datetime-local" label="{{ __('Date & Time') }}" required />
                            <flux:select wire:model="meetingType" label="{{ __('Type') }}">
                                <option value="virtual">{{ __('Virtual (Video Call)') }}</option>
                                <option value="in_person">{{ __('In Person') }}</option>
                            </flux:select>
                            <flux:textarea wire:model="meetingDescription" label="{{ __('Description') }}" placeholder="{{ __('Optional meeting details...') }}" rows="2" />
                            <div class="flex items-center justify-end gap-2 pt-2">
                                <flux:button type="button" wire:click="closeScheduleForm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                <flux:button type="submit" variant="primary">{{ __('Propose Meeting') }}</flux:button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Floating "Return to Call" pill (when call is active but panel is minimized) --}}
    @if (! $showCallPanel && $activeCallId)
        <div class="fixed bottom-6 right-6 z-40">
            <button
                type="button"
                wire:click="joinCall"
                class="flex items-center gap-2 px-4 py-2.5 rounded-full bg-green-600 text-white shadow-lg hover:bg-green-700 transition-colors animate-pulse"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                <span class="text-sm font-medium">{{ __('Return to Call') }}</span>
            </button>
        </div>
    @endif

    {{-- Incoming Call Notification (via Echo) --}}
    <div
        x-data="{
            incomingCall: false,
            callerName: '',
            init() {
                if (window.Echo) {
                    window.Echo.private('{{ $echoChannel }}')
                        .listen('VideoCallStarted', (e) => {
                            this.callerName = e.initiator_name;
                            this.incomingCall = true;
                            setTimeout(() => { this.incomingCall = false; }, 60000);
                        });
                }
            }
        }"
        x-show="incomingCall && !@js($showCallPanel)"
        x-transition
        class="fixed bottom-4 right-4 z-50 p-4 rounded-xl bg-blue-600 text-white shadow-xl max-w-sm"
    >
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
            </div>
            <div class="flex-1">
                <p class="font-medium text-sm" x-text="callerName + ' started a video call'"></p>
                <p class="text-xs text-blue-200">{{ $job->title }}</p>
            </div>
        </div>
        <div class="flex items-center gap-2 mt-3">
            <button
                type="button"
                wire:click="joinCall"
                @click="incomingCall = false"
                class="flex-1 py-2 px-3 rounded-lg bg-white text-blue-600 font-medium text-sm hover:bg-blue-50 transition-colors"
            >
                {{ __('Join') }}
            </button>
            <button
                type="button"
                @click="incomingCall = false"
                class="flex-1 py-2 px-3 rounded-lg bg-blue-500 text-white font-medium text-sm hover:bg-blue-400 transition-colors"
            >
                {{ __('Dismiss') }}
            </button>
        </div>
    </div>

    {{-- Video Call Panel (full-screen overlay) --}}
    @if ($showCallPanel && $activeRoomId)
        <div class="fixed inset-0 z-50 bg-black flex flex-col">
            {{-- Panel Header --}}
            <div class="flex items-center justify-between px-4 py-2 bg-zinc-800 border-b border-zinc-700 shrink-0">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
                    <span class="text-white text-sm font-medium">{{ $job->title }}</span>
                </div>
                <div class="flex items-center gap-2">
                    {{-- Open in new tab (and close this panel) --}}
                    <a
                        href="{{ route('video-call.test', $activeRoomId) }}"
                        target="_blank"
                        wire:click="dismissCall"
                        class="p-1.5 rounded-lg bg-zinc-700 text-zinc-300 hover:bg-zinc-600 transition-colors"
                        :title="__('Pop out to new tab')"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                    </a>
                    {{-- Minimize (hide panel, call stays active) --}}
                    <button
                        type="button"
                        wire:click="dismissCall"
                        class="p-1.5 rounded-lg bg-zinc-700 text-zinc-300 hover:bg-zinc-600 transition-colors"
                        :title="__('Minimize call')"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    {{-- End call --}}
                    <button
                        type="button"
                        wire:click="endCall"
                        class="p-1.5 rounded-lg bg-red-600 text-white hover:bg-red-700 transition-colors"
                        :title="__('End call')"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>

            {{-- Embedded iframe to video call test page --}}
            <iframe
                src="{{ route('video-call.test', $activeRoomId) }}"
                class="flex-1 w-full border-0"
                allow="camera; microphone; display-capture"
            ></iframe>
        </div>
    @endif
</div>
