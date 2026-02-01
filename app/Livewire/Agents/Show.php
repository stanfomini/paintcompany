<?php

namespace App\Livewire\Agents;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    public User $user;

    public string $activeTab = 'overview';

    public function mount(User $user): void
    {
        if (! $user->isAgent()) {
            abort(404);
        }

        $this->user = $user->load([
            'agentProfile',
            'agentListings',
            'referredCustomers',
        ]);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render(): View
    {
        return view('livewire.agents.show')
            ->layout('layouts.app', ['title' => $this->user->name]);
    }
}
