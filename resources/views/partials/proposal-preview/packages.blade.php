{{-- Read-Only Package Cards for Proposal Preview --}}
{{-- Receives: $packageData (array from packagePreviewData computed) --}}
@if (!empty($packageData))
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
        <div class="text-center mb-6">
            <flux:heading size="lg">{{ __('Package Options') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                {{ __('All packages include the same high-quality painting work') }}
            </flux:text>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-{{ min(count($packageData), 3) }} gap-4">
            @foreach ($packageData as $package)
                @php
                    $isHighlighted = $package['is_recommended'] ?? $package['is_selected'] ?? false;
                @endphp
                <div
                    class="relative rounded-xl border-2 p-5 transition-colors {{ $isHighlighted ? 'border-blue-400 dark:border-blue-600 bg-blue-50/50 dark:bg-blue-900/10' : 'border-zinc-200 dark:border-zinc-700' }}"
                >
                    {{-- Recommended Badge --}}
                    @if ($package['is_recommended'] ?? false)
                        <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                            <flux:badge color="amber" size="sm">{{ __('Recommended') }}</flux:badge>
                        </div>
                    @endif

                    <div class="text-center">
                        {{-- Tier Badge --}}
                        <span class="inline-block px-3 py-1 rounded-full text-xs font-medium uppercase tracking-wider {{ match($package['tier'] ?? '') {
                            'basic', 'good' => 'bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300',
                            'standard', 'better' => 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300',
                            'premium', 'best' => 'bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300',
                            default => 'bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300',
                        } }}">
                            {{ $package['tier_label'] ?? ucfirst($package['tier'] ?? __('Package')) }}
                        </span>

                        {{-- Name --}}
                        <flux:heading size="base" class="mt-2">{{ $package['name'] }}</flux:heading>

                        @if (!empty($package['description']))
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $package['description'] }}</flux:text>
                        @endif

                        {{-- Price --}}
                        <div class="mt-3 mb-4">
                            <span class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($package['total'], 2) }}
                            </span>
                        </div>
                    </div>

                    {{-- Included Items --}}
                    @if (!empty($package['warranty_items']) || !empty($package['service_items']))
                        <div class="space-y-1.5 text-sm">
                            @foreach ($package['warranty_items'] ?? [] as $item)
                                <div class="flex items-center gap-2">
                                    <flux:icon name="shield-check" class="w-4 h-4 text-blue-500 shrink-0" />
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $item['name'] }}</span>
                                </div>
                            @endforeach
                            @foreach ($package['service_items'] ?? [] as $item)
                                <div class="flex items-center gap-2">
                                    <flux:icon name="check" class="w-4 h-4 text-green-500 shrink-0" />
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $item['name'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @elseif (!empty($package['items']))
                        <div class="space-y-1.5 text-sm">
                            @foreach ($package['items'] as $item)
                                <div class="flex items-center gap-2">
                                    @if ($item['is_warranty'] ?? false)
                                        <flux:icon name="shield-check" class="w-4 h-4 text-blue-500 shrink-0" />
                                    @else
                                        <flux:icon name="check" class="w-4 h-4 text-green-500 shrink-0" />
                                    @endif
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $item['name'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Warranty fallback for legacy packages without items --}}
                    @if (($package['warranty_years'] ?? 0) > 0 && empty($package['warranty_items']) && empty($package['items']))
                        <div class="flex items-center gap-2 text-sm">
                            <flux:icon name="shield-check" class="w-4 h-4 text-blue-500 shrink-0" />
                            <span class="text-zinc-700 dark:text-zinc-300">
                                {{ __(':count-Year Warranty', ['count' => $package['warranty_years']]) }}
                            </span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
