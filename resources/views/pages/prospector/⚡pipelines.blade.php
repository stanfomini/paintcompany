<?php

use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Pipeline Management')]
class extends Component
{
    // Pipeline form
    public bool $showPipelineModal = false;
    public ?int $editingPipelineId = null;
    public string $pipelineName = '';
    public string $pipelineColor = '#3b82f6';

    // Stage form
    public bool $showStageModal = false;
    public ?int $stagePipelineId = null;
    public ?int $editingStageId = null;
    public string $stageName = '';
    public string $stageColor = '#6b7280';

    // Delete confirmation
    public bool $showDeletePipelineModal = false;
    public ?int $deletingPipelineId = null;
    public string $deletingPipelineName = '';

    public bool $showDeleteStageModal = false;
    public ?int $deletingStageId = null;
    public string $deletingStageName = '';

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function pipelines(): \Illuminate\Database\Eloquent\Collection
    {
        return Pipeline::query()
            ->withCount('stages')
            ->with(['stages' => fn ($q) => $q->withCount('contacts')->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    // ──────────────────────────────────────
    // Pipeline CRUD
    // ──────────────────────────────────────

    public function openCreatePipeline(): void
    {
        $this->resetPipelineForm();
        $this->showPipelineModal = true;
    }

    public function openEditPipeline(int $pipelineId): void
    {
        $pipeline = Pipeline::findOrFail($pipelineId);
        $this->editingPipelineId = $pipeline->id;
        $this->pipelineName = $pipeline->name;
        $this->pipelineColor = $pipeline->color ?? '#3b82f6';
        $this->showPipelineModal = true;
    }

    public function savePipeline(): void
    {
        $this->validate([
            'pipelineName' => ['required', 'string', 'max:255'],
            'pipelineColor' => ['required', 'string', 'max:7'],
        ]);

        if ($this->editingPipelineId) {
            $pipeline = Pipeline::findOrFail($this->editingPipelineId);
            $pipeline->update([
                'name' => $this->pipelineName,
                'color' => $this->pipelineColor,
            ]);
        } else {
            $maxSort = Pipeline::max('sort_order') ?? -1;
            Pipeline::create([
                'name' => $this->pipelineName,
                'color' => $this->pipelineColor,
                'sort_order' => $maxSort + 1,
            ]);
        }

        $this->closePipelineModal();
        unset($this->pipelines);
    }

    public function confirmDeletePipeline(int $pipelineId): void
    {
        $pipeline = Pipeline::findOrFail($pipelineId);
        $this->deletingPipelineId = $pipeline->id;
        $this->deletingPipelineName = $pipeline->name;
        $this->showDeletePipelineModal = true;
    }

    public function deletePipeline(): void
    {
        if (! $this->deletingPipelineId) {
            return;
        }

        Pipeline::findOrFail($this->deletingPipelineId)->delete();
        $this->showDeletePipelineModal = false;
        $this->deletingPipelineId = null;
        $this->deletingPipelineName = '';
        unset($this->pipelines);
    }

    public function setDefault(int $pipelineId): void
    {
        Pipeline::where('is_default', true)->update(['is_default' => false]);
        Pipeline::where('id', $pipelineId)->update(['is_default' => true]);
        unset($this->pipelines);
    }

    public function closePipelineModal(): void
    {
        $this->showPipelineModal = false;
        $this->resetPipelineForm();
    }

    protected function resetPipelineForm(): void
    {
        $this->editingPipelineId = null;
        $this->pipelineName = '';
        $this->pipelineColor = '#3b82f6';
    }

    // ──────────────────────────────────────
    // Stage CRUD
    // ──────────────────────────────────────

    public function openCreateStage(int $pipelineId): void
    {
        $this->resetStageForm();
        $this->stagePipelineId = $pipelineId;
        $this->showStageModal = true;
    }

    public function openEditStage(int $stageId): void
    {
        $stage = PipelineStage::findOrFail($stageId);
        $this->editingStageId = $stage->id;
        $this->stagePipelineId = $stage->pipeline_id;
        $this->stageName = $stage->name;
        $this->stageColor = $stage->color ?? '#6b7280';
        $this->showStageModal = true;
    }

    public function saveStage(): void
    {
        $this->validate([
            'stageName' => ['required', 'string', 'max:255'],
            'stageColor' => ['required', 'string', 'max:7'],
            'stagePipelineId' => ['required', 'integer', 'exists:prospector_pipelines,id'],
        ]);

        if ($this->editingStageId) {
            PipelineStage::findOrFail($this->editingStageId)->update([
                'name' => $this->stageName,
                'color' => $this->stageColor,
            ]);
        } else {
            $maxSort = PipelineStage::where('pipeline_id', $this->stagePipelineId)->max('sort_order') ?? -1;
            PipelineStage::create([
                'pipeline_id' => $this->stagePipelineId,
                'name' => $this->stageName,
                'color' => $this->stageColor,
                'sort_order' => $maxSort + 1,
            ]);
        }

        $this->closeStageModal();
        unset($this->pipelines);
    }

    public function confirmDeleteStage(int $stageId): void
    {
        $stage = PipelineStage::findOrFail($stageId);
        $this->deletingStageId = $stage->id;
        $this->deletingStageName = $stage->name;
        $this->showDeleteStageModal = true;
    }

    public function deleteStage(): void
    {
        if (! $this->deletingStageId) {
            return;
        }

        PipelineStage::findOrFail($this->deletingStageId)->delete();
        $this->showDeleteStageModal = false;
        $this->deletingStageId = null;
        $this->deletingStageName = '';
        unset($this->pipelines);
    }

    public function reorderStages(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            PipelineStage::where('id', $id)->update(['sort_order' => $index]);
        }
        unset($this->pipelines);
    }

    public function closeStageModal(): void
    {
        $this->showStageModal = false;
        $this->resetStageForm();
    }

    protected function resetStageForm(): void
    {
        $this->editingStageId = null;
        $this->stagePipelineId = null;
        $this->stageName = '';
        $this->stageColor = '#6b7280';
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Pipelines') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage sales pipelines and stages for tracking contacts.') }}</flux:text>
        </div>
        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="eye" :href="route('prospector.pipeline-view')" wire:navigate>
                {{ __('Kanban View') }}
            </flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openCreatePipeline">
                {{ __('New Pipeline') }}
            </flux:button>
        </div>
    </div>

    @if ($this->pipelines->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="funnel" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No pipelines yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create your first pipeline to start organizing contacts into stages.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" icon="plus" wire:click="openCreatePipeline">
                    {{ __('Create Pipeline') }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="space-y-6">
            @foreach ($this->pipelines as $pipeline)
                <div wire:key="pipeline-{{ $pipeline->id }}" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    {{-- Pipeline Header --}}
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <div class="flex items-center gap-3">
                            <div class="size-4 rounded-full" style="background-color: {{ $pipeline->color ?? '#3b82f6' }}"></div>
                            <flux:heading size="lg">{{ $pipeline->name }}</flux:heading>
                            @if ($pipeline->is_default)
                                <flux:badge size="sm" color="green">{{ __('Default') }}</flux:badge>
                            @endif
                            <flux:badge size="sm" color="zinc">
                                {{ $pipeline->stages_count }} {{ __('stages') }}
                            </flux:badge>
                        </div>
                        <div class="flex items-center gap-1">
                            @if (! $pipeline->is_default)
                                <flux:button size="sm" variant="ghost" icon="star" wire:click="setDefault({{ $pipeline->id }})" title="{{ __('Set as Default') }}" />
                            @endif
                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditPipeline({{ $pipeline->id }})" title="{{ __('Edit Pipeline') }}" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeletePipeline({{ $pipeline->id }})" title="{{ __('Delete Pipeline') }}" />
                        </div>
                    </div>

                    {{-- Stages --}}
                    <div class="p-4">
                        @if ($pipeline->stages->isEmpty())
                            <div class="py-6 text-center">
                                <flux:text class="text-zinc-500">{{ __('No stages yet.') }}</flux:text>
                            </div>
                        @else
                            <div
                                class="space-y-2"
                                x-data="{
                                    async initSortable() {
                                        const Sortable = await window.loadSortable();
                                        Sortable.create(this.$el, {
                                            handle: '.stage-handle',
                                            animation: 150,
                                            onEnd: (evt) => {
                                                const ids = [...evt.from.children].map(el => parseInt(el.dataset.stageId));
                                                $wire.reorderStages(ids);
                                            }
                                        });
                                    }
                                }"
                                x-init="initSortable()"
                            >
                                @foreach ($pipeline->stages as $stage)
                                    <div
                                        wire:key="stage-{{ $stage->id }}"
                                        data-stage-id="{{ $stage->id }}"
                                        class="flex items-center justify-between rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-700/50"
                                    >
                                        <div class="flex items-center gap-3">
                                            <div class="stage-handle cursor-grab text-zinc-400 hover:text-zinc-600 active:cursor-grabbing dark:text-zinc-500 dark:hover:text-zinc-300">
                                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-6 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                            </div>
                                            <div class="size-3 rounded-full" style="background-color: {{ $stage->color ?? '#6b7280' }}"></div>
                                            <flux:text class="font-medium">{{ $stage->name }}</flux:text>
                                            <flux:badge size="sm" color="zinc">
                                                {{ $stage->contacts_count }} {{ __('contacts') }}
                                            </flux:badge>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditStage({{ $stage->id }})" title="{{ __('Edit Stage') }}" />
                                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDeleteStage({{ $stage->id }})" title="{{ __('Delete Stage') }}" />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-3">
                            <flux:button size="sm" variant="ghost" icon="plus" wire:click="openCreateStage({{ $pipeline->id }})">
                                {{ __('Add Stage') }}
                            </flux:button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Pipeline Create/Edit Modal --}}
    <flux:modal wire:model="showPipelineModal" class="max-w-md">
        <form wire:submit="savePipeline" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingPipelineId ? __('Edit Pipeline') : __('New Pipeline') }}
                </flux:heading>
            </div>

            <flux:input wire:model="pipelineName" :label="__('Pipeline Name')" :placeholder="__('e.g., Sales Pipeline')" required autofocus />

            <div>
                <flux:label>{{ __('Color') }}</flux:label>
                <input type="color" wire:model="pipelineColor" class="mt-1 h-10 w-20 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600" />
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closePipelineModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">{{ $editingPipelineId ? __('Update') : __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Stage Create/Edit Modal --}}
    <flux:modal wire:model="showStageModal" class="max-w-md">
        <form wire:submit="saveStage" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $editingStageId ? __('Edit Stage') : __('New Stage') }}
                </flux:heading>
            </div>

            <flux:input wire:model="stageName" :label="__('Stage Name')" :placeholder="__('e.g., New Lead')" required autofocus />

            <div>
                <flux:label>{{ __('Color') }}</flux:label>
                <input type="color" wire:model="stageColor" class="mt-1 h-10 w-20 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600" />
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeStageModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">{{ $editingStageId ? __('Update') : __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Pipeline Confirmation --}}
    <flux:modal wire:model="showDeletePipelineModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Pipeline') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Delete') }} <strong>{{ $deletingPipelineName }}</strong>{{ __('? All stages will be removed and contacts will be unassigned from this pipeline.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeletePipelineModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deletePipeline" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete Stage Confirmation --}}
    <flux:modal wire:model="showDeleteStageModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Stage') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Delete') }} <strong>{{ $deletingStageName }}</strong>{{ __('? Contacts in this stage will be unassigned.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteStageModal', false)" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteStage" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
