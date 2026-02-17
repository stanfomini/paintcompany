<?php

use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';

    public string $filterVisibility = 'all';

    public string $sortBy = 'latest';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterVisibility(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function videos()
    {
        return VideoRecording::query()
            ->where('user_id', auth()->id())
            ->whereNull('recordable_type')
            ->when($this->search, function ($query) {
                $query->where('title', 'like', "%{$this->search}%");
            })
            ->when($this->filterVisibility !== 'all', function ($query) {
                $query->where('visibility', $this->filterVisibility);
            })
            ->when($this->sortBy === 'latest', fn ($q) => $q->latest())
            ->when($this->sortBy === 'oldest', fn ($q) => $q->oldest())
            ->when($this->sortBy === 'title', fn ($q) => $q->orderBy('title'))
            ->withCount('views')
            ->paginate(12);
    }

    public function deleteVideo(int $id): void
    {
        $video = VideoRecording::where('user_id', auth()->id())->findOrFail($id);
        $video->delete();
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row gap-3 mb-6">
        <div class="flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search videos...') }}" icon="magnifying-glass" />
        </div>
        <div class="flex gap-2">
            <flux:select wire:model.live="filterVisibility" class="w-36">
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                <flux:select.option value="public">{{ __('Public') }}</flux:select.option>
                <flux:select.option value="unlisted">{{ __('Unlisted') }}</flux:select.option>
                <flux:select.option value="internal">{{ __('Internal') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="sortBy" class="w-36">
                <flux:select.option value="latest">{{ __('Newest') }}</flux:select.option>
                <flux:select.option value="oldest">{{ __('Oldest') }}</flux:select.option>
                <flux:select.option value="title">{{ __('Title') }}</flux:select.option>
            </flux:select>
        </div>
    </div>

    @if($this->videos->count() > 0)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($this->videos as $video)
                <div wire:key="video-{{ $video->id }}" data-video-title="{{ $video->title }}">
                    @livewire('media.video-card', ['video' => $video], key('card-' . $video->id))
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $this->videos->links() }}
        </div>
    @else
        <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
            <flux:icon name="video-camera" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-3">{{ __('No videos found') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                @if($search || $filterVisibility !== 'all')
                    {{ __('Try adjusting your search or filters.') }}
                @else
                    {{ __('Record your first video to get started.') }}
                @endif
            </flux:text>
        </div>
    @endif
</div>
