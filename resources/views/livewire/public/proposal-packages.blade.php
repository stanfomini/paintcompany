<div class="space-y-8">
    {{-- Flash Messages --}}
    @if (session()->has('error'))
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    {{-- Package Selection Header --}}
    <div class="text-center">
        <h2 class="text-2xl font-semibold text-gray-900">Choose Your Package</h2>
        <p class="mt-2 text-gray-600">Select the package that best fits your needs</p>
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
                                Recommended
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
                        </div>

                        {{-- Price --}}
                        <div class="text-center mb-6">
                            <span class="text-3xl font-bold text-gray-900">${{ number_format($package['total'], 2) }}</span>
                        </div>

                        {{-- Includes List --}}
                        @if ($package['includes'])
                            <div class="mb-4">
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Includes:</p>
                                <p class="text-sm text-gray-700">{{ $package['includes'] }}</p>
                            </div>
                        @endif

                        {{-- Select Button --}}
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
                                    Selected
                                </span>
                            @else
                                <span wire:loading.remove wire:target="selectPackage({{ $package['id'] }})">Select Package</span>
                                <span wire:loading wire:target="selectPackage({{ $package['id'] }})">Selecting...</span>
                            @endif
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-12 bg-gray-50 rounded-lg">
            <p class="text-gray-600">No packages available for this estimate.</p>
        </div>
    @endif

    {{-- Selected Package Details --}}
    @if ($selectedPackageId)
        @php
            $selectedPackage = collect($packages)->firstWhere('id', $selectedPackageId);
        @endphp

        @if ($selectedPackage && count($selectedPackage['items']) > 0)
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Package Details</h3>
                    <p class="text-sm text-gray-600">Review and customize the items included in your package</p>
                </div>

                <div class="divide-y divide-gray-100">
                    @foreach ($selectedPackage['items'] as $item)
                        @if ($item['is_included'])
                            <div
                                wire:key="item-{{ $item['id'] }}"
                                class="px-6 py-4 flex items-center justify-between gap-4 {{ !$item['customer_approved'] ? 'bg-gray-50 opacity-60' : '' }}"
                            >
                                <div class="flex items-start gap-3 flex-1">
                                    {{-- Approval Checkbox --}}
                                    @if ($this->canModify())
                                        <button
                                            wire:click="toggleLineItemApproval({{ $item['id'] }})"
                                            wire:loading.attr="disabled"
                                            class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center transition-colors {{ $item['customer_approved']
                                                ? 'bg-green-500 border-green-500 text-white'
                                                : 'border-gray-300 hover:border-gray-400'
                                            }}"
                                        >
                                            @if ($item['customer_approved'])
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            @endif
                                        </button>
                                    @else
                                        <div class="mt-0.5 flex-shrink-0 w-5 h-5 rounded border-2 flex items-center justify-center {{ $item['customer_approved']
                                            ? 'bg-green-500 border-green-500 text-white'
                                            : 'border-gray-300'
                                        }}">
                                            @if ($item['customer_approved'])
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 {{ !$item['customer_approved'] ? 'line-through' : '' }}">
                                            {{ $item['name'] }}
                                        </p>
                                        @if ($item['description'])
                                            <p class="text-xs text-gray-500 mt-0.5">{{ $item['description'] }}</p>
                                        @endif
                                        @if (!$item['customer_approved'])
                                            <p class="text-xs text-amber-600 mt-1">Click to add back</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="text-right flex-shrink-0">
                                    <span class="text-sm font-medium {{ $item['customer_approved'] ? 'text-gray-900' : 'text-gray-400 line-through' }}">
                                        ${{ number_format($item['price'], 2) }}
                                    </span>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- Warranty Info --}}
                @if ($selectedPackage['warranty_years'] && $selectedPackage['warranty_price'] > 0)
                    <div class="px-6 py-4 bg-blue-50 border-t border-blue-100">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                </svg>
                                <span class="text-sm font-medium text-blue-900">
                                    {{ $selectedPackage['warranty_years'] }}-Year {{ $selectedPackage['warranty_type'] ?? 'Warranty' }}
                                </span>
                            </div>
                            <span class="text-sm font-medium text-blue-900">
                                Included
                            </span>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    @endif

    {{-- Optional Add-ons Section --}}
    @if (count($optionalItems) > 0)
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Optional Add-ons</h3>
                <p class="text-sm text-gray-600">Enhance your project with these additional options</p>
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
            $depositPercent = $estimate->deposit_percent ?? 30;
        @endphp

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Summary</h3>
            </div>

            <div class="px-6 py-4 space-y-3">
                {{-- Package Subtotal --}}
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-600">{{ $selectedPackage['name'] ?? 'Selected Package' }} (approved items)</span>
                    <span class="text-gray-900">${{ number_format($this->selectedPackageTotal, 2) }}</span>
                </div>

                {{-- Optional Items --}}
                @if ($this->optionalItemsTotal > 0)
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-600">Optional Add-ons</span>
                        <span class="text-gray-900">+${{ number_format($this->optionalItemsTotal, 2) }}</span>
                    </div>
                @endif

                <div class="border-t border-gray-200 pt-3 mt-3">
                    {{-- Final Total --}}
                    <div class="flex justify-between items-center">
                        <span class="text-lg font-semibold text-gray-900">Total</span>
                        <span class="text-2xl font-bold text-blue-600">${{ number_format($this->finalTotal, 2) }}</span>
                    </div>
                </div>

                {{-- Deposit & Balance --}}
                <div class="bg-gray-50 -mx-6 -mb-4 px-6 py-4 mt-4 border-t border-gray-200">
                    <div class="flex justify-between items-center text-sm mb-2">
                        <span class="text-gray-600">Deposit Due ({{ number_format($depositPercent, 0) }}%)</span>
                        <span class="font-medium text-gray-900">${{ number_format($this->depositAmount, 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-600">Balance Due on Completion</span>
                        <span class="font-medium text-gray-900">${{ number_format($this->balanceDue, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sign & Accept Button --}}
        <div class="text-center">
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
            <p class="mt-3 text-sm text-gray-500">
                By signing, you agree to the terms and conditions of this proposal.
            </p>
        </div>
    @else
        {{-- Disabled Sign Button when no package selected --}}
        <div class="text-center">
            <button
                disabled
                class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-gray-300 text-gray-500 font-semibold rounded-xl cursor-not-allowed"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                </svg>
                Sign & Accept Proposal
            </button>
            <p class="mt-3 text-sm text-gray-500">
                Please select a package above to continue.
            </p>
        </div>
    @endif
</div>
