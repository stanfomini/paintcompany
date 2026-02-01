<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use Livewire\Component;

class Show extends Component
{
    public Customer $customer;

    public function mount(Customer $customer): void
    {
        $this->customer = $customer->load(['createdBy', 'referredBy', 'properties', 'deals']);
    }

    public function render()
    {
        return view('livewire.customers.show')
            ->layout('layouts.app', ['title' => $this->customer->full_name]);
    }
}
