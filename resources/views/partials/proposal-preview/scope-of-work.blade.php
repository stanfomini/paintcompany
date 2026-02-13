{{-- Read-Only Scope of Work for Proposal Preview --}}
{{-- Receives: $estimate (Estimate model with loaded areas.surfaces.productionRate) --}}
@if ($estimate->areas->isNotEmpty())
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
        <div class="flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Scope of Work') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $estimate->areas->count() }} {{ Str::plural(__('area'), $estimate->areas->count()) }}
            </flux:text>
        </div>

        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($estimate->areas as $area)
                @php
                    $surfaces = $area->surfaces->where('is_included', true);
                    $areaPrice = $area->getCustomerPrice();
                    $surfacePrices = $area->getSurfaceCustomerPrices();
                @endphp
                <div
                    x-data="{ expanded: false }"
                    wire:key="preview-area-{{ $area->id }}"
                >
                    {{-- Area Header --}}
                    <button
                        type="button"
                        x-on:click="expanded = !expanded"
                        class="w-full px-4 py-3 flex items-center justify-between hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors text-left"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="shrink-0 w-7 h-7 rounded-lg flex items-center justify-center transition-colors"
                                :class="expanded ? 'bg-blue-100 dark:bg-blue-900/50' : 'bg-zinc-100 dark:bg-zinc-800'"
                            >
                                <flux:icon
                                    name="chevron-right"
                                    class="w-4 h-4 transition-transform duration-200"
                                    ::class="expanded ? 'rotate-90 text-blue-600 dark:text-blue-400' : 'text-zinc-400'"
                                />
                            </div>
                            <div>
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $area->name }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($surfaces->count() > 0)
                                        {{ trans_choice(':count surface|:count surfaces', $surfaces->count()) }}
                                    @endif
                                    @if (($area->wall_sqft ?? 0) > 0 || ($area->ceiling_sqft ?? 0) > 0)
                                        <span class="mx-1">&bull;</span>
                                        {{ number_format(($area->wall_sqft ?? 0) + ($area->ceiling_sqft ?? 0), 0) }} {{ __('sq ft') }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>
                        <flux:text class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($areaPrice, 2) }}
                        </flux:text>
                    </button>

                    {{-- Expanded Surface Details --}}
                    <div x-show="expanded" x-collapse x-cloak class="border-t border-zinc-100 dark:border-zinc-800">
                        @if ($surfaces->isNotEmpty())
                            <div class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                                @foreach ($surfaces as $surface)
                                    <div class="px-4 py-2.5 flex items-center justify-between bg-white dark:bg-zinc-900">
                                        <div class="flex items-center gap-3">
                                            <div class="w-1.5 h-1.5 rounded-full bg-blue-400 dark:bg-blue-500"></div>
                                            <div>
                                                <flux:text class="text-sm text-zinc-700 dark:text-zinc-300">
                                                    {{ $surface->name ?? $surface->productionRate?->name ?? __('Surface') }}
                                                </flux:text>
                                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                                    {{ number_format($surface->quantity, 0) }} {{ $surface->unit ?? 'sq ft' }}
                                                    @if ($surface->coats > 1)
                                                        &bull; {{ __(':count coats', ['count' => $surface->coats]) }}
                                                    @endif
                                                </flux:text>
                                            </div>
                                        </div>
                                        <flux:text class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            ${{ number_format($surfacePrices[$surface->id] ?? $surface->getCustomerPrice(), 2) }}
                                        </flux:text>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="p-4">
                                <flux:text class="text-sm text-zinc-500">{{ __('No surface details available.') }}</flux:text>
                            </div>
                        @endif

                        @if ($area->notes)
                            <div class="px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border-t border-zinc-100 dark:border-zinc-800">
                                <div class="flex items-start gap-2">
                                    <flux:icon name="information-circle" class="w-4 h-4 text-amber-600 dark:text-amber-400 mt-0.5 shrink-0" />
                                    <flux:text class="text-sm text-amber-800 dark:text-amber-200">{{ $area->notes }}</flux:text>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
