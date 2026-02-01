<div>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                @if ($isEditing)
                    <flux:button variant="ghost" :href="route('estimates.show', $estimate)" icon="arrow-left" wire:navigate>
                        Back
                    </flux:button>
                @else
                    <flux:button variant="ghost" :href="route('deals.show', $deal)" icon="arrow-left" wire:navigate>
                        Back to Deal
                    </flux:button>
                @endif
                <flux:heading size="xl">
                    @if ($isEditing)
                        Edit Simple Estimate: {{ $estimate->estimate_number }}
                    @else
                        Quick Estimate ($/Sq Ft)
                    @endif
                </flux:heading>
            </div>
        </div>

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">
                {{ session('error') }}
            </flux:callout>
        @endif

        <form wire:submit.prevent="saveAsDraft">
            <div class="space-y-6">
                {{-- Deal Information --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Deal Information</flux:heading>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Deal</dt>
                            <dd class="mt-1">
                                <flux:text class="font-medium">{{ $deal->title }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Customer</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $deal->customer->full_name }}</flux:text>
                            </dd>
                        </div>
                    </div>
                </div>

                {{-- Estimate Details --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Estimate Details</flux:heading>
                    <div class="space-y-4">
                        <flux:field>
                            <flux:label>Title</flux:label>
                            <flux:input wire:model="title" placeholder="e.g., Interior Painting - Whole House" />
                            <flux:error name="title" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Project Type</flux:label>
                            <div class="flex gap-6 mt-2">
                                @foreach ($projectTypes as $value => $label)
                                    <flux:radio wire:model.live="projectType" value="{{ $value }}" label="{{ $label }}" />
                                @endforeach
                            </div>
                            <flux:error name="projectType" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Valid Until</flux:label>
                            <flux:input wire:model="validUntil" type="date" />
                            <flux:error name="validUntil" />
                        </flux:field>
                    </div>
                </div>

                {{-- Square Footage & Rate --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Pricing</flux:heading>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:field>
                            <flux:label>Total Square Feet</flux:label>
                            <flux:input wire:model.live="totalSqft" type="number" step="0.01" min="1" placeholder="0.00" />
                            <flux:error name="totalSqft" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Rate per Sq Ft ($)</flux:label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-zinc-500 dark:text-zinc-400 sm:text-sm">$</span>
                                </div>
                                <flux:input wire:model.live="ratePerSqft" type="number" step="0.01" min="0.01" placeholder="0.00" class="pl-7" />
                            </div>
                            <flux:error name="ratePerSqft" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Tax Rate (%)</flux:label>
                            <div class="relative">
                                <flux:input wire:model.live="taxRate" type="number" step="0.01" min="0" max="100" placeholder="0.00" class="pr-8" />
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                    <span class="text-zinc-500 dark:text-zinc-400 sm:text-sm">%</span>
                                </div>
                            </div>
                            <flux:error name="taxRate" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Discount ($)</flux:label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <span class="text-zinc-500 dark:text-zinc-400 sm:text-sm">$</span>
                                </div>
                                <flux:input wire:model.live="discountAmount" type="number" step="0.01" min="0" placeholder="0.00" class="pl-7" />
                            </div>
                            <flux:error name="discountAmount" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Deposit (%)</flux:label>
                            <div class="relative">
                                <flux:input wire:model.live="depositPercent" type="number" step="0.01" min="0" max="100" placeholder="0.00" class="pr-8" />
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                    <span class="text-zinc-500 dark:text-zinc-400 sm:text-sm">%</span>
                                </div>
                            </div>
                            <flux:error name="depositPercent" />
                        </flux:field>
                    </div>
                </div>

                {{-- Summary Card --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 bg-zinc-50 dark:bg-zinc-800">
                    <flux:heading size="sm" class="mb-4">Estimate Summary</flux:heading>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ number_format($totalSqft, 0) }} sq ft x ${{ number_format($ratePerSqft, 2) }}/sq ft
                            </dt>
                            <dd class="font-medium">${{ number_format($this->subtotal, 2) }}</dd>
                        </div>
                        <div class="flex justify-between text-sm text-zinc-500 dark:text-zinc-400">
                            <dt>Subtotal</dt>
                            <dd>${{ number_format($this->subtotal, 2) }}</dd>
                        </div>
                        @if ($taxRate > 0)
                            <div class="flex justify-between text-sm">
                                <dt class="text-zinc-500 dark:text-zinc-400">Tax ({{ number_format($taxRate, 2) }}%)</dt>
                                <dd>${{ number_format($this->taxAmount, 2) }}</dd>
                            </div>
                        @endif
                        @if ($discountAmount > 0)
                            <div class="flex justify-between text-sm">
                                <dt class="text-zinc-500 dark:text-zinc-400">Discount</dt>
                                <dd class="text-red-600 dark:text-red-400">-${{ number_format($discountAmount, 2) }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                            <dt class="text-lg font-semibold">Total</dt>
                            <dd class="text-xl font-bold">${{ number_format($this->total, 2) }}</dd>
                        </div>
                        <div class="flex justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Deposit ({{ number_format($depositPercent, 0) }}%)</dt>
                            <dd class="font-medium">${{ number_format($this->depositAmount, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Balance Due</dt>
                            <dd class="font-medium">${{ number_format($this->balanceDue, 2) }}</dd>
                        </div>
                    </dl>
                </div>

                {{-- Notes --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Notes</flux:heading>
                    <flux:field>
                        <flux:textarea wire:model="notes" rows="4" placeholder="Additional notes for the customer..." />
                        <flux:error name="notes" />
                    </flux:field>
                </div>

                {{-- Actions --}}
                <div class="flex justify-end gap-3">
                    @if ($isEditing)
                        <flux:button variant="ghost" :href="route('estimates.show', $estimate)" wire:navigate>
                            Cancel
                        </flux:button>
                    @else
                        <flux:button variant="ghost" :href="route('deals.show', $deal)" wire:navigate>
                            Cancel
                        </flux:button>
                    @endif
                    <flux:button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveAsDraft">Save as Draft</span>
                        <span wire:loading wire:target="saveAsDraft">Saving...</span>
                    </flux:button>
                    <flux:button wire:click="saveAndSend" type="button" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveAndSend">Save & Send</span>
                        <span wire:loading wire:target="saveAndSend">Sending...</span>
                    </flux:button>
                </div>
            </div>
        </form>
    </div>
</div>
