<?php

use App\Models\FbLandingPageOffer;
use App\Models\VideoRecording;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public bool $showForm = false;

    public ?int $editingId = null;

    // Form fields
    public string $title = '';

    public string $headline = '';

    public string $subheadline = '';

    public array $bullets = [''];

    public string $cta_text = 'Claim Your Free Estimate';

    public ?int $video_recording_id = null;

    public string $fb_pixel_id = '';

    public bool $is_active = true;

    // Video picker
    public bool $showVideoPicker = false;

    public string $videoSearch = '';

    // Photo upload
    public $heroPhotoUpload = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('media.manage'), 403);
    }

    #[Computed]
    public function offers()
    {
        return FbLandingPageOffer::with('videoRecording', 'views')
            ->latest()
            ->get();
    }

    #[Computed]
    public function availableVideos()
    {
        $query = VideoRecording::query()
            ->where('status', 'ready')
            ->where(fn ($q) => $q->whereNull('recordable_type')->orWhere('available_in_hub', true));

        if ($this->videoSearch) {
            $query->where('title', 'like', '%' . $this->videoSearch . '%');
        }

        return $query->latest()->limit(20)->get();
    }

    #[Computed]
    public function selectedVideo(): ?VideoRecording
    {
        if (! $this->video_recording_id) {
            return null;
        }

        return VideoRecording::find($this->video_recording_id);
    }

    public function create(): void
    {
        $this->resetFormFields();
        $this->editingId = null;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $offer = FbLandingPageOffer::findOrFail($id);

        $this->editingId = $offer->id;
        $this->title = $offer->title;
        $this->headline = $offer->headline;
        $this->subheadline = $offer->subheadline ?? '';
        $this->bullets = $offer->offer_bullets ?? [''];
        $this->cta_text = $offer->cta_text ?? 'Claim Your Free Estimate';
        $this->video_recording_id = $offer->video_recording_id;
        $this->fb_pixel_id = $offer->fb_pixel_id ?? '';
        $this->is_active = $offer->is_active;
        $this->heroPhotoUpload = null;
        $this->showForm = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => 'required|string|max:255',
            'headline' => 'required|string|max:255',
            'subheadline' => 'nullable|string|max:500',
            'bullets' => 'required|array|min:1',
            'bullets.*' => 'nullable|string|max:255',
            'cta_text' => 'required|string|max:255',
            'video_recording_id' => 'nullable|integer|exists:video_recordings,id',
            'heroPhotoUpload' => 'nullable|image|max:5120',
            'fb_pixel_id' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        // Filter out empty bullets
        $bullets = array_values(array_filter($validated['bullets'], fn ($b) => trim($b) !== ''));

        $data = [
            'title' => $validated['title'],
            'headline' => $validated['headline'],
            'subheadline' => $validated['subheadline'] ?: null,
            'offer_bullets' => $bullets ?: null,
            'cta_text' => $validated['cta_text'],
            'video_recording_id' => $validated['video_recording_id'] ?: null,
            'fb_pixel_id' => $validated['fb_pixel_id'] ?: null,
            'is_active' => $validated['is_active'],
        ];

        // Handle hero photo upload
        if ($this->heroPhotoUpload) {
            $path = $this->heroPhotoUpload->store('offers', 'public');
            $data['hero_photo_path'] = $path;
            $data['hero_photo_disk'] = 'public';
        }

        if ($this->editingId) {
            $offer = FbLandingPageOffer::findOrFail($this->editingId);
            $offer->update($data);
        } else {
            $data['slug'] = Str::slug($validated['title']) . '-' . Str::random(6);
            FbLandingPageOffer::create($data);
        }

        $this->cancelForm();
        unset($this->offers);
    }

    public function toggleActive(int $id): void
    {
        $offer = FbLandingPageOffer::findOrFail($id);
        $offer->update(['is_active' => ! $offer->is_active]);
        unset($this->offers);
    }

    public function removeHeroPhoto(int $id): void
    {
        $offer = FbLandingPageOffer::findOrFail($id);
        $offer->update(['hero_photo_path' => null, 'hero_photo_disk' => null]);
        unset($this->offers);
    }

    public function addBullet(): void
    {
        $this->bullets[] = '';
    }

    public function removeBullet(int $index): void
    {
        unset($this->bullets[$index]);
        $this->bullets = array_values($this->bullets);

        if (empty($this->bullets)) {
            $this->bullets = [''];
        }
    }

    // Video picker methods
    public function openVideoPicker(): void
    {
        $this->videoSearch = '';
        $this->showVideoPicker = true;
        unset($this->availableVideos);
    }

    public function selectVideo(int $videoId): void
    {
        $this->video_recording_id = $videoId;
        $this->showVideoPicker = false;
        unset($this->selectedVideo);
    }

    public function clearVideo(): void
    {
        $this->video_recording_id = null;
        unset($this->selectedVideo);
    }

    public function cancelForm(): void
    {
        $this->resetFormFields();
        $this->editingId = null;
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        FbLandingPageOffer::findOrFail($id)->delete();
        unset($this->offers);
    }

    private function resetFormFields(): void
    {
        $this->title = '';
        $this->headline = '';
        $this->subheadline = '';
        $this->bullets = [''];
        $this->cta_text = 'Claim Your Free Estimate';
        $this->video_recording_id = null;
        $this->fb_pixel_id = '';
        $this->is_active = true;
        $this->heroPhotoUpload = null;
        $this->videoSearch = '';
        $this->showVideoPicker = false;
        $this->resetValidation();
    }
};
?>

<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="lg">{{ __('Facebook Offer Campaigns') }}</flux:heading>
            <flux:text class="text-zinc-500 mt-1">{{ __('Manage A/B landing page offers for Facebook ad campaigns.') }}</flux:text>
        </div>
        <flux:button variant="primary" size="sm" icon="plus" wire:click="create">
            {{ __('New Campaign') }}
        </flux:button>
    </div>

    {{-- Offers List --}}
    @if($this->offers->count() > 0)
        <div class="space-y-4">
            @foreach($this->offers as $offer)
                @php
                    $totalViews = $offer->views->count();
                    $uniqueVisitors = $offer->views->unique('visitor_id')->count();
                    $conversions = $offer->views->where('converted', true)->count();
                    $conversionRate = $uniqueVisitors > 0 ? round(($conversions / $uniqueVisitors) * 100, 1) : 0;
                    $variantAViews = $offer->views->where('variant', 'a')->count();
                    $variantBViews = $offer->views->where('variant', 'b')->count();
                @endphp
                <div wire:key="offer-{{ $offer->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden">
                    <div class="px-6 py-4">
                        <div class="flex items-start justify-between gap-4">
                            {{-- Offer Info --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-3 flex-wrap">
                                    <flux:heading size="sm">{{ $offer->title }}</flux:heading>
                                    <flux:badge size="sm" :color="$offer->is_active ? 'green' : 'zinc'">
                                        {{ $offer->is_active ? __('Active') : __('Inactive') }}
                                    </flux:badge>
                                </div>
                                <flux:text class="text-sm text-zinc-500 mt-1 truncate">{{ $offer->headline }}</flux:text>

                                {{-- A/B Media Preview --}}
                                <div class="flex items-center gap-4 mt-3">
                                    {{-- Variant A: Photo --}}
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-zinc-400 uppercase">A</span>
                                        @if($offer->hero_photo_path)
                                            <div class="w-12 h-8 rounded bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                                <img src="{{ $offer->hero_photo_url }}" alt="" class="w-full h-full object-cover">
                                            </div>
                                            <span class="text-xs text-zinc-500">Photo</span>
                                        @else
                                            <span class="text-xs text-red-400">No photo</span>
                                        @endif
                                    </div>

                                    <span class="text-zinc-300 dark:text-zinc-600">|</span>

                                    {{-- Variant B: Video --}}
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold text-zinc-400 uppercase">B</span>
                                        @if($offer->videoRecording)
                                            <div class="w-12 h-8 rounded bg-zinc-100 dark:bg-zinc-800 overflow-hidden relative">
                                                @if($offer->videoRecording->getThumbnailUrl())
                                                    <img src="{{ $offer->videoRecording->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover">
                                                @endif
                                                <div class="absolute inset-0 flex items-center justify-center">
                                                    <svg class="w-3 h-3 text-white drop-shadow" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                                                </div>
                                            </div>
                                            <span class="text-xs text-zinc-500 truncate max-w-24">{{ $offer->videoRecording->title }}</span>
                                        @else
                                            <span class="text-xs text-red-400">No video</span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Stats Row --}}
                                <div class="flex items-center gap-4 mt-3 flex-wrap">
                                    <div class="flex items-center gap-1.5">
                                        <flux:icon name="eye" class="h-4 w-4 text-zinc-400" />
                                        <flux:text class="text-xs text-zinc-500">
                                            {{ number_format($totalViews) }} {{ __('views') }}
                                            <span class="text-zinc-300 dark:text-zinc-600">(A:{{ $variantAViews }} B:{{ $variantBViews }})</span>
                                        </flux:text>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <flux:icon name="users" class="h-4 w-4 text-zinc-400" />
                                        <flux:text class="text-xs text-zinc-500">
                                            {{ number_format($uniqueVisitors) }} {{ __('unique') }}
                                        </flux:text>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <flux:icon name="check-circle" class="h-4 w-4 text-zinc-400" />
                                        <flux:text class="text-xs text-zinc-500">
                                            {{ $conversions }} {{ __('conversions') }}
                                        </flux:text>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <flux:icon name="chart-bar" class="h-4 w-4 text-zinc-400" />
                                        <flux:text class="text-xs font-medium {{ $conversionRate > 0 ? 'text-green-600 dark:text-green-400' : 'text-zinc-500' }}">
                                            {{ $conversionRate }}% {{ __('rate') }}
                                        </flux:text>
                                    </div>
                                </div>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-1 shrink-0">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil-square"
                                    wire:click="edit({{ $offer->id }})"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="{{ $offer->is_active ? 'eye-slash' : 'eye' }}"
                                    wire:click="toggleActive({{ $offer->id }})"
                                />
                                <flux:dropdown>
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item icon="link" onclick="navigator.clipboard.writeText('{{ route('offer.show', $offer->slug) }}'); this.closest('[data-flux-dropdown]')?.dispatchEvent(new Event('close'));">
                                            {{ __('Copy Link') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="arrow-top-right-on-square" onclick="window.open('{{ route('offer.show', $offer->slug) }}', '_blank')">
                                            {{ __('Preview') }}
                                        </flux:menu.item>
                                        <flux:menu.item
                                            icon="trash"
                                            variant="danger"
                                            wire:click="delete({{ $offer->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this campaign? This cannot be undone.') }}"
                                        >
                                            {{ __('Delete') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- Empty State --}}
        <div class="border border-dashed border-zinc-300 dark:border-zinc-700 rounded-lg py-16 text-center">
            <flux:icon name="megaphone" class="h-10 w-10 text-zinc-300 dark:text-zinc-600 mx-auto mb-4" />
            <flux:heading size="sm" class="text-zinc-600 dark:text-zinc-400">{{ __('No campaigns yet') }}</flux:heading>
            <flux:text class="text-zinc-500 mt-1 text-sm">{{ __('Create your first Facebook offer campaign to start A/B testing.') }}</flux:text>
            <flux:text class="text-zinc-400 mt-2 text-xs">{{ __('Variant A shows a hero photo. Variant B shows an auto-playing video.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" size="sm" icon="plus" wire:click="create">
                    {{ __('New Campaign') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Create/Edit Modal --}}
    <flux:modal wire:model="showForm" class="max-w-2xl">
        <flux:heading size="lg" class="mb-1">
            {{ $editingId ? __('Edit Campaign') : __('New Campaign') }}
        </flux:heading>
        <flux:text class="text-sm text-zinc-500 mb-4">
            {{ __('Variant A shows the hero photo. Variant B shows the auto-playing video.') }}
        </flux:text>

        <form wire:submit="save" class="space-y-4">
            {{-- Title --}}
            <flux:field>
                <flux:label>{{ __('Campaign Title') }} <span class="text-red-500">*</span></flux:label>
                <flux:input wire:model="title" placeholder="{{ __('e.g., Spring Painting Special') }}" />
                @error('title') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </flux:field>

            {{-- Headline --}}
            <flux:field>
                <flux:label>{{ __('Headline (H1 on page)') }} <span class="text-red-500">*</span></flux:label>
                <flux:input wire:model="headline" placeholder="{{ __('e.g., The VIP Paint Package') }}" />
                @error('headline') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </flux:field>

            {{-- Subheadline --}}
            <flux:field>
                <flux:label>{{ __('Subheadline') }}</flux:label>
                <flux:textarea wire:model="subheadline" rows="2" placeholder="{{ __('Optional supporting text...') }}" />
            </flux:field>

            {{-- Offer Bullets --}}
            <flux:field>
                <flux:label>{{ __('Offer Bullets') }} <span class="text-red-500">*</span></flux:label>
                <div class="space-y-2">
                    @foreach($bullets as $index => $bullet)
                        <div wire:key="bullet-{{ $index }}" class="flex items-center gap-2">
                            <flux:input
                                wire:model="bullets.{{ $index }}"
                                placeholder="{{ __('Benefit point...') }}"
                                class="flex-1"
                            />
                            @if(count($bullets) > 1)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="x-mark"
                                    wire:click="removeBullet({{ $index }})"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addBullet" class="mt-2">
                    {{ __('Add Bullet') }}
                </flux:button>
            </flux:field>

            {{-- CTA Text --}}
            <flux:field>
                <flux:label>{{ __('CTA Button Text') }}</flux:label>
                <flux:input wire:model="cta_text" placeholder="Claim Your Free Estimate" />
            </flux:field>

            {{-- Variant A: Hero Photo Upload --}}
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-amber-100 text-amber-700 text-xs font-bold">A</span>
                    <flux:label class="!mb-0">{{ __('Hero Photo (Variant A)') }}</flux:label>
                </div>

                @if($editingId)
                    @php $editingOffer = FbLandingPageOffer::find($editingId); @endphp
                    @if($editingOffer?->hero_photo_path && !$heroPhotoUpload)
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-24 h-16 rounded-lg bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <img src="{{ $editingOffer->hero_photo_url }}" alt="" class="w-full h-full object-cover">
                            </div>
                            <div class="flex-1">
                                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Current photo') }}</flux:text>
                                <flux:text class="text-xs text-zinc-400">{{ $editingOffer->hero_photo_path }}</flux:text>
                            </div>
                        </div>
                    @endif
                @endif

                <input
                    type="file"
                    wire:model="heroPhotoUpload"
                    accept="image/*"
                    class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300"
                />
                <div wire:loading wire:target="heroPhotoUpload" class="mt-2">
                    <flux:text class="text-xs text-blue-500">{{ __('Uploading...') }}</flux:text>
                </div>
                @if($heroPhotoUpload)
                    <div class="mt-2 flex items-center gap-3">
                        <div class="w-24 h-16 rounded-lg bg-zinc-100 overflow-hidden">
                            <img src="{{ $heroPhotoUpload->temporaryUrl() }}" alt="" class="w-full h-full object-cover">
                        </div>
                        <flux:text class="text-xs text-green-600">{{ __('New photo ready') }}</flux:text>
                    </div>
                @endif
                @error('heroPhotoUpload') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Variant B: Video Selection --}}
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-blue-100 text-blue-700 text-xs font-bold">B</span>
                    <flux:label class="!mb-0">{{ __('Video (Variant B)') }}</flux:label>
                </div>

                @if($this->selectedVideo)
                    <div class="flex items-center gap-3 p-2 rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20">
                        <div class="w-20 h-12 rounded bg-zinc-100 dark:bg-zinc-800 shrink-0 overflow-hidden relative">
                            @if($this->selectedVideo->getThumbnailUrl())
                                <img src="{{ $this->selectedVideo->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover">
                            @endif
                            <div class="absolute inset-0 flex items-center justify-center">
                                <svg class="w-4 h-4 text-white drop-shadow" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:text class="text-sm font-medium truncate">{{ $this->selectedVideo->title ?? __('Untitled') }}</flux:text>
                            <flux:text class="text-xs text-zinc-500">{{ $this->selectedVideo->formatted_duration ?? '' }}</flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearVideo" />
                    </div>
                @else
                    <flux:button size="sm" variant="ghost" icon="video-camera" wire:click="openVideoPicker" class="w-full justify-center border border-dashed border-zinc-300 dark:border-zinc-600 !py-6">
                        {{ __('Select Video from Media Hub') }}
                    </flux:button>
                @endif
            </div>

            {{-- FB Pixel ID --}}
            <flux:field>
                <flux:label>{{ __('Facebook Pixel ID') }}</flux:label>
                <flux:input wire:model="fb_pixel_id" placeholder="{{ __('e.g., 123456789012345') }}" />
            </flux:field>

            {{-- Active Toggle --}}
            <div class="flex items-center gap-3">
                <flux:switch wire:model="is_active" />
                <flux:text class="text-sm">{{ __('Campaign is active') }}</flux:text>
            </div>

            {{-- Form Actions --}}
            <div class="flex justify-end gap-2 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" type="button" wire:click="cancelForm">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save,heroPhotoUpload">
                    <span wire:loading.remove wire:target="save">
                        {{ $editingId ? __('Update Campaign') : __('Create Campaign') }}
                    </span>
                    <span wire:loading wire:target="save">
                        {{ __('Saving...') }}
                    </span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Video Picker Modal --}}
    <flux:modal wire:model="showVideoPicker" class="max-w-2xl">
        <flux:heading size="lg" class="mb-4">{{ __('Select Video') }}</flux:heading>

        <flux:input
            wire:model.live.debounce.300ms="videoSearch"
            placeholder="{{ __('Search videos...') }}"
            icon="magnifying-glass"
            class="mb-4"
        />

        <div class="max-h-96 overflow-y-auto space-y-2">
            @forelse($this->availableVideos as $video)
                <button
                    wire:key="picker-video-{{ $video->id }}"
                    wire:click="selectVideo({{ $video->id }})"
                    type="button"
                    class="w-full text-left flex items-center gap-3 p-2 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all"
                >
                    <div class="w-20 h-12 rounded bg-zinc-100 dark:bg-zinc-800 shrink-0 overflow-hidden relative">
                        @if($video->getThumbnailUrl())
                            <img src="{{ $video->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover">
                        @else
                            <div class="flex items-center justify-center h-full">
                                <flux:icon name="video-camera" class="h-4 w-4 text-zinc-400" />
                            </div>
                        @endif
                        <div class="absolute inset-0 flex items-center justify-center">
                            <svg class="w-4 h-4 text-white drop-shadow" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 truncate">{{ $video->title ?? __('Untitled') }}</p>
                        <p class="text-xs text-zinc-500">{{ $video->formatted_duration ?? '' }} &middot; {{ $video->created_at->format('M j, Y') }}</p>
                    </div>
                    <flux:icon name="plus-circle" class="h-5 w-5 text-blue-500 shrink-0" />
                </button>
            @empty
                <div class="text-center py-8">
                    <flux:text class="text-sm text-zinc-500">{{ __('No videos found.') }}</flux:text>
                </div>
            @endforelse
        </div>
    </flux:modal>
</div>
