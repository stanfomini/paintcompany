<?php

namespace App\Livewire\Deals;

use App\Models\Deal;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    public Deal $deal;

    public function mount(Deal $deal): void
    {
        $this->deal = $deal->load([
            'customer',
            'property',
            'assignedTo',
            'createdBy',
            'notes.user',
            'estimates',
        ]);
    }

    #[On('deal-updated')]
    #[On('estimate-created')]
    public function refreshDeal(): void
    {
        $this->deal = $this->deal->fresh([
            'customer',
            'property',
            'assignedTo',
            'createdBy',
            'notes.user',
            'estimates',
        ]);
    }

    #[On('note-created')]
    #[On('note-updated')]
    #[On('note-deleted')]
    public function refreshNotes(): void
    {
        $this->deal = $this->deal->fresh(['notes.user']);
    }

    public function getStageColorProperty(): string
    {
        return match ($this->deal->stage) {
            'lead' => 'zinc',
            'quoted' => 'blue',
            'scheduled' => 'purple',
            'in_progress' => 'yellow',
            'completed' => 'green',
            'paid' => 'emerald',
            'cancelled' => 'red',
            default => 'zinc',
        };
    }

    public function render()
    {
        return view('livewire.deals.show')
            ->layout('layouts.app', ['title' => $this->deal->title]);
    }
}
