<?php

use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public string $visibility = '';

    public string $sortBy = 'newest';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('media.manage'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedVisibility(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function videos()
    {
        $query = VideoRecording::query()
            ->where(function ($q) {
                $q->whereNull('recordable_type')
                    ->orWhere('available_in_hub', true);
            })
            ->where('status', 'ready')
            ->with('user:id,name')
            ->withCount('views');

        if ($this->search) {
            $query->where('title', 'like', '%'.$this->search.'%');
        }

        if ($this->visibility) {
            $query->where('visibility', $this->visibility);
        }

        $query->when($this->sortBy === 'newest', fn ($q) => $q->latest())
            ->when($this->sortBy === 'oldest', fn ($q) => $q->oldest())
            ->when($this->sortBy === 'most_views', fn ($q) => $q->orderByDesc('views_count'))
            ->when($this->sortBy === 'title', fn ($q) => $q->orderBy('title'));

        return $query->paginate(12);
    }

    public function deleteVideo(int $videoId): void
    {
        $video = VideoRecording::findOrFail($videoId);
        $this->authorize('delete', $video);
        $video->delete();
        unset($this->videos);
    }

    #[On('video-deleted')]
    public function refreshVideos(): void
    {
        unset($this->videos);
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <flux:heading size="lg">{{ __('All Videos') }}</flux:heading>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <div class="flex-1 min-w-[200px] max-w-sm">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search videos...') }}" icon="magnifying-glass" size="sm" />
        </div>
        <flux:select wire:model.live="visibility" size="sm" class="w-36">
            <flux:select.option value="">{{ __('All Visibility') }}</flux:select.option>
            <flux:select.option value="public">{{ __('Public') }}</flux:select.option>
            <flux:select.option value="internal">{{ __('Internal') }}</flux:select.option>
            <flux:select.option value="unlisted">{{ __('Unlisted') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="sortBy" size="sm" class="w-36">
            <flux:select.option value="newest">{{ __('Newest') }}</flux:select.option>
            <flux:select.option value="oldest">{{ __('Oldest') }}</flux:select.option>
            <flux:select.option value="most_views">{{ __('Most Views') }}</flux:select.option>
            <flux:select.option value="title">{{ __('Title') }}</flux:select.option>
        </flux:select>
    </div>

    @if($this->videos->count() > 0)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($this->videos as $video)
                <div wire:key="company-video-{{ $video->id }}">
                    @livewire('media.video-card', ['video' => $video, 'showCreator' => true], key('card-' . $video->id))
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
            <flux:text class="mt-1 text-zinc-500">{{ __('No videos match your current filters.') }}</flux:text>
        </div>
    @endif
</div>
