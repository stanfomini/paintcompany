<?php

namespace App\Livewire\AreaTemplates;

use App\Models\EstimateTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $scopeTab = 'all';

    #[Url]
    public string $visibilityFilter = 'all';

    public int $perPage = 15;

    public bool $showDeleteModal = false;

    public ?int $deleteId = null;

    public ?string $deleteName = null;

    #[On('template-created')]
    #[On('template-updated')]
    public function refreshList(): void
    {
        unset($this->templates);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedScopeTab(): void
    {
        $this->resetPage();
    }

    public function updatedVisibilityFilter(): void
    {
        $this->resetPage();
    }

    public function switchTab(string $tab): void
    {
        $this->scopeTab = $tab;
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<EstimateTemplate>
     */
    #[Computed]
    public function templates(): LengthAwarePaginator
    {
        $user = auth()->user();

        $query = EstimateTemplate::query()
            ->configurable()
            ->with(['createdBy', 'productionRate', 'templateCategory'])
            ->withCount('surfaces');

        // Apply visibility filter - specific filters override the general visibleTo scope
        if ($this->visibilityFilter === 'mine') {
            $query->where('visibility', EstimateTemplate::VISIBILITY_PRIVATE)
                ->where('created_by', $user->id);
        } elseif ($this->visibilityFilter === 'company') {
            $query->where('visibility', EstimateTemplate::VISIBILITY_COMPANY);
        } elseif ($this->visibilityFilter === 'system') {
            $query->where('visibility', EstimateTemplate::VISIBILITY_SYSTEM);
        } else {
            // "all" - show all templates visible to user
            $query->visibleTo($user);
        }

        // Apply scope filter
        if ($this->scopeTab !== 'all') {
            $query->byScope($this->scopeTab);
        }

        // Apply search
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%");
            });
        }

        return $query->orderBy('sort_order')->orderBy('name')->paginate($this->perPage);
    }

    public function confirmDelete(int $id): void
    {
        $template = EstimateTemplate::find($id);

        if (! $template) {
            session()->flash('error', 'Template not found.');

            return;
        }

        if (! $template->canBeDeletedBy(auth()->user())) {
            session()->flash('error', 'You do not have permission to delete this template.');

            return;
        }

        $this->deleteId = $id;
        $this->deleteName = $template->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $template = EstimateTemplate::find($this->deleteId);

        if (! $template) {
            session()->flash('error', 'Template not found.');
            $this->closeDeleteModal();

            return;
        }

        if (! $template->canBeDeletedBy(auth()->user())) {
            session()->flash('error', 'You do not have permission to delete this template.');
            $this->closeDeleteModal();

            return;
        }

        $template->delete();
        session()->flash('success', 'Template deleted successfully.');

        $this->closeDeleteModal();
        unset($this->templates);
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deleteId = null;
        $this->deleteName = null;
    }

    public function clone(int $id): void
    {
        $template = EstimateTemplate::find($id);

        if (! $template) {
            session()->flash('error', 'Template not found.');

            return;
        }

        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name.' (Copy)';
        $newTemplate->slug = null; // Will be auto-generated
        $newTemplate->visibility = EstimateTemplate::VISIBILITY_PRIVATE;
        $newTemplate->created_by = auth()->id();
        $newTemplate->save();

        // Clone surfaces if they exist
        foreach ($template->surfaces as $surface) {
            $newSurface = $surface->replicate();
            $newSurface->estimate_template_id = $newTemplate->id;
            $newSurface->save();
        }

        session()->flash('success', 'Template cloned successfully.');
        unset($this->templates);
    }

    public function render(): View
    {
        return view('livewire.area-templates.index')
            ->layout('layouts.app', ['title' => 'Area Templates']);
    }
}
