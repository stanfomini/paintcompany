<?php

use App\Models\Customer;
use App\Models\Estimate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('components.layouts.customer', ['title' => 'Estimate Details'])] class extends Component {
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

        // Ensure the estimate has a public token for the proposal page
        if (! $estimate->public_token || ! $estimate->isPublicTokenValid()) {
            $estimate->generatePublicToken(365);
        }

        // Redirect to the full proposal page
        $this->redirect($estimate->getPublicUrl());
    }
}; ?>

<div>
    {{-- Redirecting to proposal page --}}
</div>
