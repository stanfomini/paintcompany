<?php

use App\Models\Estimate;
use App\Models\Job;
use App\Models\PropertyPhoto;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $activeTab = 'starred';
    public string $search = '';
    public int $page = 1;
    public bool $show = true;

    public function updatedShow(): void
    {
        if (! $this->show) {
            $this->dispatch('media-picker-closed');
        }
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedActiveTab(): void
    {
        $this->search = '';
        $this->page = 1;
    }

    #[Computed]
    public function starredVideos(): \Illuminate\Database\Eloquent\Collection
    {
        return VideoRecording::starredBy(auth()->user())
            ->where('status', 'ready')
            ->latest()
            ->get();
    }

    #[Computed]
    public function starredPhotos(): \Illuminate\Database\Eloquent\Collection
    {
        return PropertyPhoto::starredBy(auth()->user())
            ->latest()
            ->get();
    }

    #[Computed]
    public function myVideos(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return VideoRecording::where('user_id', auth()->id())
            ->where('status', 'ready')
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(12);
    }

    #[Computed]
    public function jobVideos(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return VideoRecording::whereHasMorph('recordable', [Job::class])
            ->where('status', 'ready')
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(12);
    }

    #[Computed]
    public function photos(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return PropertyPhoto::query()
            ->when($this->search, fn ($q) => $q->where('caption', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(12);
    }

    public function selectMedia(string $type, int $id): void
    {
        $this->dispatch('media-selected', type: $type, id: $id);
        $this->show = false;
    }
};
?>

<div>
    <flux:modal wire:model="show" class="max-w-4xl">
        <div class="p-4">
            <flux:heading size="lg" class="mb-4">{{ __('Select Media') }}</flux:heading>

            {{-- Tab bar --}}
            <div class="flex gap-2 mb-4 border-b border-zinc-200 dark:border-zinc-700 pb-3">
                <flux:button
                    size="sm"
                    :variant="$activeTab === 'starred' ? 'filled' : 'ghost'"
                    icon="star"
                    wire:click="$set('activeTab', 'starred')"
                >
                    {{ __('Starred') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$activeTab === 'my_videos' ? 'filled' : 'ghost'"
                    icon="video-camera"
                    wire:click="$set('activeTab', 'my_videos')"
                >
                    {{ __('My Videos') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$activeTab === 'job_videos' ? 'filled' : 'ghost'"
                    icon="briefcase"
                    wire:click="$set('activeTab', 'job_videos')"
                >
                    {{ __('Job Videos') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$activeTab === 'photos' ? 'filled' : 'ghost'"
                    icon="photo"
                    wire:click="$set('activeTab', 'photos')"
                >
                    {{ __('Photos') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$activeTab === 'upload' ? 'filled' : 'ghost'"
                    icon="arrow-up-tray"
                    wire:click="$set('activeTab', 'upload')"
                >
                    {{ __('Upload') }}
                </flux:button>
            </div>

            {{-- Search --}}
            @if(in_array($activeTab, ['my_videos', 'job_videos', 'photos']))
                <div class="mb-4">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search...') }}"
                        icon="magnifying-glass"
                    />
                </div>
            @endif

            {{-- Content --}}
            <div class="min-h-[300px]">
                @if($activeTab === 'starred')
                    @php
                        $hasStarred = $this->starredVideos->isNotEmpty() || $this->starredPhotos->isNotEmpty();
                    @endphp
                    @if($hasStarred)
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->starredVideos as $video)
                                <x-studio.media-picker-card
                                    wire:key="starred-video-{{ $video->id }}"
                                    type="App\Models\VideoRecording"
                                    :id="$video->id"
                                    :title="$video->title"
                                    :thumbnail-url="$video->getThumbnailUrl()"
                                    :duration="$video->formatted_duration"
                                    :is-video="true"
                                />
                            @endforeach
                            @foreach($this->starredPhotos as $photo)
                                <x-studio.media-picker-card
                                    wire:key="starred-photo-{{ $photo->id }}"
                                    type="App\Models\PropertyPhoto"
                                    :id="$photo->id"
                                    :title="$photo->caption ?? ''"
                                    :thumbnail-url="$photo->url"
                                    :is-video="false"
                                />
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="star" class="h-10 w-10 mb-2" />
                            <p>{{ __('No starred media yet.') }}</p>
                            <p class="text-sm">{{ __('Star videos or photos to find them quickly here.') }}</p>
                        </div>
                    @endif

                @elseif($activeTab === 'my_videos')
                    @if($this->myVideos->isNotEmpty())
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->myVideos as $video)
                                <x-studio.media-picker-card
                                    wire:key="my-video-{{ $video->id }}"
                                    type="App\Models\VideoRecording"
                                    :id="$video->id"
                                    :title="$video->title"
                                    :thumbnail-url="$video->getThumbnailUrl()"
                                    :duration="$video->formatted_duration"
                                    :is-video="true"
                                />
                            @endforeach
                        </div>
                        <div class="mt-4">
                            {{ $this->myVideos->links() }}
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="video-camera" class="h-10 w-10 mb-2" />
                            <p>{{ __('No videos found.') }}</p>
                        </div>
                    @endif

                @elseif($activeTab === 'job_videos')
                    @if($this->jobVideos->isNotEmpty())
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->jobVideos as $video)
                                <x-studio.media-picker-card
                                    wire:key="job-video-{{ $video->id }}"
                                    type="App\Models\VideoRecording"
                                    :id="$video->id"
                                    :title="$video->title"
                                    :thumbnail-url="$video->getThumbnailUrl()"
                                    :duration="$video->formatted_duration"
                                    :is-video="true"
                                />
                            @endforeach
                        </div>
                        <div class="mt-4">
                            {{ $this->jobVideos->links() }}
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="briefcase" class="h-10 w-10 mb-2" />
                            <p>{{ __('No job videos found.') }}</p>
                        </div>
                    @endif

                @elseif($activeTab === 'photos')
                    @if($this->photos->isNotEmpty())
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->photos as $photo)
                                <x-studio.media-picker-card
                                    wire:key="photo-{{ $photo->id }}"
                                    type="App\Models\PropertyPhoto"
                                    :id="$photo->id"
                                    :title="$photo->caption ?? ''"
                                    :thumbnail-url="$photo->url"
                                    :is-video="false"
                                />
                            @endforeach
                        </div>
                        <div class="mt-4">
                            {{ $this->photos->links() }}
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="photo" class="h-10 w-10 mb-2" />
                            <p>{{ __('No photos found.') }}</p>
                        </div>
                    @endif

                @elseif($activeTab === 'upload')
                    <div class="flex flex-col items-center justify-center py-12 border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg">
                        <flux:icon name="arrow-up-tray" class="h-10 w-10 text-zinc-400 mb-2" />
                        <p class="text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Drag and drop files here, or click to browse') }}</p>
                        <flux:button variant="primary" size="sm">
                            {{ __('Choose Files') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
