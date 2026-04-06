<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\User;
use App\Prospector\Permissions;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Agents & Permissions')]
class extends Component
{
    // Edit modal
    public bool $showEditModal = false;

    public ?int $editingAgentId = null;

    public string $editRole = 'agent';

    public array $editPermissions = [];

    public bool $editIsActive = true;

    public ?int $editCompanyId = null;

    public bool $useCustomPermissions = false;

    // Add modal
    public bool $showAddModal = false;

    public ?int $addUserId = null;

    public string $addRole = 'agent';

    public ?int $addCompanyId = null;

    public function boot(): void
    {
        $current = Agent::forCurrentUser();
        if (! $current || ! $current->hasPermission('agents.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function currentAgent(): ?Agent
    {
        return Agent::forCurrentUser();
    }

    #[Computed]
    public function agents(): \Illuminate\Support\Collection
    {
        return Agent::with(['user', 'company'])
            ->orderBy('role')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function availableUsers(): \Illuminate\Support\Collection
    {
        // Internal users who don't already have an agent record
        $existingUserIds = Agent::pluck('user_id')->all();

        return User::where('type', 'internal')
            ->whereNotIn('id', $existingUserIds)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function permissionGroups(): array
    {
        return Permissions::PERMISSIONS;
    }

    public function openAddModal(): void
    {
        if (! $this->currentAgent?->hasPermission('agents.create')) {
            abort(403);
        }

        $this->addUserId = null;
        $this->addRole = Permissions::ROLE_AGENT;
        $this->addCompanyId = $this->companies->first()?->id;
        $this->showAddModal = true;
    }

    public function createAgent(): void
    {
        if (! $this->currentAgent?->hasPermission('agents.create')) {
            abort(403);
        }

        $this->validate([
            'addUserId' => ['required', 'exists:users,id'],
            'addRole' => ['required', 'in:'.implode(',', Permissions::allRoles())],
            'addCompanyId' => ['required', 'exists:prospector_companies,id'],
        ]);

        // Only admins can create other admins
        if ($this->addRole === Permissions::ROLE_ADMIN && ! $this->currentAgent->isAdmin()) {
            session()->flash('error', 'Only administrators can create other administrators.');

            return;
        }

        Agent::create([
            'user_id' => $this->addUserId,
            'company_id' => $this->addCompanyId,
            'role' => $this->addRole,
            'permissions' => null,
            'is_active' => true,
            'status' => 'offline',
            'max_concurrent_lines' => 1,
            'wrap_up_seconds' => 30,
        ]);

        $this->showAddModal = false;
        $this->reset(['addUserId', 'addRole', 'addCompanyId']);
        unset($this->agents, $this->availableUsers);
        session()->flash('success', 'Agent created successfully.');
    }

    public function openEditModal(int $agentId): void
    {
        $agent = Agent::findOrFail($agentId);

        if (! $this->currentAgent?->hasPermission('agents.edit')) {
            abort(403);
        }

        // Non-admins cannot edit admins
        if ($agent->isAdmin() && ! $this->currentAgent->isAdmin()) {
            session()->flash('error', 'Only administrators can edit other administrators.');

            return;
        }

        $this->editingAgentId = $agent->id;
        $this->editRole = $agent->role ?? Permissions::ROLE_AGENT;
        $this->editIsActive = (bool) $agent->is_active;
        $this->editCompanyId = $agent->company_id;
        $this->useCustomPermissions = is_array($agent->permissions) && ! empty($agent->permissions);
        $this->editPermissions = $this->useCustomPermissions
            ? $agent->permissions
            : Permissions::defaultsForRole($this->editRole);
        $this->showEditModal = true;
    }

    public function updatedEditRole(): void
    {
        // When role changes, refresh permissions to role defaults (unless using custom)
        if (! $this->useCustomPermissions) {
            $this->editPermissions = Permissions::defaultsForRole($this->editRole);
        }
    }

    public function updatedUseCustomPermissions(): void
    {
        if (! $this->useCustomPermissions) {
            // Reset to role defaults
            $this->editPermissions = Permissions::defaultsForRole($this->editRole);
        }
    }

    public function togglePermission(string $key): void
    {
        if (in_array($key, $this->editPermissions, true)) {
            $this->editPermissions = array_values(array_diff($this->editPermissions, [$key]));
        } else {
            $this->editPermissions[] = $key;
        }
    }

    public function saveAgent(): void
    {
        if (! $this->currentAgent?->hasPermission('agents.edit')) {
            abort(403);
        }

        $agent = Agent::findOrFail($this->editingAgentId);

        // Only admins can change roles to/from admin
        if (
            ($this->editRole === Permissions::ROLE_ADMIN || $agent->isAdmin())
            && ! $this->currentAgent->isAdmin()
        ) {
            session()->flash('error', 'Only administrators can change admin roles.');

            return;
        }

        // Prevent self-demotion if you're the last admin
        if ($agent->user_id === Auth::id() && $agent->isAdmin() && $this->editRole !== Permissions::ROLE_ADMIN) {
            $adminCount = Agent::where('role', Permissions::ROLE_ADMIN)->where('is_active', true)->count();
            if ($adminCount <= 1) {
                session()->flash('error', 'You cannot demote yourself — you are the last administrator.');

                return;
            }
        }

        $agent->update([
            'role' => $this->editRole,
            'permissions' => $this->useCustomPermissions ? $this->editPermissions : null,
            'is_active' => $this->editIsActive,
            'company_id' => $this->editCompanyId,
        ]);

        $this->showEditModal = false;
        unset($this->agents);
        session()->flash('success', 'Agent updated successfully.');
    }

    public function deleteAgent(int $agentId): void
    {
        if (! $this->currentAgent?->hasPermission('agents.delete')) {
            abort(403);
        }

        $agent = Agent::findOrFail($agentId);

        // Cannot delete yourself
        if ($agent->user_id === Auth::id()) {
            session()->flash('error', 'You cannot delete your own agent record.');

            return;
        }

        // Cannot delete the last admin
        if ($agent->isAdmin()) {
            $adminCount = Agent::where('role', Permissions::ROLE_ADMIN)->where('is_active', true)->count();
            if ($adminCount <= 1) {
                session()->flash('error', 'Cannot delete the last administrator.');

                return;
            }
        }

        $agent->delete();
        unset($this->agents, $this->availableUsers);
        session()->flash('success', 'Agent removed.');
    }
}; ?>

<div class="mx-auto max-w-7xl space-y-6 p-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Agents & Permissions') }}</flux:heading>
            <flux:subheading>{{ __('Manage who can access Prospector and what they can do.') }}</flux:subheading>
        </div>
        @if ($this->currentAgent?->hasPermission('agents.create'))
            <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                {{ __('Add Agent') }}
            </flux:button>
        @endif
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    {{-- Agents Table --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 text-left text-xs uppercase text-zinc-500 dark:border-zinc-700">
                    <th class="px-4 py-3 font-medium">{{ __('User') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Role') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Company') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Permissions') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-right font-medium">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                @foreach ($this->agents as $agent)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $agent->user?->name ?? '—' }}</div>
                            <div class="text-xs text-zinc-500">{{ $agent->user?->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $roleColor = match($agent->role) {
                                    'admin' => 'red',
                                    'supervisor' => 'amber',
                                    default => 'blue',
                                };
                            @endphp
                            <flux:badge :color="$roleColor" size="sm">{{ ucfirst($agent->role ?? 'agent') }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ $agent->company?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs text-zinc-500">
                                {{ count($agent->effectivePermissions()) }} {{ __('granted') }}
                                @if (is_array($agent->permissions) && ! empty($agent->permissions))
                                    <flux:badge size="xs" color="purple" class="ml-1">{{ __('Custom') }}</flux:badge>
                                @endif
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($agent->is_active)
                                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                @if ($this->currentAgent?->hasPermission('agents.edit'))
                                    <flux:button size="xs" variant="ghost" icon="pencil" wire:click="openEditModal({{ $agent->id }})" />
                                @endif
                                @if ($this->currentAgent?->hasPermission('agents.delete') && $agent->user_id !== Auth::id())
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteAgent({{ $agent->id }})" wire:confirm="{{ __('Remove this agent?') }}" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                @if ($this->agents->isEmpty())
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500">
                            {{ __('No agents yet.') }}
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- Add Agent Modal --}}
    <flux:modal wire:model.self="showAddModal" class="md:max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Add Agent') }}</flux:heading>

            <div>
                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('User') }}</label>
                <select wire:model="addUserId" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700">
                    <option value="">{{ __('Select user...') }}</option>
                    @foreach ($this->availableUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </select>
                @if ($this->availableUsers->isEmpty())
                    <p class="mt-1 text-xs text-amber-600">{{ __('All internal users already have agent records.') }}</p>
                @endif
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Role') }}</label>
                <select wire:model="addRole" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700">
                    <option value="agent">{{ __('Agent — Basic dialer access') }}</option>
                    <option value="supervisor">{{ __('Supervisor — Manage contacts, campaigns, sequences') }}</option>
                    @if ($this->currentAgent?->isAdmin())
                        <option value="admin">{{ __('Admin — Full access to everything') }}</option>
                    @endif
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Company') }}</label>
                <select wire:model="addCompanyId" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700">
                    @foreach ($this->companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showAddModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="createAgent">{{ __('Create') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit Agent Modal --}}
    <flux:modal wire:model.self="showEditModal" class="md:max-w-3xl">
        <div class="space-y-5">
            <flux:heading size="lg">{{ __('Edit Agent') }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Role') }}</label>
                    <select wire:model.live="editRole" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700">
                        <option value="agent">{{ __('Agent') }}</option>
                        <option value="supervisor">{{ __('Supervisor') }}</option>
                        @if ($this->currentAgent?->isAdmin())
                            <option value="admin">{{ __('Admin') }}</option>
                        @endif
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Company') }}</label>
                    <select wire:model="editCompanyId" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700">
                        @foreach ($this->companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="editIsActive" class="rounded border-zinc-300" />
                {{ __('Active (can access Prospector)') }}
            </label>

            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="useCustomPermissions" class="rounded border-zinc-300" />
                    <span class="font-medium">{{ __('Customize permissions (override role defaults)') }}</span>
                </label>
                @if ($editRole === 'admin')
                    <p class="mt-2 text-xs text-zinc-500">{{ __('Admins always have all permissions.') }}</p>
                @endif
            </div>

            @if ($editRole !== 'admin')
                <div class="max-h-96 overflow-y-auto rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    @foreach ($this->permissionGroups as $groupName => $perms)
                        <div class="mb-4">
                            <div class="mb-2 text-xs font-bold uppercase text-zinc-500">{{ $groupName }}</div>
                            <div class="grid grid-cols-2 gap-1.5">
                                @foreach ($perms as $key => $label)
                                    <label class="flex items-center gap-2 text-sm {{ $useCustomPermissions ? '' : 'opacity-60' }}">
                                        <input
                                            type="checkbox"
                                            class="rounded border-zinc-300"
                                            @checked(in_array($key, $editPermissions, true))
                                            @disabled(!$useCustomPermissions)
                                            wire:click="togglePermission('{{ $key }}')"
                                        />
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showEditModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveAgent">{{ __('Save Changes') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
