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
    public ?int $editingUserId = null;

    /** @var array<string> */
    public array $selectedRoles = [];

    // Custom role creation
    public bool $showCreateRole = false;

    public string $newRoleName = '';

    public string $cloneFromRole = '';

    // Role to delete
    public ?int $deletingRoleId = null;

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
        return ['owner', 'admin', 'manager', 'estimator', 'hr_manager', 'door_to_door'];
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
            ->with('permissions')
            ->get()
            ->sortBy(function ($role) use ($defaults) {
                $idx = array_search($role->name, $defaults);

                return $idx !== false ? $idx : 100 + $role->id;
            })
            ->values();
    }

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
            'hr_manager' => 'cyan',
            'door_to_door' => 'orange',
        ];
    }

    #[Computed]
    public function permissionCount(): int
    {
        return Permission::where('guard_name', 'web')->count();
    }

    /**
     * Roles that only the owner can assign.
     *
     * @return array<string>
     */
    public function restrictedRoles(): array
    {
        return ['owner', 'admin'];
    }

    /**
     * Whether the current user can assign a given role.
     */
    public function canAssignRole(string $roleName): bool
    {
        if (in_array($roleName, $this->restrictedRoles())) {
            return Auth::user()?->hasRole('owner') === true;
        }

        return true;
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
        $this->selectedRoles = $user->roles->pluck('name')->toArray();
    }

    public function cancelEditing(): void
    {
        $this->editingUserId = null;
        $this->selectedRoles = [];
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

        // Validate all selected roles exist
        $validRoles = Role::where('guard_name', 'web')
            ->whereIn('name', $this->selectedRoles)
            ->pluck('name')
            ->toArray();

        if (count($validRoles) !== count($this->selectedRoles) && count($this->selectedRoles) > 0) {
            session()->flash('error', __('One or more invalid roles selected.'));

            return;
        }

        // Only owner can assign owner/admin roles
        $restricted = $this->restrictedRoles();
        $isOwner = Auth::user()?->hasRole('owner');
        foreach ($validRoles as $roleName) {
            if (in_array($roleName, $restricted) && ! $isOwner) {
                session()->flash('error', __('Only the owner can assign the :role role.', ['role' => $roleName]));

                return;
            }
        }

        $user->syncRoles($validRoles);

        // Auto-create EmployeeProfile if missing and roles assigned
        if (count($validRoles) > 0 && ! $user->employeeProfile) {
            EmployeeProfile::create([
                'user_id' => $user->id,
                'employee_number' => EmployeeProfile::generateEmployeeNumber(),
                'hire_date' => now()->format('Y-m-d'),
                'employment_status' => 'active',
                'pay_rate' => 0,
                'pay_type' => 'hourly',
            ]);
        }

        $this->editingUserId = null;
        $this->selectedRoles = [];
        unset($this->internalUsers);

        session()->flash('success', __('Roles updated for :name.', ['name' => $user->name]));
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

            {{-- ========= ROLES SECTION ========= --}}
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Roles') }}</flux:heading>
                <flux:button size="sm" icon="plus" wire:click="openCreateRole">
                    {{ __('Create Role') }}
                </flux:button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($this->roles as $role)
                    <a
                        href="{{ route('roles.detail', $role->name) }}"
                        wire:navigate
                        wire:key="role-card-{{ $role->id }}"
                        class="block p-5 bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:border-blue-300 dark:hover:border-blue-600 hover:shadow-md transition-all"
                    >
                        <div class="flex items-center justify-between mb-3">
                            <flux:badge color="{{ $this->roleColors[$role->name] ?? 'zinc' }}">
                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                            </flux:badge>
                            <flux:text class="text-sm text-zinc-500">
                                {{ $role->permissions->count() }}/{{ $this->permissionCount }}
                            </flux:text>
                        </div>
                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400 mb-2">
                            {{ $this->roleDescriptions[$role->name] ?? __('Custom role') }}
                        </flux:text>
                        <div class="flex items-center justify-between">
                            <flux:text class="text-xs text-zinc-400">
                                {{ trans_choice('{0} No users|{1} 1 user|[2,*] :count users', $role->users()->count(), ['count' => $role->users()->count()]) }}
                            </flux:text>
                            @if (! in_array($role->name, $this->defaultRoleNames()))
                                <button
                                    wire:click.prevent="confirmDeleteRole({{ $role->id }})"
                                    class="text-red-400 hover:text-red-600 text-xs"
                                >
                                    {{ __('delete') }}
                                </button>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <flux:separator />

            {{-- ========= TEAM MEMBERS SECTION ========= --}}
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
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($this->roles as $role)
                                        @php $canAssign = $this->canAssignRole($role->name); @endphp
                                        <label class="inline-flex items-center gap-1.5 {{ $canAssign ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' }}">
                                            <flux:checkbox
                                                wire:model="selectedRoles"
                                                value="{{ $role->name }}"
                                                :disabled="!$canAssign"
                                            />
                                            <flux:text class="text-sm">{{ ucfirst(str_replace('_', ' ', $role->name)) }}</flux:text>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="flex gap-2">
                                    <flux:button size="sm" variant="primary" wire:click="saveRole">
                                        {{ __('Save') }}
                                    </flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="cancelEditing">
                                        {{ __('Cancel') }}
                                    </flux:button>
                                </div>
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

            {{-- ========= ADD TEAM MEMBERS SECTION ========= --}}
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
