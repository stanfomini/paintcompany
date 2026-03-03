<?php

use App\Models\Deal;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app', ['title' => 'Studio'])]
class extends Component {
    use WithPagination;

    #[Url]
    public string $activeTab = 'deals';

    #[Url]
    public string $search = '';

    public bool $showTemplatePicker = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
        $this->search = '';
    }

    public function createBlank(): void
    {
        $project = VideoProject::create([
            'user_id' => auth()->id(),
            'title' => __('Untitled Project'),
            'status' => 'draft',
            'creation_mode' => 'quick',
            'aspect_ratio' => '9:16',
        ]);

        $this->redirect(route('studio.create', $project), navigate: true);
    }

    public function openTemplates(): void
    {
        $this->showTemplatePicker = true;
    }

    #[On('ai-project-created')]
    public function onAiProjectCreated(int $projectId): void
    {
        $this->redirect(route('studio.create', $projectId), navigate: true);
    }

    #[Computed]
    public function deals()
    {
        return Deal::query()
            ->with(['property.photos', 'estimates.photos', 'job.videoRecordings'])
            ->search($this->search)
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function videoProjects()
    {
        return VideoProject::query()
            ->where('user_id', auth()->id())
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function recordings()
    {
        return VideoRecording::query()
            ->where('user_id', auth()->id())
            ->where('status', 'ready')
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function mediaHub()
    {
        return VideoRecording::query()
            ->where('status', 'ready')
            ->where('available_in_hub', true)
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
    }
};
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('Studio') }}</flux:heading>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ __('Browse deals, videos, and media') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button wire:click="openTemplates" variant="ghost" icon="rectangle-stack">
                {{ __('Templates') }}
            </flux:button>
            <flux:button wire:click="createBlank" variant="primary">
                <flux:icon name="plus" class="size-4 mr-1" />
                {{ __('Create') }}
            </flux:button>
        </div>
    </div>

    {{-- AI Prompt Bar --}}
    <div class="mb-6">
        <livewire:studio.ai-prompt-bar />
    </div>

    {{-- Template Picker Modal --}}
    <livewire:studio.template-picker :show="$showTemplatePicker" />

    {{-- Tabs --}}
    <div class="flex flex-wrap items-center gap-2 mb-6 sticky top-0 z-10 bg-white dark:bg-zinc-950 py-2 -mx-4 px-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 border-b border-zinc-200 dark:border-zinc-700">
        @foreach([
            'deals' => __('Deals'),
            'my_videos' => __('My Videos'),
            'recordings' => __('Recordings'),
            'media_hub' => __('Media Hub'),
        ] as $tab => $label)
            <button
                wire:click="$set('activeTab', '{{ $tab }}')"
                wire:key="tab-{{ $tab }}"
                class="px-4 py-2 text-sm font-medium rounded-full transition-colors {{ $activeTab === $tab ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Search --}}
    <div class="mb-6">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search...') }}" icon="magnifying-glass" />
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'deals')
        @if($this->deals->isEmpty())
            <div class="text-center py-16 border-2 border-dashed border-zinc-200 dark:border-zinc-700 rounded-xl">
                <flux:icon name="briefcase" class="mx-auto size-12 text-zinc-400" />
                <h3 class="mt-4 text-lg font-medium text-zinc-900 dark:text-white">{{ __('No deals found') }}</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Deals with photos and videos will appear here.') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($this->deals as $deal)
                    <div wire:key="deal-{{ $deal->id }}" class="group border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden hover:border-blue-500 dark:hover:border-blue-400 transition-colors">
                        {{-- Thumbnail strip --}}
                        @php
                            $photos = collect();
                            if ($deal->property) {
                                $photos = $photos->merge($deal->property->photos);
                            }
                            foreach ($deal->estimates as $estimate) {
                                $photos = $photos->merge($estimate->photos);
                            }
                            $photos = $photos->take(4);
                            $videoCount = $deal->job ? $deal->job->videoRecordings->count() : 0;
                        @endphp
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 relative">
                            @if($photos->isNotEmpty())
                                <div class="grid {{ $photos->count() >= 4 ? 'grid-cols-2 grid-rows-2' : ($photos->count() >= 2 ? 'grid-cols-2' : '') }} w-full h-full">
                                    @foreach($photos as $photo)
                                        <img src="{{ $photo->url }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                                    @endforeach
                                </div>
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="photo" class="size-10 text-zinc-400" />
                                </div>
                            @endif
                            {{-- Counts --}}
                            <div class="absolute bottom-2 right-2 flex items-center gap-1">
                                @if($photos->count() > 0)
                                    <span class="bg-black/75 text-white text-xs px-1.5 py-0.5 rounded flex items-center gap-1">
                                        <flux:icon name="photo" class="size-3" />
                                        {{ $photos->count() }}
                                    </span>
                                @endif
                                @if($videoCount > 0)
                                    <span class="bg-black/75 text-white text-xs px-1.5 py-0.5 rounded flex items-center gap-1">
                                        <flux:icon name="video-camera" class="size-3" />
                                        {{ $videoCount }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        {{-- Info --}}
                        <div class="p-3">
                            <h3 class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $deal->title }}</h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $deal->created_at->diffForHumans() }}</p>
                            <div class="mt-2">
                                <a href="{{ route('studio.create-from-deal', $deal) }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">
                                    {{ __('Create video') }} &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-6">{{ $this->deals->links() }}</div>
        @endif

    @elseif($activeTab === 'my_videos')
        @if($this->videoProjects->isEmpty())
            <div class="text-center py-16 border-2 border-dashed border-zinc-200 dark:border-zinc-700 rounded-xl">
                <flux:icon name="film" class="mx-auto size-12 text-zinc-400" />
                <h3 class="mt-4 text-lg font-medium text-zinc-900 dark:text-white">{{ __('No video projects yet') }}</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Create your first video project.') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($this->videoProjects as $project)
                    <div wire:key="project-{{ $project->id }}" class="group border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden hover:border-blue-500 dark:hover:border-blue-400 transition-colors">
                        <a href="{{ route('studio.edit', $project) }}" wire:navigate class="block">
                            <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 relative">
                                @if($project->thumbnail_path)
                                    <img src="{{ $project->thumbnail_path }}" alt="{{ $project->title }}" class="w-full h-full object-cover" loading="lazy" />
                                @else
                                    <div class="w-full h-full flex items-center justify-center">
                                        <flux:icon name="film" class="size-10 text-zinc-400" />
                                    </div>
                                @endif
                                <div class="absolute top-2 right-2">
                                    @if($project->isDraft())
                                        <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                                    @elseif($project->isRendering())
                                        <flux:badge size="sm" color="yellow"><span class="animate-pulse">{{ __('Rendering') }}</span></flux:badge>
                                    @elseif($project->isRendered())
                                        <flux:badge size="sm" color="green">{{ __('Rendered') }}</flux:badge>
                                    @elseif($project->isFailed())
                                        <flux:badge size="sm" color="red">{{ __('Failed') }}</flux:badge>
                                    @endif
                                </div>
                                @if($project->creation_mode !== 'classic')
                                    <div class="absolute top-2 left-2">
                                        <flux:badge size="sm" color="blue">{{ ucfirst(str_replace('_', ' ', $project->creation_mode)) }}</flux:badge>
                                    </div>
                                @endif
                            </div>
                        </a>
                        <div class="p-3">
                            <a href="{{ route('studio.edit', $project) }}" wire:navigate class="text-sm font-medium text-zinc-900 dark:text-white truncate block hover:text-blue-600 dark:hover:text-blue-400">
                                {{ $project->title }}
                            </a>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $project->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-6">{{ $this->videoProjects->links() }}</div>
        @endif

    @elseif($activeTab === 'recordings')
        @if($this->recordings->isEmpty())
            <div class="text-center py-16 border-2 border-dashed border-zinc-200 dark:border-zinc-700 rounded-xl">
                <flux:icon name="video-camera" class="mx-auto size-12 text-zinc-400" />
                <h3 class="mt-4 text-lg font-medium text-zinc-900 dark:text-white">{{ __('No recordings yet') }}</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Your ready recordings will appear here.') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($this->recordings as $recording)
                    <div wire:key="rec-{{ $recording->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden hover:border-blue-500 dark:hover:border-blue-400 transition-colors">
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 relative">
                            @if($recording->getThumbnailUrl())
                                <img src="{{ $recording->getThumbnailUrl() }}" alt="{{ $recording->title }}" class="w-full h-full object-cover" loading="lazy" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="video-camera" class="size-10 text-zinc-400" />
                                </div>
                            @endif
                            @if($recording->formatted_duration)
                                <span class="absolute bottom-2 right-2 bg-black/75 text-white text-xs px-1.5 py-0.5 rounded">
                                    {{ $recording->formatted_duration }}
                                </span>
                            @endif
                        </div>
                        <div class="p-3">
                            <h3 class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $recording->title }}</h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $recording->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-6">{{ $this->recordings->links() }}</div>
        @endif

    @elseif($activeTab === 'media_hub')
        @if($this->mediaHub->isEmpty())
            <div class="text-center py-16 border-2 border-dashed border-zinc-200 dark:border-zinc-700 rounded-xl">
                <flux:icon name="squares-2x2" class="mx-auto size-12 text-zinc-400" />
                <h3 class="mt-4 text-lg font-medium text-zinc-900 dark:text-white">{{ __('Media hub is empty') }}</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Videos shared to the hub will appear here.') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($this->mediaHub as $video)
                    <div wire:key="hub-{{ $video->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden hover:border-blue-500 dark:hover:border-blue-400 transition-colors">
                        <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 relative">
                            @if($video->getThumbnailUrl())
                                <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" loading="lazy" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="video-camera" class="size-10 text-zinc-400" />
                                </div>
                            @endif
                            @if($video->formatted_duration)
                                <span class="absolute bottom-2 right-2 bg-black/75 text-white text-xs px-1.5 py-0.5 rounded">
                                    {{ $video->formatted_duration }}
                                </span>
                            @endif
                        </div>
                        <div class="p-3">
                            <h3 class="text-sm font-medium text-zinc-900 dark:text-white truncate">{{ $video->title }}</h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $video->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-6">{{ $this->mediaHub->links() }}</div>
        @endif
    @endif
</div>
