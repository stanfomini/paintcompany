<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public int $perPage = 15;

    #[On('customer-created')]
    #[On('customer-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $customers = Customer::query()
            ->search($this->search)
            ->when($this->statusFilter !== 'all', function ($query) {
                if ($this->statusFilter === 'archived') {
                    return $query->onlyTrashed();
                }

                return $query->where('status', $this->statusFilter);
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.customers.index', [
            'customers' => $customers,
        ])->layout('layouts.app', ['title' => 'Customers']);
    }
}
