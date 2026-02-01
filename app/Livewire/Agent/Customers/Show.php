<?php

namespace App\Livewire\Agent\Customers;

use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

class Show extends Component
{
    public Customer $customer;

    public function mount(Customer $customer): void
    {
        // Ensure the agent can only view customers they referred
        if ($customer->referred_by !== auth('agent')->id()) {
            abort(Response::HTTP_FORBIDDEN, 'You are not authorized to view this customer.');
        }

        $this->customer = $customer->load(['listing', 'referredBy']);
    }

    public function render(): View
    {
        return view('livewire.agent.customers.show')
            ->layout('components.layouts.agent', ['title' => $this->customer->full_name]);
    }
}
