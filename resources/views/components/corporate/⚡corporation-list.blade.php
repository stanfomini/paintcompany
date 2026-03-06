<?php

use App\Models\CompanySetting;
use App\Models\Corporation;
use App\Models\CorporationOwner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;
    public bool $editing = false;
    public ?int $editingId = null;

    // Corporation fields
    public string $name = '';
    public string $entity_type = 'LLC';
    public string $state_of_formation = '';
    public string $ein = '';
    public ?string $formation_date = null;
    public string $registered_agent = '';
    public string $address_line_1 = '';
    public string $address_line_2 = '';
    public string $city = '';
    public string $state = '';
    public string $zip = '';
    public string $notes = '';

    // Owners
    public array $owners = [];

    public function boot(): void
    {
        if (! Auth::user()?->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    #[Computed]
    public function corporations(): \Illuminate\Database\Eloquent\Collection
    {
        return Corporation::with(['owners' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('name')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
        $this->editing = false;
    }

    public function openEditModal(int $id): void
    {
        $corp = Corporation::with(['owners' => fn ($q) => $q->orderBy('sort_order')])->findOrFail($id);

        $this->editingId = $corp->id;
        $this->name = $corp->name;
        $this->entity_type = $corp->entity_type;
        $this->state_of_formation = $corp->state_of_formation ?? '';
        $this->ein = $corp->ein ?? '';
        $this->formation_date = $corp->formation_date?->format('Y-m-d');
        $this->registered_agent = $corp->registered_agent ?? '';
        $this->address_line_1 = $corp->address_line_1 ?? '';
        $this->address_line_2 = $corp->address_line_2 ?? '';
        $this->city = $corp->city ?? '';
        $this->state = $corp->state ?? '';
        $this->zip = $corp->zip ?? '';
        $this->notes = $corp->notes ?? '';

        $this->owners = $corp->owners->map(fn ($o) => [
            'id' => $o->id,
            'name' => $o->name,
            'percentage' => (string) $o->percentage,
            'role' => $o->role,
            'email' => $o->email ?? '',
            'phone' => $o->phone ?? '',
        ])->toArray();

        $this->showModal = true;
        $this->editing = true;
    }

    public function addOwner(): void
    {
        $this->owners[] = [
            'id' => null,
            'name' => '',
            'percentage' => '',
            'role' => 'Member',
            'email' => '',
            'phone' => '',
        ];
    }

    public function removeOwner(int $index): void
    {
        unset($this->owners[$index]);
        $this->owners = array_values($this->owners);
    }

    public function save(): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'entity_type' => ['required', Rule::in(array_keys(Corporation::ENTITY_TYPES))],
            'state_of_formation' => ['nullable', 'string', 'max:2'],
            'ein' => ['nullable', 'string', 'max:20'],
            'formation_date' => ['nullable', 'date'],
            'registered_agent' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owners' => ['array'],
            'owners.*.name' => ['required', 'string', 'max:255'],
            'owners.*.percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'owners.*.role' => ['required', Rule::in(array_keys(CorporationOwner::ROLES))],
            'owners.*.email' => ['nullable', 'email', 'max:255'],
            'owners.*.phone' => ['nullable', 'string', 'max:20'],
        ]);

        $corpData = collect($validated)->except('owners')->toArray();
        $corpData['company_setting_id'] = CompanySetting::getInstance()->id;

        if ($this->editing && $this->editingId) {
            $corp = Corporation::findOrFail($this->editingId);
            $corp->update($corpData);
        } else {
            $corp = Corporation::create($corpData);
        }

        // Sync owners
        $existingOwnerIds = $corp->owners->pluck('id')->toArray();
        $keepIds = [];

        foreach ($this->owners as $i => $ownerData) {
            if (! empty($ownerData['id']) && in_array($ownerData['id'], $existingOwnerIds)) {
                CorporationOwner::where('id', $ownerData['id'])->update([
                    'name' => $ownerData['name'],
                    'percentage' => $ownerData['percentage'],
                    'role' => $ownerData['role'],
                    'email' => $ownerData['email'] ?: null,
                    'phone' => $ownerData['phone'] ?: null,
                    'sort_order' => $i,
                ]);
                $keepIds[] = $ownerData['id'];
            } else {
                $newOwner = $corp->owners()->create([
                    'name' => $ownerData['name'],
                    'percentage' => $ownerData['percentage'],
                    'role' => $ownerData['role'],
                    'email' => $ownerData['email'] ?: null,
                    'phone' => $ownerData['phone'] ?: null,
                    'sort_order' => $i,
                ]);
                $keepIds[] = $newOwner->id;
            }
        }

        // Remove owners not in the keep list
        $corp->owners()->whereNotIn('id', $keepIds)->delete();

        $this->showModal = false;
        $this->resetForm();
        unset($this->corporations);
    }

    public function deleteCorporation(int $id): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        Corporation::findOrFail($id)->delete();
        unset($this->corporations);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->entity_type = 'LLC';
        $this->state_of_formation = '';
        $this->ein = '';
        $this->formation_date = null;
        $this->registered_agent = '';
        $this->address_line_1 = '';
        $this->address_line_2 = '';
        $this->city = '';
        $this->state = '';
        $this->zip = '';
        $this->notes = '';
        $this->owners = [];
        $this->resetValidation();
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <flux:heading size="lg">{{ __('Corporations') }}</flux:heading>
        @if ($this->canManage)
            <flux:button variant="primary" size="sm" wire:click="openCreateModal" icon="plus">
                {{ __('Add Corporation') }}
            </flux:button>
        @endif
    </div>

    {{-- Corporation Cards --}}
    @if ($this->corporations->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:icon name="building-office" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
            <flux:heading size="sm" class="mt-3">{{ __('No corporations yet') }}</flux:heading>
            <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Add your first corporation to manage documents and meeting minutes.') }}
            </flux:text>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ($this->corporations as $corp)
                <div wire:key="corp-{{ $corp->id }}" class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ $corp->name }}</flux:heading>
                                <flux:badge size="sm" color="blue">{{ $corp->entity_type }}</flux:badge>
                            </div>
                            @if ($corp->ein)
                                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('EIN:') }} {{ $corp->ein }}
                                </flux:text>
                            @endif
                            @if ($corp->state_of_formation)
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('State:') }} {{ $corp->state_of_formation }}
                                    @if ($corp->formation_date)
                                        &middot; {{ __('Formed:') }} {{ $corp->formation_date->format('M j, Y') }}
                                    @endif
                                </flux:text>
                            @endif
                        </div>
                        @if ($this->canManage)
                            <div class="flex gap-1">
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditModal({{ $corp->id }})" />
                                <flux:button size="xs" variant="ghost" icon="trash" class="text-red-500 hover:text-red-700" wire:click="deleteCorporation({{ $corp->id }})" wire:confirm="{{ __('Delete this corporation and all its documents?') }}" />
                            </div>
                        @endif
                    </div>

                    {{-- Owners --}}
                    @if ($corp->owners->isNotEmpty())
                        <div class="mt-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <flux:text class="mb-2 text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">{{ __('Owners') }}</flux:text>
                            <div class="space-y-1">
                                @foreach ($corp->owners as $owner)
                                    <div wire:key="owner-{{ $owner->id }}" class="flex items-center justify-between text-sm">
                                        <div class="flex items-center gap-2">
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $owner->name }}</span>
                                            <flux:badge size="sm" variant="outline">{{ $owner->role }}</flux:badge>
                                        </div>
                                        <span class="font-medium text-zinc-600 dark:text-zinc-400">{{ $owner->percentage }}%</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Address --}}
                    @if ($corp->address_line_1)
                        <div class="mt-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $corp->address_line_1 }}
                                @if ($corp->address_line_2), {{ $corp->address_line_2 }}@endif
                                @if ($corp->city), {{ $corp->city }}@endif
                                @if ($corp->state), {{ $corp->state }}@endif
                                {{ $corp->zip }}
                            </flux:text>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Create/Edit Modal --}}
    <flux:modal wire:model="showModal" class="max-w-2xl">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editing ? __('Edit Corporation') : __('Add Corporation') }}
            </flux:heading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:field>
                        <flux:label>{{ __('Corporation Name') }}</flux:label>
                        <flux:input wire:model="name" placeholder="Apex Negotiations LLC" />
                        <flux:error name="name" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Entity Type') }}</flux:label>
                    <flux:select wire:model="entity_type">
                        @foreach (\App\Models\Corporation::ENTITY_TYPES as $key => $label)
                            <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="entity_type" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('State of Formation') }}</flux:label>
                    <flux:input wire:model="state_of_formation" maxlength="2" placeholder="CO" />
                    <flux:error name="state_of_formation" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('EIN') }}</flux:label>
                    <flux:input wire:model="ein" placeholder="XX-XXXXXXX" />
                    <flux:error name="ein" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Formation Date') }}</flux:label>
                    <flux:input wire:model="formation_date" type="date" />
                    <flux:error name="formation_date" />
                </flux:field>

                <div class="sm:col-span-2">
                    <flux:field>
                        <flux:label>{{ __('Registered Agent') }}</flux:label>
                        <flux:input wire:model="registered_agent" />
                        <flux:error name="registered_agent" />
                    </flux:field>
                </div>

                <div class="sm:col-span-2">
                    <flux:field>
                        <flux:label>{{ __('Address Line 1') }}</flux:label>
                        <flux:input wire:model="address_line_1" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Address Line 2') }}</flux:label>
                    <flux:input wire:model="address_line_2" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('City') }}</flux:label>
                    <flux:input wire:model="city" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('State') }}</flux:label>
                    <flux:input wire:model="state" maxlength="2" placeholder="CO" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('ZIP') }}</flux:label>
                    <flux:input wire:model="zip" maxlength="10" />
                </flux:field>

                <div class="sm:col-span-2">
                    <flux:field>
                        <flux:label>{{ __('Notes') }}</flux:label>
                        <flux:textarea wire:model="notes" rows="2" />
                    </flux:field>
                </div>
            </div>

            {{-- Owners Section --}}
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="sm">{{ __('Owners') }}</flux:heading>
                    <flux:button size="xs" variant="ghost" icon="plus" wire:click="addOwner">
                        {{ __('Add Owner') }}
                    </flux:button>
                </div>

                @foreach ($owners as $i => $owner)
                    <div wire:key="owner-form-{{ $i }}" class="mb-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="mb-2 flex items-center justify-between">
                            <flux:text class="text-xs font-medium text-zinc-500">{{ __('Owner') }} #{{ $i + 1 }}</flux:text>
                            <flux:button size="xs" variant="ghost" icon="x-mark" class="text-red-500" wire:click="removeOwner({{ $i }})" />
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>{{ __('Name') }}</flux:label>
                                <flux:input wire:model="owners.{{ $i }}.name" size="sm" />
                                <flux:error name="owners.{{ $i }}.name" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Ownership %') }}</flux:label>
                                <flux:input wire:model="owners.{{ $i }}.percentage" type="number" step="0.01" min="0" max="100" size="sm" />
                                <flux:error name="owners.{{ $i }}.percentage" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Role') }}</flux:label>
                                <flux:select wire:model="owners.{{ $i }}.role" size="sm">
                                    @foreach (\App\Models\CorporationOwner::ROLES as $key => $label)
                                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Email') }}</flux:label>
                                <flux:input wire:model="owners.{{ $i }}.email" type="email" size="sm" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Phone') }}</flux:label>
                                <flux:input wire:model="owners.{{ $i }}.phone" size="sm" />
                            </flux:field>
                        </div>
                    </div>
                @endforeach

                @if (empty($owners))
                    <flux:text class="text-center text-sm text-zinc-400 dark:text-zinc-500">
                        {{ __('No owners added yet.') }}
                    </flux:text>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="primary" wire:click="save">
                    {{ $editing ? __('Update') : __('Create') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
