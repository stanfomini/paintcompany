<div>
    <div class="flex flex-wrap gap-4">
        @foreach ($modes as $modeKey => $mode)
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
                            Recommended
                        </flux:badge>
                    @endif
                </div>
            </flux:button>
        @endforeach
    </div>
</div>
