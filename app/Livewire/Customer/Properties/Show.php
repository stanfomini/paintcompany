<?php

namespace App\Livewire\Customer\Properties;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('components.layouts.customer', ['title' => 'Property Details'])]
class Show extends Component
{
    public Property $property;

    public function mount(Property $property): void
    {
        // Verify ownership
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer || $property->customer_id !== $customer->id) {
            throw new NotFoundHttpException('Property not found.');
        }

        $this->property = $property;
    }

    public function render(): View
    {
        $photos = $this->property->photos()->get();

        $deals = $this->property->deals()
            ->latest()
            ->get();

        return view('livewire.customer.properties.show', [
            'property' => $this->property,
            'photos' => $photos,
            'deals' => $deals,
        ]);
    }
}
