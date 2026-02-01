<?php

namespace App\Livewire\Properties;

use App\Models\Property;
use App\Models\PropertyPhoto;
use Livewire\Component;
use Livewire\WithFileUploads;

class PhotoGallery extends Component
{
    use WithFileUploads;

    public Property $property;

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

    public function render()
    {
        return view('livewire.properties.photo-gallery', [
            'photos' => $this->property->photos()->orderBy('sort_order')->get(),
        ]);
    }
}
