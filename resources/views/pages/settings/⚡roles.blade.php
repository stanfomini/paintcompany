<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new class extends Component
{
    public ?int $editingUserId = null;

    public string $selectedRole = '';

    public function boot(): void
    {
        $user = Auth::user();

        if (! $user || ! $user->isInternal() || ! $user->can('manage roles')) {
            abort(403, 'You do not have permission to manage roles.');
        }
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function internalUsers(): Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->with('roles')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        $roleOrder = ['owner', 'admin', 'manager', 'estimator', 'crew_lead'];

        return Role::query()
            ->where('guard_name', 'web')
            ->get()
            ->sortBy(fn ($role) => array_search($role->name, $roleOrder) ?? 999)
            ->values();
    }

    #[Computed]
    public function roleDescriptions(): array
    {
        return [
            'owner' => 'Full access to all features including role management',
            'admin' => 'Everything except role management',
            'manager' => 'View and edit roadmap, KPIs, financials, invitations',
            'estimator' => 'View roadmap, KPIs, and cost breakdown',
            'crew_lead' => 'View roadmap only',
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

    public function startEditing(int $userId): void
    {
        $user = User::find($userId);
        if (! $user) {
            return;
        }

        // Prevent editing yourself (owner can't remove their own role)
        if ($userId === Auth::id()) {
            session()->flash('error', 'You cannot modify your own role.');

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

        // Validate role exists
        $role = Role::where('name', $this->selectedRole)->where('guard_name', 'web')->first();
        if (! $role && $this->selectedRole !== '') {
            session()->flash('error', 'Invalid role selected.');

            return;
        }

        // Sync roles (replace all existing roles with the selected one)
        if ($this->selectedRole) {
            $user->syncRoles([$this->selectedRole]);
        } else {
            $user->syncRoles([]);
        }

        $this->editingUserId = null;
        $this->selectedRole = '';

        // Clear computed cache
        unset($this->internalUsers);

        session()->flash('success', "Role updated for {$user->name}.");
    }

    public function removeRole(int $userId): void
    {
        if ($userId === Auth::id()) {
            session()->flash('error', 'You cannot remove your own role.');

            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        $user->syncRoles([]);

        // Clear computed cache
        unset($this->internalUsers);

        session()->flash('success', "Role removed from {$user->name}.");
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Roles & Access') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Roles & Access')" :subheading="__('Manage team member roles and permissions')">
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

            {{-- Role Legend --}}
            <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-4 border border-zinc-200 dark:border-zinc-700">
                <flux:heading size="sm" class="mb-3">Available Roles</flux:heading>
                <div class="space-y-2">
                    @foreach ($this->roles as $role)
                        <div class="flex items-start gap-3">
                            <flux:badge size="sm" color="{{ $this->roleColors[$role->name] ?? 'zinc' }}">
                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                            </flux:badge>
                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $this->roleDescriptions[$role->name] ?? 'No description' }}
                            </flux:text>
                        </div>
                    @endforeach
                </div>
            </div>

            <flux:separator />

            {{-- Team Members --}}
            <flux:heading size="sm">Team Members</flux:heading>

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
                                        <flux:badge size="sm" color="blue">You</flux:badge>
                                    @endif
                                </div>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $user->email }}
                                </flux:text>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            @if ($this->editingUserId === $user->id)
                                {{-- Editing Mode --}}
                                <flux:select wire:model="selectedRole" class="w-40">
                                    <option value="">No Role</option>
                                    @foreach ($this->roles as $role)
                                        <option value="{{ $role->name }}">
                                            {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                                        </option>
                                    @endforeach
                                </flux:select>
                                <flux:button size="sm" variant="primary" wire:click="saveRole">
                                    Save
                                </flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="cancelEditing">
                                    Cancel
                                </flux:button>
                            @else
                                {{-- Display Mode --}}
                                @if ($user->roles->isNotEmpty())
                                    @foreach ($user->roles as $role)
                                        <flux:badge color="{{ $this->roleColors[$role->name] ?? 'zinc' }}">
                                            {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                                        </flux:badge>
                                    @endforeach
                                @else
                                    <flux:text class="text-sm text-zinc-400 dark:text-zinc-500 italic">
                                        No role assigned
                                    </flux:text>
                                @endif

                                @if ($user->id !== Auth::id())
                                    <flux:dropdown>
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" wire:click="startEditing({{ $user->id }})">
                                                Change Role
                                            </flux:menu.item>
                                            @if ($user->roles->isNotEmpty())
                                                <flux:menu.item
                                                    icon="trash"
                                                    wire:click="removeRole({{ $user->id }})"
                                                    wire:confirm="Are you sure you want to remove the role from {{ $user->name }}?"
                                                    variant="danger"
                                                >
                                                    Remove Role
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
                        No internal users found. Use the Invitations feature to add team members.
                    </div>
                @endforelse
            </div>

            <flux:separator />

            {{-- Invite New Team Members --}}
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="sm">Add Team Members</flux:heading>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        Send invitations to add new internal team members.
                    </flux:text>
                </div>
                <flux:button href="{{ route('invitations') }}" wire:navigate variant="primary" icon="envelope">
                    Manage Invitations
                </flux:button>
            </div>

        </div>
    </x-pages::settings.layout>
</section>
