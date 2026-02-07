<?php

use App\Models\PropertyPhoto;
use App\Services\PhotoService;
use App\Services\PhotoUrlService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public PropertyPhoto $photo;

    public Collection $photos;

    public bool $canAnnotate = false;

    public bool $canComment = false;

    public bool $canDelete = false;

    public bool $showAnnotated = false;

    public bool $showComments = false;

    #[Computed]
    public function currentIndex(): int
    {
        return $this->photos->search(fn ($p) => $p->id === $this->photo->id) ?: 0;
    }

    #[Computed]
    public function hasAnnotation(): bool
    {
        return $this->photo->annotation !== null && $this->photo->annotated_path !== null;
    }

    #[Computed]
    public function displayUrl(): string
    {
        if ($this->showAnnotated && $this->hasAnnotation) {
            $urlService = app(PhotoUrlService::class);

            return $urlService->getUrl($this->photo, 'annotated') ?? $this->photo->url;
        }

        return $this->photo->url;
    }

    public function nextPhoto(): void
    {
        $nextIndex = $this->currentIndex + 1;

        if ($nextIndex < $this->photos->count()) {
            $this->photo = $this->photos[$nextIndex];
            $this->showAnnotated = false;
            $this->showComments = false;
            unset($this->currentIndex, $this->hasAnnotation, $this->displayUrl);
        }
    }

    public function previousPhoto(): void
    {
        $prevIndex = $this->currentIndex - 1;

        if ($prevIndex >= 0) {
            $this->photo = $this->photos[$prevIndex];
            $this->showAnnotated = false;
            $this->showComments = false;
            unset($this->currentIndex, $this->hasAnnotation, $this->displayUrl);
        }
    }

    public function toggleAnnotated(): void
    {
        $this->showAnnotated = ! $this->showAnnotated;
        unset($this->displayUrl);
    }

    public function toggleComments(): void
    {
        $this->showComments = ! $this->showComments;
    }

    public function close(): void
    {
        $this->dispatch('close-lightbox');
    }

    public function deletePhoto(): void
    {
        if (! $this->canDelete) {
            return;
        }

        app(PhotoService::class)->delete($this->photo);
        $this->dispatch('photo-deleted');
    }


}; ?>

<div
    x-data="{}"
    x-on:keydown.escape.window="$wire.close()"
    x-on:keydown.arrow-left.window="$wire.previousPhoto()"
    x-on:keydown.arrow-right.window="$wire.nextPhoto()"
    class="fixed inset-0 z-50 bg-black/90 flex flex-col"
>
    {{-- Top Bar --}}
    <div class="flex items-center justify-between px-4 py-3 text-white">
        <div class="flex items-center gap-3">
            @if ($photo->caption)
                <span class="text-sm text-zinc-300">{{ $photo->caption }}</span>
            @endif
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

        <span class="text-sm text-zinc-400">
            {{ $this->currentIndex + 1 }} / {{ $photos->count() }}
        </span>

        <button wire:click="close" class="p-2 hover:bg-white/10 rounded-lg transition-colors" title="Close (Esc)">
            <flux:icon name="x-mark" class="w-6 h-6 text-white" />
        </button>
    </div>

    {{-- Main Image Area --}}
    <div class="flex-1 flex items-center justify-center relative min-h-0 px-16">
        {{-- Previous Button --}}
        @if ($this->currentIndex > 0)
            <button
                wire:click="previousPhoto"
                class="absolute left-4 top-1/2 -translate-y-1/2 p-3 bg-white/10 hover:bg-white/20 rounded-full transition-colors"
                title="Previous"
            >
                <flux:icon name="chevron-left" class="w-6 h-6 text-white" />
            </button>
        @endif

        {{-- Photo --}}
        <img
            src="{{ $this->displayUrl }}"
            alt="{{ $photo->caption ?? $photo->filename }}"
            class="max-h-[80vh] max-w-full object-contain rounded-lg"
        />

        {{-- Next Button --}}
        @if ($this->currentIndex < $photos->count() - 1)
            <button
                wire:click="nextPhoto"
                class="absolute right-4 top-1/2 -translate-y-1/2 p-3 bg-white/10 hover:bg-white/20 rounded-full transition-colors"
                title="Next"
            >
                <flux:icon name="chevron-right" class="w-6 h-6 text-white" />
            </button>
        @endif

        {{-- Comments Panel --}}
        @if ($showComments && $canComment)
            <div class="absolute right-0 top-0 bottom-0 z-10">
                <livewire:shared.photo-comments
                    :photo="$photo"
                    wire:key="comments-{{ $photo->id }}"
                />
            </div>
        @endif
    </div>

    {{-- Bottom Action Bar --}}
    <div class="flex items-center justify-center gap-3 px-4 py-3">
        {{-- Original / Annotated Toggle --}}
        @if ($this->hasAnnotation)
            <button
                wire:click="toggleAnnotated"
                class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors {{ $showAnnotated ? 'bg-blue-600 text-white' : 'bg-white/10 text-zinc-300 hover:bg-white/20' }}"
            >
                <flux:icon name="pencil-square" class="w-4 h-4" />
                {{ $showAnnotated ? 'Annotated' : 'Original' }}
            </button>
        @endif

        {{-- Annotate Button --}}
        @if ($canAnnotate)
            <button
                x-on:click="$flux.toast('Annotation editor coming soon.')"
                class="flex items-center gap-2 px-3 py-2 bg-white/10 text-zinc-300 hover:bg-white/20 rounded-lg text-sm transition-colors"
                title="Annotate"
            >
                <flux:icon name="pencil" class="w-4 h-4" />
                Annotate
            </button>
        @endif

        {{-- Comments Button --}}
        @if ($canComment)
            <button
                wire:click="toggleComments"
                class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors {{ $showComments ? 'bg-blue-600 text-white' : 'bg-white/10 text-zinc-300 hover:bg-white/20' }}"
                title="Comments"
            >
                <flux:icon name="chat-bubble-left" class="w-4 h-4" />
                Comments
                @if ($photo->comments->count() > 0)
                    <span class="bg-white/20 rounded-full px-1.5 py-0.5 text-xs">{{ $photo->comments->count() }}</span>
                @endif
            </button>
        @endif

        {{-- Download --}}
        <a
            href="{{ $photo->url }}"
            download="{{ $photo->original_filename ?? $photo->filename }}"
            class="flex items-center gap-2 px-3 py-2 bg-white/10 text-zinc-300 hover:bg-white/20 rounded-lg text-sm transition-colors"
            title="Download"
        >
            <flux:icon name="arrow-down-tray" class="w-4 h-4" />
            Download
        </a>

        {{-- Delete --}}
        @if ($canDelete)
            <button
                wire:click="deletePhoto"
                wire:confirm="Are you sure you want to delete this photo?"
                class="flex items-center gap-2 px-3 py-2 bg-white/10 text-red-400 hover:bg-red-600/20 rounded-lg text-sm transition-colors"
                title="Delete"
            >
                <flux:icon name="trash" class="w-4 h-4" />
                Delete
            </button>
        @endif
    </div>

    {{-- Keyboard Hint --}}
    <div class="text-center pb-2">
        <flux:text class="text-xs text-zinc-500">
            Use arrow keys to navigate, Esc to close
        </flux:text>
    </div>
</div>
