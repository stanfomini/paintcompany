<?php

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new class extends Component
{
    public string $activeTab = 'members';

    public ?int $editingUserId = null;

    public string $selectedRole = '';

    // Custom role creation
    public bool $showCreateRole = false;

    public string $newRoleName = '';

    public string $cloneFromRole = '';

    // Role to delete
    public ?int $deletingRoleId = null;

    // Permission search filter
    public string $permissionSearch = '';

    public function boot(): void
    {
        $user = Auth::user();

        if (! $user || ! $user->can('team.manage_roles')) {
            abort(403);
        }
    }

    /**
     * Default roles that cannot be deleted.
     *
     * @return array<string>
     */
    protected function defaultRoleNames(): array
    {
        return ['owner', 'admin', 'manager', 'estimator', 'crew_lead'];
    }

    /**
     * Permission groups for the matrix display.
     *
     * @return array<string, array<string>>
     */
    #[Computed]
    public function permissionGroups(): array
    {
        return [
            'People' => ['people.view', 'people.create', 'people.edit', 'people.delete'],
            'Properties' => ['properties.view', 'properties.create', 'properties.edit', 'properties.delete'],
            'Deals' => ['deals.view', 'deals.create', 'deals.edit', 'deals.delete'],
            'Estimates' => ['estimates.view', 'estimates.create', 'estimates.edit', 'estimates.delete', 'estimates.send'],
            'Financials' => ['financials.view_cost_breakdown', 'financials.view_painter_breakdown', 'financials.manage_pricing'],
            'Jobs' => ['jobs.view', 'jobs.edit'],
            'Invoices' => ['invoices.view', 'invoices.create', 'invoices.send', 'invoices.manage'],
            'Payments' => ['payments.view', 'payments.record'],
            'Planner' => ['planner.view', 'planner.view_all', 'planner.manage'],
            'Production Rates' => ['production_rates.view', 'production_rates.manage'],
            'Agent Listings' => ['agent_listings.view', 'agent_listings.manage'],
            'Communication' => [
                'chat.access', 'chat.with_customers', 'chat.with_agents', 'chat.with_crew', 'chat.with_internal',
                'walkie_talkie.access', 'video_call.access',
            ],
            'Referrals' => ['referrals.view', 'referrals.manage'],
            'Business Plan' => ['business_plan.view', 'business_plan.manage'],
            'Roadmap' => ['roadmap.view', 'roadmap.edit', 'roadmap.manage'],
            'KPIs' => ['kpis.view', 'kpis.edit_targets', 'kpis.enter_actuals', 'kpis.manage'],
            'Vendors' => ['vendors.view', 'vendors.manage'],
            'Accounting' => ['accounting.view', 'accounting.manage'],
            'Photos' => ['photos.upload', 'photos.delete', 'photos.annotate', 'photos.comment'],
            'Employees' => ['employees.view', 'employees.create', 'employees.edit', 'employees.delete', 'employees.view_sensitive'],
            'Hiring' => ['hiring.view', 'hiring.manage'],
            'Onboarding' => ['onboarding.view', 'onboarding.manage'],
            'Time Tracking' => ['time_tracking.view', 'time_tracking.view_all', 'time_tracking.manage', 'time_tracking.clock'],
            'Scheduling' => ['scheduling.view', 'scheduling.view_all', 'scheduling.manage'],
            'Payroll' => ['payroll.view', 'payroll.manage'],
            'Settings' => [
                'settings.company', 'settings.packages', 'settings.allocations',
                'settings.payment_integrations', 'settings.service_categories',
                'settings.budget_mappings', 'settings.referral', 'settings.contracts',
                'settings.lead_sources', 'settings.workflows',
            ],
            'Administration' => ['team.manage_users', 'team.manage_invitations', 'team.manage_roles', 'admin.access_settings'],
        ];
    }

    /**
     * @return array<string, array<string>>
     */
    #[Computed]
    public function filteredPermissionGroups(): array
    {
        if (empty($this->permissionSearch)) {
            return $this->permissionGroups;
        }

        $search = strtolower($this->permissionSearch);
        $filtered = [];

        foreach ($this->permissionGroups as $groupName => $permissions) {
            if (str_contains(strtolower($groupName), $search)) {
                $filtered[$groupName] = $permissions;

                continue;
            }

            $matchedPermissions = array_filter($permissions, fn ($p) => str_contains(strtolower($p), $search) ||
                str_contains(strtolower($this->formatPermission($p)), $search));

            if (! empty($matchedPermissions)) {
                $filtered[$groupName] = array_values($matchedPermissions);
            }
        }

        return $filtered;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function internalUsers(): Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->with(['roles', 'employeeProfile'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        $defaults = $this->defaultRoleNames();

        return Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->sortBy(function ($role) use ($defaults) {
                $idx = array_search($role->name, $defaults);

                return $idx !== false ? $idx : 100 + $role->id;
            })
            ->values();
    }

    /**
     * Build a role -> permissions lookup for the matrix.
     *
     * @return array<string, array<string, bool>>
     */
    #[Computed]
    public function rolePermissionMap(): array
    {
        $map = [];

        foreach ($this->roles as $role) {
            $perms = $role->permissions->pluck('name')->toArray();
            $map[$role->name] = array_flip($perms);
        }

        return $map;
    }

    #[Computed]
    public function roleDescriptions(): array
    {
        return [
            'owner' => __('Full access to all features'),
            'admin' => __('Full access except role management'),
            'manager' => __('Manage team, estimates, deals, roadmap'),
            'estimator' => __('Create and manage estimates'),
            'crew_lead' => __('View jobs and planner'),
        ];
    }

    #[Computed]
    public function roleColors(): array
    {
        return [
            'owner' => 'amber',
            'admin' => 'purple',
            'manager' => 'blue',
            'estimator' => 'green',
            'crew_lead' => 'zinc',
        ];
    }

    /**
     * Toggle a permission for a role in the matrix.
     */
    public function togglePermission(string $roleName, string $permissionName): void
    {
        if ($roleName === 'owner') {
            return;
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role) {
            return;
        }

        $permission = Permission::where('name', $permissionName)->where('guard_name', 'web')->first();
        if (! $permission) {
            return;
        }

        if ($role->hasPermissionTo($permissionName)) {
            $role->revokePermissionTo($permissionName);
        } else {
            $role->givePermissionTo($permissionName);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        unset($this->rolePermissionMap);
        unset($this->roles);
    }

    /**
     * Toggle all permissions in a group for a role.
     */
    public function toggleGroupPermissions(string $roleName, string $groupName): void
    {
        if ($roleName === 'owner') {
            return;
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role) {
            return;
        }

        $permissions = $this->permissionGroups[$groupName] ?? [];
        if (empty($permissions)) {
            return;
        }

        $allGranted = collect($permissions)->every(fn ($p) => $role->hasPermissionTo($p));

        foreach ($permissions as $permName) {
            $permission = Permission::where('name', $permName)->where('guard_name', 'web')->first();
            if (! $permission) {
                continue;
            }

            if ($allGranted) {
                $role->revokePermissionTo($permName);
            } elseif (! $role->hasPermissionTo($permName)) {
                $role->givePermissionTo($permName);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        unset($this->rolePermissionMap);
        unset($this->roles);
    }

    // --- Team Members ---

    public function startEditing(int $userId): void
    {
        $user = User::find($userId);
        if (! $user) {
            return;
        }

        if ($userId === Auth::id()) {
            session()->flash('error', __('You cannot modify your own role.'));

            return;
        }

        $this->editingUserId = $userId;
        $this->selectedRole = $user->roles->first()?->name ?? '';
    }

    public function cancelEditing(): void
    {
        $this->editingUserId = null;
        $this->selectedRole = '';
    }

    public function saveRole(): void
    {
        if (! $this->editingUserId) {
            return;
        }

        $user = User::find($this->editingUserId);
        if (! $user) {
            return;
        }

        $role = Role::where('name', $this->selectedRole)->where('guard_name', 'web')->first();
        if (! $role && $this->selectedRole !== '') {
            session()->flash('error', __('Invalid role selected.'));

            return;
        }

        if ($this->selectedRole) {
            $user->syncRoles([$this->selectedRole]);

            // Auto-create EmployeeProfile if missing
            if (! $user->employeeProfile) {
                EmployeeProfile::create([
                    'user_id' => $user->id,
                    'employee_number' => EmployeeProfile::generateEmployeeNumber(),
                    'hire_date' => now()->format('Y-m-d'),
                    'employment_status' => 'active',
                    'pay_rate' => 0,
                    'pay_type' => 'hourly',
                ]);
            }
        } else {
            $user->syncRoles([]);
        }

        $this->editingUserId = null;
        $this->selectedRole = '';
        unset($this->internalUsers);

        session()->flash('success', __('Role updated for :name.', ['name' => $user->name]));
    }

    public function removeRole(int $userId): void
    {
        if ($userId === Auth::id()) {
            session()->flash('error', __('You cannot remove your own role.'));

            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        $user->syncRoles([]);
        unset($this->internalUsers);

        session()->flash('success', __('Role removed from :name.', ['name' => $user->name]));
    }

    // --- Custom Roles ---

    public function openCreateRole(): void
    {
        $this->newRoleName = '';
        $this->cloneFromRole = '';
        $this->showCreateRole = true;
    }

    public function closeCreateRole(): void
    {
        $this->showCreateRole = false;
        $this->newRoleName = '';
        $this->cloneFromRole = '';
        $this->resetValidation();
    }

    public function createRole(): void
    {
        $this->validate([
            'newRoleName' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_ ]+$/'],
        ], [
            'newRoleName.regex' => __('Role name may only contain letters, numbers, spaces, and underscores.'),
        ]);

        $slug = strtolower(str_replace(' ', '_', trim($this->newRoleName)));

        if (Role::where('name', $slug)->where('guard_name', 'web')->exists()) {
            $this->addError('newRoleName', __('A role with this name already exists.'));

            return;
        }

        $newRole = Role::create(['name' => $slug, 'guard_name' => 'web']);

        if ($this->cloneFromRole) {
            $sourceRole = Role::where('name', $this->cloneFromRole)->where('guard_name', 'web')->first();
            if ($sourceRole) {
                $newRole->syncPermissions($sourceRole->permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        unset($this->roles);
        unset($this->rolePermissionMap);

        $this->closeCreateRole();
        session()->flash('success', __('Role ":name" created.', ['name' => ucfirst(str_replace('_', ' ', $slug))]));
    }

    public function confirmDeleteRole(int $roleId): void
    {
        $this->deletingRoleId = $roleId;
    }

    public function cancelDeleteRole(): void
    {
        $this->deletingRoleId = null;
    }

    public function deleteRole(): void
    {
        if (! $this->deletingRoleId) {
            return;
        }

        $role = Role::find($this->deletingRoleId);
        if (! $role) {
            return;
        }

        if (in_array($role->name, $this->defaultRoleNames())) {
            session()->flash('error', __('Default roles cannot be deleted.'));
            $this->deletingRoleId = null;

            return;
        }

        $affectedCount = $role->users()->count();
        $roleName = $role->name;

        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        unset($this->roles);
        unset($this->rolePermissionMap);
        unset($this->internalUsers);

        $this->deletingRoleId = null;

        $msg = __('Role ":name" deleted.', ['name' => ucfirst(str_replace('_', ' ', $roleName))]);
        if ($affectedCount > 0) {
            $msg .= ' ' . __(':count user(s) were affected.', ['count' => $affectedCount]);
        }
        session()->flash('success', $msg);
    }

    /**
     * Format a permission name for display.
     */
    public function formatPermission(string $name): string
    {
        // Dot format: "estimates.view" → "View"
        $parts = explode('.', $name);
        $action = end($parts);

        return ucfirst(str_replace('_', ' ', $action));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Roles & Access') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Roles & Access')" :subheading="__('Manage team member roles and permissions')" :fullWidth="true">
        <div class="my-6 w-full space-y-6">

            @if (session('success'))
                <flux:callout variant="success" class="mb-4">
                    {{ session('success') }}
                </flux:callout>
            @endif

            @if (session('error'))
                <flux:callout variant="danger" class="mb-4">
                    {{ session('error') }}
                </flux:callout>
            @endif

            {{-- Tabs --}}
            <div class="flex items-center gap-2 border-b border-zinc-200 dark:border-zinc-700 pb-2">
                <flux:button
                    size="sm"
                    :variant="$activeTab === 'members' ? 'primary' : 'ghost'"
                    wire:click="$set('activeTab', 'members')"
                >
                    {{ __('Team Members') }}
                </flux:button>
                <flux:button
                    size="sm"
                    :variant="$activeTab === 'matrix' ? 'primary' : 'ghost'"
                    wire:click="$set('activeTab', 'matrix')"
                >
                    {{ __('Permission Matrix') }}
                </flux:button>
            </div>

            @if ($activeTab === 'members')
                {{-- ========= TEAM MEMBERS TAB ========= --}}

                {{-- Role Legend --}}
                <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-4 border border-zinc-200 dark:border-zinc-700">
                    <flux:heading size="sm" class="mb-3">{{ __('Available Roles') }}</flux:heading>
                    <div class="space-y-2">
                        @foreach ($this->roles as $role)
                            <div class="flex items-start gap-3">
                                <flux:badge size="sm" color="{{ $this->roleColors[$role->name] ?? 'zinc' }}">
                                    {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                                </flux:badge>
                                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $this->roleDescriptions[$role->name] ?? __('Custom role') }}
                                </flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>

                <flux:separator />

                {{-- Team Members --}}
                <flux:heading size="sm">{{ __('Team Members') }}</flux:heading>

                <div class="space-y-3">
                    @forelse ($this->internalUsers as $user)
                        <div
                            wire:key="user-{{ $user->id }}"
                            class="flex items-center justify-between p-4 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700"
                        >
                            <div class="flex items-center gap-4">
                                <flux:avatar name="{{ $user->name }}" size="sm" />
                                <div>
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium">{{ $user->name }}</flux:text>
                                        @if ($user->id === Auth::id())
                                            <flux:badge size="sm" color="blue">{{ __('You') }}</flux:badge>
                                        @endif
                                        @if ($user->employeeProfile?->onboarding_completed_at)
                                            <flux:badge size="sm" color="green">{{ __('Onboarded') }}</flux:badge>
                                        @elseif ($user->employeeProfile)
                                            <flux:badge size="sm" color="amber">{{ __('Pending Onboarding') }}</flux:badge>
                                        @endif
                                    </div>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $user->email }}
                                    </flux:text>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                @if ($this->editingUserId === $user->id)
                                    <flux:select wire:model="selectedRole" class="w-40">
                                        <option value="">{{ __('No Role') }}</option>
                                        @foreach ($this->roles as $role)
                                            <option value="{{ $role->name }}">
                                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                                            </option>
                                        @endforeach
                                    </flux:select>
                                    <flux:button size="sm" variant="primary" wire:click="saveRole">
                                        {{ __('Save') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="cancelEditing">
                                        {{ __('Cancel') }}
                                    </flux:button>
                                @else
                                    @if ($user->roles->isNotEmpty())
                                        @foreach ($user->roles as $role)
                                            <flux:badge color="{{ $this->roleColors[$role->name] ?? 'zinc' }}">
                                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                                            </flux:badge>
                                        @endforeach
                                    @else
                                        <flux:text class="text-sm text-zinc-400 dark:text-zinc-500 italic">
                                            {{ __('No role assigned') }}
                                        </flux:text>
                                    @endif

                                    @if ($user->id !== Auth::id())
                                        <flux:dropdown>
                                            <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil" wire:click="startEditing({{ $user->id }})">
                                                    {{ __('Change Role') }}
                                                </flux:menu.item>
                                                @if ($user->roles->isNotEmpty())
                                                    <flux:menu.item
                                                        icon="trash"
                                                        wire:click="removeRole({{ $user->id }})"
                                                        :wire:confirm="__('Are you sure you want to remove the role from :name?', ['name' => $user->name])"
                                                        variant="danger"
                                                    >
                                                        {{ __('Remove Role') }}
                                                    </flux:menu.item>
                                                @endif
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8 text-zinc-400 dark:text-zinc-500">
                            {{ __('No internal users found. Use the Invitations feature to add team members.') }}
                        </div>
                    @endforelse
                </div>

                <flux:separator />

                {{-- Invite --}}
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="sm">{{ __('Add Team Members') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('Send invitations to add new internal team members.') }}
                        </flux:text>
                    </div>
                    <flux:button href="{{ route('invitations') }}" wire:navigate variant="primary" icon="envelope">
                        {{ __('Manage Invitations') }}
                    </flux:button>
                </div>

            @else
                {{-- ========= PERMISSION MATRIX TAB ========= --}}

                <div class="flex items-center justify-between gap-4">
                    <flux:text class="text-sm text-zinc-500 shrink-0">
                        {{ __('Toggle permissions for each role. Owner always has full access.') }}
                    </flux:text>
                    <div class="flex items-center gap-3">
                        <flux:input
                            wire:model.live.debounce.300ms="permissionSearch"
                            :placeholder="__('Search permissions...')"
                            icon="magnifying-glass"
                            size="sm"
                            class="w-64"
                        />
                        <flux:button size="sm" icon="plus" wire:click="openCreateRole">
                            {{ __('Create Role') }}
                        </flux:button>
                    </div>
                </div>

                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider sticky left-0 bg-zinc-50 dark:bg-zinc-800 z-10 min-w-[200px]">
                                    {{ __('Permission') }}
                                </th>
                                @foreach ($this->roles as $role)
                                    <th class="px-3 py-3 text-center text-xs font-medium uppercase tracking-wider min-w-[100px]">
                                        <div class="flex flex-col items-center gap-1">
                                            <flux:badge size="sm" color="{{ $this->roleColors[$role->name] ?? 'zinc' }}">
                                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                                            </flux:badge>
                                            @if (! in_array($role->name, $this->defaultRoleNames()))
                                                <button
                                                    wire:click="confirmDeleteRole({{ $role->id }})"
                                                    class="text-red-400 hover:text-red-600 text-xs"
                                                    :title="__('Delete role')"
                                                >
                                                    {{ __('delete') }}
                                                </button>
                                            @endif
                                        </div>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @forelse ($this->filteredPermissionGroups as $groupName => $permissions)
                                {{-- Group header with toggle --}}
                                <tr class="bg-zinc-50/50 dark:bg-zinc-800/30">
                                    <td class="px-4 py-2">
                                        <flux:text class="font-semibold text-xs uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                                            {{ $groupName }}
                                        </flux:text>
                                    </td>
                                    @foreach ($this->roles as $role)
                                        <td class="px-3 py-2 text-center">
                                            @if ($role->name === 'owner')
                                                <div class="flex justify-center">
                                                    <span class="text-xs text-zinc-400">{{ __('All') }}</span>
                                                </div>
                                            @else
                                                @php
                                                    $allGroupPerms = $this->permissionGroups[$groupName] ?? [];
                                                    $allGranted = collect($allGroupPerms)->every(fn ($p) => isset($this->rolePermissionMap[$role->name][$p]));
                                                    $someGranted = ! $allGranted && collect($allGroupPerms)->contains(fn ($p) => isset($this->rolePermissionMap[$role->name][$p]));
                                                @endphp
                                                <div class="flex justify-center">
                                                    <button
                                                        wire:click="toggleGroupPermissions('{{ $role->name }}', '{{ $groupName }}')"
                                                        class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors cursor-pointer {{ $allGranted ? 'bg-green-500 border-green-500 text-white' : ($someGranted ? 'bg-green-200 border-green-400 dark:bg-green-800 dark:border-green-600' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-400') }}"
                                                        title="{{ $allGranted ? __('Deselect all') : __('Select all') }} {{ $groupName }} {{ __('permissions') }}"
                                                    >
                                                        @if ($allGranted)
                                                            <flux:icon name="check" class="w-3 h-3" />
                                                        @elseif ($someGranted)
                                                            <flux:icon name="minus" class="w-3 h-3 text-green-700 dark:text-green-300" />
                                                        @endif
                                                    </button>
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                                @foreach ($permissions as $permission)
                                    <tr wire:key="perm-{{ $permission }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                        <td class="px-4 py-2 text-sm text-zinc-700 dark:text-zinc-300 sticky left-0 bg-white dark:bg-zinc-900 z-10">
                                            {{ $this->formatPermission($permission) }}
                                            <span class="text-xs text-zinc-400 ml-1">({{ $permission }})</span>
                                        </td>
                                        @foreach ($this->roles as $role)
                                            <td class="px-3 py-2 text-center">
                                                @if ($role->name === 'owner')
                                                    <div class="flex justify-center">
                                                        <div class="w-5 h-5 rounded bg-green-500 flex items-center justify-center" :title="__('Owner always has full access')">
                                                            <flux:icon name="check" class="w-3 h-3 text-white" />
                                                        </div>
                                                    </div>
                                                @else
                                                    <div class="flex justify-center">
                                                        <button
                                                            wire:click="togglePermission('{{ $role->name }}', '{{ $permission }}')"
                                                            class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors cursor-pointer {{ isset($this->rolePermissionMap[$role->name][$permission]) ? 'bg-green-500 border-green-500 text-white' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-400' }}"
                                                        >
                                                            @if (isset($this->rolePermissionMap[$role->name][$permission]))
                                                                <flux:icon name="check" class="w-3 h-3" />
                                                            @endif
                                                        </button>
                                                    </div>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @empty
                                <tr>
                                    <td colspan="{{ count($this->roles) + 1 }}" class="px-4 py-8 text-center">
                                        <flux:text class="text-sm text-zinc-400 dark:text-zinc-500">
                                            {{ __('No permissions match ":search".', ['search' => $permissionSearch]) }}
                                        </flux:text>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Delete Role Confirmation --}}
                @if ($deletingRoleId)
                    @php
                        $roleToDelete = $this->roles->firstWhere('id', $deletingRoleId);
                        $affectedUsers = $roleToDelete ? $roleToDelete->users()->count() : 0;
                    @endphp
                    <flux:modal wire:model.self="deletingRoleId" class="max-w-sm">
                        <div class="space-y-4">
                            <flux:heading size="lg">{{ __('Delete Role') }}</flux:heading>
                            <flux:text>
                                {{ __('Are you sure you want to delete the') }}
                                <strong>{{ ucfirst(str_replace('_', ' ', $roleToDelete?->name ?? '')) }}</strong> {{ __('role?') }}
                            </flux:text>
                            @if ($affectedUsers > 0)
                                <flux:callout variant="warning">
                                    {{ __(':count user(s) currently have this role and will lose it.', ['count' => $affectedUsers]) }}
                                </flux:callout>
                            @endif
                            <div class="flex justify-end gap-3">
                                <flux:button variant="ghost" wire:click="cancelDeleteRole">{{ __('Cancel') }}</flux:button>
                                <flux:button variant="danger" wire:click="deleteRole">{{ __('Delete Role') }}</flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endif
            @endif
        </div>
    </x-pages::settings.layout>

    {{-- Create Role Modal --}}
    <flux:modal wire:model="showCreateRole" class="max-w-sm">
        <form wire:submit="createRole">
            <flux:heading size="lg" class="mb-4">{{ __('Create Custom Role') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Role Name') }}</flux:label>
                    <flux:input wire:model="newRoleName" :placeholder="__('e.g. Sales Rep')" />
                    <flux:error name="newRoleName" />
                    <flux:text class="text-xs text-zinc-400 mt-1">
                        {{ __('Letters, numbers, spaces, and underscores only.') }}
                    </flux:text>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Clone Permissions From') }}</flux:label>
                    <flux:select wire:model="cloneFromRole">
                        <option value="">{{ __('None (empty)') }}</option>
                        @foreach ($this->roles as $role)
                            <option value="{{ $role->name }}">
                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:text class="text-xs text-zinc-400 mt-1">
                        {{ __('Optionally copy all permissions from an existing role.') }}
                    </flux:text>
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeCreateRole" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Create Role') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
