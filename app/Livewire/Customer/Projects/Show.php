<?php

namespace App\Livewire\Customer\Projects;

use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Layout('components.layouts.customer', ['title' => 'Project Details'])]
class Show extends Component
{
    public Deal $deal;

    public function mount(Deal $deal): void
    {
        // Verify ownership
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer || $deal->customer_id !== $customer->id) {
            throw new NotFoundHttpException('Project not found.');
        }

        $this->deal = $deal;
    }

    public function render(): View
    {
        $estimates = $this->deal->estimates()
            ->whereIn('status', ['sent', 'accepted', 'rejected'])
            ->latest()
            ->get();

        $activities = $this->deal->activities()
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();

        return view('livewire.customer.projects.show', [
            'project' => $this->deal,
            'estimates' => $estimates,
            'activities' => $activities,
        ]);
    }
}
