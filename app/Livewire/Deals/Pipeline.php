<?php

namespace App\Livewire\Deals;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class Pipeline extends Component
{
    public string $search = '';

    public string $assignedToFilter = '';

    public string $customerFilter = '';

    #[On('deal-created')]
    #[On('deal-updated')]
    public function refreshPipeline(): void
    {
        // Re-render is triggered automatically
    }

    /**
     * Get the stage colors for display.
     *
     * @return array<string, string>
     */
    public function getStageColors(): array
    {
        return [
            'lead' => 'bg-blue-100 dark:bg-blue-900/50 border-blue-300 dark:border-blue-700',
            'quoted' => 'bg-purple-100 dark:bg-purple-900/50 border-purple-300 dark:border-purple-700',
            'scheduled' => 'bg-yellow-100 dark:bg-yellow-900/50 border-yellow-300 dark:border-yellow-700',
            'in_progress' => 'bg-orange-100 dark:bg-orange-900/50 border-orange-300 dark:border-orange-700',
            'completed' => 'bg-green-100 dark:bg-green-900/50 border-green-300 dark:border-green-700',
            'paid' => 'bg-emerald-100 dark:bg-emerald-900/50 border-emerald-300 dark:border-emerald-700',
            'cancelled' => 'bg-red-100 dark:bg-red-900/50 border-red-300 dark:border-red-700',
        ];
    }

    /**
     * Get the stage header colors.
     *
     * @return array<string, string>
     */
    public function getStageHeaderColors(): array
    {
        return [
            'lead' => 'bg-blue-500',
            'quoted' => 'bg-purple-500',
            'scheduled' => 'bg-yellow-500',
            'in_progress' => 'bg-orange-500',
            'completed' => 'bg-green-500',
            'paid' => 'bg-emerald-500',
            'cancelled' => 'bg-red-500',
        ];
    }

    /**
     * Handle the sort/reorder event when deals are dragged between stages.
     *
     * @param  array<int, int|string>  $order
     */
    public function updateOrder(array $order, string $fromGroup, string $toGroup): void
    {
        foreach ($order as $position => $id) {
            Deal::where('id', $id)->update([
                'stage' => $toGroup,
                'sort_order' => $position,
            ]);
        }
    }

    /**
     * Get deals grouped by stage.
     *
     * @return array<string, Collection<int, Deal>>
     */
    public function getDealsByStage(): array
    {
        $query = Deal::query()
            ->with(['customer', 'assignedTo'])
            ->search($this->search)
            ->when($this->assignedToFilter, function ($query) {
                return $query->where('assigned_to', $this->assignedToFilter);
            })
            ->when($this->customerFilter, function ($query) {
                return $query->where('customer_id', $this->customerFilter);
            })
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc');

        $deals = $query->get();

        $dealsByStage = [];
        foreach (array_keys(Deal::STAGES) as $stage) {
            $dealsByStage[$stage] = $deals->where('stage', $stage)->values();
        }

        return $dealsByStage;
    }

    /**
     * Get internal users for filter dropdown.
     *
     * @return Collection<int, User>
     */
    public function getInternalUsers(): Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get customers for filter dropdown.
     *
     * @return Collection<int, Customer>
     */
    public function getCustomers(): Collection
    {
        return Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    public function render(): View
    {
        $user = Auth::user();

        if (! $user || ! $user->isInternal()) {
            abort(403, 'Only internal users can access the deal pipeline.');
        }

        return view('livewire.deals.pipeline', [
            'stages' => Deal::STAGES,
            'dealsByStage' => $this->getDealsByStage(),
            'stageColors' => $this->getStageColors(),
            'stageHeaderColors' => $this->getStageHeaderColors(),
            'internalUsers' => $this->getInternalUsers(),
            'customers' => $this->getCustomers(),
        ])->layout('layouts.app', ['title' => 'Deal Pipeline']);
    }
}
