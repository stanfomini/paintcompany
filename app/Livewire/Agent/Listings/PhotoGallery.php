<?php

namespace App\Livewire\Agent\Listings;

use App\Models\AgentListing;
use App\Models\ListingPhoto;
use Livewire\Component;
use Livewire\WithFileUploads;

class PhotoGallery extends Component
{
    use WithFileUploads;

    public AgentListing $listing;

    public $photo;

    public string $caption = '';

    public string $category = 'general';

    public bool $showUploadModal = false;

    public function mount(AgentListing $listing): void
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

        $path = $this->photo->store('listing-photos', 'public');

        $maxSortOrder = $this->listing->photos()->max('sort_order') ?? -1;

        ListingPhoto::create([
            'listing_id' => $this->listing->id,
            'uploaded_by' => auth()->id(),
            'path' => $path,
            'filename' => $this->photo->getClientOriginalName(),
            'caption' => $this->caption ?: null,
            'category' => $this->category,
            'sort_order' => $maxSortOrder + 1,
        ]);

        $this->listing->refresh();
        $this->dispatch('photo-uploaded');
        session()->flash('success', 'Photo uploaded successfully.');
        $this->closeUploadModal();
    }

    public function deletePhoto(int $photoId): void
    {
        $photo = ListingPhoto::where('listing_id', $this->listing->id)
            ->findOrFail($photoId);

        $photo->delete();

        $this->listing->refresh();
        $this->dispatch('photo-deleted');
        session()->flash('success', 'Photo deleted successfully.');
    }

    public function updateSortOrder(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $photoId) {
            ListingPhoto::where('listing_id', $this->listing->id)
                ->where('id', $photoId)
                ->update(['sort_order' => $index]);
        }

        $this->listing->refresh();
        $this->dispatch('photos-reordered');
    }

    public function render()
    {
        return view('livewire.agent.listings.photo-gallery', [
            'photos' => $this->listing->photos()->orderBy('sort_order')->get(),
        ]);
    }
}
