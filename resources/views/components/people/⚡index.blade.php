<?php

use App\Models\Activity;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public string $activeTab = 'activity';

    public string $globalSearch = '';

    public string $timeFilter = 'today';

    // Tab-specific searches
    public string $customerSearch = '';

    public string $agentSearch = '';

    public string $crewSearch = '';

    public string $propertySearch = '';

    // Tab-specific status filters
    public string $customerStatusFilter = 'all';

    public string $agentStatusFilter = 'all';

    public string $crewStatusFilter = 'all';

    public string $propertyTypeFilter = 'all';

    public string $propertySourceFilter = 'all';

    public int $perPage = 15;

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function updatedGlobalSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAgentSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCrewSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPropertySearch(): void
    {
        $this->resetPage();
    }

    public function updatedTimeFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function activityItems(): Collection
    {
        $user = auth()->user();

        // Get pending tasks
        $tasksQuery = Activity::query()
            ->tasks()
            ->incomplete()
            ->forUserOrAssignedTo($user)
            ->with(['subject', 'user', 'assignee']);

        // Apply time filter
        if ($this->timeFilter === 'today') {
            $tasksQuery->where(function ($q) {
                $q->whereNull('due_at')
                    ->orWhereDate('due_at', '<=', today());
            });
        } elseif ($this->timeFilter === 'week') {
            $tasksQuery->where(function ($q) {
                $q->whereNull('due_at')
                    ->orWhereDate('due_at', '<=', now()->endOfWeek());
            });
        }

        $pendingTasks = $tasksQuery->orderBy('due_at')->limit(20)->get()
            ->map(fn ($task) => [
                'type' => 'task',
                'id' => $task->id,
                'title' => $task->title,
                'subtitle' => $task->subject?->title ?? $task->subject?->full_name ?? 'Planner Task',
                'due_at' => $task->due_at,
                'is_overdue' => $task->isOverdue(),
                'created_at' => $task->created_at,
                'url' => $task->subject ? $this->getSubjectUrl($task) : route('planner.index'),
            ]);

        // Get recently completed tasks (if showing all time or week)
        $completedTasks = collect();
        if ($this->timeFilter !== 'today') {
            $completedTasksQuery = Activity::query()
                ->tasks()
                ->completed()
                ->forUserOrAssignedTo($user)
                ->with(['subject', 'user', 'assignee']);

            if ($this->timeFilter === 'week') {
                $completedTasksQuery->where('completed_at', '>=', now()->startOfWeek());
            } else {
                $completedTasksQuery->where('completed_at', '>=', now()->subDays(7));
            }

            $completedTasks = $completedTasksQuery->orderByDesc('completed_at')->limit(10)->get()
                ->map(fn ($task) => [
                    'type' => 'completed_task',
                    'id' => $task->id,
                    'title' => $task->title,
                    'subtitle' => 'Completed ' . $task->completed_at->diffForHumans(),
                    'due_at' => null,
                    'is_overdue' => false,
                    'created_at' => $task->completed_at,
                    'url' => $task->subject ? $this->getSubjectUrl($task) : route('planner.index'),
                ]);
        }

        // Get threads with unread messages
        $unreadThreads = ChatThread::query()
            ->forUser($user)
            ->active()
            ->with(['latestMessage.user', 'deal.customer', 'activeParticipants.user'])
            ->whereHas('messages', function ($q) use ($user) {
                $q->where('user_id', '!=', $user->id)
                    ->where('created_at', '>', function ($sub) use ($user) {
                        $sub->select('last_read_at')
                            ->from('chat_participants')
                            ->whereColumn('chat_participants.thread_id', 'chat_messages.thread_id')
                            ->where('chat_participants.user_id', $user->id)
                            ->limit(1);
                    });
            })
            ->latestActivity()
            ->limit(20)
            ->get()
            ->map(fn ($thread) => [
                'type' => 'message',
                'id' => $thread->id,
                'title' => 'New message from ' . ($thread->latestMessage?->user?->name ?? 'Unknown'),
                'subtitle' => $thread->getDisplayNameFor($user),
                'due_at' => null,
                'is_overdue' => false,
                'created_at' => $thread->last_message_at ?? $thread->created_at,
                'url' => route('chat.show', $thread),
            ]);

        // Combine and sort by most recent
        return $pendingTasks
            ->concat($completedTasks)
            ->concat($unreadThreads)
            ->sortByDesc('created_at')
            ->values();
    }

    protected function getSubjectUrl(Activity $activity): string
    {
        if ($activity->subject instanceof \App\Models\Deal) {
            return route('deals.show', $activity->subject);
        }
        if ($activity->subject instanceof \App\Models\Customer) {
            return route('customers.show', $activity->subject);
        }

        return route('planner.index');
    }

    #[Computed]
    public function globalSearchResults(): ?array
    {
        if (strlen($this->globalSearch) < 2) {
            return null;
        }

        $search = $this->globalSearch;

        // Search customers
        $customers = Customer::query()
            ->search($search)
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->full_name,
                'subtitle' => $c->email ?? $c->phone ?? '',
                'url' => route('customers.show', $c),
            ]);

        // Search agents
        $agents = User::query()
            ->where('type', 'agent')
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('agentProfile', function ($pq) use ($search) {
                        $pq->where('company_name', 'like', "%{$search}%");
                    });
            })
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'subtitle' => $a->agentProfile?->company_name ?? $a->email,
                'url' => route('agents.show', $a),
            ]);

        // Search crews
        $crews = User::query()
            ->where('type', 'crew')
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('crewProfile', function ($pq) use ($search) {
                        $pq->where('company_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            })
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subtitle' => $c->crewProfile?->company_name ?? $c->email,
                'url' => route('crews.show', $c),
            ]);

        // Search properties
        $properties = Property::query()
            ->search($search)
            ->with('customer')
            ->limit(5)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->address,
                'subtitle' => $p->customer?->full_name ?? ($p->isListing() ? 'Agent Listing' : 'No customer'),
                'url' => route('properties.show', $p),
            ]);

        return [
            'customers' => $customers,
            'agents' => $agents,
            'crews' => $crews,
            'properties' => $properties,
        ];
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->when($this->customerSearch, fn ($q) => $q->search($this->customerSearch))
            ->when($this->customerStatusFilter !== 'all', function ($q) {
                if ($this->customerStatusFilter === 'archived') {
                    return $q->onlyTrashed();
                }

                return $q->where('status', $this->customerStatusFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function agents()
    {
        return User::query()
            ->where('type', 'agent')
            ->with('agentProfile')
            ->when($this->agentSearch, function ($q) {
                $q->where(function ($query) {
                    $query->where('name', 'like', "%{$this->agentSearch}%")
                        ->orWhere('email', 'like', "%{$this->agentSearch}%")
                        ->orWhereHas('agentProfile', function ($pq) {
                            $pq->where('company_name', 'like', "%{$this->agentSearch}%");
                        });
                });
            })
            ->when($this->agentStatusFilter !== 'all', fn ($q) => $q->where('is_active', $this->agentStatusFilter === 'active'))
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function crews()
    {
        return User::query()
            ->where('type', 'crew')
            ->with('crewProfile')
            ->when($this->crewSearch, function ($q) {
                $q->where(function ($query) {
                    $query->where('name', 'like', "%{$this->crewSearch}%")
                        ->orWhere('email', 'like', "%{$this->crewSearch}%")
                        ->orWhereHas('crewProfile', function ($pq) {
                            $pq->where('company_name', 'like', "%{$this->crewSearch}%")
                                ->orWhere('phone', 'like', "%{$this->crewSearch}%");
                        });
                });
            })
            ->when($this->crewStatusFilter !== 'all', fn ($q) => $q->where('is_active', $this->crewStatusFilter === 'active'))
            ->latest()
            ->paginate($this->perPage);
    }
    #[Computed]
    public function properties()
    {
        return Property::query()
            ->with(['customer', 'agent'])
            ->when($this->propertySearch, fn ($q) => $q->search($this->propertySearch))
            ->when($this->propertyTypeFilter !== 'all', fn ($q) => $q->ofType($this->propertyTypeFilter))
            ->when($this->propertySourceFilter !== 'all', fn ($q) => $q->ofSource($this->propertySourceFilter))
            ->latest()
            ->paginate($this->perPage);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">People</flux:heading>
        </div>

        <!-- Global Search -->
        <div class="mb-6">
            <flux:input
                wire:model.live.debounce.300ms="globalSearch"
                type="search"
                placeholder="Search all people..."
                icon="magnifying-glass"
            />
        </div>

        @if ($globalSearch && $this->globalSearchResults)
            <!-- Global Search Results -->
            <div class="space-y-6">
                @foreach (['customers' => 'Customers', 'agents' => 'Agents', 'crews' => 'Crews', 'properties' => 'Properties'] as $key => $label)
                    @if ($this->globalSearchResults[$key]->count() > 0)
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg">
                            <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex justify-between items-center">
                                <flux:heading size="sm">{{ $label }}</flux:heading>
                                <button
                                    wire:click="$set('globalSearch', ''); $set('activeTab', '{{ $key }}')"
                                    class="text-sm text-blue-600 hover:underline"
                                >
                                    View all {{ strtolower($label) }}
                                </button>
                            </div>
                            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->globalSearchResults[$key] as $result)
                                    <a href="{{ $result['url'] }}" wire:navigate class="block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                        <flux:text class="font-medium">{{ $result['name'] }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $result['subtitle'] }}</flux:text>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach

                @if (collect($this->globalSearchResults)->every(fn ($r) => $r->isEmpty()))
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon name="magnifying-glass" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">No results found</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">Try a different search term.</flux:text>
                    </div>
                @endif
            </div>
        @else
            <!-- Tab Navigation -->
            <div class="border-b border-zinc-200 dark:border-zinc-700 mb-6">
                <nav class="-mb-px flex space-x-8">
                    <button
                        wire:click="setTab('activity')"
                        class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'activity' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                    >
                        Activity
                    </button>
                    <button
                        wire:click="setTab('customers')"
                        class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'customers' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                    >
                        Customers
                    </button>
                    <button
                        wire:click="setTab('agents')"
                        class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'agents' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                    >
                        Agents
                    </button>
                    <button
                        wire:click="setTab('crews')"
                        class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'crews' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                    >
                        Crews
                    </button>
                    <button
                        wire:click="setTab('properties')"
                        class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'properties' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                    >
                        Properties
                    </button>
                </nav>
            </div>

            <!-- Tab Content -->
            @if ($activeTab === 'activity')
                <!-- Time Filter -->
                <div class="flex gap-2 mb-4">
                    <button
                        wire:click="$set('timeFilter', 'today')"
                        class="px-3 py-1 rounded-full text-sm {{ $timeFilter === 'today' ? 'bg-zinc-800 text-white dark:bg-white dark:text-zinc-800' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300' }}"
                    >
                        Today
                    </button>
                    <button
                        wire:click="$set('timeFilter', 'week')"
                        class="px-3 py-1 rounded-full text-sm {{ $timeFilter === 'week' ? 'bg-zinc-800 text-white dark:bg-white dark:text-zinc-800' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300' }}"
                    >
                        This Week
                    </button>
                    <button
                        wire:click="$set('timeFilter', 'all')"
                        class="px-3 py-1 rounded-full text-sm {{ $timeFilter === 'all' ? 'bg-zinc-800 text-white dark:bg-white dark:text-zinc-800' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300' }}"
                    >
                        All Time
                    </button>
                </div>

                @if ($this->activityItems->count() > 0)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->activityItems as $item)
                            <a href="{{ $item['url'] }}" wire:navigate wire:key="activity-{{ $item['type'] }}-{{ $item['id'] }}" class="flex items-start gap-4 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <div class="flex-shrink-0 mt-1">
                                    @if ($item['type'] === 'message')
                                        <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                                            <flux:icon name="envelope" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                                        </div>
                                    @elseif ($item['type'] === 'completed_task')
                                        <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900 flex items-center justify-center">
                                            <flux:icon name="check-circle" class="w-4 h-4 text-green-600 dark:text-green-400" />
                                        </div>
                                    @else
                                        <div class="w-8 h-8 rounded-full {{ $item['is_overdue'] ? 'bg-red-100 dark:bg-red-900' : 'bg-yellow-100 dark:bg-yellow-900' }} flex items-center justify-center">
                                            <flux:icon name="clipboard-document-check" class="w-4 h-4 {{ $item['is_overdue'] ? 'text-red-600 dark:text-red-400' : 'text-yellow-600 dark:text-yellow-400' }}" />
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <flux:text class="font-medium">{{ $item['title'] }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">{{ $item['subtitle'] }}</flux:text>
                                    @if ($item['due_at'])
                                        <flux:text class="text-sm {{ $item['is_overdue'] ? 'text-red-600' : 'text-zinc-400' }}">
                                            Due {{ $item['due_at']->diffForHumans() }}
                                        </flux:text>
                                    @endif
                                </div>
                                <div class="flex-shrink-0 text-right">
                                    <flux:text class="text-sm text-zinc-400">
                                        {{ $item['created_at']->diffForHumans() }}
                                    </flux:text>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon name="check-circle" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">All caught up!</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">No pending tasks or unread messages.</flux:text>
                    </div>
                @endif
            @endif

            @if ($activeTab === 'customers')
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <div class="flex-1">
                        <flux:input
                            wire:model.live.debounce.300ms="customerSearch"
                            type="search"
                            placeholder="Search customers..."
                            icon="magnifying-glass"
                        />
                    </div>
                    <div class="sm:w-48">
                        <flux:select wire:model.live="customerStatusFilter">
                            <option value="all">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="archived">Archived</option>
                        </flux:select>
                    </div>
                </div>

                @if ($this->customers->count() > 0)
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Phone</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->customers as $customer)
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
                                            @if ($customer->trashed())
                                                <flux:badge size="sm" color="zinc">Archived</flux:badge>
                                            @elseif ($customer->status === 'active')
                                                <flux:badge size="sm" color="green">Active</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <flux:button variant="ghost" size="sm" icon="eye" :href="route('customers.show', $customer)" wire:navigate />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">
                        {{ $this->customers->links() }}
                    </div>
                @else
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon name="users" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">No customers found</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">Try adjusting your search or filter.</flux:text>
                    </div>
                @endif
            @endif

            @if ($activeTab === 'agents')
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <div class="flex-1">
                        <flux:input
                            wire:model.live.debounce.300ms="agentSearch"
                            type="search"
                            placeholder="Search agents..."
                            icon="magnifying-glass"
                        />
                    </div>
                    <div class="sm:w-48">
                        <flux:select wire:model.live="agentStatusFilter">
                            <option value="all">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </flux:select>
                    </div>
                </div>

                @if ($this->agents->count() > 0)
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Company</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->agents as $agent)
                                    <tr wire:key="agent-{{ $agent->id }}">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text class="font-medium">{{ $agent->name }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text>{{ $agent->agentProfile?->company_name ?? '-' }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text>{{ $agent->email }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if ($agent->is_active)
                                                <flux:badge size="sm" color="green">Active</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <flux:button variant="ghost" size="sm" icon="eye" :href="route('agents.show', $agent)" wire:navigate />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">
                        {{ $this->agents->links() }}
                    </div>
                @else
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon name="user-group" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">No agents found</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">Try adjusting your search or filter.</flux:text>
                    </div>
                @endif
            @endif

            @if ($activeTab === 'crews')
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <div class="flex-1">
                        <flux:input
                            wire:model.live.debounce.300ms="crewSearch"
                            type="search"
                            placeholder="Search crews..."
                            icon="magnifying-glass"
                        />
                    </div>
                    <div class="sm:w-48">
                        <flux:select wire:model.live="crewStatusFilter">
                            <option value="all">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </flux:select>
                    </div>
                </div>

                @if ($this->crews->count() > 0)
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Company</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Crew Size</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Compliance</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->crews as $crew)
                                    <tr wire:key="crew-{{ $crew->id }}">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text class="font-medium">{{ $crew->name }}</flux:text>
                                            <flux:text class="text-sm text-zinc-500">{{ $crew->email }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text>{{ $crew->crewProfile?->company_name ?? '-' }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text>{{ $crew->crewProfile?->crew_size ?? 1 }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @php
                                                $status = $crew->crewProfile?->getComplianceStatus() ?? 'compliant';
                                            @endphp
                                            @if ($status === 'expired')
                                                <flux:badge size="sm" color="red">Expired</flux:badge>
                                            @elseif ($status === 'warning')
                                                <flux:badge size="sm" color="yellow">Expiring</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="green">OK</flux:badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if ($crew->is_active)
                                                <flux:badge size="sm" color="green">Active</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <flux:button variant="ghost" size="sm" icon="eye" :href="route('crews.show', $crew)" wire:navigate />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">
                        {{ $this->crews->links() }}
                    </div>
                @else
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon name="user-group" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">No crews found</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">Try adjusting your search or filter.</flux:text>
                    </div>
                @endif
            @endif

            @if ($activeTab === 'properties')
                <div class="flex flex-col sm:flex-row gap-4 mb-6">
                    <div class="flex-1">
                        <flux:input
                            wire:model.live.debounce.300ms="propertySearch"
                            type="search"
                            placeholder="Search properties by address, city, customer..."
                            icon="magnifying-glass"
                        />
                    </div>
                    <div class="sm:w-40">
                        <flux:select wire:model.live="propertyTypeFilter">
                            <option value="all">All Types</option>
                            <option value="residential">Residential</option>
                            <option value="commercial">Commercial</option>
                            <option value="industrial">Industrial</option>
                        </flux:select>
                    </div>
                    <div class="sm:w-40">
                        <flux:select wire:model.live="propertySourceFilter">
                            <option value="all">All Sources</option>
                            <option value="direct">Direct</option>
                            <option value="agent_referral">Agent Referral</option>
                            <option value="open_house">Open House</option>
                            <option value="manual">Manual</option>
                        </flux:select>
                    </div>
                </div>

                @if ($this->properties->count() > 0)
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Address</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">City/State</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Customer</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Source</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">Agent</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->properties as $property)
                                    <tr wire:key="property-{{ $property->id }}">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text class="font-medium">{{ $property->address }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text>{{ $property->city }}, {{ $property->state }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text>{{ $property->customer?->full_name ?? '-' }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:badge size="sm" color="zinc">{{ ucfirst($property->type) }}</flux:badge>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if ($property->source)
                                                <flux:text class="text-sm">{{ App\Models\Property::SOURCES[$property->source] ?? $property->source }}</flux:text>
                                            @else
                                                <flux:text class="text-zinc-400">-</flux:text>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <flux:text>{{ $property->agent?->name ?? '-' }}</flux:text>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <flux:button variant="ghost" size="sm" icon="eye" :href="route('properties.show', $property)" wire:navigate />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">
                        {{ $this->properties->links() }}
                    </div>
                @else
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon name="home" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:heading size="sm" class="mt-4">No properties found</flux:heading>
                        <flux:text class="mt-2 text-zinc-500">Try adjusting your search or filter.</flux:text>
                    </div>
                @endif
            @endif
        @endif
    </div>
</div>
