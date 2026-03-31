<?php

use App\Models\Prospector\Contact;
use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Pipeline View')]
class extends Component
{
    #[Url]
    public ?int $selectedPipelineId = null;

    public string $search = '';
    public string $sortBy = 'name';

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(): void
    {
        if (! $this->selectedPipelineId) {
            $default = Pipeline::where('is_default', true)->first();
            $this->selectedPipelineId = $default?->id ?? Pipeline::first()?->id;
        }
    }

    #[Computed]
    public function pipelineOptions(): Collection
    {
        return Pipeline::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function stages(): Collection
    {
        if (! $this->selectedPipelineId) {
            return collect();
        }

        return PipelineStage::query()
            ->where('pipeline_id', $this->selectedPipelineId)
            ->withCount('contacts')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function contactsByStage(): array
    {
        if (! $this->selectedPipelineId) {
            return [];
        }

        $query = Contact::query()
            ->whereHas('pipelineStage', fn ($q) => $q->where('pipeline_id', $this->selectedPipelineId))
            ->with(['company', 'pipelineStage']);

        if ($this->search) {
            $query->search($this->search);
        }

        $query->when($this->sortBy === 'name', fn ($q) => $q->orderBy('first_name')->orderBy('last_name'));
        $query->when($this->sortBy === 'last_contacted', fn ($q) => $q->orderByDesc('last_contacted_at'));
        $query->when($this->sortBy === 'follow_up', fn ($q) => $q->orderBy('follow_up_at'));
        $query->when($this->sortBy === 'priority', fn ($q) => $q->orderByDesc('priority'));

        $contacts = $query->get();

        $grouped = [];
        foreach ($this->stages as $stage) {
            $grouped[$stage->id] = $contacts->where('pipeline_stage_id', $stage->id)->values();
        }

        return $grouped;
    }

    public function moveContact(int $contactId, int $stageId): void
    {
        $stage = PipelineStage::where('pipeline_id', $this->selectedPipelineId)->findOrFail($stageId);
        Contact::findOrFail($contactId)->update(['pipeline_stage_id' => $stage->id]);
        unset($this->contactsByStage, $this->stages);
    }

    public function updatedSelectedPipelineId(): void
    {
        unset($this->stages, $this->contactsByStage);
    }

    public function updatedSearch(): void
    {
        unset($this->contactsByStage);
    }

    public function updatedSortBy(): void
    {
        unset($this->contactsByStage);
    }
}; ?>

<div>
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Pipeline View') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Drag contacts between stages to update their status.') }}</flux:text>
            </div>
            <flux:button variant="ghost" icon="cog-6-tooth" :href="route('prospector.pipelines')" wire:navigate>
                {{ __('Manage Pipelines') }}
            </flux:button>
        </div>

        <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-center">
            <div class="sm:w-60">
                <flux:select wire:model.live="selectedPipelineId">
                    @foreach ($this->pipelineOptions as $pipeline)
                        <flux:select.option value="{{ $pipeline->id }}">{{ $pipeline->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    :placeholder="__('Search contacts...')"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="sortBy">
                    <flux:select.option value="name">{{ __('Sort by Name') }}</flux:select.option>
                    <flux:select.option value="last_contacted">{{ __('Last Contacted') }}</flux:select.option>
                    <flux:select.option value="follow_up">{{ __('Follow-Up Date') }}</flux:select.option>
                    <flux:select.option value="priority">{{ __('Priority') }}</flux:select.option>
                </flux:select>
            </div>
        </div>
    </div>

    @if (! $this->selectedPipelineId || $this->stages->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="funnel" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No pipeline selected or no stages configured') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create a pipeline with stages to see the kanban board.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" :href="route('prospector.pipelines')" wire:navigate>
                    {{ __('Manage Pipelines') }}
                </flux:button>
            </div>
        </div>
    @else
        <div
            class="overflow-x-auto pb-4"
            x-data="{
                async initKanban() {
                    const Sortable = await window.loadSortable();
                    this.$el.querySelectorAll('.kanban-column').forEach(column => {
                        Sortable.create(column, {
                            group: 'pipeline-kanban',
                            handle: '.contact-handle',
                            animation: 150,
                            ghostClass: 'opacity-30',
                            onEnd: (evt) => {
                                const contactId = parseInt(evt.item.dataset.contactId);
                                const newStageId = parseInt(evt.to.dataset.stageId);
                                $wire.moveContact(contactId, newStageId);
                            }
                        });
                    });
                }
            }"
            x-init="initKanban()"
            wire:ignore.self
        >
            <div class="flex gap-4" style="min-width: max-content;">
                @foreach ($this->stages as $stage)
                    <div wire:key="stage-col-{{ $stage->id }}" class="w-80 flex-shrink-0">
                        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
                            {{-- Stage Header --}}
                            <div class="px-4 py-3" style="background-color: {{ $stage->color ?? '#6b7280' }}">
                                <div class="flex items-center justify-between">
                                    <flux:heading size="sm" class="font-semibold text-white">
                                        {{ $stage->name }}
                                    </flux:heading>
                                    <flux:badge size="sm" class="bg-white/20 text-white">
                                        {{ $stage->contacts_count }}
                                    </flux:badge>
                                </div>
                            </div>

                            {{-- Contact Cards --}}
                            <div
                                class="kanban-column min-h-[200px] space-y-2 p-2"
                                data-stage-id="{{ $stage->id }}"
                            >
                                @forelse (($this->contactsByStage[$stage->id] ?? collect()) as $contact)
                                    <div
                                        wire:key="contact-{{ $contact->id }}"
                                        data-contact-id="{{ $contact->id }}"
                                        class="rounded-lg border bg-white shadow-sm transition-shadow hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800
                                            {{ $contact->wasContactedToday() ? 'border-green-300 dark:border-green-700' : 'border-zinc-200' }}"
                                    >
                                        <div class="flex items-start gap-2 p-3">
                                            <div class="contact-handle shrink-0 cursor-grab pt-0.5 text-zinc-400 hover:text-zinc-600 active:cursor-grabbing dark:text-zinc-500 dark:hover:text-zinc-300">
                                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-6 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <flux:text class="truncate text-sm font-medium">
                                                    {{ $contact->getFullName() }}
                                                </flux:text>

                                                <div class="mt-1 flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                    <flux:icon name="phone" variant="micro" />
                                                    <span class="font-mono">{{ $contact->getDisplayPhone() }}</span>
                                                </div>

                                                @if ($contact->company)
                                                    <div class="mt-1 flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                        <flux:icon name="building-office" variant="micro" />
                                                        {{ $contact->company->name }}
                                                    </div>
                                                @endif

                                                <div class="mt-2 flex flex-wrap items-center gap-1">
                                                    @if ($contact->wasContactedToday())
                                                        <flux:badge size="sm" color="green">{{ __('Today') }}</flux:badge>
                                                    @elseif ($contact->last_contacted_at)
                                                        <flux:badge size="sm" color="zinc">{{ $contact->last_contacted_at->diffForHumans(short: true) }}</flux:badge>
                                                    @endif

                                                    @if ($contact->follow_up_at)
                                                        @php
                                                            $isOverdue = $contact->follow_up_at->isPast() && ! $contact->follow_up_at->isToday();
                                                            $isDueToday = $contact->follow_up_at->isToday();
                                                        @endphp
                                                        <flux:badge size="sm" :color="$isOverdue ? 'red' : ($isDueToday ? 'amber' : 'blue')">
                                                            <flux:icon name="calendar" class="mr-0.5 h-3 w-3" />
                                                            {{ $contact->follow_up_at->format('M j') }}
                                                        </flux:badge>
                                                    @endif

                                                    @if ($contact->last_disposition)
                                                        <flux:badge size="sm" color="zinc">{{ $contact->last_disposition }}</flux:badge>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="py-8 text-center">
                                        <flux:text class="text-xs text-zinc-400">{{ __('No contacts') }}</flux:text>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
