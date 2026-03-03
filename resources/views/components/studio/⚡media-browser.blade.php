<?php

use App\Models\Deal;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $browserTab = 'deals';
    public string $search = '';
    public ?int $selectedDealId = null;
    public bool $show = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBrowserTab(): void
    {
        $this->search = '';
        $this->selectedDealId = null;
        $this->resetPage();
    }

    public function selectDeal(int $dealId): void
    {
        $this->selectedDealId = $dealId;
    }

    public function backToDeals(): void
    {
        $this->selectedDealId = null;
    }

    public function selectMedia(string $type, int $id): void
    {
        $this->dispatch('media-selected', type: $type, id: $id);
        $this->show = false;
    }

    #[Computed]
    public function deals(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Deal::query()
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function dealMedia(): array
    {
        if (! $this->selectedDealId) {
            return ['photos' => collect(), 'videos' => collect()];
        }

        $deal = Deal::with(['property.photos', 'estimates.photos', 'estimates.videoRecordings', 'job.videoRecordings'])
            ->find($this->selectedDealId);

        if (! $deal) {
            return ['photos' => collect(), 'videos' => collect()];
        }

        $photos = collect();
        if ($deal->property) {
            $photos = $photos->merge($deal->property->photos);
        }
        $photos = $photos->merge($deal->estimates->flatMap->photos);

        $videos = collect();
        if ($deal->job) {
            $videos = $videos->merge($deal->job->videoRecordings);
        }
        $videos = $videos->merge($deal->estimates->flatMap->videoRecordings);

        return ['photos' => $photos, 'videos' => $videos];
    }

    #[Computed]
    public function hubVideos(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return VideoRecording::where('status', 'ready')
            ->where('available_in_hub', true)
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function myRecordings(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return VideoRecording::where('user_id', auth()->id())
            ->where('status', 'ready')
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(20);
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
                    :variant="$browserTab === 'deals' ? 'filled' : 'ghost'"
                    icon="folder"
                    wire:click="$set('browserTab', 'deals')"
                >
                    {{ __('Deals') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$browserTab === 'hub' ? 'filled' : 'ghost'"
                    icon="globe-alt"
                    wire:click="$set('browserTab', 'hub')"
                >
                    {{ __('Hub') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$browserTab === 'recordings' ? 'filled' : 'ghost'"
                    icon="video-camera"
                    wire:click="$set('browserTab', 'recordings')"
                >
                    {{ __('Recordings') }}
                </flux:button>
            </div>

            {{-- Search --}}
            @if($browserTab !== 'deals' || ! $selectedDealId)
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
                @if($browserTab === 'deals')
                    @if($selectedDealId)
                        @php $media = $this->dealMedia; @endphp
                        @php $deal = \App\Models\Deal::find($selectedDealId); @endphp
                        <div class="flex items-center gap-2 mb-4">
                            <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="backToDeals">
                                {{ __('Back') }}
                            </flux:button>
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ $deal?->title ?? __('Deal') }}
                            </span>
                        </div>

                        {{-- Photos --}}
                        @if($media['photos']->isNotEmpty())
                            <h4 class="text-sm font-medium text-zinc-600 dark:text-zinc-400 mb-2">{{ __('Photos') }}</h4>
                            <div class="grid grid-cols-3 gap-3 mb-4">
                                @foreach($media['photos'] as $photo)
                                    <x-studio.media-picker-card
                                        wire:key="deal-photo-{{ $photo->id }}"
                                        type="App\Models\PropertyPhoto"
                                        :id="$photo->id"
                                        :title="$photo->caption ?? ''"
                                        :thumbnail-url="$photo->url"
                                        :is-video="false"
                                    />
                                @endforeach
                            </div>
                        @endif

                        {{-- Videos --}}
                        @if($media['videos']->isNotEmpty())
                            <h4 class="text-sm font-medium text-zinc-600 dark:text-zinc-400 mb-2">{{ __('Videos') }}</h4>
                            <div class="grid grid-cols-3 gap-3">
                                @foreach($media['videos'] as $video)
                                    <x-studio.media-picker-card
                                        wire:key="deal-video-{{ $video->id }}"
                                        type="App\Models\VideoRecording"
                                        :id="$video->id"
                                        :title="$video->title"
                                        :thumbnail-url="$video->getThumbnailUrl()"
                                        :duration="$video->formatted_duration"
                                        :is-video="true"
                                    />
                                @endforeach
                            </div>
                        @endif

                        @if($media['photos']->isEmpty() && $media['videos']->isEmpty())
                            <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                                <flux:icon name="photo" class="h-10 w-10 mb-2" />
                                <p>{{ __('No media found for this deal.') }}</p>
                            </div>
                        @endif
                    @else
                        {{-- Deal list --}}
                        @if($this->deals->isNotEmpty())
                            <div class="grid grid-cols-2 gap-3">
                                @foreach($this->deals as $deal)
                                    <button
                                        wire:key="deal-{{ $deal->id }}"
                                        wire:click="selectDeal({{ $deal->id }})"
                                        type="button"
                                        class="text-left rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 hover:border-blue-500 dark:hover:border-blue-400 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 truncate">{{ $deal->title }}</p>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ $deal->stage_label }}</p>
                                    </button>
                                @endforeach
                            </div>
                            <div class="mt-4">
                                {{ $this->deals->links() }}
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                                <flux:icon name="folder" class="h-10 w-10 mb-2" />
                                <p>{{ __('No deals found.') }}</p>
                            </div>
                        @endif
                    @endif

                @elseif($browserTab === 'hub')
                    @if($this->hubVideos->isNotEmpty())
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->hubVideos as $video)
                                <x-studio.media-picker-card
                                    wire:key="hub-video-{{ $video->id }}"
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
                            {{ $this->hubVideos->links() }}
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="globe-alt" class="h-10 w-10 mb-2" />
                            <p>{{ __('No hub videos found.') }}</p>
                        </div>
                    @endif

                @elseif($browserTab === 'recordings')
                    @if($this->myRecordings->isNotEmpty())
                        <div class="grid grid-cols-3 gap-3">
                            @foreach($this->myRecordings as $video)
                                <x-studio.media-picker-card
                                    wire:key="recording-{{ $video->id }}"
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
                            {{ $this->myRecordings->links() }}
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-12 text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="video-camera" class="h-10 w-10 mb-2" />
                            <p>{{ __('No recordings found.') }}</p>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </flux:modal>
</div>
