<div>
    <div class="max-w-4xl mx-auto">
        <div class="mb-6">
            <flux:button variant="ghost" href="{{ route('customer.properties.index') }}" icon="arrow-left">
                {{ __('Back to Properties') }}
            </flux:button>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <flux:heading size="xl">{{ $property->display_name }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ $property->full_address }}</flux:text>
            </div>
            @if ($property->type)
                <flux:badge size="lg" color="blue" class="capitalize">{{ $property->type }}</flux:badge>
            @endif
        </div>

        <!-- Property Info Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Type') }}</flux:text>
                <flux:text class="text-lg font-semibold capitalize">
                    {{ $property->type ?? __('Not specified') }}
                </flux:text>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Square Footage') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    @if ($property->square_footage)
                        {{ number_format($property->square_footage) }} sq ft
                    @else
                        {{ __('Not set') }}
                    @endif
                </flux:text>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Year Built') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    {{ $property->year_built ?? __('Not set') }}
                </flux:text>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Projects') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    {{ $deals->count() }}
                </flux:text>
            </div>
        </div>

        <!-- Property Details -->
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            <flux:heading size="lg" class="mb-4">{{ __('Property Details') }}</flux:heading>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <flux:text class="text-zinc-500 text-sm">{{ __('Address') }}</flux:text>
                    <flux:text class="font-medium">{{ $property->address }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500 text-sm">{{ __('City') }}</flux:text>
                    <flux:text class="font-medium">{{ $property->city }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500 text-sm">{{ __('State') }}</flux:text>
                    <flux:text class="font-medium">{{ $property->state }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500 text-sm">{{ __('ZIP Code') }}</flux:text>
                    <flux:text class="font-medium">{{ $property->zip }}</flux:text>
                </div>
            </div>

            @if ($property->notes)
                <div class="mt-6">
                    <flux:text class="text-zinc-500 text-sm">{{ __('Notes') }}</flux:text>
                    <flux:text class="whitespace-pre-wrap">{{ $property->notes }}</flux:text>
                </div>
            @endif
        </div>

        <!-- Photo Gallery -->
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            <flux:heading size="lg" class="mb-4">{{ __('Photo Gallery') }}</flux:heading>
            @if ($photos->isNotEmpty())
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                    @foreach ($photos as $photo)
                        <div wire:key="photo-{{ $photo->id }}" class="aspect-square rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                            <img
                                src="{{ Storage::url($photo->path) }}"
                                alt="{{ $photo->caption ?? __('Property photo') }}"
                                class="w-full h-full object-cover"
                            />
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <flux:icon name="photo" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:text class="mt-4 text-zinc-500">{{ __('No photos available yet.') }}</flux:text>
                </div>
            @endif
        </div>

        <!-- Associated Projects -->
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="lg" class="mb-4">{{ __('Associated Projects') }}</flux:heading>
            @if ($deals->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($deals as $deal)
                        <a wire:key="deal-{{ $deal->id }}" href="{{ route('customer.projects.show', $deal) }}" class="block">
                            <div class="flex items-center justify-between p-4 rounded-lg bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                                <div class="flex items-center gap-4">
                                    <div class="flex-shrink-0 rounded-lg bg-blue-100 dark:bg-blue-900 p-2">
                                        <flux:icon name="briefcase" class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                    </div>
                                    <div>
                                        <flux:text class="font-medium">{{ $deal->title }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $deal->created_at->format('M j, Y') }}</flux:text>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    @php
                                        $stageColor = match($deal->stage) {
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
                                        {{ $deal->stage_label }}
                                    </flux:badge>
                                    @if ($deal->estimated_value)
                                        <flux:text class="font-semibold">${{ number_format($deal->estimated_value, 0) }}</flux:text>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <flux:icon name="briefcase" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:text class="mt-4 text-zinc-500">{{ __('No projects for this property yet.') }}</flux:text>
                </div>
            @endif
        </div>

        <!-- Created/Updated Info -->
        <div class="mt-6 text-sm text-zinc-500">
            <flux:text>{{ __('Created') }}: {{ $property->created_at->format('M j, Y g:i A') }}</flux:text>
            @if ($property->updated_at->ne($property->created_at))
                <flux:text class="ml-4">{{ __('Updated') }}: {{ $property->updated_at->format('M j, Y g:i A') }}</flux:text>
            @endif
        </div>
    </div>
</div>
