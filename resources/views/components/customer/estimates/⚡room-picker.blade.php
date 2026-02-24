<?php

use App\Models\EstimateTemplate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $activeScope = 'interior';

    #[Computed]
    public function templates()
    {
        return EstimateTemplate::query()
            ->active()
            ->configurable()
            ->whereHas('templateCategory', fn ($q) => $q->where('type', 'room'))
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn ($t) => $t->scope_type === 'exterior' ? 'exterior' : 'interior');
    }

    #[Computed]
    public function interiorTemplates()
    {
        return $this->templates->get('interior', collect());
    }

    #[Computed]
    public function exteriorTemplates()
    {
        return $this->templates->get('exterior', collect());
    }

    #[Computed]
    public function activeTemplates()
    {
        return $this->activeScope === 'exterior'
            ? $this->exteriorTemplates
            : $this->interiorTemplates;
    }

    public function selectRoom(int $templateId, string $size = 'medium'): void
    {
        $this->dispatch('room-added', templateId: $templateId, size: $size);
    }
}; ?>

<div>
    {{-- Scope Tabs --}}
    <div class="flex gap-2 mb-6">
        <flux:button
            wire:click="$set('activeScope', 'interior')"
            :variant="$activeScope === 'interior' ? 'primary' : 'ghost'"
            icon="home"
            size="sm"
        >
            {{ __('Interior') }}
            @if ($this->interiorTemplates->isNotEmpty())
                <flux:badge size="sm" class="ml-1">{{ $this->interiorTemplates->count() }}</flux:badge>
            @endif
        </flux:button>

        <flux:button
            wire:click="$set('activeScope', 'exterior')"
            :variant="$activeScope === 'exterior' ? 'primary' : 'ghost'"
            icon="sun"
            size="sm"
        >
            {{ __('Exterior') }}
            @if ($this->exteriorTemplates->isNotEmpty())
                <flux:badge size="sm" class="ml-1">{{ $this->exteriorTemplates->count() }}</flux:badge>
            @endif
        </flux:button>
    </div>

    {{-- Room Template Grid --}}
    @if ($this->activeTemplates->isEmpty())
        <div class="py-12 text-center">
            <flux:icon name="squares-2x2" class="mx-auto size-10 text-zinc-400 dark:text-zinc-500" />
            <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
                {{ __('No templates available for :scope.', ['scope' => $activeScope]) }}
            </flux:text>
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach ($this->activeTemplates as $template)
                <div
                    wire:key="template-{{ $template->id }}"
                    x-data="{
                        selectedSize: '{{ $template->metadata['default_size'] ?? 'medium' }}',
                        sizes: {{ Js::from($template->metadata['sizes'] ?? []) }},
                    }"
                    class="relative flex flex-col rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800"
                >
                    {{-- Room Icon & Name --}}
                    <div class="mb-3 flex items-center gap-2">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-700">
                            <flux:icon
                                :name="$template->icon ?? 'squares-2x2'"
                                class="size-5 text-zinc-600 dark:text-zinc-300"
                            />
                        </div>
                        <flux:heading size="sm" class="leading-tight">
                            {{ $template->name }}
                        </flux:heading>
                    </div>

                    {{-- Size Selector --}}
                    @if (! empty($template->metadata['sizes']))
                        <div class="mb-3 flex gap-1">
                            @foreach (['small' => 'S', 'medium' => 'M', 'large' => 'L'] as $sizeKey => $sizeLabel)
                                @if (isset($template->metadata['sizes'][$sizeKey]))
                                    <button
                                        type="button"
                                        x-on:click="selectedSize = '{{ $sizeKey }}'"
                                        :class="selectedSize === '{{ $sizeKey }}'
                                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                            : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600'"
                                        class="flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg px-2 text-sm font-medium transition"
                                    >
                                        {{ $sizeLabel }}
                                    </button>
                                @endif
                            @endforeach
                        </div>

                        {{-- Size Label --}}
                        <div class="mb-3">
                            @foreach ($template->metadata['sizes'] as $sizeKey => $sizeData)
                                <flux:text
                                    x-show="selectedSize === '{{ $sizeKey }}'"
                                    x-cloak
                                    size="sm"
                                    class="text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ $sizeData['label'] ?? ucfirst($sizeKey) }}
                                </flux:text>
                            @endforeach
                        </div>
                    @endif

                    {{-- Add Button --}}
                    <div class="mt-auto">
                        <flux:button
                            x-on:click="$wire.selectRoom({{ $template->id }}, selectedSize)"
                            variant="primary"
                            size="sm"
                            icon="plus"
                            class="w-full min-h-[48px]"
                        >
                            {{ __('Add') }}
                        </flux:button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
