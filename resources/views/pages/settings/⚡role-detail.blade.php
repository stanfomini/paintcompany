<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new class extends Component
{
    public Role $role;

    public string $permissionSearch = '';

    public function boot(): void
    {
        $user = Auth::user();

        if (! $user || ! $user->can('team.manage_roles')) {
            abort(403);
        }
    }

    public function mount(string $roleName): void
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if (! $role) {
            abort(404);
        }

        $this->role = $role;
    }

    /**
     * @return array<string>
     */
    protected function defaultRoleNames(): array
    {
        return ['owner', 'admin', 'manager', 'estimator', 'hr_manager', 'door_to_door', 'door_knocker'];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function roleDescriptions(): array
    {
        return [
            'owner' => __('Full access to all features'),
            'admin' => __('Full access except role management'),
            'manager' => __('Manage team, estimates, deals, roadmap'),
            'estimator' => __('Create and manage estimates'),
            'hr_manager' => __('Employee management, hiring, onboarding, payroll'),
            'door_to_door' => __('Door knocking, territory claiming, quick video outreach'),
            'door_knocker' => __('Assigned block knocking, minimal access'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function roleColors(): array
    {
        return [
            'owner' => 'amber',
            'admin' => 'purple',
            'manager' => 'blue',
            'estimator' => 'green',
            'hr_manager' => 'cyan',
            'door_to_door' => 'orange',
            'door_knocker' => 'zinc',
        ];
    }

    /**
     * @return array<string, array<string>>
     */
    #[Computed]
    public function permissionGroups(): array
    {
        return [
            'People' => ['people.view', 'people.view_all', 'people.create', 'people.edit', 'people.delete'],
            'Properties' => ['properties.view', 'properties.view_all', 'properties.create', 'properties.edit', 'properties.delete'],
            'Deals' => ['deals.view', 'deals.view_all', 'deals.create', 'deals.edit', 'deals.delete'],
            'Estimates' => ['estimates.view', 'estimates.view_all', 'estimates.create', 'estimates.edit', 'estimates.delete', 'estimates.send'],
            'Financials' => ['financials.view_cost_breakdown', 'financials.view_painter_breakdown', 'financials.manage_pricing'],
            'Jobs' => ['jobs.view', 'jobs.view_all', 'jobs.edit'],
            'Invoices' => ['invoices.view', 'invoices.view_all', 'invoices.create', 'invoices.send', 'invoices.manage'],
            'Payments' => ['payments.view', 'payments.record'],
            'Planner' => ['planner.view', 'planner.view_all', 'planner.manage', 'planner.delegate', 'planner.delegate_own'],
            'Production Rates' => ['production_rates.view', 'production_rates.manage'],
            'Agent Listings' => ['agent_listings.view', 'agent_listings.manage'],
            'Email' => ['email.access', 'email.manage'],
            'Communication' => [
                'chat.access', 'chat.view_deal_chats', 'chat.with_customers', 'chat.with_agents', 'chat.with_crew', 'chat.with_internal',
                'walkie_talkie.access', 'video_call.access',
            ],
            'Media Hub' => [
                'media.view', 'media.record', 'media.manage',
                'media.manage_channels', 'media.manage_training',
                'media.send_emails', 'media.view_own_analytics',
            ],
            'Sales Territories' => ['sales_territories.view', 'sales_territories.manage'],
            'Door Knocks' => ['door_knocks.view', 'door_knocks.record', 'door_knocks.view_all'],
            'Territory Dispatch' => ['territory_dispatch.view', 'territory_dispatch.manage', 'territory_dispatch.claim'],
            'Territory Hierarchy' => ['territories.manage_regions', 'territories.manage_zones', 'territories.manage_blocks', 'territories.assign_team', 'territories.view_all', 'territories.view_own', 'territories.dispatch_blocks'],
            'Commission' => ['commission.configure', 'commission.view_all', 'commission.view_team'],
            'Analytics' => ['analytics.territory_pnl'],
            'Compensation' => ['compensation.planner'],
            'Referrals' => ['referrals.view', 'referrals.manage'],
            'Business Plan' => ['business_plan.view', 'business_plan.manage'],
            'Roadmap' => ['roadmap.view', 'roadmap.edit', 'roadmap.manage'],
            'KPIs' => ['kpis.view', 'kpis.edit_targets', 'kpis.enter_actuals', 'kpis.manage'],
            'Reports' => ['reports.view', 'reports.own'],
            'Vendors' => ['vendors.view', 'vendors.manage'],
            'Accounting' => ['accounting.view', 'accounting.manage'],
            'AR Portal' => ['ar.view', 'ar.manage', 'ar.approve_payables', 'ar.create_journal_entries'],
            'Photos' => ['photos.upload', 'photos.delete', 'photos.annotate', 'photos.comment'],
            'Employees' => ['employees.view', 'employees.create', 'employees.edit', 'employees.delete', 'employees.view_sensitive'],
            'Financial Summaries' => ['team.view_financials', 'crew.view_financials'],
            'Hiring' => ['hiring.view', 'hiring.manage'],
            'Onboarding' => ['onboarding.view', 'onboarding.manage'],
            'Time Tracking' => ['time_tracking.view', 'time_tracking.view_all', 'time_tracking.manage', 'time_tracking.clock'],
            'Scheduling' => ['scheduling.view', 'scheduling.view_all', 'scheduling.manage'],
            'Payroll' => ['payroll.view', 'payroll.manage'],
            'Workflows' => ['workflows.view', 'workflows.manage'],
            'Messenger' => ['messenger.manage', 'messenger.view', 'messenger.respond'],
            'Notifications' => [
                'notify.estimate_accepted', 'notify.estimate_rejected', 'notify.proposal_viewed',
                'notify.job_created', 'notify.job_stage_advanced',
                'notify.customer_confirmed_date', 'notify.customer_confirmed_colors', 'notify.customer_proposed_date',
                'notify.new_account_created', 'notify.new_support_chat', 'notify.new_messenger_lead',
                'notify.customer_portal_login',
            ],
            'Settings' => [
                'settings.company', 'settings.packages', 'settings.allocations',
                'settings.payment_integrations', 'settings.service_categories',
                'settings.budget_mappings', 'settings.referral', 'settings.contracts',
                'settings.lead_sources', 'settings.workflows', 'settings.messenger',
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
     * @return array<string>
     */
    #[Computed]
    public function grantedPermissions(): array
    {
        return $this->role->permissions->pluck('name')->toArray();
    }

    #[Computed]
    public function totalPermissionCount(): int
    {
        $count = 0;

        foreach ($this->permissionGroups as $permissions) {
            $count += count($permissions);
        }

        return $count;
    }

    public function togglePermission(string $permissionName): void
    {
        if ($this->role->name === 'owner') {
            return;
        }

        $permission = Permission::where('name', $permissionName)->where('guard_name', 'web')->first();
        if (! $permission) {
            return;
        }

        if ($this->role->hasPermissionTo($permissionName)) {
            $this->role->revokePermissionTo($permissionName);
        } else {
            $this->role->givePermissionTo($permissionName);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->role->load('permissions');
        unset($this->grantedPermissions);
    }

    public function toggleGroupPermissions(string $groupName): void
    {
        if ($this->role->name === 'owner') {
            return;
        }

        $permissions = $this->permissionGroups[$groupName] ?? [];
        if (empty($permissions)) {
            return;
        }

        $allGranted = collect($permissions)->every(fn ($p) => $this->role->hasPermissionTo($p));

        foreach ($permissions as $permName) {
            $permission = Permission::where('name', $permName)->where('guard_name', 'web')->first();
            if (! $permission) {
                continue;
            }

            if ($allGranted) {
                $this->role->revokePermissionTo($permName);
            } elseif (! $this->role->hasPermissionTo($permName)) {
                $this->role->givePermissionTo($permName);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->role->load('permissions');
        unset($this->grantedPermissions);
    }

    public function formatPermission(string $name): string
    {
        $parts = explode('.', $name);
        $action = end($parts);

        return ucfirst(str_replace('_', ' ', $action));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Role: ' . ucfirst(str_replace('_', ' ', $role->name))) }}</flux:heading>

    <x-pages::settings.layout :heading="__('Role: ' . ucfirst(str_replace('_', ' ', $role->name)))" :subheading="$this->roleDescriptions[$role->name] ?? __('Custom role')" :fullWidth="true">
        <div class="my-6 w-full space-y-6">

            {{-- Back link and search --}}
            <div class="flex items-center justify-between gap-4">
                <flux:button href="{{ route('roles.edit') }}" wire:navigate variant="ghost" icon="arrow-left" size="sm">
                    {{ __('Back to Roles') }}
                </flux:button>
                <flux:input
                    wire:model.live.debounce.300ms="permissionSearch"
                    :placeholder="__('Search permissions...')"
                    icon="magnifying-glass"
                    size="sm"
                    class="w-64"
                />
            </div>

            {{-- Role header --}}
            <div class="flex items-center gap-4">
                <flux:badge size="lg" color="{{ $this->roleColors[$role->name] ?? 'zinc' }}">
                    {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                </flux:badge>
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ count($this->grantedPermissions) }} {{ __('of') }} {{ $this->totalPermissionCount }} {{ __('permissions') }}
                </flux:text>
            </div>

            {{-- Owner callout --}}
            @if ($role->name === 'owner')
                <flux:callout variant="success" icon="shield-check">
                    <flux:callout.heading>{{ __('Full Access') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('The Owner role has all permissions and cannot be modified.') }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:separator />

            {{-- Permission groups accordion --}}
            <div class="space-y-2">
                @forelse ($this->filteredPermissionGroups as $groupName => $permissions)
                    @php
                        $allGroupPerms = $this->permissionGroups[$groupName] ?? [];
                        $grantedCount = collect($allGroupPerms)->filter(fn ($p) => in_array($p, $this->grantedPermissions))->count();
                        $allGranted = $role->name === 'owner' || $grantedCount === count($allGroupPerms);
                        $someGranted = ! $allGranted && $grantedCount > 0;
                    @endphp
                    <div
                        wire:key="group-{{ Str::slug($groupName) }}"
                        x-data="{ open: false }"
                        class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden"
                    >
                        {{-- Group header --}}
                        <button
                            @click="open = !open"
                            type="button"
                            class="w-full flex items-center justify-between px-4 py-3 bg-zinc-50 dark:bg-zinc-800/50 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <svg
                                    :class="open ? 'rotate-90' : ''"
                                    class="w-4 h-4 text-zinc-400 transition-transform"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                                <span class="font-semibold text-sm text-zinc-700 dark:text-zinc-300">{{ $groupName }}</span>
                                <flux:badge size="sm" :color="$allGranted ? 'green' : ($someGranted ? 'amber' : 'zinc')">
                                    {{ $grantedCount }}/{{ count($allGroupPerms) }}
                                </flux:badge>
                            </div>

                            @if ($role->name !== 'owner')
                                <div
                                    wire:click.stop="toggleGroupPermissions('{{ $groupName }}')"
                                    class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors cursor-pointer shrink-0 {{ $allGranted ? 'bg-green-500 border-green-500 text-white' : ($someGranted ? 'bg-green-200 border-green-400 dark:bg-green-800 dark:border-green-600' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-400') }}"
                                    title="{{ $allGranted ? __('Deselect all') : __('Select all') }} {{ $groupName }} {{ __('permissions') }}"
                                >
                                    @if ($allGranted)
                                        <flux:icon name="check" class="w-3 h-3" />
                                    @elseif ($someGranted)
                                        <flux:icon name="minus" class="w-3 h-3 text-green-700 dark:text-green-300" />
                                    @endif
                                </div>
                            @else
                                <div class="w-5 h-5 rounded bg-green-500 flex items-center justify-center shrink-0">
                                    <flux:icon name="check" class="w-3 h-3 text-white" />
                                </div>
                            @endif
                        </button>

                        {{-- Permission rows --}}
                        <div x-show="open" x-collapse>
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                                @foreach ($permissions as $permission)
                                    <div
                                        wire:key="perm-{{ $permission }}"
                                        class="flex items-center justify-between px-4 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/30 transition-colors"
                                    >
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $this->formatPermission($permission) }}</span>
                                            <span class="text-xs text-zinc-400">({{ $permission }})</span>
                                        </div>

                                        @if ($role->name === 'owner')
                                            <div class="w-5 h-5 rounded bg-green-500 flex items-center justify-center shrink-0">
                                                <flux:icon name="check" class="w-3 h-3 text-white" />
                                            </div>
                                        @else
                                            <button
                                                wire:click="togglePermission('{{ $permission }}')"
                                                class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors cursor-pointer shrink-0 {{ in_array($permission, $this->grantedPermissions) ? 'bg-green-500 border-green-500 text-white' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-400' }}"
                                            >
                                                @if (in_array($permission, $this->grantedPermissions))
                                                    <flux:icon name="check" class="w-3 h-3" />
                                                @endif
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8">
                        <flux:text class="text-sm text-zinc-400 dark:text-zinc-500">
                            {{ __('No permissions match ":search".', ['search' => $permissionSearch]) }}
                        </flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    </x-pages::settings.layout>
</section>
