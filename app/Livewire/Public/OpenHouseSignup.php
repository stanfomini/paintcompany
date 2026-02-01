<?php

namespace App\Livewire\Public;

use App\Models\AgentListing;
use App\Models\Customer;
use App\Notifications\NewOpenHouseSignup;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class OpenHouseSignup extends Component
{
    public AgentListing $agentListing;

    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $notes = '';

    public string $honeypot = '';

    public bool $submitted = false;

    public function mount(AgentListing $agentListing): void
    {
        if (! $agentListing->isActive()) {
            abort(404);
        }

        $this->agentListing = $agentListing;
    }

    public function submit(): void
    {
        // Honeypot check - if filled, silently reject
        if (! empty($this->honeypot)) {
            $this->submitted = true;

            return;
        }

        $this->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'firstName.required' => 'Please enter your first name.',
            'lastName.required' => 'Please enter your last name.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        $customer = Customer::create([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'city' => $this->city ?: null,
            'state' => $this->state ?: null,
            'zip' => $this->zip ?: null,
            'notes' => $this->notes ?: null,
            'referred_by' => $this->agentListing->agent_id,
            'listing_id' => $this->agentListing->id,
            'source' => 'open_house',
            'status' => 'active',
            'created_by' => $this->agentListing->agent_id,
        ]);

        // Notify the agent about the new sign-up
        $this->agentListing->agent->notify(new NewOpenHouseSignup($customer, $this->agentListing));

        $this->submitted = true;
    }

    public function render()
    {
        return view('livewire.public.open-house-signup');
    }
}
