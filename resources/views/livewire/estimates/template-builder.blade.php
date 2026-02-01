<div>
    {{-- Mobile Sticky Header with Total --}}
    <div class="lg:hidden fixed top-0 left-0 right-0 z-40 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700 px-4 py-3 shadow-sm">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                @if ($isEditing)
                    <flux:button variant="ghost" :href="route('estimates.show', $estimate)" icon="arrow-left" wire:navigate size="sm" />
                @else
                    <flux:button variant="ghost" :href="route('deals.show', $deal)" icon="arrow-left" wire:navigate size="sm" />
                @endif
                <div>
                    <div class="font-semibold text-sm">Template Builder</div>
                    <div class="text-xs text-zinc-500">{{ count($selectedTemplates) }} items</div>
                </div>
            </div>
            <div class="text-right">
                <div class="text-xs text-zinc-500">Total</div>
                <div class="text-lg font-bold text-blue-600 dark:text-blue-400">${{ number_format($this->total, 2) }}</div>
            </div>
        </div>
    </div>

    {{-- Mobile Bottom Action Bar --}}
    <div class="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-white dark:bg-zinc-900 border-t border-zinc-200 dark:border-zinc-700 p-4 shadow-lg">
        <div class="flex gap-3">
            <flux:button wire:click="saveAsDraft" wire:loading.attr="disabled" class="flex-1" size="sm">
                <span wire:loading.remove wire:target="saveAsDraft">Draft</span>
                <span wire:loading wire:target="saveAsDraft">...</span>
            </flux:button>
            <flux:button wire:click="saveAndSend" variant="primary" wire:loading.attr="disabled" class="flex-1" size="sm">
                <span wire:loading.remove wire:target="saveAndSend">Save & Send</span>
                <span wire:loading wire:target="saveAndSend">...</span>
            </flux:button>
        </div>
    </div>

    {{-- Main Content --}}
    <div class="max-w-7xl mx-auto pt-16 pb-24 lg:pt-0 lg:pb-0">
        {{-- Desktop Header --}}
        <div class="hidden lg:flex items-center justify-between mb-6">
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
                        Edit Template Estimate: {{ $estimate->estimate_number }}
                    @else
                        Template Builder
                    @endif
                </flux:heading>
            </div>
        </div>

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4 mx-4 lg:mx-0">
                {{ session('error') }}
            </flux:callout>
        @endif

        <div class="lg:grid lg:grid-cols-12 lg:gap-6">
            {{-- Main Column --}}
            <div class="lg:col-span-8 space-y-4 px-4 lg:px-0">
                {{-- Project & Title - Collapsed Card --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm mb-4">
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400">Deal:</span>
                            <span class="font-medium ml-1">{{ $deal->title }}</span>
                        </div>
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400">Customer:</span>
                            <span class="font-medium ml-1">{{ $deal->customer->full_name }}</span>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Estimate Title</flux:label>
                            <flux:input wire:model="title" placeholder="e.g., Interior Painting - Living Room" />
                            <flux:error name="title" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Valid Until</flux:label>
                            <flux:input wire:model="validUntil" type="date" />
                            <flux:error name="validUntil" />
                        </flux:field>
                    </div>
                </div>

                {{-- Project Scope Selector (Primary) --}}
                <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg p-4 bg-gradient-to-r from-blue-50 to-white dark:from-blue-950/30 dark:to-zinc-900">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <flux:heading size="sm" class="text-blue-800 dark:text-blue-200">Project Type</flux:heading>
                            <flux:text size="sm" class="text-blue-600 dark:text-blue-400">Select interior or exterior to filter all templates</flux:text>
                        </div>
                        <div class="flex gap-2">
                            @foreach ($this->getProjectScopeOptions() as $value => $label)
                                <button
                                    wire:click="$set('projectScope', '{{ $value }}')"
                                    type="button"
                                    class="px-6 py-2.5 text-sm font-semibold rounded-lg transition-all {{ $projectScope === $value ? ($value === 'interior' ? 'bg-blue-600 text-white shadow-md ring-2 ring-blue-300 dark:ring-blue-700' : 'bg-green-600 text-white shadow-md ring-2 ring-green-300 dark:ring-green-700') : 'bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-300 dark:border-zinc-600 hover:border-blue-400 dark:hover:border-blue-500' }}"
                                >
                                    @if ($value === 'interior')
                                        <flux:icon name="home" class="w-4 h-4 inline mr-1.5 -mt-0.5" />
                                    @else
                                        <flux:icon name="sun" class="w-4 h-4 inline mr-1.5 -mt-0.5" />
                                    @endif
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Quick Add Buttons --}}
                @if ($this->quickAddTemplates->isNotEmpty())
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                        <div class="flex items-center justify-between mb-3">
                            <flux:heading size="sm">Quick Add</flux:heading>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">One-click to add common rooms</flux:text>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->quickAddTemplates as $template)
                                <button
                                    wire:click="addTemplate({{ $template->id }})"
                                    wire:key="quick-add-{{ $template->id }}"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-300 hover:border-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 dark:hover:border-blue-500 transition-colors active:scale-95"
                                >
                                    <flux:icon name="plus" class="w-4 h-4 text-blue-500" />
                                    {{ $template->name }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Template Selection --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    {{-- Filters Header --}}
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <flux:heading size="sm">All Templates</flux:heading>
                            {{-- Scope Filter Pills (now secondary, synced with projectScope) --}}
                            <div class="flex gap-1">
                                @foreach ($this->getScopeFilterOptions() as $value => $label)
                                    <button
                                        wire:click="$set('scopeFilter', '{{ $value }}')"
                                        type="button"
                                        class="px-3 py-1.5 text-xs font-medium rounded-full transition-colors {{ $scopeFilter === $value ? 'bg-blue-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}"
                                    >
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Category Tabs - Horizontal Scroll --}}
                    <div class="border-b border-zinc-200 dark:border-zinc-700 overflow-x-auto">
                        <nav class="flex min-w-max px-4">
                            @foreach ($this->categories as $category)
                                <button
                                    wire:click="$set('activeTab', '{{ $category->type }}')"
                                    class="px-4 py-3 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors {{ $activeTab === $category->type ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' }}"
                                >
                                    {{ $category->name }}
                                    @if ($this->templatesByCategory->has($category->type))
                                        <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full {{ $activeTab === $category->type ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500' }}">
                                            {{ $this->templatesByCategory[$category->type]->count() }}
                                        </span>
                                    @endif
                                </button>
                            @endforeach
                        </nav>
                    </div>

                    {{-- Template Cards Grid --}}
                    <div class="p-4">
                        @if ($this->templatesByCategory->has($activeTab) && $this->templatesByCategory[$activeTab]->count() > 0)
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                @foreach ($this->templatesByCategory[$activeTab] as $template)
                                    <button
                                        wire:click="addTemplate({{ $template->id }})"
                                        wire:key="template-card-{{ $template->id }}"
                                        class="p-4 border-2 border-zinc-200 dark:border-zinc-700 rounded-xl hover:border-blue-500 hover:shadow-md dark:hover:bg-zinc-800/50 transition-all text-left group active:scale-[0.98]"
                                    >
                                        <div class="flex items-start gap-3">
                                            <div class="flex-shrink-0 w-12 h-12 bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-800/20 rounded-xl flex items-center justify-center group-hover:from-blue-200 group-hover:to-blue-100 dark:group-hover:from-blue-800/40 dark:group-hover:to-blue-900/30 transition-colors">
                                                <flux:icon name="squares-2x2" class="w-6 h-6 text-blue-600 dark:text-blue-400" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="font-semibold text-zinc-900 dark:text-zinc-100 truncate">
                                                    {{ $template->name }}
                                                </div>
                                                <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                                                    {{ number_format($template->default_quantity, 0) }} {{ $template->default_unit ?? 'sqft' }}
                                                    @if ($template->productionRate)
                                                        <span class="text-zinc-400 dark:text-zinc-500">· {{ $template->productionRate->production_rate }}/hr</span>
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-2 mt-2">
                                                    <span class="text-lg font-bold text-blue-600 dark:text-blue-400">
                                                        ${{ number_format($template->calculatePrice($template->default_quantity, $template->default_coats ?? 2), 2) }}
                                                    </span>
                                                    @if ($template->scope_type && $template->scope_type !== 'both')
                                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $template->scope_type === 'interior' ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300' : 'bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300' }}">
                                                            {{ $template->scope_type_label }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <flux:icon name="plus-circle" class="w-6 h-6 text-blue-500" />
                                            </div>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-12 text-zinc-500 dark:text-zinc-400">
                                <flux:icon name="squares-2x2" class="w-12 h-12 mx-auto mb-3 text-zinc-300 dark:text-zinc-600" />
                                <p class="font-medium">No templates in this category</p>
                                <p class="text-sm mt-1">Try selecting a different category above</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Selected Templates --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <flux:heading size="sm">Your Estimate</flux:heading>
                        @if (count($selectedTemplates) > 0)
                            <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300">
                                {{ count($selectedTemplates) }} {{ Str::plural('item', count($selectedTemplates)) }}
                            </span>
                        @endif
                    </div>

                    <div class="p-4">
                        @if (count($selectedTemplates) === 0)
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                                <div class="w-16 h-16 mx-auto mb-3 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <flux:icon name="inbox" class="w-8 h-8 text-zinc-400 dark:text-zinc-500" />
                                </div>
                                <p class="font-medium">No items yet</p>
                                <p class="text-sm mt-1">Tap templates above to build your estimate</p>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($selectedTemplates as $index => $templateData)
                                    <div wire:key="selected-template-{{ $index }}" x-data="{ showBreakdown: false }" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden group">
                                        {{-- Main row --}}
                                        <div class="flex items-center gap-3 p-3 bg-zinc-50 dark:bg-zinc-800/50">
                                            <div class="flex-shrink-0 w-10 h-10 bg-white dark:bg-zinc-700 rounded-lg flex items-center justify-center shadow-sm">
                                                <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="font-medium text-zinc-900 dark:text-zinc-100 truncate">
                                                    {{ $templateData['name'] }}
                                                </div>
                                                <div class="flex flex-wrap items-center gap-x-2 text-sm text-zinc-500 dark:text-zinc-400">
                                                    {{-- Size dropdown if template has sizes --}}
                                                    @if (!empty($templateData['sizes']))
                                                        <select
                                                            wire:change="changeSize({{ $index }}, $event.target.value)"
                                                            class="text-sm bg-white dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600 rounded-md py-0.5 px-2 font-medium text-blue-600 dark:text-blue-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                        >
                                                            @foreach ($templateData['sizes'] as $sizeKey => $sizeData)
                                                                <option value="{{ $sizeKey }}" {{ ($templateData['selected_size'] ?? '') === $sizeKey ? 'selected' : '' }}>
                                                                    {{ $sizeData['label'] ?? ucfirst($sizeKey) }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        <span>{{ number_format($templateData['quantity'], 0) }} sqft</span>
                                                    @endif
                                                    @if ($templateData['coats'] > 1)
                                                        <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                                        <span>{{ $templateData['coats'] }} coats</span>
                                                    @endif
                                                    @if (!empty($templateData['labor_hours']) && $templateData['labor_hours'] > 0)
                                                        <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                                        <span class="text-blue-600 dark:text-blue-400">{{ number_format($templateData['labor_hours'], 1) }} hrs</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex-shrink-0 text-right">
                                                <div class="font-bold text-lg text-blue-600 dark:text-blue-400">
                                                    ${{ number_format($templateData['price'], 2) }}
                                                </div>
                                                @if (!empty($templateData['base_total']) && $templateData['base_total'] > 0 && $templateData['base_total'] != $templateData['price'])
                                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                                        Base: ${{ number_format($templateData['base_total'], 2) }}
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="flex-shrink-0 flex items-center gap-1">
                                                @if (!empty($templateData['labor_hours']) && $templateData['labor_hours'] > 0)
                                                    <button
                                                        @click="showBreakdown = !showBreakdown"
                                                        type="button"
                                                        class="p-1.5 rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-600 transition-colors"
                                                        title="Show PBP breakdown"
                                                    >
                                                        <svg x-show="!showBreakdown" class="w-4 h-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                        <svg x-show="showBreakdown" class="w-4 h-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                                    </button>
                                                @endif
                                                <flux:button
                                                    wire:click="openAdjustment({{ $index }})"
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="pencil"
                                                    class="opacity-60 group-hover:opacity-100"
                                                />
                                                <flux:button
                                                    wire:click="removeTemplate({{ $index }})"
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="trash"
                                                    class="text-red-500 hover:text-red-700 dark:text-red-400 opacity-60 group-hover:opacity-100"
                                                />
                                            </div>
                                        </div>

                                        {{-- PBP Breakdown (expandable) --}}
                                        @if (!empty($templateData['labor_hours']) && $templateData['labor_hours'] > 0)
                                            <div x-show="showBreakdown" x-collapse class="border-t border-zinc-200 dark:border-zinc-700">
                                                <div class="p-3 bg-blue-50/50 dark:bg-blue-950/20 text-xs">
                                                    {{-- Per-Surface Breakdown (if available) --}}
                                                    @if (!empty($templateData['surfaces']))
                                                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">Surfaces Breakdown</div>
                                                        <div class="space-y-1 mb-3">
                                                            @foreach ($templateData['surfaces'] as $surfaceKey => $surface)
                                                                <div class="flex justify-between items-center py-1 {{ !$loop->last ? 'border-b border-blue-100 dark:border-blue-900' : '' }}">
                                                                    <div class="flex items-center gap-2">
                                                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $surface['name'] }}</span>
                                                                        <span class="text-zinc-500 dark:text-zinc-400">
                                                                            @if ($surface['unit'] === 'fixed')
                                                                                (fixed)
                                                                            @else
                                                                                {{ number_format($surface['quantity'], 0) }} {{ $surface['unit'] }}
                                                                            @endif
                                                                        </span>
                                                                    </div>
                                                                    <div class="text-right text-zinc-600 dark:text-zinc-400">
                                                                        @if ($surface['labor_hours'] > 0)
                                                                            {{ number_format($surface['labor_hours'], 1) }} hrs
                                                                        @endif
                                                                        @if ($surface['material_cost'] > 0 && $surface['labor_hours'] == 0)
                                                                            ${{ number_format($surface['material_cost'], 2) }}
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif

                                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">Cost Summary</div>
                                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">Labor</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                {{ number_format($templateData['labor_hours'], 1) }} hrs
                                                                <span class="text-zinc-400 dark:text-zinc-500">·</span>
                                                                ${{ number_format($templateData['labor_cost'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">Cleanup</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                {{ number_format($templateData['cleanup_hours'] ?? 0, 1) }} hrs
                                                                <span class="text-zinc-400 dark:text-zinc-500">·</span>
                                                                ${{ number_format($templateData['cleanup_cost'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">Sundries</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                ${{ number_format($templateData['sundries_cost'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">Materials</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                ${{ number_format($templateData['material_cost'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="flex justify-between items-center mt-3 pt-2 border-t border-blue-200 dark:border-blue-800">
                                                        <div class="text-zinc-600 dark:text-zinc-400">
                                                            Base Total → With Margins
                                                        </div>
                                                        <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                            ${{ number_format($templateData['base_total'] ?? 0, 2) }}
                                                            <span class="text-blue-500 mx-1">→</span>
                                                            <span class="text-blue-600 dark:text-blue-400 font-bold">${{ number_format($templateData['with_margins'] ?? $templateData['price'], 2) }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @error('selectedTemplates')
                            <p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Notes - Collapsible on Mobile --}}
                <details class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 group">
                    <summary class="p-4 cursor-pointer list-none flex items-center justify-between">
                        <flux:heading size="sm">Notes (Optional)</flux:heading>
                        <flux:icon name="chevron-down" class="w-5 h-5 text-zinc-400 transition-transform group-open:rotate-180" />
                    </summary>
                    <div class="px-4 pb-4">
                        <flux:textarea wire:model="notes" rows="3" placeholder="Additional notes for the customer..." />
                        <flux:error name="notes" />
                    </div>
                </details>
            </div>

            {{-- Desktop Sidebar --}}
            <div class="hidden lg:block lg:col-span-4">
                <div class="sticky top-6 space-y-4">
                    {{-- Pricing Options --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                        <flux:heading size="sm" class="mb-4">Pricing</flux:heading>
                        <div class="space-y-3">
                            <flux:field>
                                <flux:label>Tax Rate</flux:label>
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model.live="taxRate" type="number" step="0.01" min="0" max="100" placeholder="0" class="flex-1" />
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">%</span>
                                </div>
                            </flux:field>
                            <flux:field>
                                <flux:label>Discount</flux:label>
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">$</span>
                                    <flux:input wire:model.live="discountAmount" type="number" step="0.01" min="0" placeholder="0" class="flex-1" />
                                </div>
                            </flux:field>
                            <flux:field>
                                <flux:label>Deposit Required</flux:label>
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model.live="depositPercent" type="number" step="1" min="0" max="100" placeholder="30" class="flex-1" />
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">%</span>
                                </div>
                            </flux:field>
                        </div>
                    </div>

                    {{-- Summary Card --}}
                    <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg p-4 bg-gradient-to-br from-blue-50 to-white dark:from-blue-950/30 dark:to-zinc-900">
                        <flux:heading size="sm" class="mb-4">Summary</flux:heading>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-zinc-600 dark:text-zinc-400">Subtotal ({{ count($selectedTemplates) }} items)</dt>
                                <dd class="font-medium">${{ number_format($this->subtotal, 2) }}</dd>
                            </div>
                            @if ($taxRate > 0)
                                <div class="flex justify-between">
                                    <dt class="text-zinc-600 dark:text-zinc-400">Tax ({{ number_format($taxRate, 1) }}%)</dt>
                                    <dd>${{ number_format($this->taxAmount, 2) }}</dd>
                                </div>
                            @endif
                            @if ($discountAmount > 0)
                                <div class="flex justify-between">
                                    <dt class="text-zinc-600 dark:text-zinc-400">Discount</dt>
                                    <dd class="text-red-600 dark:text-red-400">-${{ number_format($discountAmount, 2) }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between pt-3 border-t border-blue-200 dark:border-blue-800">
                                <dt class="text-lg font-bold">Total</dt>
                                <dd class="text-2xl font-bold text-blue-600 dark:text-blue-400">${{ number_format($this->total, 2) }}</dd>
                            </div>
                            <div class="pt-3 border-t border-blue-100 dark:border-blue-900 space-y-1">
                                <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                                    <dt>Deposit ({{ number_format($depositPercent, 0) }}%)</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->depositAmount, 2) }}</dd>
                                </div>
                                <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                                    <dt>Balance Due</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->balanceDue, 2) }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>

                    {{-- Actions --}}
                    <div class="space-y-2">
                        <flux:button wire:click="saveAndSend" variant="primary" wire:loading.attr="disabled" class="w-full">
                            <span wire:loading.remove wire:target="saveAndSend">Save & Send to Customer</span>
                            <span wire:loading wire:target="saveAndSend">Sending...</span>
                        </flux:button>
                        <flux:button wire:click="saveAsDraft" wire:loading.attr="disabled" class="w-full">
                            <span wire:loading.remove wire:target="saveAsDraft">Save as Draft</span>
                            <span wire:loading wire:target="saveAsDraft">Saving...</span>
                        </flux:button>
                        @if ($isEditing)
                            <flux:button variant="ghost" :href="route('estimates.show', $estimate)" wire:navigate class="w-full">
                                Cancel
                            </flux:button>
                        @else
                            <flux:button variant="ghost" :href="route('deals.show', $deal)" wire:navigate class="w-full">
                                Cancel
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Adjustment Modal --}}
    <flux:modal wire:model="showAdjustmentModal" name="adjustment-modal" class="max-w-md">
        <div class="p-6">
            @if ($adjustingTemplateIndex !== null && isset($selectedTemplates[$adjustingTemplateIndex]))
                @php $adjustingTemplate = $selectedTemplates[$adjustingTemplateIndex]; @endphp

                <flux:heading size="lg" class="mb-6">
                    Adjust: {{ $adjustingTemplate['name'] }}
                </flux:heading>

                <div class="space-y-6">
                    {{-- Quantity --}}
                    <flux:field>
                        <flux:label>Square Footage</flux:label>
                        <flux:input
                            wire:model.live="adjustQuantity"
                            type="number"
                            step="1"
                            min="1"
                            placeholder="Enter square footage"
                            class="text-lg"
                        />
                        <flux:error name="adjustQuantity" />
                    </flux:field>

                    {{-- Quick Presets --}}
                    <div>
                        <flux:label class="mb-2">Quick Sizes</flux:label>
                        <div class="grid grid-cols-4 gap-2">
                            @foreach ([100, 200, 300, 400, 500, 750, 1000, 1500] as $preset)
                                <button
                                    type="button"
                                    wire:click="$set('adjustQuantity', {{ $preset }})"
                                    class="py-2 text-sm font-medium rounded-lg border transition-colors {{ $adjustQuantity == $preset ? 'bg-blue-600 border-blue-600 text-white' : 'border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-300 hover:border-blue-500' }}"
                                >
                                    {{ $preset }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Coats --}}
                    <flux:field>
                        <flux:label>Number of Coats</flux:label>
                        <div class="grid grid-cols-4 gap-2">
                            @for ($i = 1; $i <= 4; $i++)
                                <button
                                    type="button"
                                    wire:click="$set('adjustCoats', {{ $i }})"
                                    class="py-3 text-lg font-semibold rounded-lg border transition-colors {{ $adjustCoats === $i ? 'bg-blue-600 border-blue-600 text-white' : 'border-zinc-300 dark:border-zinc-600 text-zinc-700 dark:text-zinc-300 hover:border-blue-500' }}"
                                >
                                    {{ $i }}
                                </button>
                            @endfor
                        </div>
                    </flux:field>

                    {{-- Live PBP Breakdown Preview --}}
                    @php $previewBreakdown = $this->adjustmentPreviewBreakdown; @endphp
                    <div class="p-4 bg-gradient-to-r from-blue-50 to-blue-100 dark:from-blue-950/50 dark:to-blue-900/30 rounded-xl space-y-3">
                        @if ($previewBreakdown['labor_hours'] > 0)
                            {{-- PBP Breakdown Grid --}}
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div class="flex justify-between">
                                    <span class="text-zinc-600 dark:text-zinc-400">Labor:</span>
                                    <span class="font-medium">{{ number_format($previewBreakdown['labor_hours'], 1) }} hrs · ${{ number_format($previewBreakdown['labor_cost'], 2) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-zinc-600 dark:text-zinc-400">Cleanup:</span>
                                    <span class="font-medium">{{ number_format($previewBreakdown['cleanup_hours'], 1) }} hrs · ${{ number_format($previewBreakdown['cleanup_cost'], 2) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-zinc-600 dark:text-zinc-400">Sundries:</span>
                                    <span class="font-medium">${{ number_format($previewBreakdown['sundries_cost'], 2) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-zinc-600 dark:text-zinc-400">Materials:</span>
                                    <span class="font-medium">${{ number_format($previewBreakdown['material_cost'], 2) }}</span>
                                </div>
                            </div>
                            <div class="flex justify-between items-center text-xs pt-2 border-t border-blue-200 dark:border-blue-800">
                                <span class="text-zinc-600 dark:text-zinc-400">Base Total:</span>
                                <span class="font-medium">${{ number_format($previewBreakdown['base_total'], 2) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between items-center pt-2 border-t border-blue-200 dark:border-blue-800">
                            <span class="text-sm font-medium text-blue-700 dark:text-blue-300">With Margins</span>
                            <span class="text-3xl font-bold text-blue-700 dark:text-blue-300">
                                ${{ number_format($this->adjustmentPreviewPrice, 2) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <flux:button wire:click="closeAdjustment" variant="ghost" class="flex-1">
                        Cancel
                    </flux:button>
                    <flux:button wire:click="saveAdjustment" variant="primary" class="flex-1">
                        Update
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
