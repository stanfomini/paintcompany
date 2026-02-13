<?php

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /** @var array<int, array{id: int, title: string, customer_name: string, created_at: string}> */
    public array $alerts = [];

    public function getListeners(): array
    {
        // Only listen if user is internal
        if (! auth()->check() || ! auth()->user()->can('deals.view')) {
            return [];
        }

        return [
            'echo-private:internal-team,lead.created' => 'handleNewLead',
        ];
    }

    public function handleNewLead(array $data): void
    {
        $deal = $data['deal'] ?? null;

        if (! $deal) {
            return;
        }

        // Don't show alert for leads created by the current user
        if (isset($deal['created_by']['id']) && $deal['created_by']['id'] === auth()->id()) {
            return;
        }

        $this->alerts[] = [
            'id' => $deal['id'],
            'title' => $deal['title'],
            'customer_name' => $deal['customer']['full_name'] ?? 'Unknown',
            'created_at' => $deal['created_at'],
            'created_by' => $deal['created_by']['name'] ?? 'Unknown',
        ];

        // Play notification sound (via browser)
        $this->dispatch('play-notification-sound');
    }

    public function dismissAlert(int $index): void
    {
        unset($this->alerts[$index]);
        $this->alerts = array_values($this->alerts);
    }

    public function dismissAll(): void
    {
        $this->alerts = [];
    }

    #[On('refresh-alerts')]
    public function refreshAlerts(): void
    {
        // Called to refresh the component if needed
    }
}; ?>

<div>
    @if (count($alerts) > 0)
        <div class="fixed bottom-4 right-4 z-50 space-y-2 max-w-sm">
            @foreach ($alerts as $index => $alert)
                <div
                    wire:key="alert-{{ $alert['id'] }}"
                    class="bg-green-50 dark:bg-green-900/50 border border-green-200 dark:border-green-800 rounded-lg shadow-lg p-4 animate-slide-in"
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-2"
                >
                    <div class="flex items-start gap-3">
                        <div class="shrink-0">
                            <flux:icon name="user-plus" class="w-5 h-5 text-green-600 dark:text-green-400" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-green-800 dark:text-green-200">
                                {{ __('New Lead Created') }}
                            </p>
                            <p class="text-sm text-green-700 dark:text-green-300 truncate">
                                {{ $alert['customer_name'] }}
                            </p>
                            <p class="text-xs text-green-600 dark:text-green-400 mt-1">
                                {{ __('by') }} {{ $alert['created_by'] }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <a
                                href="{{ route('deals.show', $alert['id']) }}"
                                class="text-xs font-medium text-green-700 dark:text-green-300 hover:text-green-900 dark:hover:text-green-100"
                                wire:navigate
                            >
                                {{ __('View') }}
                            </a>
                            <button
                                wire:click="dismissAlert({{ $index }})"
                                class="text-green-500 hover:text-green-700 dark:text-green-400 dark:hover:text-green-200"
                            >
                                <flux:icon name="x-mark" class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach

            @if (count($alerts) > 1)
                <div class="text-right">
                    <button
                        wire:click="dismissAll"
                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
                    >
                        {{ __('Dismiss all') }}
                    </button>
                </div>
            @endif
        </div>
    @endif

    @script
    <script>
        $wire.on('play-notification-sound', () => {
            // Try to play a notification sound
            try {
                const audio = new Audio('/sounds/notification.mp3');
                audio.volume = 0.5;
                audio.play().catch(() => {
                    // Ignore audio play errors (user hasn't interacted yet)
                });
            } catch (e) {
                // Audio not supported
            }
        });
    </script>
    @endscript
</div>
