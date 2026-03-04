<?php

use App\Models\VideoProject;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Studio'])]
class extends Component {
    public string $search = '';

    public function createProject(): void
    {
        $project = VideoProject::create([
            'user_id' => auth()->id(),
            'title' => __('Untitled Project'),
        ]);

        $this->redirect(route('studio.edit', $project), navigate: true);
    }

    public function deleteProject(int $projectId): void
    {
        VideoProject::where('id', $projectId)
            ->where('user_id', auth()->id())
            ->delete();
    }

    public function updatedSearch(): void
    {
        // Reactivity handled by Livewire
    }

    #[Computed]
    public function projects()
    {
        return VideoProject::query()
            ->where('user_id', auth()->id())
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(12);
    }
};
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('Studio') }}</flux:heading>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ __('Create and edit video projects') }}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('studio.live') }}" class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300">
                {{ __('Go Live') }}
            </a>
            <flux:button wire:click="createProject" variant="primary">
                <flux:icon name="plus" class="size-4 mr-1" />
                {{ __('New Project') }}
            </flux:button>
        </div>
    </div>

    {{-- Search --}}
    <div class="mb-6">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search projects...') }}" icon="magnifying-glass" />
    </div>

    {{-- Project Grid --}}
    @if($this->projects->isEmpty())
        <div class="text-center py-16 border-2 border-dashed border-zinc-200 dark:border-zinc-700 rounded-xl">
            <flux:icon name="video-camera" class="mx-auto size-12 text-zinc-400" />
            <h3 class="mt-4 text-lg font-medium text-zinc-900 dark:text-white">{{ __('No projects yet') }}</h3>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Create your first video project to get started.') }}</p>
            <div class="mt-4">
                <flux:button wire:click="createProject" variant="primary" size="sm">
                    <flux:icon name="plus" class="size-4 mr-1" />
                    {{ __('New Project') }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($this->projects as $project)
                <div wire:key="project-{{ $project->id }}" class="group border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden hover:border-blue-500 dark:hover:border-blue-400 transition-colors">
                    {{-- Thumbnail --}}
                    <a href="{{ route('studio.edit', $project) }}" wire:navigate class="block">
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 relative">
                            @if($project->thumbnail_path)
                                <img src="{{ $project->thumbnail_path }}" alt="{{ $project->title }}" class="w-full h-full object-cover" loading="lazy" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="film" class="size-10 text-zinc-400" />
                                </div>
                            @endif

                            {{-- Status badge --}}
                            <div class="absolute top-2 right-2">
                                @if($project->isDraft())
                                    <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                                @elseif($project->isRendering())
                                    <flux:badge size="sm" color="yellow">
                                        <span class="animate-pulse">{{ __('Rendering') }}</span>
                                    </flux:badge>
                                @elseif($project->isRendered())
                                    <flux:badge size="sm" color="green">{{ __('Rendered') }}</flux:badge>
                                @elseif($project->isFailed())
                                    <flux:badge size="sm" color="red">{{ __('Failed') }}</flux:badge>
                                @endif
                            </div>

                            {{-- Scene count --}}
                            @if($project->scenes_count ?? 0)
                                <span class="absolute bottom-2 right-2 bg-black/75 text-white text-xs px-1.5 py-0.5 rounded">
                                    {{ $project->scenes_count }} {{ __('scenes') }}
                                </span>
                            @endif
                        </div>
                    </a>

                    {{-- Info --}}
                    <div class="p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('studio.edit', $project) }}" wire:navigate class="text-sm font-medium text-zinc-900 dark:text-white truncate block hover:text-blue-600 dark:hover:text-blue-400">
                                    {{ $project->title }}
                                </a>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                    {{ $project->created_at->diffForHumans() }}
                                    @if($project->duration_seconds)
                                        &middot; {{ gmdate('i:s', (int) $project->duration_seconds) }}
                                    @endif
                                </p>
                            </div>
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" class="opacity-0 group-hover:opacity-100 transition-opacity" />
                                <flux:menu>
                                    <flux:menu.item href="{{ route('studio.edit', $project) }}" wire:navigate icon="pencil">
                                        {{ __('Edit') }}
                                    </flux:menu.item>
                                    <flux:menu.item wire:click="deleteProject({{ $project->id }})" wire:confirm="{{ __('Delete this project? This cannot be undone.') }}" icon="trash" class="text-red-600 dark:text-red-400">
                                        {{ __('Delete') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $this->projects->links() }}
        </div>
    @endif
</div>
