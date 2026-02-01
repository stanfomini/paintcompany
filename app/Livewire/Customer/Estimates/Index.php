<?php

namespace App\Livewire\Customer\Estimates;

use App\Models\Customer;
use App\Models\Estimate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public int $perPage = 15;

    #[On('estimate-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        $estimates = collect();

        if ($customer) {
            $estimates = Estimate::query()
                ->whereHas('deal', fn ($q) => $q->where('customer_id', $customer->id))
                ->whereIn('status', ['sent', 'accepted', 'rejected', 'expired'])
                ->with('deal')
                ->when($this->search, fn ($q) => $q->search($this->search))
                ->when($this->status, fn ($q) => $q->byStatus($this->status))
                ->latest()
                ->paginate($this->perPage);
        }

        $statuses = [
            'sent' => 'Pending',
            'accepted' => 'Accepted',
            'rejected' => 'Declined',
            'expired' => 'Expired',
        ];

        return view('livewire.customer.estimates.index', [
            'estimates' => $estimates,
            'statuses' => $statuses,
        ])->layout('components.layouts.customer', ['title' => 'Estimates']);
    }
}
