<?php

namespace App\Livewire\Customer\Projects;

use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $stage = '';

    public int $perPage = 15;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStage(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        $projects = collect();

        if ($customer) {
            $projects = Deal::query()
                ->where('customer_id', $customer->id)
                ->with(['property'])
                ->when($this->search, fn ($q) => $q->search($this->search))
                ->when($this->stage, fn ($q) => $q->byStage($this->stage))
                ->latest()
                ->paginate($this->perPage);
        }

        return view('livewire.customer.projects.index', [
            'projects' => $projects,
            'stages' => Deal::STAGES,
        ])->layout('components.layouts.customer', ['title' => 'My Projects']);
    }
}
