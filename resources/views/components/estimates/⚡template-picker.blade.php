<?php

use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component {
    #[Reactive]
    public string $projectScope = 'interior';

    public bool $showTemplatePicker = false;

    public function openPicker(): void
    {
        $this->showTemplatePicker = true;
    }

    public function closePicker(): void
    {
        $this->showTemplatePicker = false;
    }

    #[On('open-template-picker')]
    public function handleOpen(): void
    {
        $this->showTemplatePicker = true;
    }

    #[On('scope-changed')]
    public function handleScopeChanged(string $scope): void
    {
        $this->projectScope = $scope;
        unset($this->pickerTemplates, $this->fixedPriceTemplates, $this->fixedPriceTemplatesByCategory);
    }

    public function selectTemplate(int $templateId): void
    {
        $this->showTemplatePicker = false;
        $this->dispatch('add-template-from-picker', templateId: $templateId);
    }

    public function selectFixedPriceTemplate(int $nestedTemplateId): void
    {
        $this->showTemplatePicker = false;
        $this->dispatch('add-fixed-price-from-picker', nestedTemplateId: $nestedTemplateId);
    }

    /**
     * Get configurable area templates for the picker.
     *
     * @return Collection<int, EstimateTemplate>
     */
    #[Computed]
    public function pickerTemplates(): Collection
    {
        return EstimateTemplate::query()
            ->active()
            ->configurable()
            ->whereIn('scope_type', [$this->projectScope, EstimateTemplate::SCOPE_TYPE_BOTH])
            ->with(['templateCategory', 'surfaces'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Get fixed price templates (NestedAreaTemplates) for the picker.
     *
     * @return Collection<int, NestedAreaTemplate>
     */
    #[Computed]
    public function fixedPriceTemplates(): Collection
    {
        return NestedAreaTemplate::query()
            ->active()
            ->forScope($this->projectScope)
            ->ordered()
            ->get();
    }

    /**
     * Get fixed price templates grouped by category.
     *
     * @return Collection<string, Collection<int, NestedAreaTemplate>>
     */
    #[Computed]
    public function fixedPriceTemplatesByCategory(): Collection
    {
        return $this->fixedPriceTemplates->groupBy('category');
    }
};
?>

<div>
    <flux:modal wire:model="showTemplatePicker" name="template-picker-modal" class="max-w-3xl" x-data="{ pickerTab: 'areas' }">
        <div class="p-6">
            <flux:heading size="lg" class="mb-4">
                {{ __('Add to Estimate') }}
            </flux:heading>

            {{-- Tabs for Area Templates vs Fixed Price --}}
            <div class="flex gap-2 mb-4 border-b border-zinc-200 dark:border-zinc-700">
                <button
                    @click="pickerTab = 'areas'"
                    type="button"
                    :class="pickerTab === 'areas' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                >
                    {{ __('Area Templates') }}
                    <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-zinc-100 dark:bg-zinc-800">
                        {{ $this->pickerTemplates->count() }}
                    </span>
                </button>
                <button
                    @click="pickerTab = 'fixed'"
                    type="button"
                    :class="pickerTab === 'fixed' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                >
                    {{ __('Fixed Price') }}
                    <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-zinc-100 dark:bg-zinc-800">
                        {{ $this->fixedPriceTemplates->count() }}
                    </span>
                </button>
            </div>

            {{-- Area Templates Tab --}}
            <div x-show="pickerTab === 'areas'" x-cloak>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-4">
                    {{ __('Choose an area template with full dimension and surface control.') }}
                </flux:text>

                @if ($this->pickerTemplates->isEmpty())
                    <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="squares-2x2" class="w-12 h-12 mx-auto mb-3 text-zinc-300 dark:text-zinc-600" />
                        <p class="font-medium">{{ __('No area templates available') }}</p>
                        <p class="text-sm mt-1">{{ __('Create area templates in Area Templates first, or add a custom area.') }}</p>
                    </div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 max-h-[50vh] overflow-y-auto">
                        @foreach ($this->pickerTemplates as $template)
                            <button
                                wire:click="selectTemplate({{ $template->id }})"
                                wire:key="picker-template-{{ $template->id }}"
                                type="button"
                                class="p-4 border-2 border-zinc-200 dark:border-zinc-700 rounded-xl hover:border-blue-500 hover:shadow-md dark:hover:bg-zinc-800/50 transition-all text-left group active:scale-[0.98]"
                            >
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-800/20 rounded-xl flex items-center justify-center group-hover:from-blue-200 group-hover:to-blue-100 transition-colors">
                                        <flux:icon name="{{ $template->icon ?? 'squares-2x2' }}" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold text-sm text-zinc-900 dark:text-zinc-100 truncate">
                                            {{ __($template->name) }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __($template->templateCategory?->name ?? 'Room') }}
                                        </div>
                                    </div>
                                </div>
                                @if ($template->description)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">
                                        {{ $template->description }}
                                    </p>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Fixed Price Tab --}}
            <div x-show="pickerTab === 'fixed'" x-cloak>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-4">
                    {{ __('Add fixed-price items like closets, pantries, and exterior features.') }}
                </flux:text>

                @if ($this->fixedPriceTemplates->isEmpty())
                    <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="currency-dollar" class="w-12 h-12 mx-auto mb-3 text-zinc-300 dark:text-zinc-600" />
                        <p class="font-medium">{{ __('No fixed price templates available') }}</p>
                        <p class="text-sm mt-1">{{ __('Create fixed price templates in Production Rates first.') }}</p>
                    </div>
                @else
                    <div class="max-h-[50vh] overflow-y-auto space-y-4">
                        @foreach ($this->fixedPriceTemplatesByCategory as $category => $templates)
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">
                                    {{ ucfirst($category) }}
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                    @foreach ($templates as $template)
                                        <button
                                            wire:click="selectFixedPriceTemplate({{ $template->id }})"
                                            wire:key="fixed-price-{{ $template->id }}"
                                            type="button"
                                            class="p-3 border-2 border-zinc-200 dark:border-zinc-700 rounded-lg hover:border-emerald-500 hover:shadow-md dark:hover:bg-zinc-800/50 transition-all text-left group active:scale-[0.98]"
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <div class="min-w-0">
                                                    <div class="font-medium text-sm text-zinc-900 dark:text-zinc-100 truncate">
                                                        {{ __($template->name) }}
                                                    </div>
                                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 font-mono">
                                                        {{ $template->code }}
                                                    </div>
                                                </div>
                                                <div class="flex-shrink-0 text-right">
                                                    <div class="font-bold text-emerald-600 dark:text-emerald-400">
                                                        {{ $template->formatted_price }}
                                                    </div>
                                                </div>
                                            </div>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closePicker" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
