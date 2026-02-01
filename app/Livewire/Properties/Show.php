<?php

namespace App\Livewire\Properties;

use App\Models\Property;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    public Property $property;

    public function mount(Property $property): void
    {
        $this->property = $property->load(['customer', 'photos', 'deals']);
    }

    #[On('deal-created')]
    public function refreshDeals(): void
    {
        $this->property = $this->property->fresh(['customer', 'photos', 'deals']);
    }

    public function render()
    {
        return view('livewire.properties.show')
            ->layout('layouts.app', ['title' => $this->property->display_name]);
    }
}
