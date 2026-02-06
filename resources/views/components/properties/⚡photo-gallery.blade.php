<?php

use App\Models\Property;
use App\Models\PropertyPhoto;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Property $property;

    #[Computed]
    public function photos()
    {
        return $this->property->photos()->orderBy('sort_order')->get();
    }

    public $photo;

    public string $caption = '';

    public string $category = 'general';

    public bool $showUploadModal = false;

    public function mount(Property $property): void
    {
        $this->property = $property;
    }

    public function openUploadModal(): void
    {
        $this->showUploadModal = true;
    }

    public function closeUploadModal(): void
    {
        $this->showUploadModal = false;
        $this->reset(['photo', 'caption', 'category']);
        $this->resetValidation();
    }

    public function uploadPhoto(): void
    {
        $this->validate([
            'photo' => ['required', 'image', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:500'],
            'category' => ['required', 'string', 'in:before,during,after,general'],
        ]);

        $path = $this->photo->store('property-photos', 'public');

        $maxSortOrder = $this->property->photos()->max('sort_order') ?? -1;

        PropertyPhoto::create([
            'property_id' => $this->property->id,
            'uploaded_by' => auth()->id(),
            'path' => $path,
            'filename' => $this->photo->getClientOriginalName(),
            'caption' => $this->caption ?: null,
            'category' => $this->category,
            'sort_order' => $maxSortOrder + 1,
        ]);

        $this->property->refresh();
        $this->dispatch('photo-uploaded');
        session()->flash('success', 'Photo uploaded successfully.');
        $this->closeUploadModal();
    }

    public function deletePhoto(int $photoId): void
    {
        $photo = PropertyPhoto::where('property_id', $this->property->id)
            ->findOrFail($photoId);

        $photo->delete();

        $this->property->refresh();
        $this->dispatch('photo-deleted');
        session()->flash('success', 'Photo deleted successfully.');
    }

    public function updateSortOrder(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $photoId) {
            PropertyPhoto::where('property_id', $this->property->id)
                ->where('id', $photoId)
                ->update(['sort_order' => $index]);
        }

        $this->property->refresh();
        $this->dispatch('photos-reordered');
    }
}; ?>

<div>
    <div class="flex items-center justify-between mb-4">
        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ $this->photos->count() }} {{ Str::plural('photo', $this->photos->count()) }}
        </flux:text>
        <flux:button wire:click="openUploadModal" icon="plus" size="sm">
            Upload Photo
        </flux:button>
    </div>

    @if (session('success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('success') }}
        </flux:callout>
    @endif

    @if ($this->photos->count() > 0)
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach ($this->photos as $propertyPhoto)
                <div wire:key="photo-{{ $propertyPhoto->id }}" class="relative group">
                    <div class="aspect-square overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                        <img
                            src="{{ $propertyPhoto->url }}"
                            alt="{{ $propertyPhoto->caption ?? $propertyPhoto->filename }}"
                            class="h-full w-full object-cover"
                        />
                    </div>
                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all rounded-lg flex items-center justify-center opacity-0 group-hover:opacity-100">
                        <flux:button
                            wire:click="deletePhoto({{ $propertyPhoto->id }})"
                            wire:confirm="Are you sure you want to delete this photo?"
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            class="text-white hover:text-red-400"
                        />
                    </div>
                    <div class="mt-2">
                        @if ($propertyPhoto->category !== 'general')
                            <flux:badge size="sm" color="{{ match($propertyPhoto->category) {
                                'before' => 'yellow',
                                'during' => 'blue',
                                'after' => 'green',
                                default => 'zinc'
                            } }}">
                                {{ ucfirst($propertyPhoto->category) }}
                            </flux:badge>
                        @endif
                        @if ($propertyPhoto->caption)
                            <flux:text class="text-xs text-zinc-500 mt-1 truncate">{{ $propertyPhoto->caption }}</flux:text>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-8">
            <flux:icon name="photo" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-4">No photos yet</flux:heading>
            <flux:text class="mt-2 text-zinc-500">
                Upload photos to document this property.
            </flux:text>
        </div>
    @endif

    <flux:modal wire:model="showUploadModal" variant="flyout" class="max-w-md">
        <form wire:submit="uploadPhoto">
            <flux:heading size="lg" class="mb-6">Upload Photo</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Photo</flux:label>
                    <input
                        type="file"
                        wire:model="photo"
                        accept="image/*"
                        class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-lg file:border-0
                            file:text-sm file:font-medium
                            file:bg-zinc-100 file:text-zinc-700
                            dark:file:bg-zinc-800 dark:file:text-zinc-300
                            hover:file:bg-zinc-200 dark:hover:file:bg-zinc-700"
                    />
                    <flux:error name="photo" />
                    @if ($photo && method_exists($photo, 'temporaryUrl'))
                        <div class="mt-2">
                            @php
                                try {
                                    $previewUrl = $photo->temporaryUrl();
                                } catch (\Livewire\Features\SupportFileUploads\FileNotPreviewableException $e) {
                                    $previewUrl = null;
                                }
                            @endphp
                            @if ($previewUrl)
                                <img src="{{ $previewUrl }}" class="h-32 w-32 object-cover rounded-lg" />
                            @endif
                        </div>
                    @endif
                </flux:field>

                <flux:field>
                    <flux:label>Category</flux:label>
                    <flux:select wire:model="category">
                        <option value="general">General</option>
                        <option value="before">Before</option>
                        <option value="during">During</option>
                        <option value="after">After</option>
                    </flux:select>
                    <flux:error name="category" />
                </flux:field>

                <flux:field>
                    <flux:label>Caption (optional)</flux:label>
                    <flux:input wire:model="caption" placeholder="Describe this photo..." />
                    <flux:error name="caption" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeUploadModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="uploadPhoto">Upload</span>
                    <span wire:loading wire:target="uploadPhoto">Uploading...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
