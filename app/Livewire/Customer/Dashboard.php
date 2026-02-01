<?php

namespace App\Livewire\Customer;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Dashboard extends Component
{
    public function render(): View
    {
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer) {
            return view('livewire.customer.dashboard', [
                'customer' => null,
                'activeProjectsCount' => 0,
                'pendingEstimatesCount' => 0,
                'propertiesCount' => 0,
                'recentProjects' => collect(),
                'pendingEstimates' => collect(),
            ])->layout('components.layouts.customer', ['title' => 'Dashboard']);
        }

        // Stats
        $activeProjectsCount = Deal::query()
            ->where('customer_id', $customer->id)
            ->active()
            ->count();

        $pendingEstimatesCount = Estimate::query()
            ->whereHas('deal', fn ($q) => $q->where('customer_id', $customer->id))
            ->where('status', 'sent')
            ->count();

        $propertiesCount = Property::query()
            ->where('customer_id', $customer->id)
            ->count();

        // Recent projects
        $recentProjects = Deal::query()
            ->where('customer_id', $customer->id)
            ->with('property')
            ->latest()
            ->limit(5)
            ->get();

        // Pending estimates requiring action
        $pendingEstimates = Estimate::query()
            ->whereHas('deal', fn ($q) => $q->where('customer_id', $customer->id))
            ->where('status', 'sent')
            ->with('deal')
            ->latest()
            ->limit(5)
            ->get();

        return view('livewire.customer.dashboard', [
            'customer' => $customer,
            'activeProjectsCount' => $activeProjectsCount,
            'pendingEstimatesCount' => $pendingEstimatesCount,
            'propertiesCount' => $propertiesCount,
            'recentProjects' => $recentProjects,
            'pendingEstimates' => $pendingEstimates,
        ])->layout('components.layouts.customer', ['title' => 'Dashboard']);
    }
}
