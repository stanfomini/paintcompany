<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimatePhoto;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithFileUploads;

class PhotoManager extends Component
{
    use WithFileUploads;

    public Estimate $estimate;

    /** @var Collection<int, EstimatePhoto> */
    public Collection $photos;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploadedPhotos = [];

    public bool $showUploadModal = false;

    public bool $showEditModal = false;

    public ?int $editingPhotoId = null;

    public string $photoCaption = '';

    public string $photoCategory = 'other';

    public bool $photoShowOnProposal = true;

    public ?int $photoAreaId = null;

    public string $defaultCategory = 'other';

    public bool $defaultShowOnProposal = true;

    public string $filterCategory = 'all';

    protected function rules(): array
    {
        return [
            'uploadedPhotos' => ['required', 'array', 'min:1'],
            'uploadedPhotos.*' => ['image', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp'],
            'photoCaption' => ['nullable', 'string', 'max:255'],
            'photoCategory' => ['required', 'string', 'in:before,during,reference,other'],
            'photoShowOnProposal' => ['boolean'],
            'photoAreaId' => ['nullable', 'integer', 'exists:estimate_areas,id'],
        ];
    }

    protected function messages(): array
    {
        return [
            'uploadedPhotos.required' => 'Please select at least one photo to upload.',
            'uploadedPhotos.*.image' => 'Each file must be an image.',
            'uploadedPhotos.*.max' => 'Each image must be less than 5MB.',
            'uploadedPhotos.*.mimes' => 'Only JPG, PNG, GIF, and WebP images are allowed.',
        ];
    }

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
        $this->loadPhotos();
    }

    public function loadPhotos(): void
    {
        $this->photos = $this->estimate->photos()->with('area')->orderBy('sort_order')->get();
    }

    public function openUploadModal(): void
    {
        $this->reset(['uploadedPhotos', 'defaultCategory', 'defaultShowOnProposal']);
        $this->defaultCategory = 'other';
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
            'uploadedPhotos.*' => ['image', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp'],
            'defaultCategory' => ['required', 'string', 'in:before,during,reference,other'],
            'defaultShowOnProposal' => ['boolean'],
        ]);

        $storagePath = "public/estimates/{$this->estimate->id}";
        $maxSortOrder = $this->estimate->photos()->max('sort_order') ?? 0;

        foreach ($this->uploadedPhotos as $photo) {
            $filename = $photo->hashName();
            $path = $photo->storeAs($storagePath, $filename);

            $this->estimate->photos()->create([
                'uploaded_by' => auth()->id(),
                'path' => $path,
                'filename' => $photo->getClientOriginalName(),
                'category' => $this->defaultCategory,
                'show_on_proposal' => $this->defaultShowOnProposal,
                'sort_order' => ++$maxSortOrder,
            ]);
        }

        $this->loadPhotos();
        $this->closeUploadModal();
        $this->dispatch('photos-updated');
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
        $this->photoAreaId = $photo->area_id;
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
            'photoCategory' => ['required', 'string', 'in:before,during,reference,other'],
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
            'area_id' => $this->photoAreaId,
        ]);

        $this->loadPhotos();
        $this->closeEditModal();
        $this->dispatch('photos-updated');
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

    public function setFilterCategory(string $category): void
    {
        $this->filterCategory = $category;
    }

    /**
     * Get photos grouped by category.
     *
     * @return Collection<string, Collection<int, EstimatePhoto>>
     */
    public function getPhotosByCategory(): Collection
    {
        return $this->photos->groupBy('category');
    }

    /**
     * Get filtered photos based on category filter.
     *
     * @return Collection<int, EstimatePhoto>
     */
    public function getFilteredPhotosProperty(): Collection
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
    public function getAreasProperty(): Collection
    {
        return $this->estimate->areas()->orderBy('sort_order')->get();
    }

    public function render(): View
    {
        return view('livewire.estimates.photo-manager', [
            'categories' => EstimatePhoto::CATEGORIES,
            'filteredPhotos' => $this->filteredPhotos,
            'areas' => $this->areas,
        ]);
    }
}
