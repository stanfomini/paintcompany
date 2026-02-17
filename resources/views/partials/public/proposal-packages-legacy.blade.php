{{-- LEGACY PACKAGE SYSTEM - Simplified View --}}
{{-- Package Selection Header --}}
<div class="text-center">
    @if (count($packages) === 1)
        <h2 class="text-2xl font-semibold text-gray-900">{{ __('Your Proposal') }}</h2>
        <p class="mt-2 text-gray-600">{{ __('Review the details of your custom proposal') }}</p>
    @else
        <h2 class="text-2xl font-semibold text-gray-900">{{ __('Choose Your Package') }}</h2>
        <p class="mt-2 text-gray-600">{{ __('Select the package that best fits your needs') }}</p>
    @endif
</div>

{{-- Package Comparison Cards --}}
@if (count($packages) > 0)
    <div class="grid grid-cols-1 md:grid-cols-{{ min(count($packages), 3) }} gap-6">
        @foreach ($packages as $package)
            <div
                wire:key="package-{{ $package['id'] }}"
                class="relative rounded-xl border-2 transition-all duration-200 {{ $selectedPackageId === $package['id'] ? 'border-blue-500 bg-blue-50/50 shadow-lg' : 'border-gray-200 bg-white hover:border-gray-300 hover:shadow-md' }}"
            >
                {{-- Recommended Badge --}}
                @if ($package['is_recommended'])
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-400 text-amber-900">
                            {{ __('Recommended') }}
                        </span>
                    </div>
                @endif

                <div class="p-6">
                    {{-- Package Header --}}
                    <div class="text-center mb-6">
                        <span class="inline-block px-3 py-1 rounded-full text-xs font-medium uppercase tracking-wider {{ match($package['tier']) {
                            'basic' => 'bg-gray-100 text-gray-700',
                            'standard' => 'bg-blue-100 text-blue-700',
                            'premium' => 'bg-purple-100 text-purple-700',
                            default => 'bg-gray-100 text-gray-700'
                        } }}">
                            {{ $package['tier_label'] }}
                        </span>
                        <h3 class="mt-3 text-xl font-semibold text-gray-900">{{ $package['name'] }}</h3>
                        @if ($package['description'])
                            <p class="mt-2 text-sm text-gray-600">{{ $package['description'] }}</p>
                        @endif

                        @if (! empty($package['paint_products']))
                            <div class="mt-3 space-y-1">
                                @foreach ($package['paint_products'] as $paint)
                                    <div class="flex items-center justify-center gap-1.5 text-xs">
                                        <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M3.5 2A1.5 1.5 0 002 3.5V5c0 .325.103.625.278.871L7 12.5V17a1 1 0 001.555.832l3-2A1 1 0 0012 15v-2.5l4.722-6.629A1.5 1.5 0 0017 5V3.5A1.5 1.5 0 0015.5 2h-12z"/>
                                        </svg>
                                        <span class="text-gray-700 font-medium">{{ $paint['name'] }}</span>
                                        @if ($paint['sheen'])
                                            <span class="text-gray-400">&middot;</span>
                                            <span class="text-gray-500">{{ $paint['sheen'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Price --}}
                    <div class="text-center mb-6">
                        <span class="text-3xl font-bold text-gray-900">${{ number_format($package['total'], 2) }}</span>
                    </div>

                    {{-- Package Features / Add-on Services --}}
                    <div class="space-y-2 mb-6">
                        {{-- Painting Work --}}
                        <div class="flex items-center gap-2 text-sm">
                            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="text-gray-700">{{ __('Complete Painting Work') }}</span>
                        </div>

                        @if (! empty($templateAddons[$package['tier']] ?? []))
                            {{-- Template add-on items --}}
                            @foreach ($templateAddons[$package['tier']] as $addon)
                                <div class="flex items-center justify-between gap-2 text-sm">
                                    <div class="flex items-center gap-2 min-w-0">
                                        @if ($addon['is_warranty'])
                                            {{-- Shield icon for warranty --}}
                                            <svg class="w-4 h-4 text-blue-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                            </svg>
                                        @else
                                            {{-- Checkmark icon for services --}}
                                            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        @endif
                                        <span class="text-gray-700">{{ $addon['name'] }}</span>
                                    </div>
                                    @if ($addon['price'] > 0)
                                        <span class="text-xs text-gray-500 shrink-0">+${{ number_format($addon['price'], 2) }}</span>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            {{-- Fallback: legacy warranty and service items --}}
                            @if (($package['warranty_years'] ?? 0) > 0 || ($package['warranty_price'] ?? 0) > 0)
                                <div class="flex items-center gap-2 text-sm">
                                    <svg class="w-4 h-4 text-blue-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                    <span class="text-gray-700">
                                        @if ($package['warranty_years'] > 0)
                                            {{ __(':count-Year Warranty', ['count' => $package['warranty_years']]) }}
                                        @else
                                            {{ __('Warranty Included') }}
                                        @endif
                                    </span>
                                </div>
                            @endif

                            @php
                                $serviceItems = collect($package['items'] ?? [])->filter(fn($item) => ($item['itemable_type'] ?? '') === 'EstimateItem');
                            @endphp
                            @if ($serviceItems->count() > 0)
                                @foreach ($serviceItems as $item)
                                    @if ($item['customer_approved'] ?? true)
                                        <div class="flex items-center gap-2 text-sm">
                                            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                            <span class="text-gray-700">{{ $item['name'] }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            @endif
                        @endif
                    </div>

                    {{-- Select Button --}}
                    @if (count($packages) === 1)
                        <div class="w-full py-3 px-4 rounded-lg font-medium bg-blue-600 text-white text-center">
                            <span class="flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                {{ __('Your Package') }}
                            </span>
                        </div>
                    @else
                        <button
                            wire:click="selectPackage({{ $package['id'] }})"
                            wire:loading.attr="disabled"
                            @if (!$this->canModify()) disabled @endif
                            class="w-full py-3 px-4 rounded-lg font-medium transition-colors duration-200 {{ $selectedPackageId === $package['id']
                                ? 'bg-blue-600 text-white cursor-default'
                                : ($package['is_recommended']
                                    ? 'bg-blue-600 text-white hover:bg-blue-700'
                                    : 'bg-gray-100 text-gray-900 hover:bg-gray-200')
                            }} disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            @if ($selectedPackageId === $package['id'])
                                <span class="flex items-center justify-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    {{ __('Selected') }}
                                </span>
                            @else
                                <span wire:loading.remove wire:target="selectPackage({{ $package['id'] }})">{{ __('Select Package') }}</span>
                                <span wire:loading wire:target="selectPackage({{ $package['id'] }})">{{ __('Selecting...') }}</span>
                            @endif
                        </button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@else
    <div class="text-center py-12 bg-gray-50 rounded-lg">
        <p class="text-gray-600">{{ __('No packages available for this estimate.') }}</p>
    </div>
@endif

{{-- Optional Add-ons Section --}}
@if (count($optionalItems) > 0)
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Optional Add-ons') }}</h3>
            <p class="text-sm text-gray-600">{{ __('Enhance your project with these additional options') }}</p>
        </div>

        <div class="divide-y divide-gray-100">
            @foreach ($optionalItems as $optionalItem)
                <div
                    wire:key="optional-{{ $optionalItem['id'] }}"
                    class="px-6 py-4 flex items-center justify-between gap-4 {{ $optionalItem['is_selected_by_customer'] ? 'bg-green-50' : '' }}"
                >
                    <div class="flex items-start gap-3 flex-1">
                        {{-- Selection Checkbox --}}
                        @if ($this->canModify())
                            <button
                                wire:click="toggleOptionalItem({{ $optionalItem['id'] }})"
                                wire:loading.attr="disabled"
                                class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center transition-colors {{ $optionalItem['is_selected_by_customer']
                                    ? 'bg-green-500 border-green-500 text-white'
                                    : 'border-gray-300 hover:border-gray-400'
                                }}"
                            >
                                @if ($optionalItem['is_selected_by_customer'])
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                @endif
                            </button>
                        @else
                            <div class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center {{ $optionalItem['is_selected_by_customer']
                                ? 'bg-green-500 border-green-500 text-white'
                                : 'border-gray-300'
                            }}">
                                @if ($optionalItem['is_selected_by_customer'])
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                @endif
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900">{{ $optionalItem['name'] }}</p>
                            @if ($optionalItem['description'])
                                <p class="text-xs text-gray-500 mt-0.5">{{ $optionalItem['description'] }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="text-right flex-shrink-0">
                        <span class="text-sm font-medium text-gray-900">
                            +${{ number_format($optionalItem['price'], 2) }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- Summary Section --}}
@if ($selectedPackageId)
    @php
        $selectedPackage = collect($packages)->firstWhere('id', $selectedPackageId);
        $depositPercent = (float) \App\Models\CompanySetting::getInstance()->default_deposit_percent;
    @endphp

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Summary') }}</h3>
        </div>

        <div class="px-6 py-4 space-y-3">
            {{-- Package Total --}}
            <div class="flex justify-between items-center text-sm">
                <span class="text-gray-600">{{ $selectedPackage['name'] ?? __('Selected Package') }}</span>
                <span class="text-gray-900">${{ number_format($selectedPackage['total'] ?? 0, 2) }}</span>
            </div>

            {{-- Optional Items --}}
            @if ($this->optionalItemsTotal > 0)
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-600">{{ __('Optional Add-ons') }}</span>
                    <span class="text-gray-900">+${{ number_format($this->optionalItemsTotal, 2) }}</span>
                </div>
            @endif

            <div class="border-t border-gray-200 pt-3 mt-3">
                {{-- Final Total --}}
                <div class="flex justify-between items-center">
                    <span class="text-lg font-semibold text-gray-900">{{ __('Total') }}</span>
                    <span class="text-2xl font-bold text-blue-600">${{ number_format($this->finalTotal, 2) }}</span>
                </div>
            </div>

            {{-- Deposit & Balance --}}
            <div class="bg-gray-50 -mx-6 -mb-4 px-6 py-4 mt-4 border-t border-gray-200">
                <div class="flex justify-between items-center text-sm mb-2">
                    <span class="text-gray-600">{{ __('Deposit Due') }} ({{ number_format($depositPercent, 0) }}%)</span>
                    <span class="font-medium text-gray-900">${{ number_format($this->depositAmount, 2) }}</span>
                </div>
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-600">{{ __('Balance Due on Completion') }}</span>
                    <span class="font-medium text-gray-900">${{ number_format($this->balanceDue, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Sign & Accept Button --}}
    <div class="text-center">
        @if ($estimate->status === 'accepted')
            <div class="inline-flex items-center gap-2 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-6 py-3 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span class="font-medium">{{ __('Proposal Accepted') }}</span>
            </div>
        @elseif ($estimate->hasPendingAmendments())
            <button
                disabled
                class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-gray-300 dark:bg-gray-700 text-gray-500 dark:text-gray-400 font-semibold rounded-xl cursor-not-allowed mb-3"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                </svg>
                {{ __('Sign & Accept Proposal') }}
            </button>
            <div>
                <a
                    href="#amendments-section"
                    class="inline-flex items-center gap-2 text-amber-700 dark:text-amber-400 font-medium hover:underline text-sm"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                    {{ __('Review and acknowledge changes made to this estimate before signing') }}
                </a>
            </div>
        @else
            <button
                wire:click="proceedToSignature"
                wire:loading.attr="disabled"
                @if (!$this->canModify()) disabled @endif
                class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                </svg>
                <span wire:loading.remove wire:target="proceedToSignature">{{ __('Sign & Accept Proposal') }}</span>
                <span wire:loading wire:target="proceedToSignature">{{ __('Processing...') }}</span>
            </button>
            <p class="mt-3 text-sm text-gray-500">
                {{ __('By signing, you agree to the terms and conditions of this proposal.') }}
            </p>
        @endif
    </div>
@else
    {{-- Disabled Sign Button when no package selected --}}
    <div class="text-center">
        @if ($estimate->status === 'accepted')
            <div class="inline-flex items-center gap-2 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-6 py-3 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span class="font-medium">{{ __('Proposal Accepted') }}</span>
            </div>
        @else
            <button
                disabled
                class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-gray-300 text-gray-500 font-semibold rounded-xl cursor-not-allowed"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                </svg>
                {{ __('Sign & Accept Proposal') }}
            </button>
            <p class="mt-3 text-sm text-gray-500">
                {{ __('Please select a package above to continue.') }}
            </p>
        @endif
    </div>
@endif
