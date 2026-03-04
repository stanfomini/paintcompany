<?php

use App\Events\EstimatePhotoUpdated;
use App\Models\Estimate;
use App\Models\PropertyPhoto;
use App\Services\PhotoService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Estimate $estimate;

    /** @var Collection<int, PropertyPhoto> */
    public Collection $photos;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploadedPhotos = [];

    public bool $showUploadModal = false;

    public bool $showEditModal = false;

    public ?int $editingPhotoId = null;

    public string $photoCaption = '';

    public string $photoCategory = 'general';

    public bool $photoShowOnProposal = true;

    public ?int $photoAreaId = null;

    public string $defaultCategory = 'general';

    public bool $defaultShowOnProposal = true;

    public string $filterCategory = 'all';

    protected function rules(): array
    {
        return [
            'uploadedPhotos' => ['required', 'array', 'min:1'],
            'uploadedPhotos.*' => ['file', 'max:102400', 'mimes:jpg,jpeg,png,gif,webp,pdf'],
            'photoCaption' => ['nullable', 'string', 'max:255'],
            'photoCategory' => ['required', 'string', 'in:before,during,after,reference,general'],
            'photoShowOnProposal' => ['boolean'],
            'photoAreaId' => ['nullable', 'integer', 'exists:estimate_areas,id'],
        ];
    }

    protected function messages(): array
    {
        return [
            'uploadedPhotos.required' => __('Please select at least one photo to upload.'),
            'uploadedPhotos.*.file' => __('Each file must be a valid file.'),
            'uploadedPhotos.*.max' => __('Each file must be less than 100MB.'),
            'uploadedPhotos.*.mimes' => __('Only JPG, PNG, GIF, WebP, and PDF files are allowed.'),
        ];
    }

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
        $this->loadPhotos();
    }

    public function getListeners(): array
    {
        return [
            "echo-private:estimate.{$this->estimate->id},.estimate.photo.updated" => 'handlePhotoUpdated',
        ];
    }

    public function handlePhotoUpdated(): void
    {
        $this->loadPhotos();
    }

    public function loadPhotos(): void
    {
        $this->photos = $this->estimate->photos()->with('estimateArea')->orderBy('sort_order')->get();
    }

    public function openUploadModal(): void
    {
        $this->reset(['uploadedPhotos', 'defaultCategory', 'defaultShowOnProposal']);
        $this->defaultCategory = 'general';
        $this->defaultShowOnProposal = true;
        $this->showUploadModal = true;
    }

    public function closeUploadModal(): void
    {
        $this->showUploadModal = false;
        $this->reset(['uploadedPhotos', 'defaultCategory', 'defaultShowOnProposal']);
        $this->resetErrorBag();
    }

    public function removeUploadedPhoto(int $index): void
    {
        if (isset($this->uploadedPhotos[$index])) {
            unset($this->uploadedPhotos[$index]);
            $this->uploadedPhotos = array_values($this->uploadedPhotos);
        }
    }

    public function uploadPhotos(): void
    {
        $this->validate([
            'uploadedPhotos' => ['required', 'array', 'min:1'],
            'uploadedPhotos.*' => ['file', 'max:102400', 'mimes:jpg,jpeg,png,gif,webp,pdf'],
            'defaultCategory' => ['required', 'string', 'in:before,during,after,reference,general'],
            'defaultShowOnProposal' => ['boolean'],
        ]);

        $property = $this->estimate->deal?->property;

        if ($property) {
            $photoService = app(PhotoService::class);
            $meta = [
                'category' => $this->defaultCategory,
                'show_on_proposal' => $this->defaultShowOnProposal,
                'estimate_id' => $this->estimate->id,
            ];

            foreach ($this->uploadedPhotos as $photo) {
                $photoService->upload($photo, $property, auth()->user(), $meta);
            }
        } else {
            // Fallback for estimates without a property
            $disk = config('filesystems.default');
            $storagePath = "photos/estimates/{$this->estimate->id}";
            $maxSortOrder = $this->estimate->photos()->max('sort_order') ?? 0;

            foreach ($this->uploadedPhotos as $photo) {
                $filename = $photo->hashName();
                $path = $photo->storeAs($storagePath, $filename, config('filesystems.default'));

                PropertyPhoto::create([
                    'property_id' => null,
                    'estimate_id' => $this->estimate->id,
                    'uploaded_by' => auth()->id(),
                    'path' => $path,
                    'filename' => $photo->getClientOriginalName(),
                    'original_filename' => $photo->getClientOriginalName(),
                    'mime_type' => $photo->getMimeType(),
                    'file_size' => $photo->getSize(),
                    'disk' => $disk,
                    'category' => $this->defaultCategory,
                    'show_on_proposal' => $this->defaultShowOnProposal,
                    'sort_order' => ++$maxSortOrder,
                    'processing_status' => 'pending',
                ]);
            }
        }

        $this->loadPhotos();
        $this->closeUploadModal();
        $this->dispatch('photos-updated');

        broadcast(new EstimatePhotoUpdated(
            $this->estimate->id,
            0,
            'uploaded',
            auth()->user()?->name ?? 'System'
        ))->toOthers();
    }

    public function openEditModal(int $id): void
    {
        $photo = $this->estimate->photos()->find($id);

        if (! $photo) {
            return;
        }

        $this->editingPhotoId = $photo->id;
        $this->photoCaption = $photo->caption ?? '';
        $this->photoCategory = $photo->category;
        $this->photoShowOnProposal = $photo->show_on_proposal;
        $this->photoAreaId = $photo->estimate_area_id;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->reset(['editingPhotoId', 'photoCaption', 'photoCategory', 'photoShowOnProposal', 'photoAreaId']);
        $this->resetErrorBag();
    }

    public function savePhoto(): void
    {
        $this->validate([
            'photoCaption' => ['nullable', 'string', 'max:255'],
            'photoCategory' => ['required', 'string', 'in:before,during,after,reference,general'],
            'photoShowOnProposal' => ['boolean'],
            'photoAreaId' => ['nullable', 'integer', 'exists:estimate_areas,id'],
        ]);

        $photo = $this->estimate->photos()->find($this->editingPhotoId);

        if (! $photo) {
            return;
        }

        $photo->update([
            'caption' => $this->photoCaption ?: null,
            'category' => $this->photoCategory,
            'show_on_proposal' => $this->photoShowOnProposal,
            'estimate_area_id' => $this->photoAreaId,
        ]);

        $this->loadPhotos();
        $this->closeEditModal();
        $this->dispatch('photos-updated');

        broadcast(new EstimatePhotoUpdated(
            $this->estimate->id,
            $this->editingPhotoId ?? 0,
            'updated',
            auth()->user()?->name ?? 'System'
        ))->toOthers();
    }

    public function toggleShowOnProposal(int $id): void
    {
        $photo = $this->estimate->photos()->find($id);

        if (! $photo) {
            return;
        }

        $photo->update(['show_on_proposal' => ! $photo->show_on_proposal]);
        $this->loadPhotos();
    }

    public function deletePhoto(int $id): void
    {
        $photo = $this->estimate->photos()->find($id);

        if (! $photo) {
            return;
        }

        $photo->delete();
        $this->loadPhotos();
        $this->dispatch('photos-updated');

        broadcast(new EstimatePhotoUpdated(
            $this->estimate->id,
            $id,
            'deleted',
            auth()->user()?->name ?? 'System'
        ))->toOthers();
    }

    public function reorderPhotos(array $order): void
    {
        foreach ($order as $index => $photoId) {
            $this->estimate->photos()
                ->where('id', $photoId)
                ->update(['sort_order' => $index]);
        }

        $this->loadPhotos();
        $this->dispatch('photos-updated');
    }

    #[Computed]
    public function categories(): array
    {
        return PropertyPhoto::CATEGORIES;
    }

    public function setFilterCategory(string $category): void
    {
        $this->filterCategory = $category;
    }

    /**
     * Get photos grouped by category.
     *
     * @return Collection<string, Collection<int, PropertyPhoto>>
     */
    public function getPhotosByCategory(): Collection
    {
        return $this->photos->groupBy('category');
    }

    /**
     * Get filtered photos based on category filter.
     *
     * @return Collection<int, PropertyPhoto>
     */
    #[Computed]
    public function filteredPhotos(): Collection
    {
        if ($this->filterCategory === 'all') {
            return $this->photos;
        }

        return $this->photos->where('category', $this->filterCategory);
    }

    /**
     * Get available areas for association.
     *
     * @return Collection<int, \App\Models\EstimateArea>
     */
    #[Computed]
    public function areas(): Collection
    {
        return $this->estimate->areas()->orderBy('sort_order')->get();
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="lg">{{ __('Photos') }}</flux:heading>
        <div class="flex items-center gap-3">
            <a href="{{ route('estimates.visualize', $this->estimate) }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm text-purple-600 hover:text-purple-700 dark:text-purple-400">
                <flux:icon.sparkles class="size-4" />
                {{ __('Visualize Colors') }}
            </a>
            <flux:button wire:click="openUploadModal" icon="arrow-up-tray" size="sm">
                {{ __('Upload Photos') }}
            </flux:button>
        </div>
    </div>

    {{-- Category Filter Tabs --}}
    <div class="flex gap-2 mb-4 overflow-x-auto">
        <flux:button
            wire:click="setFilterCategory('all')"
            variant="{{ $filterCategory === 'all' ? 'filled' : 'ghost' }}"
            size="sm"
        >
            All ({{ $photos->count() }})
        </flux:button>
        @foreach ($this->categories as $key => $label)
            @php
                $count = $photos->where('category', $key)->count();
            @endphp
            <flux:button
                wire:click="setFilterCategory('{{ $key }}')"
                variant="{{ $filterCategory === $key ? 'filled' : 'ghost' }}"
                size="sm"
            >
                {{ $label }} ({{ $count }})
            </flux:button>
        @endforeach
    </div>

    {{-- Photo Grid --}}
    @if ($this->filteredPhotos->isEmpty())
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
            <flux:icon name="photo" class="w-12 h-12 mx-auto text-zinc-400 mb-3" />
            <flux:heading size="sm" class="mb-1">{{ __('No photos yet') }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400 mb-4">
                @if ($filterCategory === 'all')
                    {{ __('Upload photos to document this estimate.') }}
                @else
                    {{ __('No :category photos found.', ['category' => strtolower($categories[$filterCategory] ?? $filterCategory)]) }}
                @endif
            </flux:text>
            @if ($filterCategory === 'all')
                <flux:button wire:click="openUploadModal" icon="arrow-up-tray" size="sm">
                    {{ __('Upload Photos') }}
                </flux:button>
            @endif
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach ($this->filteredPhotos as $photo)
                <div wire:key="photo-{{ $photo->id }}" class="group relative border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                    {{-- Photo Thumbnail --}}
                    <div class="aspect-square bg-zinc-100 dark:bg-zinc-800">
                        @if ($photo->mime_type === 'application/pdf')
                            <a href="{{ $photo->url }}" target="_blank" class="w-full h-full flex flex-col items-center justify-center text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 transition-colors">
                                <flux:icon name="document" class="w-12 h-12 mb-2" />
                                <span class="text-xs font-medium">PDF</span>
                                <span class="text-xs truncate max-w-full px-2">{{ $photo->filename }}</span>
                            </a>
                        @else
                            <img
                                src="{{ $photo->url }}"
                                alt="{{ $photo->caption ?? $photo->filename }}"
                                class="w-full h-full object-cover"
                            />
                        @endif
                    </div>

                    {{-- Overlay with info --}}
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors">
                        {{-- Action Buttons (visible on hover) --}}
                        <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <flux:button
                                wire:click="openEditModal({{ $photo->id }})"
                                variant="filled"
                                size="xs"
                                icon="pencil"
                            />
                            <flux:button
                                wire:click="deletePhoto({{ $photo->id }})"
                                wire:confirm="{{ __('Are you sure you want to delete this photo?') }}"
                                variant="danger"
                                size="xs"
                                icon="trash"
                            />
                        </div>

                        {{-- Show on Proposal toggle --}}
                        <div class="absolute top-2 left-2">
                            <flux:tooltip :content="$photo->show_on_proposal ? __('Visible on proposal — click to hide') : __('Hidden from proposal — click to show')">
                                <button
                                    wire:click.stop="toggleShowOnProposal({{ $photo->id }})"
                                    class="rounded-full p-1 transition-colors {{ $photo->show_on_proposal ? 'bg-green-500 text-white' : 'bg-zinc-200 dark:bg-zinc-700 text-zinc-400 dark:text-zinc-500 opacity-0 group-hover:opacity-100' }}"
                                >
                                    <flux:icon name="{{ $photo->show_on_proposal ? 'eye' : 'eye-slash' }}" class="w-3.5 h-3.5" />
                                </button>
                            </flux:tooltip>
                        </div>
                    </div>

                    {{-- Photo Info --}}
                    <div class="p-2">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                @if ($photo->caption)
                                    <flux:text class="text-sm font-medium truncate">{{ $photo->caption }}</flux:text>
                                @else
                                    <flux:text class="text-sm text-zinc-400 truncate">{{ $photo->filename }}</flux:text>
                                @endif
                                @if ($photo->estimateArea)
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
                                        {{ $photo->estimateArea->name }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:badge size="sm" color="{{ match($photo->category) {
                                'before' => 'blue',
                                'during' => 'yellow',
                                'reference' => 'purple',
                                default => 'zinc',
                            } }}">
                                {{ $categories[$photo->category] ?? $photo->category }}
                            </flux:badge>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Upload Modal --}}
    <flux:modal wire:model="showUploadModal" class="max-w-2xl">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Upload Photos') }}</flux:heading>

            {{-- Drag and Drop Area --}}
            <div
                x-data="{
                    isDragging: false,
                    handleDrop(e) {
                        this.isDragging = false;
                        const files = Array.from(e.dataTransfer.files);
                        @this.uploadMultiple('uploadedPhotos', files);
                    }
                }"
                x-on:dragover.prevent="isDragging = true"
                x-on:dragleave.prevent="isDragging = false"
                x-on:drop.prevent="handleDrop($event)"
                class="relative border-2 border-dashed rounded-lg p-8 text-center transition-colors"
                :class="isDragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-950' : 'border-zinc-300 dark:border-zinc-600'"
            >
                <flux:icon name="cloud-arrow-up" class="w-12 h-12 mx-auto text-zinc-400 mb-3" />
                <flux:text class="mb-2">
                    {{ __('Drag and drop images here, or') }}
                    <label class="text-blue-600 dark:text-blue-400 hover:underline cursor-pointer">
                        {{ __('browse') }}
                        <input
                            type="file"
                            wire:model="uploadedPhotos"
                            accept="image/*,.pdf,application/pdf"
                            multiple
                            class="hidden"
                        />
                    </label>
                </flux:text>
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('JPG, PNG, GIF, WebP, PDF up to 20MB each') }}
                </flux:text>

                {{-- Loading indicator --}}
                <div wire:loading wire:target="uploadedPhotos" class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-zinc-900/80 rounded-lg">
                    <div class="flex items-center gap-2">
                        <flux:icon name="arrow-path" class="w-5 h-5 animate-spin" />
                        <flux:text>{{ __('Uploading...') }}</flux:text>
                    </div>
                </div>
            </div>

            @error('uploadedPhotos')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror
            @error('uploadedPhotos.*')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror

            {{-- Preview uploaded photos --}}
            @if (count($uploadedPhotos) > 0)
                <div>
                    <flux:text class="font-medium mb-2">{{ __('Selected Photos') }} ({{ count($uploadedPhotos) }})</flux:text>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($uploadedPhotos as $index => $photo)
                            <div wire:key="preview-{{ $index }}" class="relative aspect-square rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                                @if ($photo && method_exists($photo, 'temporaryUrl'))
                                    @if (str_ends_with(strtolower($photo->getClientOriginalName()), '.pdf'))
                                        <div class="w-full h-full flex flex-col items-center justify-center text-zinc-500">
                                            <flux:icon name="document" class="w-10 h-10 mb-1" />
                                            <span class="text-xs truncate max-w-full px-2">{{ $photo->getClientOriginalName() }}</span>
                                        </div>
                                    @else
                                        <img
                                            src="{{ $photo->temporaryUrl() }}"
                                            alt="Preview"
                                            class="w-full h-full object-cover"
                                        />
                                    @endif
                                @endif
                                <button
                                    type="button"
                                    wire:click="removeUploadedPhoto({{ $index }})"
                                    class="absolute top-1 right-1 p-1 bg-red-500 text-white rounded-full hover:bg-red-600"
                                >
                                    <flux:icon name="x-mark" class="w-3 h-3" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Upload Options --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Default Category') }}</flux:label>
                    <flux:select wire:model="defaultCategory">
                        @foreach ($this->categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model="defaultShowOnProposal" :label="__('Show on Proposal')" />
                </flux:field>
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeUploadModal" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    wire:click="uploadPhotos"
                    variant="primary"
                    wire:loading.attr="disabled"
                    :disabled="count($uploadedPhotos) === 0"
                >
                    <span wire:loading.remove wire:target="uploadPhotos">
                        Upload {{ count($uploadedPhotos) }} Photo{{ count($uploadedPhotos) !== 1 ? 's' : '' }}
                    </span>
                    <span wire:loading wire:target="uploadPhotos">{{ __('Uploading...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit Modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Edit Photo') }}</flux:heading>

            {{-- Image Preview --}}
            @if ($editingPhotoId)
                @php
                    $editingPhoto = $photos->firstWhere('id', $editingPhotoId);
                @endphp
                @if ($editingPhoto)
                    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden">
                        @if ($editingPhoto->mime_type === 'application/pdf')
                            <a href="{{ $editingPhoto->url }}" target="_blank" class="w-full h-full flex flex-col items-center justify-center text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                                <flux:icon name="document" class="w-16 h-16 mb-2" />
                                <span class="text-sm font-medium">{{ $editingPhoto->original_filename ?? $editingPhoto->filename }}</span>
                                <span class="text-xs mt-1 text-blue-600 dark:text-blue-400">{{ __('Click to open PDF') }}</span>
                            </a>
                        @else
                            <img
                                src="{{ $editingPhoto->url }}"
                                alt="{{ $editingPhoto->caption ?? $editingPhoto->filename }}"
                                class="w-full h-full object-contain"
                            />
                        @endif
                    </div>
                @endif
            @endif

            {{-- Form Fields --}}
            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Caption') }}</flux:label>
                    <flux:input wire:model="photoCaption" placeholder="{{ __('Enter a caption for this photo...') }}" />
                    <flux:error name="photoCaption" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <flux:select wire:model="photoCategory">
                        @foreach ($this->categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="photoCategory" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Associated Area') }}</flux:label>
                    <flux:select wire:model="photoAreaId">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->areas as $area)
                            <option value="{{ $area->id }}">{{ $area->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="photoAreaId" />
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model="photoShowOnProposal" :label="__('Show on Proposal')" />
                </flux:field>
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeEditModal" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button wire:click="savePhoto" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="savePhoto">{{ __('Save Changes') }}</span>
                    <span wire:loading wire:target="savePhoto">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
