<?php

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public const MODE_SIMPLE = 'simple';

    public const MODE_TEMPLATE = 'template';

    public string $selectedMode = self::MODE_TEMPLATE;

    public bool $showDescriptions = true;

    public function selectMode(string $mode): void
    {
        if (in_array($mode, [self::MODE_SIMPLE, self::MODE_TEMPLATE])) {
            $this->selectedMode = $mode;
            $this->dispatch('mode-selected', mode: $mode);
        }
    }

    /**
     * @return array<string, array{icon: string, name: string, description: string, time: string, recommended?: bool}>
     */
    #[Computed]
    public function modes(): array
    {
        return $this->getModes();
    }

    public function getModes(): array
    {
        return [
            self::MODE_SIMPLE => [
                'icon' => 'bolt',
                'name' => __('Quick Estimate'),
                'description' => __('$/sqft pricing'),
                'time' => __('~30 seconds'),
            ],
            self::MODE_TEMPLATE => [
                'icon' => 'squares-2x2',
                'name' => __('Template Builder'),
                'description' => __('Pre-made cards'),
                'time' => __('~5 minutes'),
                'recommended' => true,
            ],
        ];
    }
}; ?>

<div>
    <div class="flex flex-wrap gap-4">
        @foreach ($this->modes as $modeKey => $mode)
            <flux:button
                wire:key="mode-{{ $modeKey }}"
                wire:click="selectMode('{{ $modeKey }}')"
                type="button"
                :variant="$selectedMode === $modeKey ? 'primary' : 'ghost'"
                class="flex-1 min-w-[160px] h-auto py-4 px-4 flex flex-col items-center gap-2"
            >
                <flux:icon :name="$mode['icon']" class="w-6 h-6" />

                <span class="font-medium">{{ $mode['name'] }}</span>

                @if ($showDescriptions)
                    <span class="text-xs opacity-75">{{ $mode['description'] }}</span>
                @endif

                <div class="flex items-center gap-2 mt-1">
                    <flux:badge size="sm" :color="$selectedMode === $modeKey ? 'white' : 'zinc'">
                        {{ $mode['time'] }}
                    </flux:badge>

                    @if (isset($mode['recommended']) && $mode['recommended'])
                        <flux:badge size="sm" color="green">
                            {{ __('Recommended') }}
                        </flux:badge>
                    @endif
                </div>
            </flux:button>
        @endforeach
    </div>
</div>
