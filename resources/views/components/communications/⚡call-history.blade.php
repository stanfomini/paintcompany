<?php

use App\Models\CallLog;
use App\Models\CallLogUser;
use App\Services\MissedCallVoicemailService;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('phone.view_call_history')) {
            abort(403);
        }
    }

    public ?int $customerId = null;

    public string $filter = 'all';

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    #[Computed]
    public function callLogs()
    {
        $query = CallLog::query()
            ->with(['customer', 'callerUser', 'answeredByUser', 'voicemailForUser', 'userInteractions'])
            ->latest('started_at');

        // Scope to specific customer when viewing per-contact calls
        if ($this->customerId) {
            $query->where('customer_id', $this->customerId);
        }

        // For missed/voicemail/unhandled filters, apply visibility scoping
        if (in_array($this->filter, ['missed', 'voicemail', 'unhandled'])) {
            $query->visibleTo(auth()->user());
        }

        if ($this->filter === 'missed') {
            $query->where('status', 'no-answer');
        } elseif ($this->filter === 'voicemail') {
            $query->where('status', 'voicemail');
        } elseif ($this->filter === 'unhandled') {
            $query->missed()->unhandled();
        } elseif ($this->filter === 'inbound') {
            $query->inbound();
        } elseif ($this->filter === 'outbound') {
            $query->outbound();
        }

        if (! empty($this->search)) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('from_number', 'like', "%{$search}%")
                    ->orWhere('to_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        return $query->paginate(25);
    }

    public function markListened(int $callLogId): void
    {
        $callLog = CallLog::find($callLogId);
        if ($callLog) {
            app(MissedCallVoicemailService::class)->markListened($callLog, auth()->user());
        }
    }

    public function markHandled(int $callLogId, string $action): void
    {
        $callLog = CallLog::find($callLogId);
        if ($callLog) {
            app(MissedCallVoicemailService::class)->markHandled($callLog, auth()->user(), $action);
            unset($this->callLogs);
            $this->dispatch('refresh-notification-counts');
        }
    }

    public function callBack(string $phone): void
    {
        $this->dispatch('open-dial-pad', phone: $phone);
    }
};
?>

<div>
    {{-- Filters --}}
    <div class="flex items-center gap-1 border-b border-zinc-200 dark:border-zinc-700 mb-4 overflow-x-auto shrink-0">
        @php
            $filters = [
                'all' => __('All Calls'),
                'missed' => __('Missed'),
                'voicemail' => __('Voicemail'),
                'unhandled' => __('Unhandled'),
                'inbound' => __('Inbound'),
                'outbound' => __('Outbound'),
            ];
        @endphp
        @foreach ($filters as $key => $label)
            <button
                wire:click="setFilter('{{ $key }}')"
                wire:key="filter-{{ $key }}"
                class="px-2.5 sm:px-3 py-2.5 text-xs sm:text-sm font-medium whitespace-nowrap border-b-2 transition-colors {{ $filter === $key ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Search --}}
    <div class="mb-4 px-1">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            placeholder="{{ __('Search by phone number or customer name...') }}"
            size="sm"
        />
    </div>

    {{-- Call Log List --}}
    @if ($this->callLogs->isEmpty())
        <div class="text-center py-12">
            <flux:icon name="phone" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
            <flux:text size="sm" class="text-zinc-500">{{ __('No call history found.') }}</flux:text>
        </div>
    @else
        <div class="space-y-2 px-1" x-data="callHistoryPlayer()">
            @foreach ($this->callLogs as $log)
                <div
                    wire:key="call-{{ $log->id }}"
                    class="flex items-start gap-3 p-3 sm:p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                >
                    {{-- Direction Icon --}}
                    <div class="shrink-0 mt-0.5">
                        @if ($log->status === 'no-answer')
                            <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                                <flux:icon name="phone-x-mark" class="w-4 h-4 text-red-600 dark:text-red-400" />
                            </div>
                        @elseif ($log->isVoicemail())
                            <div class="w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                                <flux:icon name="inbox" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                            </div>
                        @elseif ($log->direction === 'inbound')
                            <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                                <flux:icon name="phone-arrow-down-left" class="w-4 h-4 text-green-600 dark:text-green-400" />
                            </div>
                        @else
                            <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                                <flux:icon name="phone-arrow-up-right" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                            </div>
                        @endif
                    </div>

                    {{-- Call Details --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            {{-- Caller/Recipient Name --}}
                            @if ($log->customer)
                                <flux:text class="font-medium text-sm">{{ $log->customer->full_name }}</flux:text>
                            @elseif ($log->direction === 'inbound')
                                <flux:text class="font-medium text-sm">{{ $log->from_number }}</flux:text>
                            @else
                                <flux:text class="font-medium text-sm">{{ $log->to_number }}</flux:text>
                            @endif

                            {{-- Status Badge --}}
                            @if ($log->status === 'no-answer')
                                <flux:badge size="sm" color="red">{{ __('Missed') }}</flux:badge>
                            @elseif ($log->isVoicemail())
                                <flux:badge size="sm" color="yellow">{{ __('Voicemail') }}</flux:badge>
                            @elseif ($log->status === 'in-progress')
                                <flux:badge size="sm" color="green">{{ __('In Progress') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Completed') }}</flux:badge>
                            @endif

                            {{-- Ownership Badge --}}
                            @if ($log->isVoicemail() || $log->isMissedCall())
                                @if ($log->isCompanyWide())
                                    <flux:badge size="sm" color="blue">{{ __('Company') }}</flux:badge>
                                @elseif ($log->voicemailForUser)
                                    <flux:badge size="sm" color="purple">{{ $log->voicemailForUser->name }}</flux:badge>
                                @endif
                            @endif

                            {{-- Handled Status --}}
                            @if (($log->isVoicemail() || $log->isMissedCall()) && $log->isHandled())
                                <flux:badge size="sm" color="green">{{ __('Handled') }}</flux:badge>
                            @elseif ($log->isVoicemail() || $log->isMissedCall())
                                <flux:badge size="sm" color="orange">{{ __('Needs Callback') }}</flux:badge>
                            @endif
                        </div>

                        {{-- Phone number (show if customer exists, as secondary info) --}}
                        @if ($log->customer)
                            <flux:text size="sm" class="text-zinc-500 mt-0.5">
                                {{ $log->direction === 'inbound' ? $log->from_number : $log->to_number }}
                            </flux:text>
                        @endif

                        {{-- Answered by --}}
                        @if ($log->answeredByUser)
                            <flux:text size="sm" class="text-zinc-400 mt-0.5">
                                {{ __('Answered by') }} {{ $log->answeredByUser->name }}
                            </flux:text>
                        @elseif ($log->callerUser && $log->direction === 'outbound')
                            <flux:text size="sm" class="text-zinc-400 mt-0.5">
                                {{ __('Called by') }} {{ $log->callerUser->name }}
                            </flux:text>
                        @endif

                        {{-- Voicemail Transcription --}}
                        @if ($log->isVoicemail() && $log->voicemail_transcription)
                            <div class="mt-2 p-2 rounded bg-amber-50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800" x-data="{ expanded: false }">
                                <flux:text size="sm" class="text-zinc-700 dark:text-zinc-300 italic">
                                    <span x-show="!expanded">"{{ \Illuminate\Support\Str::limit($log->voicemail_transcription, 120) }}"</span>
                                    <span x-show="expanded" x-cloak>"{{ $log->voicemail_transcription }}"</span>
                                </flux:text>
                                @if (strlen($log->voicemail_transcription) > 120)
                                    <button @click="expanded = !expanded" class="text-xs text-blue-600 dark:text-blue-400 hover:underline mt-1">
                                        <span x-show="!expanded">{{ __('Show more') }}</span>
                                        <span x-show="expanded" x-cloak>{{ __('Show less') }}</span>
                                    </button>
                                @endif
                            </div>
                        @endif

                        {{-- Audio Player for Voicemail/Recording --}}
                        @if ($log->hasRecording())
                            <div class="mt-2">
                                <button
                                    @click="togglePlay('{{ $log->recording_url }}'); $wire.markListened({{ $log->id }})"
                                    class="inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
                                >
                                    <template x-if="currentUrl === '{{ $log->recording_url }}' && playing">
                                        <flux:icon name="pause" class="w-3.5 h-3.5" />
                                    </template>
                                    <template x-if="currentUrl !== '{{ $log->recording_url }}' || !playing">
                                        <flux:icon name="play" class="w-3.5 h-3.5" />
                                    </template>
                                    <span>
                                        {{ $log->isVoicemail() ? __('Play Voicemail') : __('Play Recording') }}
                                        @if ($log->recording_duration_seconds)
                                            ({{ gmdate($log->recording_duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $log->recording_duration_seconds) }})
                                        @endif
                                    </span>
                                </button>
                            </div>
                        @endif
                    </div>

                    {{-- Right Side: Time + Actions --}}
                    <div class="shrink-0 text-right space-y-1">
                        <flux:text size="xs" class="text-zinc-400">
                            {{ $log->started_at?->diffForHumans() }}
                        </flux:text>
                        @if ($log->duration_seconds > 0)
                            <flux:text size="xs" class="text-zinc-400">
                                {{ $log->getFormattedDuration() }}
                            </flux:text>
                        @endif
                        @can('phone.make_calls')
                            <div class="mt-1">
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="phone"
                                    wire:click="callBack('{{ $log->direction === 'inbound' ? $log->from_number : $log->to_number }}')"
                                    title="{{ __('Call back') }}"
                                />
                            </div>
                        @endcan
                        @if (($log->isVoicemail() || $log->isMissedCall()) && !$log->isHandled())
                            @can('phone.manage_voicemails')
                                <div class="mt-1">
                                    <flux:dropdown>
                                        <flux:button size="xs" variant="ghost" icon="check-circle">
                                            {{ __('Handle') }}
                                        </flux:button>
                                        <flux:menu>
                                            <flux:menu.item wire:click="markHandled({{ $log->id }}, 'called_back')" icon="phone">
                                                {{ __('Called Back') }}
                                            </flux:menu.item>
                                            <flux:menu.item wire:click="markHandled({{ $log->id }}, 'left_message')" icon="chat-bubble-left">
                                                {{ __('Left Message') }}
                                            </flux:menu.item>
                                            <flux:menu.item wire:click="markHandled({{ $log->id }}, 'not_needed')" icon="x-mark">
                                                {{ __('Not Needed') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            @endcan
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-4 px-1">
            {{ $this->callLogs->links() }}
        </div>

        {{-- Hidden Audio Element --}}
        <audio x-ref="audioPlayer" class="hidden"></audio>
    @endif

    @script
    <script>
        Alpine.data('callHistoryPlayer', () => ({
            playing: false,
            currentUrl: null,

            togglePlay(url) {
                const audio = this.$refs.audioPlayer;
                if (!audio) return;

                if (this.currentUrl === url && this.playing) {
                    audio.pause();
                    this.playing = false;
                    return;
                }

                if (this.currentUrl !== url) {
                    audio.src = url;
                    this.currentUrl = url;
                }

                audio.play();
                this.playing = true;

                audio.onended = () => {
                    this.playing = false;
                };
                audio.onpause = () => {
                    this.playing = false;
                };
            }
        }));
    </script>
    @endscript
</div>
