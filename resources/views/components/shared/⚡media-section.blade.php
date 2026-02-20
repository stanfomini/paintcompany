<?php

use App\Models\Estimate;
use App\Models\Job;
use App\Models\Property;
use App\Models\VideoRecording;
use App\Models\VideoView;
use App\Services\PhotoService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $propertyId = null;

    public int $jobId;

    public string $context = 'job-internal';

    public bool $readOnly = false;

    public string $mediaTab = 'photos';

    public function mount(?Property $property = null, Job $job, string $context = 'job-internal', bool $readOnly = false): void
    {
        $this->propertyId = $property?->id;
        $this->jobId = $job->id;
        $this->context = $context;
        $this->readOnly = $readOnly;
    }

    private function resolveUser(): ?\App\Models\User
    {
        return auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();
    }

    #[Computed]
    public function photoUnviewedCount(): int
    {
        $user = $this->resolveUser();
        if (! $user || ! $this->propertyId) {
            return 0;
        }

        $property = Property::find($this->propertyId);
        if (! $property) {
            return 0;
        }

        $job = Job::find($this->jobId);
        $photos = app(PhotoService::class)->getVisiblePhotos($user, $property, $job);

        return $photos->filter(fn ($photo) => $photo->hasUnviewedChanges($user))->count();
    }

    #[Computed]
    public function videoUnviewedCount(): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            return 0;
        }

        return $this->getVideosQuery()->get()->filter(
            fn ($video) => $video->hasUnviewedContent($user)
        )->count();
    }

    public function switchToTab(string $tab): void
    {
        $this->mediaTab = $tab;

        if ($tab === 'videos') {
            $this->markVideosViewed();
        }
    }

    public function markVideosViewed(): void
    {
        $user = $this->resolveUser();
        if (! $user) {
            return;
        }

        $videos = $this->getVideosQuery()->get();
        foreach ($videos as $video) {
            if ($video->hasUnviewedContent($user)) {
                VideoView::markContentViewed($video, $user);
            }
        }
        unset($this->videoUnviewedCount);
    }

    private function getVideosQuery()
    {
        $job = Job::find($this->jobId);
        $query = VideoRecording::where('recordable_type', Job::class)
            ->where('recordable_id', $this->jobId);

        if ($job?->estimate_id) {
            $query = VideoRecording::where(function ($q) {
                $q->where('recordable_type', Job::class)
                    ->where('recordable_id', $this->jobId);
            })->orWhere(function ($q) use ($job) {
                $q->where('recordable_type', Estimate::class)
                    ->where('recordable_id', $job->estimate_id);
            });
        }

        return $query;
    }
};
?>

<div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden print:hidden">
    {{-- Tab Header --}}
    <div class="flex border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
        <button
            wire:click="switchToTab('photos')"
            type="button"
            class="flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors {{ $mediaTab === 'photos' ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400 bg-white dark:bg-zinc-900' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
        >
            <flux:icon name="photo" class="w-4 h-4" />
            {{ __('Photos') }}
            @if ($this->photoUnviewedCount > 0)
                <span class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-blue-500 rounded-full">{{ $this->photoUnviewedCount }}</span>
            @endif
        </button>
        <button
            wire:click="switchToTab('videos')"
            type="button"
            class="flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors {{ $mediaTab === 'videos' ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400 bg-white dark:bg-zinc-900' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}"
        >
            <flux:icon name="video-camera" class="w-4 h-4" />
            {{ __('Videos') }}
            @if ($this->videoUnviewedCount > 0)
                <span class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-blue-500 rounded-full">{{ $this->videoUnviewedCount }}</span>
            @endif
        </button>
    </div>

    {{-- Tab Content --}}
    <div class="p-4">
        @if ($mediaTab === 'photos')
            @if ($propertyId)
                <livewire:shared.photo-gallery
                    :property="\App\Models\Property::find($propertyId)"
                    :job="\App\Models\Job::find($jobId)"
                    :context="$context"
                    :readOnly="$readOnly"
                    :key="'media-photos-'.$jobId"
                />
            @else
                <div class="text-center py-8">
                    <flux:icon name="photo" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                    <flux:text class="text-zinc-500">{{ __('No property linked to this job.') }}</flux:text>
                </div>
            @endif
        @else
            <livewire:estimates.video-walkarounds
                :recordable="\App\Models\Job::find($jobId)"
                :readOnly="$readOnly"
                :key="'media-videos-'.$jobId"
            />
        @endif
    </div>
</div>
