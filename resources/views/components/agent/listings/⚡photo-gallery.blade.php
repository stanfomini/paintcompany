<?php

use App\Models\Property;
use App\Models\PropertyPhoto;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Property $listing;

    #[Computed]
    public function photos()
    {
        return $this->listing->photos()->orderBy('sort_order')->get();
    }

    public $photo;

    public string $caption = '';

    public string $category = 'general';

    public bool $showUploadModal = false;

    public function mount(Property $listing): void
    {
        $this->listing = $listing;
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
            'category' => ['required', 'string', 'in:before,after,general'],
        ]);

        $path = $this->photo->store('listing-photos', config('filesystems.default'));

        $maxSortOrder = $this->listing->photos()->max('sort_order') ?? -1;

        PropertyPhoto::create([
            'property_id' => $this->listing->id,
            'disk' => config('filesystems.default'),
            'uploaded_by' => auth()->id(),
            'path' => $path,
            'filename' => $this->photo->getClientOriginalName(),
            'caption' => $this->caption ?: null,
            'category' => $this->category,
            'sort_order' => $maxSortOrder + 1,
        ]);

        $this->listing->refresh();
        $this->dispatch('photo-uploaded');
        session()->flash('success', __('Photo uploaded successfully.'));
        $this->closeUploadModal();
    }

    public function deletePhoto(int $photoId): void
    {
        $photo = PropertyPhoto::where('property_id', $this->listing->id)
            ->findOrFail($photoId);

        $photo->delete();

        $this->listing->refresh();
        $this->dispatch('photo-deleted');
        session()->flash('success', __('Photo deleted successfully.'));
    }

    public function updateSortOrder(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $photoId) {
            PropertyPhoto::where('property_id', $this->listing->id)
                ->where('id', $photoId)
                ->update(['sort_order' => $index]);
        }

        $this->listing->refresh();
        $this->dispatch('photos-reordered');
    }
}; ?>

<div>
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">{{ __('Photos') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="openUploadModal" size="sm">
                {{ __('Add Photo') }}
            </flux:button>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if ($this->photos->isEmpty())
            <div class="text-center py-8">
                <flux:icon name="photo" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:text class="mt-2 text-zinc-500">{{ __('No photos yet') }}</flux:text>
                <flux:text class="text-sm text-zinc-400">{{ __('Add before & after photos to showcase your work') }}</flux:text>
            </div>
        @else
            <!-- Category Tabs -->
            @php
                $beforePhotos = $this->photos->where('category', 'before');
                $afterPhotos = $this->photos->where('category', 'after');
                $generalPhotos = $this->photos->where('category', 'general');
            @endphp

            @if ($beforePhotos->isNotEmpty() || $afterPhotos->isNotEmpty())
                <!-- Before/After Section -->
                <div class="mb-6">
                    <flux:text class="font-medium text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Before & After') }}</flux:text>
                    <div class="grid grid-cols-2 gap-4">
                        <!-- Before Column -->
                        <div>
                            <flux:badge color="amber" size="sm" class="mb-2">{{ __('Before') }}</flux:badge>
                            <div class="space-y-2">
                                @forelse ($beforePhotos as $photo)
                                    <div class="relative group rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700">
                                        <img src="{{ $photo->url }}" alt="{{ $photo->caption ?? 'Before photo' }}" class="w-full h-32 object-cover">
                                        <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                                            <flux:button size="xs" variant="ghost" class="text-white hover:text-red-400" wire:click="deletePhoto({{ $photo->id }})" wire:confirm="{{ __('Are you sure you want to delete this photo?') }}">
                                                <flux:icon name="trash" class="w-4 h-4" />
                                            </flux:button>
                                        </div>
                                        @if ($photo->caption)
                                            <div class="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-xs p-1 truncate">
                                                {{ $photo->caption }}
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <div class="h-32 border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg flex items-center justify-center">
                                        <flux:text class="text-sm text-zinc-400">{{ __('No before photos') }}</flux:text>
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        <!-- After Column -->
                        <div>
                            <flux:badge color="green" size="sm" class="mb-2">{{ __('After') }}</flux:badge>
                            <div class="space-y-2">
                                @forelse ($afterPhotos as $photo)
                                    <div class="relative group rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700">
                                        <img src="{{ $photo->url }}" alt="{{ $photo->caption ?? 'After photo' }}" class="w-full h-32 object-cover">
                                        <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                                            <flux:button size="xs" variant="ghost" class="text-white hover:text-red-400" wire:click="deletePhoto({{ $photo->id }})" wire:confirm="{{ __('Are you sure you want to delete this photo?') }}">
                                                <flux:icon name="trash" class="w-4 h-4" />
                                            </flux:button>
                                        </div>
                                        @if ($photo->caption)
                                            <div class="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-xs p-1 truncate">
                                                {{ $photo->caption }}
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <div class="h-32 border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg flex items-center justify-center">
                                        <flux:text class="text-sm text-zinc-400">{{ __('No after photos') }}</flux:text>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if ($generalPhotos->isNotEmpty())
                <!-- General Photos -->
                <div>
                    <flux:text class="font-medium text-zinc-700 dark:text-zinc-300 mb-3">{{ __('General Photos') }}</flux:text>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
                        @foreach ($generalPhotos as $photo)
                            <div class="relative group rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700">
                                <img src="{{ $photo->url }}" alt="{{ $photo->caption ?? 'Photo' }}" class="w-full h-24 object-cover">
                                <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                    <flux:button size="xs" variant="ghost" class="text-white hover:text-red-400" wire:click="deletePhoto({{ $photo->id }})" wire:confirm="{{ __('Are you sure you want to delete this photo?') }}">
                                        <flux:icon name="trash" class="w-4 h-4" />
                                    </flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>

    <!-- Upload Modal -->
    <flux:modal wire:model="showUploadModal" class="max-w-md">
        <div>
            <flux:heading size="lg" class="mb-4">{{ __('Upload Photo') }}</flux:heading>

            <form wire:submit="uploadPhoto" class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Photo') }}</flux:label>
                    <input type="file" wire:model="photo" accept="image/*" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300">
                    @error('photo')
                        <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                    @enderror
                    <div wire:loading wire:target="photo" class="text-sm text-zinc-500">{{ __('Uploading...') }}</div>
                </flux:field>

                @if ($photo)
                    <div class="rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700">
                        <img src="{{ $photo->temporaryUrl() }}" alt="Preview" class="w-full h-48 object-cover">
                    </div>
                @endif

                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <flux:select wire:model="category">
                        <flux:select.option value="general">{{ __('General') }}</flux:select.option>
                        <flux:select.option value="before">{{ __('Before') }}</flux:select.option>
                        <flux:select.option value="after">{{ __('After') }}</flux:select.option>
                    </flux:select>
                    @error('category')
                        <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                    @enderror
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Caption') }} <span class="text-zinc-400">({{ __('optional') }})</span></flux:label>
                    <flux:input wire:model="caption" placeholder="{{ __('Describe this photo...') }}" />
                    @error('caption')
                        <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
                    @enderror
                </flux:field>

                <div class="flex justify-end gap-2 pt-4">
                    <flux:button variant="ghost" type="button" wire:click="closeUploadModal">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="uploadPhoto">{{ __('Upload') }}</span>
                        <span wire:loading wire:target="uploadPhoto">{{ __('Uploading...') }}</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
