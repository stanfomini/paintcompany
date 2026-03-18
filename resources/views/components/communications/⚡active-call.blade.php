<?php

use App\Models\CallLog;
use App\Models\User;
use App\Services\TwilioService;
use App\Services\TwilioVoiceService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $isActive = false;

    public ?int $callLogId = null;

    public ?string $callLogUlid = null;

    public ?string $callerName = null;

    public ?string $callerNumber = null;

    public ?int $customerId = null;

    public bool $showTransferPicker = false;

    public function getListeners(): array
    {
        $userId = auth()->id();

        return [
            "echo-private:call.user.{$userId},.call.connected" => 'onCallConnected',
            "echo-private:call.user.{$userId},.call.ended" => 'onCallEnded',
        ];
    }

    public function onCallConnected(array $data): void
    {
        $this->isActive = true;
        $this->callLogId = $data['call_log_id'] ?? null;
        $this->callLogUlid = $data['ulid'] ?? null;
        $this->callerName = $data['caller_name'] ?? null;
        $this->callerNumber = $data['from_number'] ?? null;
        $this->customerId = $data['customer_id'] ?? null;
        $this->showTransferPicker = false;
    }

    public function onCallEnded(array $data): void
    {
        if (isset($data['call_log_id']) && $data['call_log_id'] === $this->callLogId) {
            $this->resetState();
        }
    }

    public function hangup(): void
    {
        if (! $this->callLogId) {
            return;
        }

        $callLog = CallLog::find($this->callLogId);

        if ($callLog && $callLog->twilio_call_sid && ! str_starts_with($callLog->twilio_call_sid, 'pending-')) {
            try {
                app(TwilioService::class)
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

        $this->resetState();
    }

    public function transfer(string $extension): void
    {
        if (! $this->callLogId) {
            return;
        }

        $callLog = CallLog::find($this->callLogId);

        if (! $callLog) {
            $this->dispatch('notify', type: 'error', message: 'Call not found.');
            return;
        }

        try {
            $voice = app(TwilioVoiceService::class);
            $voice->transferCall($callLog->twilio_call_sid, $extension);
            $this->resetState();
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Failed to transfer call.');
        }
    }

    public function toggleTransferPicker(): void
    {
        $this->showTransferPicker = ! $this->showTransferPicker;
    }

    public function closeTransferPicker(): void
    {
        $this->showTransferPicker = false;
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Support\Collection
    {
        return User::where('type', 'internal')
            ->whereNotNull('extension')
            ->whereNotNull('forwarding_number')
            ->where('id', '!=', auth()->id())
            ->orderBy('name')
            ->get(['id', 'name', 'extension']);
    }

    private function resetState(): void
    {
        $this->isActive = false;
        $this->callLogId = null;
        $this->callLogUlid = null;
        $this->callerName = null;
        $this->callerNumber = null;
        $this->customerId = null;
        $this->showTransferPicker = false;
    }
};
?>

<div>
    @if ($isActive)
        <div
            x-data="{
                expanded: true,
                seconds: 0,
                timer: null,
                init() {
                    this.timer = setInterval(() => this.seconds++, 1000);
                },
                destroy() {
                    if (this.timer) clearInterval(this.timer);
                },
                formatTime() {
                    const m = Math.floor(this.seconds / 60).toString().padStart(2, '0');
                    const s = (this.seconds % 60).toString().padStart(2, '0');
                    return m + ':' + s;
                },
                collapse() {
                    this.expanded = false;
                    $wire.closeTransferPicker();
                }
            }"
            class="bg-green-700 dark:bg-green-800 text-white"
        >
            {{-- Actions row (expands upward, shown above info row) --}}
            <div
                x-show="expanded"
                x-transition
                class="flex items-center gap-2 px-4 py-2 border-b border-green-600"
            >
                {{-- Transfer button --}}
                @can('phone.transfer_calls')
                    <div class="relative flex-1">
                        <button
                            wire:click="toggleTransferPicker"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-white/20 hover:bg-white/30 transition-colors"
                            title="{{ __('Transfer') }}"
                        >
                            <flux:icon name="arrow-right-start-on-rectangle" class="size-4" />
                            <span>{{ __('Transfer') }}</span>
                        </button>

                        {{-- Transfer picker — opens upward --}}
                        @if ($showTransferPicker)
                            <div class="absolute left-0 bottom-full mb-2 w-56 bg-white dark:bg-zinc-800 rounded-lg shadow-xl border border-zinc-200 dark:border-zinc-700 py-1 z-50">
                                <p class="px-3 py-1.5 text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Transfer to') }}
                                </p>
                                @forelse ($this->internalUsers as $user)
                                    <button
                                        wire:key="transfer-user-{{ $user->id }}"
                                        wire:click="transfer('{{ $user->extension }}')"
                                        class="w-full text-left px-3 py-2 text-sm text-zinc-900 dark:text-white hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors flex items-center justify-between"
                                    >
                                        <span class="truncate">{{ $user->name }}</span>
                                        <span class="text-xs text-zinc-400 dark:text-zinc-500 font-mono">x{{ $user->extension }}</span>
                                    </button>
                                @empty
                                    <p class="px-3 py-2 text-sm text-zinc-400 dark:text-zinc-500">
                                        {{ __('No users available') }}
                                    </p>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @endcan

                {{-- Hang up button --}}
                <button
                    wire:click="hangup"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-red-500 hover:bg-red-600 transition-colors ml-auto"
                    title="{{ __('Hang Up') }}"
                >
                    <flux:icon name="phone-x-mark" variant="solid" class="size-4" />
                    <span>{{ __('Hang Up') }}</span>
                </button>
            </div>

            {{-- Info row — always visible --}}
            <div class="px-4 py-2.5 flex items-center justify-between gap-4">
                {{-- Caller info --}}
                <div class="flex items-center gap-3 min-w-0">
                    <div class="shrink-0 flex items-center gap-1.5">
                        <span class="relative flex size-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white/75"></span>
                            <span class="relative inline-flex rounded-full size-2.5 bg-white"></span>
                        </span>
                        <flux:icon name="phone" variant="solid" class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate">
                            {{ $callerName ?? $callerNumber ?? __('Active Call') }}
                        </p>
                        @if ($callerName && $callerNumber)
                            <p class="text-xs text-green-200 truncate">{{ $callerNumber }}</p>
                        @endif
                    </div>
                </div>

                {{-- Timer + chevron --}}
                <div class="flex items-center gap-3 shrink-0">
                    <span class="text-sm font-mono tabular-nums" x-text="formatTime()">00:00</span>
                    <button
                        @click="expanded ? collapse() : expanded = true"
                        class="text-white/80 hover:text-white transition-colors p-2 -mr-2"
                        :title="expanded ? '{{ __('Collapse') }}' : '{{ __('Expand') }}'"
                    >
                        <flux:icon name="chevron-up" class="size-4 transition-transform" :class="expanded ? '' : 'rotate-180'" />
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
