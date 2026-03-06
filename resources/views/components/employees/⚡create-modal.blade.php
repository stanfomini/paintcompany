<?php

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Spatie\Permission\Models\Role;

new class extends Component {
    public bool $show = false;

    public string $mode = 'new';

    public string $existing_user_id = '';

    // User fields
    public string $name = '';

    public string $email = '';

    public string $password = '';

    // Employee fields
    public string $job_title = '';

    public string $department = '';

    public string $pay_rate = '';

    public string $pay_type = 'hourly';

    public string $phone = '';

    public ?string $hire_date = null;

    /** @var array<string> */
    public array $selectedRoles = [];

    #[Computed]
    public function availableRoles(): \Illuminate\Support\Collection
    {
        return Role::where('guard_name', 'web')->orderBy('name')->get(['id', 'name']);
    }

    public function canAssignRole(string $roleName): bool
    {
        if (in_array($roleName, ['owner', 'admin'])) {
            return auth()->user()?->hasRole('owner') === true;
        }

        return true;
    }

    /** @var array<string> Roles the selected user already has (cannot be removed) */
    public array $existingRoles = [];

    #[Computed]
    public function availableUsers(): \Illuminate\Support\Collection
    {
        return User::where('type', 'internal')
            ->whereDoesntHave('employeeProfile')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function updatedExistingUserId(string $value): void
    {
        if ($value !== '') {
            $user = User::find((int) $value);
            $this->existingRoles = $user ? $user->roles->pluck('name')->toArray() : [];
            $this->selectedRoles = array_unique(array_merge($this->selectedRoles, $this->existingRoles));
        } else {
            $this->existingRoles = [];
            $this->selectedRoles = [];
        }
    }

    #[On('open-create-employee-modal')]
    public function open(): void
    {
        $this->reset();
        $this->hire_date = now()->format('Y-m-d');
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->reset();
        $this->resetValidation();
    }

    public function save(): void
    {
        if (! auth()->user()?->can('employees.create')) {
            abort(403);
        }

        $rules = [
            'job_title' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'pay_rate' => [$this->pay_type === 'commission' ? 'nullable' : 'required', 'numeric', 'min:0'],
            'pay_type' => ['required', 'in:'.implode(',', array_keys(EmployeeProfile::PAY_TYPES))],
            'phone' => ['nullable', 'string', 'max:50'],
            'hire_date' => ['nullable', 'date'],
        ];

        if ($this->mode === 'new') {
            $rules['name'] = ['required', 'string', 'max:255'];
            $rules['email'] = ['required', 'email', 'unique:users,email'];
            $rules['password'] = ['required', Password::defaults()];
        } else {
            $rules['existing_user_id'] = ['required', 'exists:users,id'];
        }

        $this->validate($rules);

        if ($this->mode === 'new') {
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make($this->password),
                'type' => 'internal',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        } else {
            $user = User::findOrFail((int) $this->existing_user_id);

            if ($user->employeeProfile) {
                $this->addError('existing_user_id', __('This user already has an employee profile.'));

                return;
            }
        }

        EmployeeProfile::create([
            'user_id' => $user->id,
            'employee_number' => EmployeeProfile::generateEmployeeNumber(),
            'job_title' => $this->job_title,
            'department' => $this->department ?: null,
            'pay_rate' => $this->pay_rate ?: null,
            'pay_type' => $this->pay_type,
            'phone' => $this->phone ?: null,
            'hire_date' => $this->hire_date,
        ]);

        // Merge selected roles with existing roles (existing roles can't be removed here)
        $rolesToSync = array_unique(array_merge($this->selectedRoles, $this->existingRoles));

        if (! empty($rolesToSync)) {
            $validRoles = Role::where('guard_name', 'web')
                ->whereIn('name', $rolesToSync)
                ->pluck('name')
                ->toArray();

            // Only owner can assign NEW owner/admin roles (existing ones are always kept)
            $isOwner = auth()->user()?->hasRole('owner');
            $validRoles = array_filter($validRoles, function ($role) use ($isOwner) {
                return in_array($role, $this->existingRoles)
                    || ! in_array($role, ['owner', 'admin'])
                    || $isOwner;
            });

            $user->syncRoles(array_values($validRoles));
        }

        $this->show = false;
        $this->dispatch('employee-created');
        session()->flash('success', __('Employee created successfully.'));
    }
}; ?>

<div>
    <flux:modal wire:model="show" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-1">{{ __('Add Employee') }}</flux:heading>
            <flux:text class="mb-6">{{ __('Create a new employee account and profile.') }}</flux:text>

            <div class="space-y-4">
                <div class="flex gap-2 mb-4">
                    <flux:button size="sm" :variant="$mode === 'new' ? 'primary' : 'ghost'" wire:click="$set('mode', 'new')" type="button">
                        {{ __('New Employee') }}
                    </flux:button>
                    <flux:button size="sm" :variant="$mode === 'existing' ? 'primary' : 'ghost'" wire:click="$set('mode', 'existing')" type="button">
                        {{ __('Existing User') }}
                    </flux:button>
                </div>

                @if($mode === 'existing')
                    <flux:field>
                        <flux:label>{{ __('Select User') }}</flux:label>
                        <flux:select wire:model.live="existing_user_id" placeholder="{{ __('Choose an internal user...') }}">
                            @foreach($this->availableUsers as $availableUser)
                                <flux:select.option value="{{ $availableUser->id }}">
                                    {{ $availableUser->name }} ({{ $availableUser->email }})
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="existing_user_id" />
                    </flux:field>

                    <flux:separator />
                @else
                    <flux:heading size="sm">{{ __('Account') }}</flux:heading>

                    <flux:field>
                        <flux:label>{{ __('Full Name') }}</flux:label>
                        <flux:input wire:model="name" placeholder="John Doe" required />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Email') }}</flux:label>
                        <flux:input wire:model="email" type="email" placeholder="john@company.com" required />
                        <flux:error name="email" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Password') }}</flux:label>
                        <flux:input wire:model="password" type="password" required />
                        <flux:error name="password" />
                    </flux:field>

                    <flux:separator />
                @endif

                <flux:heading size="sm">{{ __('Employment Details') }}</flux:heading>

                <flux:field>
                    <flux:label>{{ __('Job Title') }}</flux:label>
                    <flux:input wire:model="job_title" placeholder="Lead Painter" required />
                    <flux:error name="job_title" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Department') }}</flux:label>
                    <flux:input wire:model="department" placeholder="Operations" />
                    <flux:error name="department" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Pay Rate') }}</flux:label>
                        <flux:input wire:model="pay_rate" type="number" step="0.01" min="0" placeholder="0.00" icon="currency-dollar" :required="$pay_type !== 'commission'" />
                        <flux:error name="pay_rate" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Pay Type') }}</flux:label>
                        <flux:select wire:model.live="pay_type">
                            @foreach (\App\Models\EmployeeProfile::PAY_TYPES as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="pay_type" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Phone') }}</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Hire Date') }}</flux:label>
                    <flux:input wire:model="hire_date" type="date" />
                    <flux:error name="hire_date" />
                </flux:field>

                <flux:separator />

                <flux:heading size="sm">{{ __('Roles') }}</flux:heading>

                <div class="flex flex-wrap gap-3">
                    @foreach($this->availableRoles as $role)
                        @php
                            $isExisting = in_array($role->name, $existingRoles);
                            $canAssign = !$isExisting && $this->canAssignRole($role->name);
                            $disabled = $isExisting || !$canAssign;
                        @endphp
                        <label class="inline-flex items-center gap-1.5 {{ $disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer' }}">
                            <flux:checkbox wire:model="selectedRoles" value="{{ $role->name }}" :disabled="$disabled" />
                            <flux:text class="text-sm">{{ ucfirst(str_replace('_', ' ', $role->name)) }}</flux:text>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="close" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Create Employee') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
