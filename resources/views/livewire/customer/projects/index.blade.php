<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('My Projects') }}</flux:heading>
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Search projects...') }}"
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-full sm:w-48">
                <flux:select wire:model.live="stage">
                    <option value="">{{ __('All Stages') }}</option>
                    @foreach ($stages as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($projects instanceof \Illuminate\Pagination\LengthAwarePaginator && $projects->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($projects as $project)
                    <a wire:key="project-{{ $project->id }}" href="{{ route('customer.projects.show', $project) }}" class="block">
                        <flux:card class="h-full hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors">
                            <div class="flex items-start justify-between mb-3">
                                <flux:heading size="sm" class="font-medium truncate pr-2">{{ $project->title }}</flux:heading>
                                @php
                                    $stageColor = match($project->stage) {
                                        'lead' => 'zinc',
                                        'quoted' => 'yellow',
                                        'scheduled' => 'blue',
                                        'in_progress' => 'purple',
                                        'completed' => 'green',
                                        'paid' => 'green',
                                        'cancelled' => 'red',
                                        default => 'zinc',
                                    };
                                @endphp
                                <flux:badge size="sm" color="{{ $stageColor }}">
                                    {{ $project->stage_label }}
                                </flux:badge>
                            </div>

                            @if ($project->property)
                                <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400 mb-3">
                                    <flux:icon name="map-pin" class="h-4 w-4" />
                                    <flux:text class="text-sm truncate">{{ $project->property->display_name }}</flux:text>
                                </div>
                            @endif

                            @if ($project->description)
                                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400 line-clamp-2 mb-3">
                                    {{ $project->description }}
                                </flux:text>
                            @endif

                            <div class="flex items-center justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                @if ($project->expected_start_date)
                                    <div class="flex items-center gap-1 text-zinc-500">
                                        <flux:icon name="calendar" class="h-4 w-4" />
                                        <flux:text class="text-sm">{{ $project->expected_start_date->format('M j, Y') }}</flux:text>
                                    </div>
                                @else
                                    <flux:text class="text-sm text-zinc-400">{{ __('Not scheduled') }}</flux:text>
                                @endif

                                @if ($project->estimated_value)
                                    <flux:text class="font-semibold">${{ number_format($project->estimated_value, 0) }}</flux:text>
                                @endif
                            </div>
                        </flux:card>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $projects->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="briefcase" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No projects found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $stage)
                        {{ __('Try adjusting your search criteria.') }}
                    @else
                        {{ __('Your projects will appear here once created.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
