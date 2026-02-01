<div>
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Welcome, :name!', ['name' => auth('customer')->user()->name]) }}</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                    {{ __('Track your painting projects and communicate with our team.') }}
                </flux:text>
            </div>
        </div>

        @if (! $customer)
            <flux:callout variant="info">
                <flux:callout.heading>{{ __('Welcome to Your Customer Portal') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Your account has been set up. Once we create your customer profile and project, you\'ll be able to view your projects, estimates, and properties here.') }}
                </flux:callout.text>
            </flux:callout>
        @else
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-blue-100 dark:bg-blue-900 p-3">
                            <flux:icon name="briefcase" class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Active Projects') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">{{ $activeProjectsCount }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-yellow-100 dark:bg-yellow-900 p-3">
                            <flux:icon name="document-text" class="h-6 w-6 text-yellow-600 dark:text-yellow-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Pending Estimates') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">{{ $pendingEstimatesCount }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-green-100 dark:bg-green-900 p-3">
                            <flux:icon name="home" class="h-6 w-6 text-green-600 dark:text-green-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Properties') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">{{ $propertiesCount }}</flux:text>
                        </div>
                    </div>
                </flux:card>
            </div>

            <!-- Content Sections -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Recent Projects -->
                <flux:card>
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="lg">{{ __('Recent Projects') }}</flux:heading>
                        @if ($recentProjects->isNotEmpty())
                            <flux:button href="{{ route('customer.projects.index') }}" variant="ghost" size="sm">
                                {{ __('View All') }}
                            </flux:button>
                        @endif
                    </div>
                    @if ($recentProjects->isNotEmpty())
                        <div class="space-y-4">
                            @foreach ($recentProjects as $project)
                                <a wire:key="project-{{ $project->id }}" href="{{ route('customer.projects.show', $project) }}" class="block">
                                    <div class="flex items-start gap-4 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                                        <div class="flex-shrink-0 rounded-lg bg-blue-100 dark:bg-blue-900 p-2">
                                            <flux:icon name="briefcase" class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <flux:text class="font-medium truncate">{{ $project->title }}</flux:text>
                                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 truncate">
                                                {{ $project->property?->display_name ?? __('No property assigned') }}
                                            </flux:text>
                                        </div>
                                        <div class="flex-shrink-0">
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
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <flux:icon name="briefcase" class="mx-auto h-12 w-12 text-zinc-400" />
                            <flux:text class="mt-4 text-zinc-500">{{ __('No projects yet.') }}</flux:text>
                        </div>
                    @endif
                </flux:card>

                <!-- Pending Estimates -->
                <flux:card>
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="lg">{{ __('Estimates Awaiting Response') }}</flux:heading>
                        @if ($pendingEstimates->isNotEmpty())
                            <flux:button href="{{ route('customer.estimates.index') }}" variant="ghost" size="sm">
                                {{ __('View All') }}
                            </flux:button>
                        @endif
                    </div>
                    @if ($pendingEstimates->isNotEmpty())
                        <div class="space-y-4">
                            @foreach ($pendingEstimates as $estimate)
                                <a wire:key="estimate-{{ $estimate->id }}" href="{{ route('customer.estimates.show', $estimate) }}" class="block">
                                    <div class="flex items-start gap-4 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                                        <div class="flex-shrink-0 rounded-lg bg-yellow-100 dark:bg-yellow-900 p-2">
                                            <flux:icon name="document-text" class="h-5 w-5 text-yellow-600 dark:text-yellow-400" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <flux:text class="font-medium truncate">{{ $estimate->estimate_number }}</flux:text>
                                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 truncate">
                                                {{ $estimate->deal->title }}
                                            </flux:text>
                                        </div>
                                        <div class="flex-shrink-0 text-right">
                                            <flux:text class="font-semibold">${{ number_format($estimate->total, 2) }}</flux:text>
                                            <flux:badge size="sm" color="yellow">{{ __('Pending') }}</flux:badge>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
                            <flux:text class="mt-4 text-zinc-500">{{ __('No pending estimates.') }}</flux:text>
                        </div>
                    @endif
                </flux:card>
            </div>
        @endif

        <!-- Contact Support -->
        <flux:card>
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">{{ __('Need Help?') }}</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">
                        {{ __('Our team is here to assist you with any questions.') }}
                    </flux:text>
                </div>
                <flux:button variant="primary" icon="chat-bubble-left-right">
                    {{ __('Contact Us') }}
                </flux:button>
            </div>
        </flux:card>
    </div>
</div>
