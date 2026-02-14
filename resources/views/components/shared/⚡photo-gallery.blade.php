<?php

use App\Models\Estimate;
use App\Models\Job;
use App\Models\PhotoView;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Services\PhotoService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public Property $property;

    public ?int $estimateId = null;

    public ?int $jobId = null;

    public string $context = 'property';

    public bool $compact = false;

    public string $categoryFilter = 'all';

    public ?int $selectedPhotoId = null;

    public ?int $annotatingPhotoId = null;

    public bool $canUpload = false;

    public bool $canDelete = false;

    public bool $canAnnotate = false;

    public bool $canComment = false;

    public bool $canReorder = false;

    public bool $canSetProposal = false;

    private function resolveUser(): ?\App\Models\User
    {
        return auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();
    }

    private function resolveUserId(): ?int
    {
        return auth()->id() ?? auth('customer')->id() ?? auth('agent')->id() ?? auth('crew')->id();
    }

    public function mount(
        Property $property,
        ?Estimate $estimate = null,
        ?Job $job = null,
        string $context = 'property',
        bool $readOnly = false,
    ): void {
        $this->property = $property;
        $this->estimateId = $estimate?->id;
        $this->jobId = $job?->id;
        $this->context = $context;

        if ($readOnly) {
            return;
        }

        $this->resolvePermissions($estimate, $job);
    }

    private function resolvePermissions(?Estimate $estimate, ?Job $job): void
    {
        $user = $this->resolveUser();
        $photoService = app(PhotoService::class);

        if (! $user) {
            return;
        }

        $this->canUpload = $photoService->canUpload($user, $this->property, 'general', $job);
        $this->canDelete = $user->isInternal() && $user->can('photos.delete');
        $this->canAnnotate = ($user->isInternal() && $user->can('photos.annotate'))
            || $user->type === 'crew'
            || $user->type === 'customer'
            || $user->type === 'agent';
        $this->canComment = $user->isInternal() || $user->type === 'crew' || $user->type === 'customer' || $user->type === 'agent';
        $this->canReorder = $user->isInternal();
        $this->canSetProposal = $user->isInternal() && $estimate !== null;
    }

    #[Computed]
    public function photos(): Collection
    {
        $user = $this->resolveUser();
        $job = $this->jobId ? Job::find($this->jobId) : null;

        if ($user) {
            $photos = app(PhotoService::class)->getVisiblePhotos($user, $this->property, $job, $this->estimateId);
        } else {
            $photos = PropertyPhoto::where('property_id', $this->property->id)
                ->where('show_on_proposal', true)
                ->orderBy('sort_order')
                ->get();
        }

        return $photos->load(['annotations', 'comments', 'views']);
    }

    #[Computed]
    public function filteredPhotos(): Collection
    {
        if ($this->categoryFilter === 'all') {
            return $this->photos;
        }

        return $this->photos->where('category', $this->categoryFilter);
    }

    #[Computed]
    public function categoryCounts(): array
    {
        $counts = [];

        foreach (PropertyPhoto::CATEGORIES as $key => $label) {
            $counts[$key] = $this->photos->where('category', $key)->count();
        }

        return $counts;
    }

    #[Computed]
    public function unviewedCount(): int
    {
        $user = $this->resolveUser();

        if (! $user) {
            return 0;
        }

        return $this->photos->filter(fn ($photo) => $photo->hasUnviewedChanges($user))->count();
    }

    public function setCategoryFilter(string $category): void
    {
        $this->categoryFilter = $category;
    }

    public function deletePhoto(int $id): void
    {
        if (! $this->canDelete) {
            return;
        }

        $photo = PropertyPhoto::where('property_id', $this->property->id)->find($id);

        if (! $photo) {
            return;
        }

        app(PhotoService::class)->delete($photo);
        unset($this->photos, $this->filteredPhotos, $this->categoryCounts);
    }

    public function toggleProposal(int $id): void
    {
        if (! $this->canSetProposal) {
            return;
        }

        $photo = PropertyPhoto::where('property_id', $this->property->id)->find($id);

        if (! $photo) {
            return;
        }

        $photo->update(['show_on_proposal' => ! $photo->show_on_proposal]);
        unset($this->photos, $this->filteredPhotos);
    }

    public function openLightbox(int $id): void
    {
        $this->selectedPhotoId = $id;
    }

    public function closeLightbox(): void
    {
        $this->selectedPhotoId = null;
    }

    #[On('close-lightbox')]
    public function handleCloseLightbox(): void
    {
        $this->selectedPhotoId = null;
    }

    #[On('open-annotator')]
    public function openAnnotator(int $photoId): void
    {
        $this->selectedPhotoId = null;
        $this->annotatingPhotoId = $photoId;
    }

    #[On('close-annotator')]
    public function closeAnnotator(): void
    {
        $this->annotatingPhotoId = null;
        unset($this->photos, $this->filteredPhotos);
    }

    public function getListeners(): array
    {
        $listeners = [
            'photos-uploaded' => 'refreshPhotos',
            'photo-deleted' => 'handlePhotoDeleted',
        ];

        if ($this->jobId) {
            $channel = match ($this->resolveUser()?->type) {
                'crew' => "echo-private:crew.job.{$this->jobId}",
                'customer' => "echo-private:customer.job.{$this->jobId}",
                default => "echo-private:job.{$this->jobId}",
            };
            $listeners["{$channel},photo.updated"] = 'onPhotoUpdated';
        }

        return $listeners;
    }

    public function onPhotoUpdated(array $event): void
    {
        if (($event['actor_id'] ?? null) == $this->resolveUserId()) {
            return;
        }

        unset($this->photos, $this->filteredPhotos, $this->categoryCounts, $this->unviewedCount);
    }

    public function refreshPhotos(): void
    {
        unset($this->photos, $this->filteredPhotos, $this->categoryCounts, $this->unviewedCount);
    }

    public function handlePhotoDeleted(): void
    {
        $this->selectedPhotoId = null;
        unset($this->photos, $this->filteredPhotos, $this->categoryCounts, $this->unviewedCount);
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <flux:heading size="lg">{{ __('Photos') }}</flux:heading>
            @if ($this->unviewedCount > 0)
                <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-xs font-bold text-white bg-blue-500 rounded-full">
                    {{ $this->unviewedCount }}
                </span>
            @endif
        </div>
        @if ($canUpload)
            <flux:button wire:click="$dispatch('open-upload-modal')" icon="arrow-up-tray" size="sm">
                {{ __('Upload Photos') }}
            </flux:button>
        @endif
    </div>

    {{-- Category Filter Tabs --}}
    <div class="flex gap-2 mb-4 overflow-x-auto">
        <flux:button
            wire:click="setCategoryFilter('all')"
            variant="{{ $categoryFilter === 'all' ? 'filled' : 'ghost' }}"
            size="sm"
        >
            {{ __('All') }} ({{ $this->photos->count() }})
        </flux:button>
        @foreach (\App\Models\PropertyPhoto::CATEGORIES as $key => $label)
            @php $count = $this->categoryCounts[$key] ?? 0; @endphp
            @if ($count > 0)
                <flux:button
                    wire:click="setCategoryFilter('{{ $key }}')"
                    variant="{{ $categoryFilter === $key ? 'filled' : 'ghost' }}"
                    size="sm"
                >
                    {{ $label }} ({{ $count }})
                </flux:button>
            @endif
        @endforeach
    </div>

    {{-- Photo Grid --}}
    @if ($this->filteredPhotos->isEmpty())
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
            <flux:icon name="photo" class="w-12 h-12 mx-auto text-zinc-400 mb-3" />
            <flux:heading size="sm" class="mb-1">{{ __('No photos yet') }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400 mb-4">
                @if ($categoryFilter === 'all')
                    {{ __('Upload photos to document this property.') }}
                @else
                    {{ __('No :category photos found.', ['category' => strtolower(\App\Models\PropertyPhoto::CATEGORIES[$categoryFilter] ?? $categoryFilter)]) }}
                @endif
            </flux:text>
            @if ($categoryFilter === 'all' && $canUpload)
                <flux:button wire:click="$dispatch('open-upload-modal')" icon="arrow-up-tray" size="sm">
                    {{ __('Upload Photos') }}
                </flux:button>
            @endif
        </div>
    @else
        <div class="grid {{ $compact ? 'grid-cols-2 sm:grid-cols-3 gap-2' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3' }}">
            @foreach ($this->filteredPhotos as $photo)
                <div wire:key="photo-{{ $photo->id }}" class="group relative rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-800 cursor-pointer">
                    {{-- Thumbnail --}}
                    <div class="aspect-square" wire:click="openLightbox({{ $photo->id }})">
                        <img
                            src="{{ $photo->display_url }}"
                            alt="{{ $photo->caption ?? $photo->filename }}"
                            class="w-full h-full object-cover"
                            loading="lazy"
                        />
                    </div>

                    {{-- Category Badge (top-left) --}}
                    <div class="absolute top-2 left-2 flex flex-col gap-1">
                        <flux:badge size="sm" color="{{ match($photo->category) {
                            'before' => 'yellow',
                            'during' => 'blue',
                            'after' => 'green',
                            'reference' => 'purple',
                            default => 'zinc',
                        } }}">
                            {{ \App\Models\PropertyPhoto::CATEGORIES[$photo->category] ?? $photo->category }}
                        </flux:badge>
                        @if ($photo->hasUnviewedChanges(auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user()))
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-bold text-white bg-blue-500 rounded-full shadow-sm">
                                <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="5" fill="currentColor" /></svg>
                                {{ __('Edited') }}
                            </span>
                        @endif
                    </div>

                    {{-- Annotation Indicators (top-right) --}}
                    @if ($photo->has_annotations)
                        <div class="absolute top-2 right-2 flex items-center gap-1">
                            @foreach ($photo->annotations as $ann)
                                <div
                                    class="w-3 h-3 rounded-full border border-white/50"
                                    style="background: {{ $ann->author_color }}"
                                    title="{{ $ann->author_type_label }} annotation"
                                ></div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Comment Count (bottom-right) --}}
                    @if ($photo->comments->count() > 0)
                        <div class="absolute bottom-2 right-2">
                            <div class="flex items-center gap-1 bg-white/80 dark:bg-zinc-900/80 rounded-full px-2 py-0.5 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                <flux:icon name="chat-bubble-left" class="w-3 h-3" />
                                {{ $photo->comments->count() }}
                            </div>
                        </div>
                    @endif

                    {{-- Hover Overlay --}}
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors pointer-events-none">
                        <div class="absolute bottom-2 left-2 right-2 flex items-center justify-between opacity-0 group-hover:opacity-100 transition-opacity pointer-events-auto">
                            <div class="flex gap-1">
                                <button
                                    wire:click="openLightbox({{ $photo->id }})"
                                    class="p-1.5 bg-white/90 dark:bg-zinc-800/90 rounded-lg hover:bg-white dark:hover:bg-zinc-700 transition-colors"
                                    title="{{ __('View') }}"
                                >
                                    <flux:icon name="eye" class="w-4 h-4 text-zinc-700 dark:text-zinc-300" />
                                </button>
                                @if ($canSetProposal)
                                    <button
                                        wire:click.stop="toggleProposal({{ $photo->id }})"
                                        class="p-1.5 rounded-lg transition-colors {{ $photo->show_on_proposal ? 'bg-amber-100 dark:bg-amber-900/50 hover:bg-amber-200' : 'bg-white/90 dark:bg-zinc-800/90 hover:bg-white dark:hover:bg-zinc-700' }}"
                                        title="{{ $photo->show_on_proposal ? __('Remove from proposal') : __('Add to proposal') }}"
                                    >
                                        <flux:icon name="star" class="w-4 h-4 {{ $photo->show_on_proposal ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-700 dark:text-zinc-300' }}" />
                                    </button>
                                @endif
                            </div>
                            @if ($canDelete)
                                <button
                                    wire:click.stop="deletePhoto({{ $photo->id }})"
                                    wire:confirm="{{ __('Are you sure you want to delete this photo?') }}"
                                    class="p-1.5 bg-white/90 dark:bg-zinc-800/90 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"
                                    title="{{ __('Delete') }}"
                                >
                                    <flux:icon name="trash" class="w-4 h-4 text-red-600 dark:text-red-400" />
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Proposal Indicator --}}
                    @if ($photo->show_on_proposal && $canSetProposal)
                        <div class="absolute top-2 right-2 {{ $photo->has_annotations ? 'top-8' : '' }}">
                            <div class="bg-amber-100/90 dark:bg-amber-900/80 rounded-full p-1">
                                <flux:icon name="star" class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" />
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Upload Modal --}}
    @if ($canUpload)
        <livewire:shared.photo-upload
            :property="$property"
            :estimate-id="$estimateId"
            :job-id="$jobId"
            :allowed-categories="app(PhotoService::class)->getAllowedUploadCategories(auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user(), $jobId ? Job::find($jobId) : null)"
            wire:key="upload-{{ $property->id }}"
        />
    @endif

    {{-- Lightbox --}}
    @if ($selectedPhotoId)
        @php
            $selectedPhoto = $this->photos->firstWhere('id', $selectedPhotoId);
        @endphp
        @if ($selectedPhoto)
            <livewire:shared.photo-lightbox
                :photo="$selectedPhoto"
                :photos="$this->photos"
                :can-annotate="$canAnnotate"
                :can-comment="$canComment"
                :can-delete="$canDelete"
                wire:key="lightbox-{{ $selectedPhotoId }}"
            />
        @endif
    @endif

    {{-- Annotation Editor --}}
    @if ($annotatingPhotoId && $canAnnotate)
        @php
            $annotatingPhoto = PropertyPhoto::with('annotations')->find($annotatingPhotoId);
        @endphp
        @if ($annotatingPhoto)
            <livewire:shared.photo-annotator
                :photo="$annotatingPhoto"
                wire:key="annotator-{{ $annotatingPhotoId }}"
            />
        @endif
    @endif
</div>
