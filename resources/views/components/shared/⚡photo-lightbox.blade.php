<?php

use App\Models\PhotoView;
use App\Models\PropertyPhoto;
use App\Services\PhotoService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public PropertyPhoto $photo;

    public Collection $photos;

    public bool $canAnnotate = false;

    public bool $canComment = false;

    public bool $canDelete = false;

    /** @var string 'all'|'internal'|'crew'|'customer'|'original' */
    public string $annotationFilter = 'all';

    public bool $showComments = false;

    public function mount(): void
    {
        // Force reload annotations (not loadMissing) to get fresh data after edits
        $this->photo->load('annotations');
        $this->annotationFilter = $this->photo->has_annotations ? 'all' : 'original';
        $this->markCurrentPhotoViewed();
    }

    public function getListeners(): array
    {
        $jobId = $this->photo->job_id;

        if (! $jobId) {
            return [];
        }

        $user = auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();
        $channel = match ($user?->type) {
            'crew' => "echo-private:crew.job.{$jobId}",
            'customer' => "echo-private:customer.job.{$jobId}",
            default => "echo-private:job.{$jobId}",
        };

        return [
            "{$channel},photo.updated" => 'onPhotoUpdated',
        ];
    }

    public function onPhotoUpdated(array $event): void
    {
        $userId = auth()->id() ?? auth('customer')->id() ?? auth('agent')->id() ?? auth('crew')->id();
        if (($event['actor_id'] ?? null) == $userId) {
            return;
        }

        // Refresh annotations when the current photo was annotated
        if (($event['photo_id'] ?? null) == $this->photo->id) {
            $this->photo->load('annotations');
            $this->annotationFilter = $this->photo->has_annotations ? 'all' : 'original';
            unset($this->hasAnnotations, $this->availableLayers, $this->displayUrl, $this->annotationsForFilter);
        }
    }

    private function markCurrentPhotoViewed(): void
    {
        $user = auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();

        if ($user) {
            PhotoView::markViewed($this->photo, $user);
        }
    }

    #[Computed]
    public function currentIndex(): int
    {
        return $this->photos->search(fn ($p) => $p->id === $this->photo->id) ?: 0;
    }

    #[Computed]
    public function hasAnnotations(): bool
    {
        return $this->photo->has_annotations;
    }

    /** @return array<string, string> */
    #[Computed]
    public function availableLayers(): array
    {
        $layers = [];
        $annotations = $this->photo->annotations;

        // Show "All" if there are any annotations (client-side composite rendering)
        if ($annotations->isNotEmpty()) {
            $layers['all'] = __('All');
        }

        foreach ($annotations as $annotation) {
            $layers[$annotation->author_type] = $annotation->author_type_label;
        }

        $layers['original'] = __('Original');

        return $layers;
    }

    #[Computed]
    public function displayUrl(): string
    {
        return $this->photo->url;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function annotationsForFilter(): array
    {
        if ($this->annotationFilter === 'original') {
            return [];
        }

        $annotations = $this->photo->annotations;

        if ($this->annotationFilter === 'all') {
            return $annotations->map(fn ($a) => $a->canvas_json)->values()->all();
        }

        $annotation = $annotations->firstWhere('author_type', $this->annotationFilter);

        return $annotation ? [$annotation->canvas_json] : [];
    }

    public function setAnnotationFilter(string $filter): void
    {
        $this->annotationFilter = $filter;
        unset($this->displayUrl, $this->annotationsForFilter);
    }

    public function nextPhoto(): void
    {
        $nextIndex = $this->currentIndex + 1;

        if ($nextIndex < $this->photos->count()) {
            $this->photo = $this->photos[$nextIndex];
            $this->photo->load('annotations');
            $this->annotationFilter = $this->photo->has_annotations ? 'all' : 'original';
            $this->showComments = false;
            $this->markCurrentPhotoViewed();
            unset($this->currentIndex, $this->hasAnnotations, $this->availableLayers, $this->displayUrl, $this->annotationsForFilter);
        }
    }

    public function previousPhoto(): void
    {
        $prevIndex = $this->currentIndex - 1;

        if ($prevIndex >= 0) {
            $this->photo = $this->photos[$prevIndex];
            $this->photo->load('annotations');
            $this->annotationFilter = $this->photo->has_annotations ? 'all' : 'original';
            $this->showComments = false;
            $this->markCurrentPhotoViewed();
            unset($this->currentIndex, $this->hasAnnotations, $this->availableLayers, $this->displayUrl, $this->annotationsForFilter);
        }
    }

    public function goToPhoto(int $index): void
    {
        if ($index >= 0 && $index < $this->photos->count()) {
            $this->photo = $this->photos[$index];
            $this->photo->load('annotations');
            $this->annotationFilter = $this->photo->has_annotations ? 'all' : 'original';
            $this->showComments = false;
            $this->markCurrentPhotoViewed();
            unset($this->currentIndex, $this->hasAnnotations, $this->availableLayers, $this->displayUrl, $this->annotationsForFilter);
        }
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
    x-data="{
        touchStartX: 0,
        touchEndX: 0,
        handleTouchStart(e) { this.touchStartX = e.changedTouches[0].screenX; },
        handleTouchEnd(e) {
            this.touchEndX = e.changedTouches[0].screenX;
            let diff = this.touchStartX - this.touchEndX;
            if (Math.abs(diff) > 50) {
                if (diff > 0) $wire.nextPhoto();
                else $wire.previousPhoto();
            }
        },
    }"
    x-on:keydown.escape.window="$wire.close()"
    x-on:keydown.arrow-left.window="$wire.previousPhoto()"
    x-on:keydown.arrow-right.window="$wire.nextPhoto()"
    x-on:touchstart.passive="handleTouchStart($event)"
    x-on:touchend.passive="handleTouchEnd($event)"
    class="fixed inset-0 z-50 bg-black flex flex-col"
    data-photo-overlay
>
    {{-- Top Bar --}}
    <div class="flex items-center justify-between px-3 sm:px-4 py-2 sm:py-3 bg-zinc-900/80 backdrop-blur-sm border-b border-zinc-800 shrink-0 relative z-20">
        {{-- Left: Close button + info --}}
        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
            <button
                wire:click="close"
                class="p-2 hover:bg-white/10 rounded-lg transition-colors shrink-0"
                title="{{ __('Close (Esc)') }}"
            >
                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <div class="flex items-center gap-2 min-w-0">
                <flux:badge size="sm" color="{{ match($photo->category) {
                    'before' => 'yellow',
                    'during' => 'blue',
                    'after' => 'green',
                    'reference' => 'purple',
                    default => 'zinc',
                } }}">
                    {{ \App\Models\PropertyPhoto::CATEGORIES[$photo->category] ?? $photo->category }}
                </flux:badge>
                @if ($photo->caption)
                    <span class="text-sm text-zinc-300 truncate hidden sm:inline">{{ $photo->caption }}</span>
                @endif
            </div>
        </div>

        {{-- Center: Counter --}}
        <span class="text-sm font-medium text-zinc-300">
            {{ $this->currentIndex + 1 }} / {{ $photos->count() }}
        </span>

        {{-- Right: Actions --}}
        <div class="flex items-center gap-1 sm:gap-2">
            {{-- Annotation Layer Filter --}}
            @if ($this->hasAnnotations)
                <div class="hidden sm:flex items-center gap-1 bg-zinc-800 rounded-lg p-0.5">
                    @foreach ($this->availableLayers as $key => $label)
                        @php
                            $layerColor = match($key) {
                                'internal' => '#3b82f6',
                                'crew' => '#f59e0b',
                                'customer' => '#10b981',
                                default => null,
                            };
                        @endphp
                        <button
                            wire:click="setAnnotationFilter('{{ $key }}')"
                            class="flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium transition-colors {{ $annotationFilter === $key ? 'bg-white/20 text-white' : 'text-zinc-400 hover:text-white hover:bg-white/10' }}"
                            title="{{ __('Show :label', ['label' => $label]) }}"
                        >
                            @if ($layerColor)
                                <span class="w-2 h-2 rounded-full shrink-0" style="background: {{ $layerColor }}"></span>
                            @endif
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                {{-- Mobile: dropdown style --}}
                <div class="sm:hidden relative" x-data="{ open: false }" x-on:touchstart.stop x-on:touchend.stop>
                    <button x-on:click.stop="open = !open" class="p-2 rounded-lg text-sm transition-colors text-zinc-300 hover:bg-white/10">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                        </svg>
                    </button>
                    <div
                        x-show="open"
                        x-on:click.outside="open = false"
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute right-0 top-full mt-1 bg-zinc-800 rounded-lg shadow-xl border border-zinc-700 py-1 min-w-[160px] z-[60]"
                        style="transform-origin: top right;"
                    >
                        @foreach ($this->availableLayers as $key => $label)
                            @php
                                $layerColor = match($key) {
                                    'internal' => '#3b82f6',
                                    'crew' => '#f59e0b',
                                    'customer' => '#10b981',
                                    default => null,
                                };
                            @endphp
                            <button
                                x-on:click.stop="open = false; $wire.call('setAnnotationFilter', '{{ $key }}')"
                                class="flex items-center gap-2 w-full px-3 py-3 text-sm text-left transition-colors {{ $annotationFilter === $key ? 'bg-white/10 text-white' : 'text-zinc-300 active:bg-white/10' }}"
                            >
                                @if ($layerColor)
                                    <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: {{ $layerColor }}"></span>
                                @endif
                                {{ $label }}
                                @if ($annotationFilter === $key)
                                    <svg class="w-3.5 h-3.5 ml-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Annotate --}}
            @if ($canAnnotate)
                <button
                    wire:click="$dispatch('open-annotator', { photoId: {{ $photo->id }} })"
                    class="p-2 text-zinc-300 hover:bg-white/10 rounded-lg transition-colors"
                    title="{{ __('Annotate') }}"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" />
                    </svg>
                </button>
            @endif

            {{-- Comments --}}
            @if ($canComment)
                <button
                    wire:click="toggleComments"
                    class="p-2 rounded-lg transition-colors relative {{ $showComments ? 'bg-blue-600 text-white' : 'text-zinc-300 hover:bg-white/10' }}"
                    title="{{ __('Comments') }}"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                    </svg>
                    @if ($photo->comments->count() > 0)
                        <span class="absolute -top-0.5 -right-0.5 bg-blue-500 text-white text-[10px] font-bold rounded-full w-4 h-4 flex items-center justify-center">{{ $photo->comments->count() }}</span>
                    @endif
                </button>
            @endif

            {{-- Download --}}
            <a
                href="{{ $photo->url }}"
                download="{{ $photo->original_filename ?? $photo->filename }}"
                class="p-2 text-zinc-300 hover:bg-white/10 rounded-lg transition-colors"
                title="{{ __('Download') }}"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
            </a>

            {{-- Delete --}}
            @if ($canDelete)
                <button
                    wire:click="deletePhoto"
                    wire:confirm="{{ __('Are you sure you want to delete this photo?') }}"
                    class="p-2 text-red-400 hover:bg-red-600/20 rounded-lg transition-colors"
                    title="{{ __('Delete') }}"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                    </svg>
                </button>
            @endif
        </div>
    </div>

    {{-- Main Image Area --}}
    <div class="flex-1 flex items-center justify-center relative min-h-0 p-2 sm:p-4" x-ref="imageArea">
        {{-- Previous Button (Desktop) --}}
        @if ($this->currentIndex > 0)
            <button
                wire:click="previousPhoto"
                class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 p-2 sm:p-3 bg-black/50 hover:bg-black/70 rounded-full transition-colors z-10"
                title="{{ __('Previous') }}"
            >
                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                </svg>
            </button>
        @endif

        {{-- Photo with client-side annotation overlay --}}
        <div class="relative flex items-center justify-center">
            <img
                src="{{ $photo->url }}"
                alt="{{ $photo->caption ?? $photo->filename }}"
                class="block max-h-full max-w-full object-contain"
                x-ref="lightboxImg"
                wire:key="lightbox-img-{{ $photo->id }}"
                x-init="$nextTick(() => {
                    let fitImage = () => {
                        let area = $refs.imageArea;
                        if (!area || !$el.naturalWidth) return;
                        let cs = getComputedStyle(area);
                        let maxW = area.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
                        let maxH = area.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
                        let scale = Math.min(maxW / $el.naturalWidth, maxH / $el.naturalHeight, 1);
                        $el.style.width = Math.floor($el.naturalWidth * scale) + 'px';
                        $el.style.height = Math.floor($el.naturalHeight * scale) + 'px';
                    };
                    if ($el.complete && $el.naturalWidth) fitImage();
                    $el.addEventListener('load', fitImage);
                })"
            />
            @if ($annotationFilter !== 'original' && count($this->annotationsForFilter) > 0)
                <canvas
                    wire:key="ann-canvas-{{ $photo->id }}-{{ $annotationFilter }}"
                    x-data="annotationRenderer(@js($this->annotationsForFilter))"
                    x-init="render()"
                    class="!absolute top-0 left-0 w-full h-full pointer-events-none"
                ></canvas>
            @endif
        </div>

        {{-- Next Button (Desktop) --}}
        @if ($this->currentIndex < $photos->count() - 1)
            <button
                wire:click="nextPhoto"
                class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 p-2 sm:p-3 bg-black/50 hover:bg-black/70 rounded-full transition-colors z-10"
                title="{{ __('Next') }}"
            >
                <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
            </button>
        @endif

        {{-- Comments Panel --}}
        @if ($showComments && $canComment)
            <div class="absolute right-0 top-0 bottom-0 w-80 sm:w-96 z-10 bg-zinc-900/95 backdrop-blur-sm border-l border-zinc-700">
                <livewire:shared.photo-comments
                    :photo="$photo"
                    wire:key="comments-{{ $photo->id }}"
                />
            </div>
        @endif
    </div>

    {{-- Bottom Thumbnail Strip --}}
    @if ($photos->count() > 1)
        <div class="shrink-0 bg-zinc-900/80 backdrop-blur-sm border-t border-zinc-800 px-3 py-2">
            <div class="flex gap-2 overflow-x-auto justify-center max-w-full" style="scrollbar-width: thin;">
                @foreach ($photos as $index => $thumbPhoto)
                    <button
                        wire:click="goToPhoto({{ $index }})"
                        wire:key="thumb-{{ $thumbPhoto->id }}"
                        class="shrink-0 w-12 h-12 sm:w-14 sm:h-14 rounded-md overflow-hidden transition-all {{ $thumbPhoto->id === $photo->id ? 'ring-2 ring-blue-500 opacity-100' : 'opacity-50 hover:opacity-80' }}"
                    >
                        <img
                            src="{{ $thumbPhoto->url }}"
                            alt=""
                            class="w-full h-full object-cover"
                            loading="lazy"
                        />
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>

@script
<script>
let fabricUrl = @js(Illuminate\Support\Facades\Vite::asset('resources/js/photo-annotator.js'));

Alpine.data('annotationRenderer', (layers) => ({
    fc: null,

    render() {
        if (!layers || !layers.length) return;

        let doRender = () => {
            if (window.fabric && window.fabric.StaticCanvas) {
                this.doRender();
            } else {
                requestAnimationFrame(doRender);
            }
        };

        // Load Fabric.js if not yet available
        if (!window.fabric || !window.fabric.StaticCanvas) {
            if (!document.querySelector('script[data-fabric-loader]')) {
                let s = document.createElement('script');
                s.type = 'module';
                s.dataset.fabricLoader = '1';
                s.src = fabricUrl;
                document.head.appendChild(s);
            }
        }

        requestAnimationFrame(doRender);
    },

    doRender() {
        let canvasEl = this.$el;
        if (!canvasEl || !layers.length) return;

        // Use annotation canvas dimensions for internal resolution
        let cw = layers[0].width || 800;
        let ch = layers[0].height || 600;

        // Set internal pixel resolution
        canvasEl.width = cw;
        canvasEl.height = ch;

        this.fc = new window.fabric.StaticCanvas(canvasEl, { width: cw, height: ch });

        // Restore CSS sizing after Fabric.js sets inline styles
        canvasEl.style.position = 'absolute';
        canvasEl.style.top = '0';
        canvasEl.style.left = '0';
        canvasEl.style.width = '100%';
        canvasEl.style.height = '100%';
        canvasEl.style.pointerEvents = 'none';

        // Collect all objects from selected layers
        let allObjects = [];
        for (let layer of layers) {
            if (layer && layer.objects) {
                // Scale objects if this layer has different dimensions
                let layerW = layer.width || cw;
                let layerH = layer.height || ch;
                let scaleX = cw / layerW;
                let scaleY = ch / layerH;

                for (let obj of layer.objects) {
                    if (Math.abs(scaleX - 1) > 0.01 || Math.abs(scaleY - 1) > 0.01) {
                        // Clone and scale the object data
                        let scaled = JSON.parse(JSON.stringify(obj));
                        scaled.left = (scaled.left || 0) * scaleX;
                        scaled.top = (scaled.top || 0) * scaleY;
                        scaled.scaleX = (scaled.scaleX || 1) * scaleX;
                        scaled.scaleY = (scaled.scaleY || 1) * scaleY;
                        allObjects.push(scaled);
                    } else {
                        allObjects.push(obj);
                    }
                }
            }
        }

        if (!allObjects.length) return;

        window.fabric.util.enlivenObjects(allObjects).then((objs) => {
            objs.forEach((o) => {
                o.selectable = false;
                o.evented = false;
                this.fc.add(o);
            });
            this.fc.renderAll();
        });
    },

    destroy() {
        if (this.fc) {
            this.fc.dispose();
            this.fc = null;
        }
    },
}));
</script>
@endscript
