<?php

use App\Models\Estimate;
use App\Models\Job;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Services\PhotoService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Property $property;

    public ?int $estimateId = null;

    public ?int $jobId = null;

    public string $context = 'property';

    public string $categoryFilter = 'all';

    public ?int $selectedPhotoId = null;

    public bool $canUpload = false;

    public bool $canDelete = false;

    public bool $canAnnotate = false;

    public bool $canComment = false;

    public bool $canReorder = false;

    public bool $canSetProposal = false;

    public function mount(
        Property $property,
        ?Estimate $estimate = null,
        ?Job $job = null,
        string $context = 'property',
    ): void {
        $this->property = $property;
        $this->estimateId = $estimate?->id;
        $this->jobId = $job?->id;
        $this->context = $context;

        $this->resolvePermissions($estimate, $job);
    }

    private function resolvePermissions(?Estimate $estimate, ?Job $job): void
    {
        $user = auth()->user();
        $photoService = app(PhotoService::class);

        if (! $user) {
            return;
        }

        $this->canUpload = $photoService->canUpload($user, $this->property, 'general', $job);
        $this->canDelete = $user->isInternal() && $user->can('photos.delete');
        $this->canAnnotate = $user->isInternal() && $user->can('photos.annotate');
        $this->canComment = $user->isInternal() || $user->type === 'crew' || $user->type === 'customer';
        $this->canReorder = $user->isInternal();
        $this->canSetProposal = $user->isInternal() && $estimate !== null;
    }

    #[Computed]
    public function photos(): Collection
    {
        $user = auth()->user();
        $job = $this->jobId ? Job::find($this->jobId) : null;

        if ($user) {
            $photos = app(PhotoService::class)->getVisiblePhotos($user, $this->property, $job, $this->estimateId);
        } else {
            $photos = PropertyPhoto::where('property_id', $this->property->id)
                ->where('show_on_proposal', true)
                ->orderBy('sort_order')
                ->get();
        }

        return $photos->load(['annotation', 'comments']);
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

    #[On('photos-uploaded')]
    public function refreshPhotos(): void
    {
        unset($this->photos, $this->filteredPhotos, $this->categoryCounts);
    }

    #[On('photo-deleted')]
    public function handlePhotoDeleted(): void
    {
        $this->selectedPhotoId = null;
        unset($this->photos, $this->filteredPhotos, $this->categoryCounts);
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="lg">Photos</flux:heading>
        @if ($canUpload)
            <flux:button wire:click="$dispatch('open-upload-modal')" icon="arrow-up-tray" size="sm">
                Upload Photos
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
            All ({{ $this->photos->count() }})
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
            <flux:heading size="sm" class="mb-1">No photos yet</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400 mb-4">
                @if ($categoryFilter === 'all')
                    Upload photos to document this property.
                @else
                    No {{ strtolower(\App\Models\PropertyPhoto::CATEGORIES[$categoryFilter] ?? $categoryFilter) }} photos found.
                @endif
            </flux:text>
            @if ($categoryFilter === 'all' && $canUpload)
                <flux:button wire:click="$dispatch('open-upload-modal')" icon="arrow-up-tray" size="sm">
                    Upload Photos
                </flux:button>
            @endif
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach ($this->filteredPhotos as $photo)
                <div wire:key="photo-{{ $photo->id }}" class="group relative rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-800 cursor-pointer">
                    {{-- Thumbnail --}}
                    <div class="aspect-square" wire:click="openLightbox({{ $photo->id }})">
                        <img
                            src="{{ $photo->url }}"
                            alt="{{ $photo->caption ?? $photo->filename }}"
                            class="w-full h-full object-cover"
                            loading="lazy"
                        />
                    </div>

                    {{-- Category Badge (top-left) --}}
                    <div class="absolute top-2 left-2">
                        <flux:badge size="sm" color="{{ match($photo->category) {
                            'before' => 'yellow',
                            'during' => 'blue',
                            'after' => 'green',
                            'reference' => 'purple',
                            default => 'zinc',
                        } }}">
                            {{ \App\Models\PropertyPhoto::CATEGORIES[$photo->category] ?? $photo->category }}
                        </flux:badge>
                    </div>

                    {{-- Annotation Indicator (top-right) --}}
                    @if ($photo->annotation)
                        <div class="absolute top-2 right-2">
                            <div class="bg-white/80 dark:bg-zinc-900/80 rounded-full p-1">
                                <flux:icon name="pencil" class="w-3.5 h-3.5 text-zinc-700 dark:text-zinc-300" />
                            </div>
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
                                    title="View"
                                >
                                    <flux:icon name="eye" class="w-4 h-4 text-zinc-700 dark:text-zinc-300" />
                                </button>
                                @if ($canSetProposal)
                                    <button
                                        wire:click.stop="toggleProposal({{ $photo->id }})"
                                        class="p-1.5 rounded-lg transition-colors {{ $photo->show_on_proposal ? 'bg-amber-100 dark:bg-amber-900/50 hover:bg-amber-200' : 'bg-white/90 dark:bg-zinc-800/90 hover:bg-white dark:hover:bg-zinc-700' }}"
                                        title="{{ $photo->show_on_proposal ? 'Remove from proposal' : 'Add to proposal' }}"
                                    >
                                        <flux:icon name="star" class="w-4 h-4 {{ $photo->show_on_proposal ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-700 dark:text-zinc-300' }}" />
                                    </button>
                                @endif
                            </div>
                            @if ($canDelete)
                                <button
                                    wire:click.stop="deletePhoto({{ $photo->id }})"
                                    wire:confirm="Are you sure you want to delete this photo?"
                                    class="p-1.5 bg-white/90 dark:bg-zinc-800/90 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors"
                                    title="Delete"
                                >
                                    <flux:icon name="trash" class="w-4 h-4 text-red-600 dark:text-red-400" />
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Proposal Indicator --}}
                    @if ($photo->show_on_proposal && $canSetProposal)
                        <div class="absolute top-2 right-2 {{ $photo->annotation ? 'top-8' : '' }}">
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
            :allowed-categories="app(PhotoService::class)->getAllowedUploadCategories(auth()->user(), $jobId ? Job::find($jobId) : null)"
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
</div>
