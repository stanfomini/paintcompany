{{-- NEW VALUE-ADD PACKAGE TEMPLATE SYSTEM --}}
@php
    $selectedTemplate = $selectedTier ? collect($packageTemplates)->firstWhere('tier', $selectedTier) : null;
    $paintingTotal = $selectedTemplate ? $selectedTemplate['painting_total'] : 0;
    $depositPercent = (float) \App\Models\CompanySetting::getInstance()->default_deposit_percent;
@endphp

{{-- Package Selection Header --}}
<div class="text-center">
    <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Select Your Package</h2>
    <p class="mt-2 text-gray-600 dark:text-gray-400">All packages include the same high-quality painting work. Choose based on warranty and services.</p>
</div>

{{-- Painting Total Callout --}}
@if ($paintingTotal > 0)
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                </svg>
                <span class="font-medium text-blue-900 dark:text-blue-100">Painting Work</span>
            </div>
            <span class="text-xl font-bold text-blue-900 dark:text-blue-100">${{ number_format($paintingTotal, 0) }}</span>
        </div>
        <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">Professional painting of all specified areas. Same quality across all packages.</p>
    </div>
@endif

{{-- Package Tier Cards --}}
@if (count($packageTemplates) > 0)
    <div class="grid grid-cols-1 md:grid-cols-{{ min(count($packageTemplates), 3) }} gap-6">
        @foreach ($packageTemplates as $template)
            @php
                $isSelected = $selectedTier === $template['tier'];
                $templateItems = collect($template['items']);
                $warrantyItems = $templateItems->where('is_warranty', true);
                $serviceItems = $templateItems->where('is_warranty', false);

                // Calculate total for this template
                $templateTotal = $template['painting_total'];
                foreach ($template['items'] as $item) {
                    $itemSelected = $selectedPackageItems[$item['key']] ?? $item['is_included_by_default'];
                    if ($itemSelected) {
                        $templateTotal += $item['price'];
                    }
                }
            @endphp

            <div
                wire:key="template-{{ $template['id'] }}"
                class="relative rounded-xl border-2 transition-all duration-200 cursor-pointer {{ $isSelected
                    ? 'border-blue-500 bg-blue-50/50 dark:bg-blue-900/20 shadow-lg'
                    : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-md'
                }}"
                wire:click="selectTier('{{ $template['tier'] }}')"
            >
                {{-- Popular Badge --}}
                @if ($template['is_popular'])
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-400 text-amber-900">
                            Popular
                        </span>
                    </div>
                @endif

                <div class="p-6">
                    {{-- Package Header --}}
                    <div class="text-center mb-4">
                        <span class="inline-block px-3 py-1 rounded-full text-xs font-medium uppercase tracking-wider {{ match($template['tier']) {
                            'good' => 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300',
                            'better' => 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300',
                            'best' => 'bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300',
                            default => 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'
                        } }}">
                            {{ $template['name'] }}
                        </span>
                    </div>

                    {{-- Price --}}
                    <div class="text-center mb-4">
                        <span class="text-3xl font-bold text-gray-900 dark:text-white">${{ number_format($templateTotal, 0) }}</span>
                    </div>

                    {{-- Warranty Items (dynamic from catalog) --}}
                    @if ($warrantyItems->isNotEmpty())
                        <div class="space-y-1 text-sm mb-3">
                            @foreach ($warrantyItems as $wItem)
                                @php $wSelected = $selectedPackageItems[$wItem['key']] ?? $wItem['is_included_by_default']; @endphp
                                @if ($wSelected)
                                    <div class="flex items-center justify-center gap-2">
                                        <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                        </svg>
                                        <span class="font-medium text-blue-700 dark:text-blue-300">{{ $wItem['name'] }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    {{-- Description --}}
                    @if ($template['description'])
                        <p class="text-sm text-gray-600 dark:text-gray-400 text-center mb-4">{{ $template['description'] }}</p>
                    @endif

                    {{-- Included Service Items Preview --}}
                    @if ($serviceItems->isNotEmpty())
                        <div class="space-y-1 text-sm text-gray-600 dark:text-gray-400 mb-4">
                            @foreach ($serviceItems as $sItem)
                                @php $sSelected = $selectedPackageItems[$sItem['key']] ?? $sItem['is_included_by_default']; @endphp
                                @if ($sSelected)
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        <span>{{ $sItem['name'] }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    {{-- Selection Indicator --}}
                    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                        @if ($isSelected)
                            <div class="flex items-center justify-center gap-2 text-blue-600 dark:text-blue-400 font-medium">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Selected
                            </div>
                        @else
                            <div class="text-center text-gray-500 dark:text-gray-400 text-sm">
                                Click to select
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@else
    <div class="text-center py-12 bg-gray-50 dark:bg-gray-800 rounded-lg">
        <p class="text-gray-600 dark:text-gray-400">No packages configured. Please contact us for pricing.</p>
    </div>
@endif

{{-- Selected Package Details --}}
@if ($selectedTemplate)
    @php
        $allItems = collect($selectedTemplate['items']);
        $warrantyItems = $allItems->where('is_warranty', true)->values();
        $serviceItems = $allItems->where('is_warranty', false)->values();
    @endphp

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedTemplate['name'] }} Package Breakdown</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">Review what's included and customize your selections</p>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            {{-- Painting Work (always included) --}}
            <div class="px-6 py-4 flex items-center justify-between gap-4">
                <div class="flex items-start gap-3 flex-1">
                    <div class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center bg-green-500 border-green-500 text-white">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Professional Painting</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">All areas as specified in the proposal</p>
                    </div>
                </div>
                <div class="text-right flex-shrink-0">
                    <span class="text-sm font-medium text-gray-900 dark:text-white">${{ number_format($paintingTotal, 2) }}</span>
                </div>
            </div>

            {{-- Warranty Items (blue highlight, shield icon, customer-toggleable) --}}
            @foreach ($warrantyItems as $item)
                @php
                    $itemSelected = $selectedPackageItems[$item['key']] ?? $item['is_included_by_default'];
                    $isCustomerSelectable = $item['is_customer_selectable'] ?? false;
                @endphp
                <div
                    wire:key="item-{{ $item['id'] }}"
                    class="px-6 py-4 flex items-center justify-between gap-4 {{ $itemSelected ? 'bg-blue-50/50 dark:bg-blue-900/20' : 'bg-gray-50 dark:bg-gray-900 opacity-60' }}"
                >
                    <div class="flex items-start gap-3 flex-1">
                        @if ($isCustomerSelectable && $this->canModify())
                            <button
                                wire:click.stop="togglePackageItem('{{ $item['key'] }}')"
                                wire:loading.attr="disabled"
                                class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center transition-colors {{ $itemSelected
                                    ? 'bg-blue-500 border-blue-500 text-white'
                                    : 'border-gray-300 dark:border-gray-600 hover:border-blue-400 dark:hover:border-blue-500'
                                }}"
                            >
                                @if ($itemSelected)
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                @endif
                            </button>
                        @else
                            <div class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center {{ $itemSelected
                                ? 'bg-blue-500 border-blue-500 text-white'
                                : 'border-gray-300 dark:border-gray-600'
                            }}">
                                @if ($itemSelected)
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                @endif
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium {{ $itemSelected ? 'text-blue-900 dark:text-blue-100' : 'text-gray-500 dark:text-gray-400 line-through' }}">
                                {{ $item['name'] }}
                            </p>
                            @if ($item['description'])
                                <p class="text-xs text-blue-600/70 dark:text-blue-400/70 mt-0.5">{{ $item['description'] }}</p>
                            @endif
                            @if ($isCustomerSelectable)
                                <p class="text-xs {{ $itemSelected ? 'text-blue-600 dark:text-blue-400' : 'text-amber-600 dark:text-amber-400' }} mt-1">
                                    {{ $itemSelected ? 'Included - click to remove' : 'Click to add warranty' }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="text-right flex-shrink-0">
                        <span class="text-sm font-medium {{ $itemSelected ? 'text-blue-900 dark:text-blue-100' : 'text-gray-400 dark:text-gray-500 line-through' }}">
                            +${{ number_format($item['price'], 2) }}
                        </span>
                    </div>
                </div>
            @endforeach

            {{-- Service Items (green checkmark, standard styling) --}}
            @foreach ($serviceItems as $item)
                @php
                    $itemSelected = $selectedPackageItems[$item['key']] ?? $item['is_included_by_default'];
                    $isCustomerSelectable = $item['is_customer_selectable'] ?? false;
                @endphp
                <div
                    wire:key="item-{{ $item['id'] }}"
                    class="px-6 py-4 flex items-center justify-between gap-4 {{ $itemSelected ? '' : 'bg-gray-50 dark:bg-gray-900 opacity-60' }}"
                >
                    <div class="flex items-start gap-3 flex-1">
                        {{-- Toggle Checkbox (only if customer-selectable and can modify) --}}
                        @if ($isCustomerSelectable && $this->canModify())
                            <button
                                wire:click.stop="togglePackageItem('{{ $item['key'] }}')"
                                wire:loading.attr="disabled"
                                class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center transition-colors {{ $itemSelected
                                    ? 'bg-green-500 border-green-500 text-white'
                                    : 'border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500'
                                }}"
                            >
                                @if ($itemSelected)
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                @endif
                            </button>
                        @else
                            {{-- Read-only checkmark (estimator decided, customer can't toggle) --}}
                            <div class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center {{ $itemSelected
                                ? 'bg-green-500 border-green-500 text-white'
                                : 'border-gray-300 dark:border-gray-600'
                            }}">
                                @if ($itemSelected)
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                @endif
                            </div>
                        @endif

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium {{ $itemSelected ? 'text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400 line-through' }}">
                                {{ $item['name'] }}
                            </p>
                            @if ($item['description'])
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $item['description'] }}</p>
                            @endif
                            @if ($isCustomerSelectable)
                                <p class="text-xs {{ $itemSelected ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400' }} mt-1">
                                    {{ $itemSelected ? 'Included' : 'Click to add' }}
                                </p>
                            @elseif ($itemSelected)
                                <p class="text-xs text-green-600 dark:text-green-400 mt-1">Included in package</p>
                            @endif
                        </div>
                    </div>

                    <div class="text-right flex-shrink-0">
                        <span class="text-sm font-medium {{ $itemSelected ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500 line-through' }}">
                            +${{ number_format($item['price'], 2) }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Summary Section --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Summary</h3>
        </div>

        <div class="px-6 py-4 space-y-3">
            {{-- Painting --}}
            <div class="flex justify-between items-center text-sm">
                <span class="text-gray-600 dark:text-gray-400">Professional Painting</span>
                <span class="text-gray-900 dark:text-white">${{ number_format($paintingTotal, 2) }}</span>
            </div>

            {{-- Warranty --}}
            @if ($this->selectedTemplateWarrantyTotal > 0)
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-600 dark:text-gray-400">Warranty Protection</span>
                    <span class="text-gray-900 dark:text-white">${{ number_format($this->selectedTemplateWarrantyTotal, 2) }}</span>
                </div>
            @endif

            {{-- Services --}}
            @if ($this->selectedTemplateServicesTotal > 0)
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-600 dark:text-gray-400">Add-on Services</span>
                    <span class="text-gray-900 dark:text-white">${{ number_format($this->selectedTemplateServicesTotal, 2) }}</span>
                </div>
            @endif

            <div class="border-t border-gray-200 dark:border-gray-700 pt-3 mt-3">
                {{-- Final Total --}}
                <div class="flex justify-between items-center">
                    <span class="text-lg font-semibold text-gray-900 dark:text-white">Total</span>
                    <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">${{ number_format($this->finalTotal, 2) }}</span>
                </div>
            </div>

            {{-- Deposit & Balance --}}
            <div class="bg-gray-50 dark:bg-gray-900 -mx-6 -mb-4 px-6 py-4 mt-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex justify-between items-center text-sm mb-2">
                    <span class="text-gray-600 dark:text-gray-400">Deposit Due ({{ number_format($depositPercent, 0) }}%)</span>
                    <span class="font-medium text-gray-900 dark:text-white">${{ number_format($this->depositAmount, 2) }}</span>
                </div>
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-600 dark:text-gray-400">Balance Due on Completion</span>
                    <span class="font-medium text-gray-900 dark:text-white">${{ number_format($this->balanceDue, 2) }}</span>
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
                <span class="font-medium">Proposal Accepted</span>
            </div>
        @elseif ($estimate->hasPendingAmendments())
            <button
                disabled
                class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-gray-300 dark:bg-gray-700 text-gray-500 dark:text-gray-400 font-semibold rounded-xl cursor-not-allowed mb-3"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                </svg>
                Sign & Accept Proposal
            </button>
            <div>
                <a
                    href="#amendments-section"
                    class="inline-flex items-center gap-2 text-amber-700 dark:text-amber-400 font-medium hover:underline text-sm"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                    Review and acknowledge changes made to this estimate before signing
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
                <span wire:loading.remove wire:target="proceedToSignature">Sign & Accept Proposal</span>
                <span wire:loading wire:target="proceedToSignature">Processing...</span>
            </button>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                By signing, you agree to the terms and conditions of this proposal.
            </p>
        @endif
    </div>
@else
    {{-- No package selected --}}
    <div class="text-center">
        @if ($estimate->status === 'accepted')
            <div class="inline-flex items-center gap-2 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-6 py-3 rounded-lg">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span class="font-medium">Proposal Accepted</span>
            </div>
        @else
            <button
                disabled
                class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-gray-300 dark:bg-gray-700 text-gray-500 dark:text-gray-400 font-semibold rounded-xl cursor-not-allowed"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                </svg>
                Sign & Accept Proposal
            </button>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Please select a package above to continue.
            </p>
        @endif
    </div>
@endif
