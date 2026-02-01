<?php

namespace App\Livewire\Properties;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $typeFilter = 'all';

    public string $customerFilter = '';

    public int $perPage = 15;

    #[On('property-created')]
    #[On('property-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerFilter(): void
    {
        $this->resetPage();
    }

    public function getCustomersProperty(): Collection
    {
        return Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    public function render(): View
    {
        $user = Auth::user();

        if (! $user || ! $user->isInternal()) {
            abort(403, 'Only internal users can access properties.');
        }

        $properties = Property::query()
            ->with(['customer', 'photos'])
            ->search($this->search)
            ->when($this->typeFilter !== 'all', function ($query) {
                return $query->ofType($this->typeFilter);
            })
            ->when($this->customerFilter !== '', function ($query) {
                return $query->where('customer_id', $this->customerFilter);
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.properties.index', [
            'properties' => $properties,
        ])->layout('layouts.app', ['title' => 'Properties']);
    }
}
