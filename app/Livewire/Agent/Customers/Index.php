<?php

namespace App\Livewire\Agent\Customers;

use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public int $perPage = 15;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $customers = Customer::query()
            ->where('referred_by', auth('agent')->id())
            ->with('listing')
            ->search($this->search)
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.agent.customers.index', [
            'customers' => $customers,
        ])->layout('components.layouts.agent', ['title' => 'My Customers']);
    }
}
