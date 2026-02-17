<?php

use App\Models\TrainingPath;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $showCreateModal = false;

    public string $newPathName = '';

    public string $newPathDescription = '';

    public string $newPathVisibility = 'all_internal';

    public bool $newPathRequired = false;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('media.manage_training'), 403);
    }

    #[Computed]
    public function paths()
    {
        return TrainingPath::query()
            ->withCount('steps')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function createPath(): void
    {
        $this->validate([
            'newPathName' => ['required', 'string', 'max:255'],
            'newPathDescription' => ['nullable', 'string', 'max:1000'],
            'newPathVisibility' => ['required', 'in:employees,crews,all_internal'],
            'newPathRequired' => ['boolean'],
        ]);

        TrainingPath::create([
            'name' => $this->newPathName,
            'description' => $this->newPathDescription ?: null,
            'visibility' => $this->newPathVisibility,
            'is_required' => $this->newPathRequired,
        ]);

        $this->reset('newPathName', 'newPathDescription', 'newPathVisibility', 'newPathRequired', 'showCreateModal');
        unset($this->paths);
    }

    public function toggleActive(int $pathId): void
    {
        $path = TrainingPath::findOrFail($pathId);
        $path->update(['is_active' => ! $path->is_active]);
        unset($this->paths);
    }

    public function deletePath(int $pathId): void
    {
        $path = TrainingPath::findOrFail($pathId);
        $path->delete();
        unset($this->paths);
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <flux:heading size="lg">{{ __('Training Paths') }}</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" icon="chart-bar" :href="route('media.training.dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </flux:button>
            <flux:button size="sm" variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">
                {{ __('New Path') }}
            </flux:button>
        </div>
    </div>

    @if($this->paths->count() > 0)
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach($this->paths as $path)
                <div wire:key="path-{{ $path->id }}" class="px-6 py-4 flex items-center justify-between" data-training-path="{{ $path->name }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <flux:text class="font-medium">{{ $path->name }}</flux:text>
                            @php
                                $visColors = ['employees' => 'blue', 'crews' => 'amber', 'all_internal' => 'green'];
                                $visLabels = ['employees' => __('Employees'), 'crews' => __('Crews'), 'all_internal' => __('All Internal')];
                            @endphp
                            <flux:badge size="sm" color="{{ $visColors[$path->visibility] ?? 'zinc' }}">
                                {{ $visLabels[$path->visibility] ?? ucfirst($path->visibility) }}
                            </flux:badge>
                            @if($path->is_required)
                                <flux:badge size="sm" color="red">{{ __('Required') }}</flux:badge>
                            @endif
                            @unless($path->is_active)
                                <flux:badge size="sm" color="yellow">{{ __('Inactive') }}</flux:badge>
                            @endunless
                        </div>
                        <flux:text class="text-sm text-zinc-500 mt-0.5">
                            {{ $path->steps_count }} {{ trans_choice('step|steps', $path->steps_count) }}
                            @if($path->description)
                                &middot; {{ Str::limit($path->description, 60) }}
                            @endif
                        </flux:text>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 ml-4">
                        <flux:button size="sm" variant="ghost" icon="pencil" :href="route('media.training.manage', $path)" wire:navigate />
                        <flux:button size="sm" variant="ghost" icon="{{ $path->is_active ? 'eye-slash' : 'eye' }}" wire:click="toggleActive({{ $path->id }})" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="deletePath({{ $path->id }})" wire:confirm="{{ __('Delete this training path and all its steps?') }}" />
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
            <flux:icon name="academic-cap" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-3">{{ __('No training paths yet') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Create a training path to organize video-based learning for your team.') }}</flux:text>
        </div>
    @endif

    {{-- Create Path Modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-md">
        <form wire:submit="createPath">
            <flux:heading size="lg" class="mb-4">{{ __('Create Training Path') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="newPathName" placeholder="{{ __('e.g. New Hire Onboarding') }}" autofocus />
                    <flux:error name="newPathName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:textarea wire:model="newPathDescription" rows="3" />
                    <flux:error name="newPathDescription" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Visibility') }}</flux:label>
                    <flux:select wire:model="newPathVisibility">
                        <flux:select.option value="all_internal">{{ __('All Internal') }}</flux:select.option>
                        <flux:select.option value="employees">{{ __('Employees Only') }}</flux:select.option>
                        <flux:select.option value="crews">{{ __('Crews Only') }}</flux:select.option>
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model="newPathRequired" label="{{ __('Required for all assigned users') }}" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
