<?php

namespace App\Livewire\Customer\Properties;

use App\Models\Customer;
use App\Models\Property;
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
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        $properties = collect();

        if ($customer) {
            $properties = Property::query()
                ->where('customer_id', $customer->id)
                ->withCount('photos', 'deals')
                ->when($this->search, fn ($q) => $q->search($this->search))
                ->latest()
                ->paginate($this->perPage);
        }

        return view('livewire.customer.properties.index', [
            'properties' => $properties,
        ])->layout('components.layouts.customer', ['title' => 'My Properties']);
    }
}
