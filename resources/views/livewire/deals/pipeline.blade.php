<div>
    <div class="max-w-full mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Deal Pipeline</flux:heading>
            @livewire('deals.create-modal')
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="Search deals..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="assignedToFilter">
                    <option value="">All Team Members</option>
                    @foreach ($internalUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="customerFilter">
                    <option value="">All Customers</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->full_name }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <div class="overflow-x-auto pb-4">
            <div class="flex gap-4 min-w-max">
                @foreach ($stages as $stageKey => $stageLabel)
                    <div class="w-72 flex-shrink-0">
                        <div class="rounded-lg border {{ $stageColors[$stageKey] }} overflow-hidden">
                            <div class="px-4 py-3 {{ $stageHeaderColors[$stageKey] }}">
                                <div class="flex items-center justify-between">
                                    <flux:heading size="sm" class="text-white font-semibold">
                                        {{ $stageLabel }}
                                    </flux:heading>
                                    <flux:badge size="sm" class="bg-white/20 text-white">
                                        {{ $dealsByStage[$stageKey]->count() }}
                                    </flux:badge>
                                </div>
                            </div>

                            <div
                                wire:sort="updateOrder"
                                wire:sort:group="deals"
                                wire:sort:config="{{ json_encode(['group' => $stageKey]) }}"
                                class="p-2 min-h-[200px] space-y-2"
                                data-stage="{{ $stageKey }}"
                            >
                                @forelse ($dealsByStage[$stageKey] as $deal)
                                    <div
                                        wire:sort:item="{{ $deal->id }}"
                                        wire:key="deal-{{ $deal->id }}"
                                        class="bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 shadow-sm cursor-move hover:shadow-md transition-shadow"
                                    >
                                        <div class="flex items-start justify-between mb-2">
                                            <flux:text class="font-medium text-sm truncate flex-1">
                                                {{ $deal->title }}
                                            </flux:text>
                                            <flux:dropdown>
                                                <flux:button variant="ghost" size="xs" icon="ellipsis-vertical" class="ml-1" />
                                                <flux:menu>
                                                    <flux:menu.item icon="eye" href="{{ route('deals.show', $deal) }}" wire:navigate>
                                                        View
                                                    </flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </div>

                                        @if ($deal->customer)
                                            <div class="flex items-center text-xs text-zinc-500 dark:text-zinc-400 mb-2">
                                                <flux:icon name="user" class="h-3 w-3 mr-1" />
                                                {{ $deal->customer->full_name }}
                                            </div>
                                        @endif

                                        <div class="flex items-center justify-between">
                                            @if ($deal->estimated_value)
                                                <flux:badge size="sm" color="green">
                                                    ${{ number_format($deal->estimated_value, 0) }}
                                                </flux:badge>
                                            @else
                                                <span></span>
                                            @endif

                                            @if ($deal->assignedTo)
                                                <flux:tooltip content="{{ $deal->assignedTo->name }}">
                                                    <flux:avatar size="xs" name="{{ $deal->assignedTo->name }}" />
                                                </flux:tooltip>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-center py-8 text-zinc-400 dark:text-zinc-500 text-sm">
                                        No deals
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
