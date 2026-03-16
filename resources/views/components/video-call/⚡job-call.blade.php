<?php

use App\Events\VideoCallStarted;
use App\Models\Job;
use App\Models\User;
use App\Models\VideoCall;
use App\Notifications\VideoCallInvitation;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Job $job;

    public ?int $activeCallId = null;

    public ?string $activeRoomId = null;

    public bool $showCallPanel = false;

    public string $echoChannel = '';

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

        // Use the deal's assigned estimator for a consistent room slug
        $deal = $this->job->deal;
        $estimator = $deal ? User::find($deal->assigned_to) : null;
        $estimator = $estimator ?? $user;

        // For jobs, scope the room to the deal (same room as deal-call)
        $dealId = $deal?->id ?? 0;
        $roomId = $estimator->videoCallRoomId($dealId);

        // Find-or-create VideoCall with this permanent room ID
        $videoCall = VideoCall::where('room_id', $roomId)->first();

        if (! $videoCall) {
            $videoCall = VideoCall::create([
                'job_id' => $this->job->id,
                'callable_type' => $deal ? \App\Models\Deal::class : null,
                'callable_id' => $deal?->id,
                'initiated_by' => $user->id,
                'room_id' => $roomId,
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

            // Broadcast to other participants on this job
            VideoCallStarted::dispatch($videoCall, $user->name);

            // Send email notifications to all job participants (except caller)
            $this->notifyJobParticipants($videoCall, $user);
        } elseif ($videoCall->isEnded()) {
            $videoCall->update([
                'status' => 'active',
                'started_at' => now(),
                'ended_at' => null,
                'job_id' => $this->job->id,
            ]);
            VideoCallStarted::dispatch($videoCall, $user->name);
            $this->notifyJobParticipants($videoCall, $user);
        }

        $this->activeCallId = $videoCall->id;
        $this->activeRoomId = $videoCall->room_id;
        $this->showCallPanel = true;
    }

    public function joinCall(): void
    {
        // Re-query for active call in case it was started after this component mounted
        if (! $this->activeRoomId) {
            $activeCall = VideoCall::where('job_id', $this->job->id)
                ->whereIn('status', ['pending', 'waiting', 'active'])
                ->first();

            if ($activeCall) {
                $this->activeCallId = $activeCall->id;
                $this->activeRoomId = $activeCall->room_id;
            }
        }

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
                <flux:button
                    wire:click="startCall"
                    variant="ghost"
                    size="sm"
                    icon="video-camera"
                >
                    {{ __('Start Call') }}
                </flux:button>
            @endif
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
                        .listen('.video.call.started', (e) => {
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
