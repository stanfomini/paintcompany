<?php

namespace App\Livewire\Agents;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public int $perPage = 15;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function activate(User $agent): void
    {
        $agent->update(['is_active' => true]);
        session()->flash('success', 'Agent activated successfully.');
    }

    public function deactivate(User $agent): void
    {
        $agent->update(['is_active' => false]);
        session()->flash('success', 'Agent deactivated successfully.');
    }

    public function render(): View
    {
        $agents = User::query()
            ->where('type', 'agent')
            ->with(['agentProfile', 'agentListings', 'referredCustomers'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhereHas('agentProfile', function ($profileQuery) {
                            $profileQuery->where('company_name', 'like', "%{$this->search}%")
                                ->orWhere('license_number', 'like', "%{$this->search}%");
                        });
                });
            })
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->where('is_active', $this->statusFilter === 'active');
            })
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.agents.index', [
            'agents' => $agents,
        ])->layout('layouts.app', ['title' => 'Agents']);
    }
}
