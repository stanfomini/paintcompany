<?php

use App\Models\LandingPageVideoSection;
use App\Models\LandingPageVideoSectionItem;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showPicker = false;

    public ?int $activeSection = null;

    public string $pickerSearch = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('media.manage'), 403);
    }

    #[Computed]
    public function sections()
    {
        return LandingPageVideoSection::query()
            ->with(['videos' => fn ($q) => $q->where('status', 'ready')])
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function availableVideos()
    {
        if (! $this->activeSection) {
            return collect();
        }

        $section = LandingPageVideoSection::find($this->activeSection);
        $existingIds = $section ? $section->videos()->pluck('video_recordings.id')->toArray() : [];

        $query = VideoRecording::query()
            ->where('status', 'ready')
            ->where(function ($q) {
                $q->whereNull('recordable_type')
                    ->orWhere('available_in_hub', true);
            })
            ->whereNotIn('id', $existingIds);

        if ($this->pickerSearch) {
            $query->where('title', 'like', '%'.$this->pickerSearch.'%');
        }

        return $query->latest()->limit(20)->get();
    }

    public function toggleSection(int $sectionId): void
    {
        $section = LandingPageVideoSection::findOrFail($sectionId);
        $section->update(['is_active' => ! $section->is_active]);
        unset($this->sections);
    }

    public function updateSubtitle(int $sectionId, string $subtitle): void
    {
        $section = LandingPageVideoSection::findOrFail($sectionId);
        $section->update(['subtitle' => $subtitle ?: null]);
        unset($this->sections);
    }

    public function openPicker(int $sectionId): void
    {
        $this->activeSection = $sectionId;
        $this->pickerSearch = '';
        $this->showPicker = true;
        unset($this->availableVideos);
    }

    public function addVideo(int $videoId): void
    {
        if (! $this->activeSection) {
            return;
        }

        $section = LandingPageVideoSection::findOrFail($this->activeSection);

        if ($section->videos()->count() >= 3) {
            $this->dispatch('notify', message: __('Maximum 3 videos per section.'));

            return;
        }

        $maxSort = LandingPageVideoSectionItem::where('landing_page_video_section_id', $section->id)->max('sort_order') ?? 0;

        LandingPageVideoSectionItem::create([
            'landing_page_video_section_id' => $section->id,
            'video_recording_id' => $videoId,
            'sort_order' => $maxSort + 1,
        ]);

        unset($this->sections);
        unset($this->availableVideos);
    }

    public function removeVideo(int $sectionId, int $videoId): void
    {
        LandingPageVideoSectionItem::where('landing_page_video_section_id', $sectionId)
            ->where('video_recording_id', $videoId)
            ->delete();

        unset($this->sections);
    }

    public function reorderVideos(int $sectionId, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $videoId) {
            LandingPageVideoSectionItem::where('landing_page_video_section_id', $sectionId)
                ->where('video_recording_id', $videoId)
                ->update(['sort_order' => $index + 1]);
        }

        unset($this->sections);
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="lg">{{ __('Landing Page Videos') }}</flux:heading>
            <flux:text class="text-zinc-500 mt-1">{{ __('Configure video sections displayed on the public landing page.') }}</flux:text>
        </div>
    </div>

    <div class="space-y-6">
        @foreach($this->sections as $section)
            <div wire:key="section-{{ $section->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden">
                {{-- Section Header --}}
                <div class="px-6 py-4 flex items-center justify-between border-b border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center gap-3">
                        <flux:heading size="sm">{{ $section->title }}</flux:heading>
                        <flux:badge size="sm" :color="$section->is_active ? 'green' : 'zinc'">
                            {{ $section->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                        <flux:text class="text-xs text-zinc-500">{{ $section->videos->count() }}/3 {{ __('videos') }}</flux:text>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" icon="{{ $section->is_active ? 'eye-slash' : 'eye' }}" wire:click="toggleSection({{ $section->id }})" />
                    </div>
                </div>

                {{-- Subtitle --}}
                <div class="px-6 py-3 border-b border-zinc-100 dark:border-zinc-800">
                    <flux:field>
                        <flux:label>{{ __('Subtitle') }}</flux:label>
                        <flux:input
                            size="sm"
                            value="{{ $section->subtitle }}"
                            placeholder="{{ __('Optional subtitle for this section...') }}"
                            wire:blur="updateSubtitle({{ $section->id }}, $event.target.value)"
                        />
                    </flux:field>
                </div>

                {{-- Videos Grid --}}
                <div class="px-6 py-4">
                    @if($section->videos->count() > 0)
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            @foreach($section->videos as $video)
                                <div wire:key="section-video-{{ $section->id }}-{{ $video->id }}" class="relative group">
                                    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden">
                                        @if($video->getThumbnailUrl())
                                            <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" />
                                        @else
                                            <div class="w-full h-full flex items-center justify-center">
                                                <flux:icon name="video-camera" class="h-6 w-6 text-zinc-400" />
                                            </div>
                                        @endif
                                    </div>
                                    <div class="mt-2 flex items-start justify-between gap-2">
                                        <flux:text class="text-sm font-medium truncate">{{ $video->title ?? __('Untitled') }}</flux:text>
                                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeVideo({{ $section->id }}, {{ $video->id }})" wire:confirm="{{ __('Remove this video from the section?') }}" class="shrink-0" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($section->videos->count() < 3)
                        <div class="{{ $section->videos->count() > 0 ? 'mt-4' : '' }}">
                            <flux:button size="sm" variant="ghost" icon="plus" wire:click="openPicker({{ $section->id }})">
                                {{ __('Add Video') }}
                            </flux:button>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Video Picker Modal --}}
    <flux:modal wire:model="showPicker" class="max-w-2xl">
        <flux:heading size="lg" class="mb-4">{{ __('Add Video to Section') }}</flux:heading>

        <flux:input wire:model.live.debounce.300ms="pickerSearch" placeholder="{{ __('Search videos...') }}" icon="magnifying-glass" class="mb-4" />

        <div class="max-h-96 overflow-y-auto space-y-2">
            @if($this->availableVideos->count() > 0)
                @foreach($this->availableVideos as $video)
                    <div wire:key="picker-video-{{ $video->id }}" class="flex items-center gap-3 p-2 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 cursor-pointer" wire:click="addVideo({{ $video->id }})">
                        <div class="w-20 aspect-video bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden shrink-0">
                            @if($video->getThumbnailUrl())
                                <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="video-camera" class="h-4 w-4 text-zinc-400" />
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:text class="font-medium truncate">{{ $video->title ?? __('Untitled') }}</flux:text>
                            <flux:text class="text-xs text-zinc-500">{{ $video->formatted_duration }} &middot; {{ ucfirst($video->visibility) }}</flux:text>
                        </div>
                        <flux:icon name="plus-circle" class="h-5 w-5 text-blue-500 shrink-0" />
                    </div>
                @endforeach
            @else
                <div class="text-center py-8">
                    <flux:text class="text-zinc-500">{{ __('No videos available to add.') }}</flux:text>
                </div>
            @endif
        </div>

        <div class="flex justify-end mt-4">
            <flux:button variant="ghost" wire:click="$set('showPicker', false)">{{ __('Close') }}</flux:button>
        </div>
    </flux:modal>
</div>
