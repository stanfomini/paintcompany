<?php

namespace App\Livewire\Customer\Estimates;

use App\Models\Customer;
use App\Models\Estimate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('components.layouts.customer', ['title' => 'Estimate Details'])]
class Show extends Component
{
    public Estimate $estimate;

    public bool $showAcceptModal = false;

    public bool $showDeclineModal = false;

    public function mount(Estimate $estimate): void
    {
        // Verify ownership through the deal
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer || $estimate->deal->customer_id !== $customer->id) {
            throw new NotFoundHttpException('Estimate not found.');
        }

        // Only show non-draft estimates to customers
        if ($estimate->status === 'draft') {
            throw new NotFoundHttpException('Estimate not found.');
        }

        $this->estimate = $estimate;
    }

    public function confirmAccept(): void
    {
        $this->showAcceptModal = true;
    }

    public function cancelAccept(): void
    {
        $this->showAcceptModal = false;
    }

    public function accept(): void
    {
        if ($this->estimate->status !== 'sent') {
            session()->flash('error', 'This estimate can no longer be accepted.');

            return;
        }

        // TODO: When signature modal is implemented, remove force: true
        // and require signature through the public proposal flow
        $this->estimate->markAsAccepted(force: true);

        // Update deal stage to 'quoted' if it was 'lead'
        if ($this->estimate->deal->stage === 'lead') {
            $this->estimate->deal->moveTo('quoted');
        }

        $this->showAcceptModal = false;

        session()->flash('success', 'Estimate accepted successfully! We will be in touch shortly to schedule your project.');

        $this->estimate->refresh();

        $this->dispatch('estimate-updated');
    }

    public function confirmDecline(): void
    {
        $this->showDeclineModal = true;
    }

    public function cancelDecline(): void
    {
        $this->showDeclineModal = false;
    }

    public function decline(): void
    {
        if ($this->estimate->status !== 'sent') {
            session()->flash('error', 'This estimate can no longer be declined.');

            return;
        }

        $this->estimate->markAsRejected();

        $this->showDeclineModal = false;

        session()->flash('success', 'Estimate declined. If you have any questions, please contact us.');

        $this->estimate->refresh();

        $this->dispatch('estimate-updated');
    }

    public function render(): View
    {
        $items = $this->estimate->items()->get();

        return view('livewire.customer.estimates.show', [
            'estimate' => $this->estimate,
            'items' => $items,
        ]);
    }
}
