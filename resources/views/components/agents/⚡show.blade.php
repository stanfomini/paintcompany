<?php

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public User $user;

    public string $activeTab = 'overview';

    public function mount(User $user): void
    {
        if (! $user->isAgent()) {
            abort(404);
        }

        $this->user = $user->load([
            'agentProfile',
            'agentListings',
            'referredCustomers',
        ]);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('agents.index')" icon="arrow-left" wire:navigate>
                    Back
                </flux:button>
                <flux:heading size="xl">{{ $user->name }}</flux:heading>
                @if ($user->is_active)
                    <flux:badge size="sm" color="green">Active</flux:badge>
                @else
                    <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                @endif
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <!-- Stats Overview -->
        <div class="grid gap-4 md:grid-cols-3 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Total Listings</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $user->agentListings->count() }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Customers Referred</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $user->referredCustomers->count() }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Active Listings</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $user->agentListings->where('status', 'active')->count() }}</flux:heading>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="border-b border-zinc-200 dark:border-zinc-700 mb-6">
            <nav class="-mb-px flex space-x-8">
                <button
                    wire:click="setTab('overview')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'overview' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    Overview
                </button>
                <button
                    wire:click="setTab('listings')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'listings' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    Listings ({{ $user->agentListings->count() }})
                </button>
                <button
                    wire:click="setTab('customers')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'customers' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    Referred Customers ({{ $user->referredCustomers->count() }})
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        @if ($activeTab === 'overview')
            <div class="grid gap-6 md:grid-cols-2">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Contact Information</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Email</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->email }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Phone</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->agentProfile?->phone ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Professional Details</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Company</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->agentProfile?->company_name ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">License Number</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->agentProfile?->license_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            @if ($user->agentProfile?->bio)
                <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Bio</flux:heading>
                    <flux:text>{{ $user->agentProfile->bio }}</flux:text>
                </div>
            @endif
        @endif

        @if ($activeTab === 'listings')
            @if ($user->agentListings->count() > 0)
                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Address
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Price
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Open House
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($user->agentListings as $listing)
                                <tr wire:key="listing-{{ $listing->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $listing->address }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $listing->city }}, {{ $listing->state }} {{ $listing->zip }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>${{ number_format($listing->listing_price, 2) }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @switch($listing->status)
                                            @case('active')
                                                <flux:badge size="sm" color="green">Active</flux:badge>
                                                @break
                                            @case('pending')
                                                <flux:badge size="sm" color="yellow">Pending</flux:badge>
                                                @break
                                            @case('sold')
                                                <flux:badge size="sm" color="blue">Sold</flux:badge>
                                                @break
                                            @default
                                                <flux:badge size="sm" color="zinc">Inactive</flux:badge>
                                        @endswitch
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>
                                            @if ($listing->open_house_date)
                                                {{ $listing->open_house_date->format('M j, Y g:i A') }}
                                            @else
                                                -
                                            @endif
                                        </flux:text>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="building-office" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">No listings</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">This agent has no listings yet.</flux:text>
                </div>
            @endif
        @endif

        @if ($activeTab === 'customers')
            @if ($user->referredCustomers->count() > 0)
                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Name
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Email
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Phone
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($user->referredCustomers as $customer)
                                <tr wire:key="customer-{{ $customer->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $customer->full_name }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>{{ $customer->email ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>{{ $customer->phone ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if ($customer->status === 'active')
                                            <flux:badge size="sm" color="green">Active</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <flux:button variant="ghost" size="sm" icon="eye" :href="route('customers.show', $customer)" wire:navigate>
                                            View
                                        </flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="users" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">No referred customers</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">This agent has not referred any customers yet.</flux:text>
                </div>
            @endif
        @endif
    </div>
</div>
