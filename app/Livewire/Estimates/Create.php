<?php

namespace App\Livewire\Estimates;

use App\Models\Deal;
use App\Models\Estimate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Create extends Component
{
    public Deal $deal;

    public ?string $selectedMode = null;

    public function mount(Deal $deal): void
    {
        $this->deal = $deal;
    }

    #[On('mode-selected')]
    public function onModeSelected(string $mode): void
    {
        $this->selectedMode = $mode;

        // Redirect to the appropriate builder based on mode
        $route = match ($mode) {
            Estimate::MODE_SIMPLE => route('estimates.create.simple', $this->deal),
            Estimate::MODE_TEMPLATE => route('estimates.create.template', $this->deal),
            Estimate::MODE_DETAILED => route('estimates.create.detailed', $this->deal),
            default => route('estimates.create.template', $this->deal),
        };

        $this->redirect($route, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.estimates.create')
            ->layout('layouts.app', ['title' => 'Create Estimate']);
    }
}
