<?php

use App\Models\Deal;
use App\Models\LandingPageSectionPhoto;
use App\Models\LandingPageVideoSection;
use App\Models\LandingPageVideoSectionItem;
use App\Models\PropertyPhoto;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads, WithPagination;

    public bool $showPicker = false;

    public ?int $activeSection = null;

    public string $pickerSearch = '';

    // Photo picker state
    public bool $showPhotoPicker = false;

    public bool $showPhotoUploader = false;

    public ?int $photoActiveSection = null;

    public string $dealSearch = '';

    public ?int $selectedDealId = null;

    /** @var array */
    public $uploadPhotos = [];

    public string $uploadCaption = '';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('media.manage'), 403);
    }

    #[Computed]
    public function sections()
    {
        return LandingPageVideoSection::query()
            ->with([
                'videos' => fn ($q) => $q->where('status', 'ready'),
                'photos',
            ])
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

    #[Computed]
    public function deals(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Deal::query()
            ->whereHas('property', function ($q) {
                $q->whereHas('photos', fn ($pq) => $pq->where('category', 'reference'));
            })
            ->with([
                'customer',
                'property.photos' => fn ($q) => $q->where('category', 'reference')->orderBy('sort_order')->limit(4),
            ]);

        if ($this->dealSearch) {
            $search = '%'.$this->dealSearch.'%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('first_name', 'like', $search)
                            ->orWhere('last_name', 'like', $search)
                            ->orWhere('phone', 'like', $search)
                            ->orWhere('email', 'like', $search);
                    })
                    ->orWhereHas('property', fn ($pq) => $pq->where('address', 'like', $search));
            });
        }

        return $query->latest()->paginate(12);
    }

    #[Computed]
    public function selectedDeal()
    {
        if (! $this->selectedDealId) {
            return null;
        }

        return Deal::with('customer', 'property')->find($this->selectedDealId);
    }

    #[Computed]
    public function dealPhotos(): \Illuminate\Support\Collection
    {
        if (! $this->selectedDealId) {
            return collect();
        }

        $deal = Deal::with(['property.photos', 'estimates.photos'])->find($this->selectedDealId);

        if (! $deal) {
            return collect();
        }

        $photos = collect();

        if ($deal->property) {
            $photos = $photos->merge($deal->property->photos);
        }

        $photos = $photos->merge($deal->estimates->flatMap->photos);

        return $photos->unique('id')->sortBy('category')->values();
    }

    public function toggleSection(int $sectionId): void
    {
        $section = LandingPageVideoSection::findOrFail($sectionId);
        $section->update(['is_active' => ! $section->is_active]);
        unset($this->sections);
    }

    public function toggleShowOnProposal(int $sectionId): void
    {
        $section = LandingPageVideoSection::findOrFail($sectionId);
        $section->update(['show_on_proposal' => ! $section->show_on_proposal]);
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

    // Photo methods

    public function openPhotoPicker(int $sectionId): void
    {
        $this->photoActiveSection = $sectionId;
        $this->dealSearch = '';
        $this->selectedDealId = null;
        $this->showPhotoPicker = true;
        $this->resetPage();
        unset($this->deals, $this->dealPhotos, $this->selectedDeal);
    }

    public function selectDeal(int $dealId): void
    {
        $this->selectedDealId = $dealId;
        unset($this->dealPhotos, $this->selectedDeal);
    }

    public function backToDeals(): void
    {
        $this->selectedDealId = null;
        unset($this->dealPhotos, $this->selectedDeal);
    }

    public function updatedDealSearch(): void
    {
        $this->resetPage();
    }

    public function addPhotoFromDeal(int $photoId): void
    {
        if (! $this->photoActiveSection) {
            return;
        }

        $section = LandingPageVideoSection::findOrFail($this->photoActiveSection);

        if ($section->photos()->count() >= 6) {
            $this->dispatch('notify', message: __('Maximum 6 photos per section.'));

            return;
        }

        $photo = PropertyPhoto::findOrFail($photoId);
        $maxSort = $section->photos()->max('sort_order') ?? 0;

        LandingPageSectionPhoto::create([
            'landing_page_video_section_id' => $section->id,
            'path' => $photo->path,
            'thumbnail_path' => $photo->thumbnail_path,
            'disk' => $photo->disk,
            'caption' => $photo->caption,
            'original_filename' => $photo->original_filename ?? null,
            'deal_id' => $this->selectedDealId,
            'property_photo_id' => $photo->id,
            'sort_order' => $maxSort + 1,
        ]);

        unset($this->sections);
        $this->dispatch('notify', message: __('Photo added to section.'));
    }

    public function openPhotoUploader(int $sectionId): void
    {
        $this->photoActiveSection = $sectionId;
        $this->uploadPhotos = [];
        $this->uploadCaption = '';
        $this->showPhotoUploader = true;
    }

    public function uploadSectionPhotos(): void
    {
        if (! $this->photoActiveSection) {
            return;
        }

        $this->validate([
            'uploadPhotos' => 'required|array|min:1',
            'uploadPhotos.*' => 'image|max:10240',
        ]);

        $section = LandingPageVideoSection::findOrFail($this->photoActiveSection);
        $currentCount = $section->photos()->count();
        $remaining = 6 - $currentCount;

        if ($remaining <= 0) {
            $this->dispatch('notify', message: __('Maximum 6 photos per section.'));

            return;
        }

        $maxSort = $section->photos()->max('sort_order') ?? 0;
        $added = 0;

        $disk = config('filesystems.default');

        foreach (array_slice($this->uploadPhotos, 0, $remaining) as $file) {
            $path = $file->store('landing-page/section-photos', $disk);

            LandingPageSectionPhoto::create([
                'landing_page_video_section_id' => $section->id,
                'path' => $path,
                'disk' => $disk,
                'caption' => $this->uploadCaption ?: null,
                'original_filename' => $file->getClientOriginalName(),
                'sort_order' => $maxSort + (++$added),
            ]);
        }

        $this->showPhotoUploader = false;
        $this->uploadPhotos = [];
        $this->uploadCaption = '';
        unset($this->sections);
        $this->dispatch('notify', message: __(':count photo(s) uploaded.', ['count' => $added]));
    }

    public function removeSectionPhoto(int $sectionId, int $photoId): void
    {
        $photo = LandingPageSectionPhoto::where('landing_page_video_section_id', $sectionId)
            ->where('id', $photoId)
            ->firstOrFail();

        $photo->delete();
        unset($this->sections);
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="lg">{{ __('Landing Page Videos & Photos') }}</flux:heading>
            <flux:text class="text-zinc-500 mt-1">{{ __('Configure video and photo sections displayed on the public landing page and proposals.') }}</flux:text>
        </div>
    </div>

    <div class="space-y-6">
        @foreach($this->sections as $section)
            <div wire:key="section-{{ $section->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden">
                {{-- Section Header --}}
                <div class="px-6 py-4 flex items-center justify-between border-b border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center gap-3 flex-wrap">
                        <flux:heading size="sm">{{ $section->title }}</flux:heading>
                        <flux:badge size="sm" :color="$section->is_active ? 'green' : 'zinc'">
                            {{ $section->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                        <button wire:click="toggleShowOnProposal({{ $section->id }})" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium transition-colors {{ $section->show_on_proposal ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                            {{ $section->show_on_proposal ? __('On Proposal') : __('Not on Proposal') }}
                        </button>
                        <flux:text class="text-xs text-zinc-500">{{ $section->videos->count() }}/3 {{ __('videos') }} &middot; {{ $section->photos->count() }}/6 {{ __('photos') }}</flux:text>
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
                        <flux:text class="text-xs font-semibold text-zinc-500 mb-2">{{ __('Videos') }}</flux:text>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            @foreach($section->videos as $video)
                                <div wire:key="section-video-{{ $section->id }}-{{ $video->id }}" class="relative group">
                                    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden">
                                        @if($video->getThumbnailUrl())
                                            <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" loading="lazy" />
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

                    {{-- Photos Grid --}}
                    @if($section->photos->count() > 0)
                        <flux:text class="text-xs font-semibold text-zinc-500 mb-2 {{ $section->videos->count() > 0 ? 'mt-4' : '' }}">{{ __('Photos') }}</flux:text>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                            @foreach($section->photos as $photo)
                                <div wire:key="section-photo-{{ $section->id }}-{{ $photo->id }}" class="relative group">
                                    <div class="aspect-[4/3] bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden">
                                        @if($photo->thumbnail_url)
                                            <img src="{{ $photo->thumbnail_url }}" alt="{{ $photo->caption ?? '' }}" class="w-full h-full object-cover" loading="lazy" />
                                        @else
                                            <div class="w-full h-full flex items-center justify-center">
                                                <flux:icon name="photo" class="h-6 w-6 text-zinc-400" />
                                            </div>
                                        @endif
                                    </div>
                                    <div class="mt-2 flex items-start justify-between gap-2">
                                        <flux:text class="text-xs truncate">{{ $photo->caption ?? $photo->original_filename ?? __('Photo') }}</flux:text>
                                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeSectionPhoto({{ $section->id }}, {{ $photo->id }})" wire:confirm="{{ __('Remove this photo from the section?') }}" class="shrink-0" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Add Buttons --}}
                    <div class="flex items-center gap-2 {{ ($section->videos->count() > 0 || $section->photos->count() > 0) ? 'mt-4' : '' }}">
                        @if($section->videos->count() < 3)
                            <flux:button size="sm" variant="ghost" icon="video-camera" wire:click="openPicker({{ $section->id }})">
                                {{ __('Add Video') }}
                            </flux:button>
                        @endif
                        @if($section->photos->count() < 6)
                            <flux:dropdown>
                                <flux:button size="sm" variant="ghost" icon="photo">
                                    {{ __('Add Photo') }}
                                </flux:button>
                                <flux:menu>
                                    <flux:menu.item icon="arrow-up-tray" wire:click="openPhotoUploader({{ $section->id }})">{{ __('Upload from Device') }}</flux:menu.item>
                                    <flux:menu.item icon="building-storefront" wire:click="openPhotoPicker({{ $section->id }})">{{ __('Select from Deal') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        @endif
                    </div>
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
                                <img src="{{ $video->getThumbnailUrl() }}" alt="{{ $video->title }}" class="w-full h-full object-cover" loading="lazy" />
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

    {{-- Photo Picker Modal (Deal-based) --}}
    <flux:modal wire:model="showPhotoPicker" class="max-w-4xl">
        @if(! $selectedDealId)
            {{-- Step 1: Browse deals --}}
            <flux:heading size="lg" class="mb-1">{{ __('Select a Deal') }}</flux:heading>
            <flux:text class="text-zinc-500 mb-4">{{ __('Showing deals with reference photos. Select one to pick images.') }}</flux:text>

            <flux:input
                wire:model.live.debounce.300ms="dealSearch"
                placeholder="{{ __('Search by customer name, phone, address...') }}"
                icon="magnifying-glass"
                class="mb-4"
            />

            <div class="max-h-[32rem] overflow-y-auto -mx-2">
                @if($this->deals->count() > 0)
                    <div class="space-y-3 px-2">
                        @foreach($this->deals as $deal)
                            @php $referencePhotos = $deal->property?->photos ?? collect(); @endphp
                            <button
                                wire:key="picker-deal-{{ $deal->id }}"
                                wire:click="selectDeal({{ $deal->id }})"
                                type="button"
                                class="w-full text-left rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 hover:border-blue-500 dark:hover:border-blue-400 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                <div class="flex items-start gap-4">
                                    <div class="shrink-0 flex gap-1.5">
                                        @foreach($referencePhotos->take(3) as $refPhoto)
                                            <div class="w-16 h-16 rounded-md bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                                <img src="{{ $refPhoto->url }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                                            </div>
                                        @endforeach
                                        @if($referencePhotos->count() > 3)
                                            <div class="w-16 h-16 rounded-md bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                                <span class="text-xs font-medium text-zinc-500">+{{ $referencePhotos->count() - 3 }}</span>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200 truncate">{{ $deal->title }}</p>
                                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 mt-0.5">
                                            @if($deal->customer)
                                                <span>{{ $deal->customer->name }}</span>
                                            @endif
                                        </div>
                                        @if($deal->property?->address)
                                            <p class="text-xs text-zinc-400 truncate mt-0.5">{{ $deal->property->address }}</p>
                                        @endif
                                    </div>
                                    <flux:icon name="chevron-right" class="h-5 w-5 text-zinc-300 shrink-0 mt-1" />
                                </div>
                            </button>
                        @endforeach
                    </div>
                    <div class="mt-4 px-2">{{ $this->deals->links() }}</div>
                @else
                    <div class="text-center py-12">
                        <flux:icon name="photo" class="h-8 w-8 text-zinc-300 mx-auto mb-3" />
                        <flux:text class="text-zinc-500">
                            {{ $dealSearch ? __('No deals with reference photos match your search.') : __('No deals with reference photos found.') }}
                        </flux:text>
                    </div>
                @endif
            </div>

            <div class="flex justify-end mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('showPhotoPicker', false)">{{ __('Cancel') }}</flux:button>
            </div>
        @else
            {{-- Step 2: Browse deal photos & click to add --}}
            @php $deal = $this->selectedDeal; @endphp

            <div class="flex items-center gap-3 mb-4">
                <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="backToDeals">
                    {{ __('Back') }}
                </flux:button>
                <div class="min-w-0">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $deal?->title ?? __('Deal') }}</span>
                    <p class="text-xs text-zinc-500">
                        {{ $deal?->customer?->name }}
                        @if($deal?->property?->address)
                            &middot; {{ $deal->property->address }}
                        @endif
                    </p>
                </div>
            </div>

            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400 mb-3">{{ __('Click a photo to add it to the section.') }}</flux:text>

            @php
                $photos = $this->dealPhotos;
                $grouped = $photos->groupBy('category');
            @endphp

            <div class="max-h-[28rem] overflow-y-auto space-y-4">
                @foreach($grouped as $category => $categoryPhotos)
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <h4 class="text-sm font-medium text-zinc-600 dark:text-zinc-400">
                                {{ \App\Models\PropertyPhoto::CATEGORIES[$category] ?? ucfirst($category) }}
                            </h4>
                            <flux:badge size="sm" color="zinc">{{ $categoryPhotos->count() }}</flux:badge>
                        </div>
                        <div class="grid grid-cols-4 sm:grid-cols-5 gap-2">
                            @foreach($categoryPhotos as $photo)
                                <button
                                    wire:key="deal-photo-{{ $photo->id }}"
                                    wire:click="addPhotoFromDeal({{ $photo->id }})"
                                    type="button"
                                    class="relative aspect-square bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden ring-0 hover:ring-2 hover:ring-blue-400 transition-all focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    <img src="{{ $photo->url }}" alt="{{ $photo->caption ?? '' }}" class="w-full h-full object-cover" loading="lazy" />
                                    <div class="absolute inset-0 bg-black/0 hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 hover:opacity-100">
                                        <flux:icon name="plus-circle" class="h-8 w-8 text-white drop-shadow" />
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if($photos->isEmpty())
                    <div class="text-center py-12">
                        <flux:icon name="photo" class="h-8 w-8 text-zinc-300 mx-auto mb-3" />
                        <flux:text class="text-zinc-500">{{ __('No photos found for this deal.') }}</flux:text>
                    </div>
                @endif
            </div>

            <div class="flex justify-between mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="backToDeals">{{ __('Back') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showPhotoPicker', false)">{{ __('Done') }}</flux:button>
            </div>
        @endif
    </flux:modal>

    {{-- Photo Upload Modal --}}
    <flux:modal wire:model="showPhotoUploader" class="max-w-lg">
        <flux:heading size="lg" class="mb-4">{{ __('Upload Photos') }}</flux:heading>

        <form wire:submit="uploadSectionPhotos" class="space-y-4">
            <flux:field>
                <flux:label>{{ __('Photos') }}</flux:label>
                <input type="file" wire:model="uploadPhotos" multiple accept="image/*" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-blue-900/30 dark:file:text-blue-400" />
                @error('uploadPhotos.*') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Caption (optional)') }}</flux:label>
                <flux:input size="sm" wire:model="uploadCaption" placeholder="{{ __('Optional caption for these photos...') }}" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showPhotoUploader', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="uploadSectionPhotos">{{ __('Upload') }}</span>
                    <span wire:loading wire:target="uploadSectionPhotos">{{ __('Uploading...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
