<?php

use App\Models\CallLog;
use App\Services\TwilioVoiceService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public string $phoneNumber = '';

    public bool $isOpen = false;

    public string $callState = 'idle'; // idle, calling, connected, ended

    public ?int $activeCallLogId = null;

    public ?string $activeCallLogUlid = null;

    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            "echo-private:call.user.{$userId},.call.connected" => 'onCallConnected',
            "echo-private:call.user.{$userId},.call.ended" => 'onCallEnded',
        ];
    }

    #[On('open-dial-pad')]
    public function open(?string $phone = null): void
    {
        $this->isOpen = true;
        $this->phoneNumber = $phone ?? '';
        $this->callState = 'idle';
        $this->activeCallLogId = null;
        $this->activeCallLogUlid = null;
    }

    public function close(): void
    {
        if ($this->callState === 'connected' || $this->callState === 'calling') {
            return;
        }

        $this->isOpen = false;
    }

    public function appendDigit(string $digit): void
    {
        if (strlen($this->phoneNumber) >= 20) {
            return;
        }

        $this->phoneNumber .= $digit;
    }

    public function backspace(): void
    {
        $this->phoneNumber = substr($this->phoneNumber, 0, -1);
    }

    public function clearNumber(): void
    {
        $this->phoneNumber = '';
    }

    public function dial(): void
    {
        if (empty($this->phoneNumber)) {
            return;
        }

        if (empty(auth()->user()->forwarding_number)) {
            $this->dispatch('notify', type: 'error', message: 'You must configure a forwarding number in your profile before making calls.');

            return;
        }

        $this->callState = 'calling';

        try {
            $voice = app(TwilioVoiceService::class);
            $callLog = $voice->initiateOutboundCall(auth()->user(), $this->phoneNumber);
            $this->activeCallLogId = $callLog->id;
            $this->activeCallLogUlid = $callLog->ulid;
            $this->isOpen = false;
            $this->dispatch('notify', type: 'info', message: __('Calling your phone...'));
        } catch (\Exception $e) {
            $this->callState = 'idle';
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function hangup(): void
    {
        if ($this->activeCallLogId) {
            $callLog = CallLog::find($this->activeCallLogId);

            if ($callLog && $callLog->twilio_call_sid && ! str_starts_with($callLog->twilio_call_sid, 'pending-')) {
                try {
                    app(\App\Services\TwilioService::class)
                        ->getClient()
                        ->calls($callLog->twilio_call_sid)
                        ->update(['status' => 'completed']);
                } catch (\Throwable) {
                    // Call may already be ended
                }
            }

            if ($callLog) {
                $callLog->update([
                    'status' => 'completed',
                    'ended_at' => now(),
                ]);
            }
        }

        $this->callState = 'ended';
    }

    public function onCallConnected(array $data): void
    {
        if (isset($data['call_log_id']) && $data['call_log_id'] === $this->activeCallLogId) {
            $this->callState = 'connected';
            $this->isOpen = false;
        }
    }

    public function onCallEnded(array $data): void
    {
        if (isset($data['call_log_id']) && $data['call_log_id'] === $this->activeCallLogId) {
            $this->callState = 'ended';
        }
    }

    public function resetDialPad(): void
    {
        $this->phoneNumber = '';
        $this->callState = 'idle';
        $this->activeCallLogId = null;
        $this->activeCallLogUlid = null;
    }

    public function selectContact(string $phone): void
    {
        $this->phoneNumber = $phone;
    }

    #[Computed]
    public function recentContacts(): Collection
    {
        return CallLog::where('caller_user_id', auth()->id())
            ->where('direction', 'outbound')
            ->latest()
            ->limit(30)
            ->get()
            ->unique('to_number')
            ->take(5);
    }
};
?>

<div>
    @if ($isOpen)
        <div
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50"
            wire:click.self="close"
            x-data="{
                seconds: 0,
                timer: null,
                startTimer() {
                    this.seconds = 0;
                    this.timer = setInterval(() => this.seconds++, 1000);
                },
                stopTimer() {
                    if (this.timer) {
                        clearInterval(this.timer);
                        this.timer = null;
                    }
                },
                formatTime() {
                    const m = Math.floor(this.seconds / 60).toString().padStart(2, '0');
                    const s = (this.seconds % 60).toString().padStart(2, '0');
                    return m + ':' + s;
                }
            }"
            x-effect="
                if ($wire.callState === 'connected' && !timer) { startTimer(); }
                if ($wire.callState !== 'connected') { stopTimer(); }
            "
        >
            <div class="bg-white dark:bg-zinc-800 rounded-t-2xl sm:rounded-2xl shadow-2xl w-full max-w-xs p-6">
                {{-- Header --}}
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Make a Call') }}</h3>
                    @if ($callState === 'idle' || $callState === 'ended')
                        <button wire:click="close" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors">
                            <flux:icon name="x-mark" class="size-5" />
                        </button>
                    @endif
                </div>

                {{-- Phone number display --}}
                <div class="text-center text-2xl font-mono tracking-wider h-10 flex items-center justify-center text-zinc-900 dark:text-white mb-4">
                    @if ($callState === 'idle' || $callState === 'ended')
                        {{ $phoneNumber ?: '...' }}
                    @elseif ($callState === 'calling')
                        <span class="text-base text-zinc-500 dark:text-zinc-400 animate-pulse">{{ __('Calling your phone...') }}</span>
                    @elseif ($callState === 'connected')
                        <span class="text-base text-green-500" x-text="formatTime()">00:00</span>
                    @endif
                </div>

                {{-- Keypad grid --}}
                @if ($callState === 'idle' || $callState === 'ended')
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        @foreach (['1' => '', '2' => 'ABC', '3' => 'DEF', '4' => 'GHI', '5' => 'JKL', '6' => 'MNO', '7' => 'PQRS', '8' => 'TUV', '9' => 'WXYZ', '*' => '', '0' => '+', '#' => ''] as $digit => $letters)
                            <button
                                wire:click="appendDigit('{{ $digit }}')"
                                class="h-14 rounded-full bg-zinc-100 dark:bg-zinc-700 hover:bg-zinc-200 dark:hover:bg-zinc-600 flex flex-col items-center justify-center transition-colors"
                            >
                                <span class="text-xl font-semibold text-zinc-900 dark:text-white">{{ $digit }}</span>
                                @if ($letters)
                                    <span class="text-[10px] text-zinc-400 tracking-widest">{{ $letters }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif

                {{-- Action buttons --}}
                <div class="flex items-center justify-center gap-4">
                    @if ($callState === 'idle' || $callState === 'ended')
                        {{-- Backspace --}}
                        <button
                            wire:click="backspace"
                            class="h-10 w-10 rounded-full hover:bg-zinc-100 dark:hover:bg-zinc-700 flex items-center justify-center text-zinc-400 transition-colors"
                            @if (empty($phoneNumber)) disabled @endif
                        >
                            <flux:icon name="backspace" class="size-5" />
                        </button>

                        {{-- Dial --}}
                        <button
                            wire:click="dial"
                            wire:loading.attr="disabled"
                            class="h-14 w-14 rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center justify-center shadow-lg transition-colors disabled:opacity-50"
                            @if (empty($phoneNumber)) disabled @endif
                        >
                            <flux:icon name="phone" variant="solid" class="size-6" />
                        </button>

                        {{-- Clear --}}
                        <button
                            wire:click="clearNumber"
                            class="h-10 w-10 rounded-full hover:bg-zinc-100 dark:hover:bg-zinc-700 flex items-center justify-center text-zinc-400 transition-colors"
                            @if (empty($phoneNumber)) disabled @endif
                        >
                            <flux:icon name="x-mark" class="size-5" />
                        </button>
                    @elseif ($callState === 'calling')
                        {{-- Cancel / Hangup while ringing --}}
                        <button
                            wire:click="hangup"
                            class="h-14 w-14 rounded-full bg-red-500 hover:bg-red-600 text-white flex items-center justify-center shadow-lg transition-colors"
                        >
                            <flux:icon name="phone-x-mark" variant="solid" class="size-6" />
                        </button>
                    @elseif ($callState === 'connected')
                        <div class="flex flex-col items-center gap-3">
                            <p class="text-sm text-green-500 font-medium">{{ __('Connected') }}</p>
                            <button
                                wire:click="hangup"
                                class="h-14 w-14 rounded-full bg-red-500 hover:bg-red-600 text-white flex items-center justify-center shadow-lg transition-colors"
                            >
                                <flux:icon name="phone-x-mark" variant="solid" class="size-6" />
                            </button>
                        </div>
                    @endif
                </div>

                {{-- New call button after ended --}}
                @if ($callState === 'ended')
                    <div class="mt-4 text-center">
                        <button wire:click="resetDialPad" class="text-sm text-blue-500 hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300">
                            {{ __('New Call') }}
                        </button>
                    </div>
                @endif

                {{-- Recent contacts --}}
                @if ($callState === 'idle' && $this->recentContacts->count() > 0)
                    <div class="mt-4 border-t border-zinc-200 dark:border-zinc-700 pt-3">
                        <p class="text-xs text-zinc-400 mb-2">{{ __('Recent') }}</p>
                        <div class="space-y-1">
                            @foreach ($this->recentContacts as $contact)
                                <button
                                    wire:key="recent-{{ $contact->id }}"
                                    wire:click="selectContact('{{ $contact->to_number }}')"
                                    class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-700 text-left transition-colors"
                                >
                                    <flux:icon name="phone" class="size-4 text-zinc-400 shrink-0" />
                                    <div class="min-w-0">
                                        @if ($contact->customer?->name)
                                            <p class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $contact->customer->name }}</p>
                                            <p class="text-xs text-zinc-400 truncate">{{ $contact->to_number }}</p>
                                        @else
                                            <p class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $contact->to_number }}</p>
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
