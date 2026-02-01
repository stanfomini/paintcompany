<div>
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg">
        <div class="flex items-center justify-between p-4 border-b border-zinc-200 dark:border-zinc-700">
            <flux:heading size="sm">Provide Package Options</flux:heading>
            <div class="flex items-center gap-2">
                @if (count($packages) === 0)
                    <flux:button
                        wire:click="createGoodBetterBest"
                        wire:loading.attr="disabled"
                        type="button"
                        variant="primary"
                        size="sm"
                    >
                        <span wire:loading.remove wire:target="createGoodBetterBest">Create Good/Better/Best</span>
                        <span wire:loading wire:target="createGoodBetterBest">Creating...</span>
                    </flux:button>
                @endif
                <flux:button wire:click="openPackageModal" type="button" variant="ghost" size="sm" icon="plus">
                    Add Package
                </flux:button>
            </div>
        </div>

        @if (count($packages) > 0)
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 p-4">
                @foreach ($packages as $package)
                    <div
                        wire:key="package-{{ $package['id'] }}"
                        class="border rounded-lg {{ $package['is_recommended'] ? 'border-green-500 dark:border-green-400 ring-2 ring-green-500/20' : 'border-zinc-200 dark:border-zinc-700' }}"
                    >
                        {{-- Package Header --}}
                        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 {{ $package['is_recommended'] ? 'bg-green-50 dark:bg-green-900/20' : 'bg-zinc-50 dark:bg-zinc-800' }} rounded-t-lg">
                            <div class="flex items-start justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <flux:heading size="sm">{{ $package['name'] }}</flux:heading>
                                    <flux:badge color="{{ $this->getTierBadgeColor($package['tier']) }}" size="sm">
                                        {{ $package['tier_label'] }}
                                    </flux:badge>
                                </div>
                                <div class="flex items-center gap-1">
                                    <flux:button
                                        wire:click="openPackageModal({{ $package['id'] }})"
                                        type="button"
                                        variant="ghost"
                                        size="xs"
                                        icon="pencil"
                                    />
                                    <flux:button
                                        wire:click="deletePackage({{ $package['id'] }})"
                                        wire:confirm="Are you sure you want to delete this package? All items will be removed."
                                        type="button"
                                        variant="ghost"
                                        size="xs"
                                        icon="trash"
                                    />
                                </div>
                            </div>

                            @if ($package['is_recommended'])
                                <flux:badge color="green" size="sm" class="mb-2">Recommended</flux:badge>
                            @else
                                <flux:button
                                    wire:click="markAsRecommended({{ $package['id'] }})"
                                    type="button"
                                    variant="ghost"
                                    size="xs"
                                    class="text-zinc-500 hover:text-green-600"
                                >
                                    Set as Recommended
                                </flux:button>
                            @endif

                            @if ($package['description'])
                                <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mt-2">
                                    {{ $package['description'] }}
                                </flux:text>
                            @endif
                        </div>

                        {{-- Package Items --}}
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800 max-h-64 overflow-y-auto">
                            @forelse ($package['items'] as $item)
                                <div
                                    wire:key="package-{{ $package['id'] }}-item-{{ $item['id'] }}"
                                    class="px-4 py-2 flex items-center gap-3 {{ !$item['is_included'] ? 'opacity-50 bg-zinc-50 dark:bg-zinc-900' : '' }}"
                                >
                                    <flux:checkbox
                                        wire:click="toggleItemInclusion({{ $package['id'] }}, {{ $item['id'] }})"
                                        :checked="$item['is_included']"
                                    />
                                    <div class="flex-1 min-w-0">
                                        <flux:text size="sm" class="truncate">
                                            {{ $item['name'] }}
                                        </flux:text>
                                        <flux:text size="xs" class="text-zinc-400">
                                            {{ $item['itemable_type'] }}
                                        </flux:text>
                                    </div>
                                    <flux:text size="sm" class="font-medium {{ $item['is_included'] ? '' : 'line-through' }}">
                                        ${{ number_format($item['effective_price'], 2) }}
                                    </flux:text>
                                </div>
                            @empty
                                <div class="p-4 text-center">
                                    <flux:text size="sm" class="text-zinc-400">No items in this package</flux:text>
                                </div>
                            @endforelse
                        </div>

                        {{-- Package Totals --}}
                        <div class="p-4 bg-zinc-50 dark:bg-zinc-800 border-t border-zinc-200 dark:border-zinc-700 rounded-b-lg space-y-2">
                            <div class="flex justify-between text-sm">
                                <flux:text class="text-zinc-500">Subtotal</flux:text>
                                <flux:text>${{ number_format($package['subtotal'], 2) }}</flux:text>
                            </div>

                            @if ($package['discount_percent'] > 0 || $package['discount_amount'] > 0)
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">
                                        Discount
                                        @if ($package['discount_percent'] > 0)
                                            ({{ number_format($package['discount_percent'], 1) }}%)
                                        @endif
                                    </flux:text>
                                    <flux:text class="text-red-600 dark:text-red-400">
                                        -${{ number_format($package['discount_amount'], 2) }}
                                    </flux:text>
                                </div>
                            @endif

                            @if ($package['tax_rate'] > 0)
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">Tax ({{ number_format($package['tax_rate'], 2) }}%)</flux:text>
                                    <flux:text>${{ number_format($package['tax_amount'], 2) }}</flux:text>
                                </div>
                            @endif

                            <div class="flex justify-between font-semibold pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:text>Total</flux:text>
                                <flux:text class="text-lg">${{ number_format($package['total'], 2) }}</flux:text>
                            </div>

                            <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700 space-y-1">
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">Deposit Required</flux:text>
                                    <flux:text class="text-green-600 dark:text-green-400 font-medium">
                                        ${{ number_format($package['deposit_amount'], 2) }}
                                    </flux:text>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <flux:text class="text-zinc-500">Balance Due</flux:text>
                                    <flux:text>${{ number_format($package['balance_due'], 2) }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-8 text-center">
                <flux:icon name="cube" class="w-12 h-12 mx-auto text-zinc-400 dark:text-zinc-500 mb-3" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">No packages created yet</flux:text>
                <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500 mt-1">
                    Create Good/Better/Best packages to offer customers multiple pricing options.
                </flux:text>
            </div>
        @endif
    </div>

    {{-- Package Modal --}}
    <flux:modal wire:model="showPackageModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingPackageId ? 'Edit Package' : 'Add Package' }}
            </flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Package Name</flux:label>
                    <flux:input
                        wire:model="packageName"
                        placeholder="e.g., Premium Package"
                    />
                    <flux:error name="packageName" />
                </flux:field>

                <flux:field>
                    <flux:label>Tier</flux:label>
                    <flux:select wire:model="packageTier">
                        <option value="basic">Good (Basic)</option>
                        <option value="standard">Better (Standard)</option>
                        <option value="premium">Best (Premium)</option>
                        <option value="custom">Custom</option>
                    </flux:select>
                    <flux:error name="packageTier" />
                </flux:field>

                <flux:field>
                    <flux:label>Description</flux:label>
                    <flux:textarea
                        wire:model="packageDescription"
                        rows="3"
                        placeholder="Describe what this package includes..."
                    />
                    <flux:error name="packageDescription" />
                </flux:field>

                <flux:field>
                    <flux:label>Discount Percent (%)</flux:label>
                    <flux:input
                        wire:model="packageDiscountPercent"
                        type="number"
                        step="0.1"
                        min="0"
                        max="100"
                        placeholder="0"
                    />
                    <flux:error name="packageDiscountPercent" />
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-1">
                        Enter a percentage to discount the package total.
                    </flux:text>
                </flux:field>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closePackageModal" type="button" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button
                    wire:click="savePackage"
                    type="button"
                    variant="primary"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="savePackage">
                        {{ $editingPackageId ? 'Update Package' : 'Create Package' }}
                    </span>
                    <span wire:loading wire:target="savePackage">Saving...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
