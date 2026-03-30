<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Prospector Companies')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    // Form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public ?int $twilioAccountId = null;
    public string $timezone = 'America/Denver';

    // Delete
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function companies(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Company::query()
            ->with(['twilioAccount', 'mainPhoneNumber'])
            ->withCount('phoneNumbers')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate(15);
    }

    #[Computed]
    public function twilioAccounts(): \Illuminate\Support\Collection
    {
        return TwilioAccount::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function timezoneOptions(): array
    {
        return [
            'America/Denver' => 'Mountain (Denver)',
            'America/Chicago' => 'Central (Chicago)',
            'America/New_York' => 'Eastern (New York)',
            'America/Los_Angeles' => 'Pacific (Los Angeles)',
            'America/Phoenix' => 'Arizona (Phoenix)',
            'America/Anchorage' => 'Alaska (Anchorage)',
            'Pacific/Honolulu' => 'Hawaii (Honolulu)',
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $company = Company::findOrFail($id);
        $this->editingId = $company->id;
        $this->name = $company->name;
        $this->twilioAccountId = $company->twilio_account_id;
        $this->timezone = $company->timezone ?? 'America/Denver';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('prospector_companies', 'name')->ignore($this->editingId),
            ],
            'twilioAccountId' => ['required', 'exists:prospector_twilio_accounts,id'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        if ($this->editingId) {
            $company = Company::findOrFail($this->editingId);
            $company->update([
                'name' => $this->name,
                'twilio_account_id' => $this->twilioAccountId,
                'timezone' => $this->timezone,
            ]);
        } else {
            Company::create([
                'name' => $this->name,
                'twilio_account_id' => $this->twilioAccountId,
                'timezone' => $this->timezone,
                'is_active' => true,
            ]);
        }

        $this->closeModal();
        unset($this->companies);
    }

    public function toggleActive(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $company = Company::findOrFail($id);
        $company->update(['is_active' => ! $company->is_active]);
        unset($this->companies);
    }

    public function confirmDelete(int $id): void
    {
        $company = Company::findOrFail($id);

        if ($company->phoneNumbers()->exists()) {
            session()->flash('error', __('Cannot delete company with assigned phone numbers. Unassign numbers first.'));

            return;
        }

        $this->deletingId = $id;
        $this->deletingName = $company->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->deletingId) {
            return;
        }

        $company = Company::findOrFail($this->deletingId);

        if ($company->phoneNumbers()->exists()) {
            session()->flash('error', __('Cannot delete company with assigned phone numbers.'));
            $this->cancelDelete();

            return;
        }

        $company->delete();
        $this->cancelDelete();
        unset($this->companies);
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->twilioAccountId = null;
        $this->timezone = 'America/Denver';
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Companies') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage prospector companies and their phone number pools.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openAddModal">
            {{ __('Create Company') }}
        </flux:button>
    </div>

    @if (session('error'))
        <flux:callout color="red" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>{{ session('error') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Search --}}
    <div class="mb-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search companies...')"
            class="max-w-sm"
        />
    </div>

    {{-- Companies table --}}
    @if ($this->companies->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="building-office" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No companies found') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create your first company to start assigning phone numbers.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                    {{ __('Create Company') }}
                </flux:button>
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Company') }}</flux:table.column>
                <flux:table.column>{{ __('Main Number') }}</flux:table.column>
                <flux:table.column>{{ __('Phone Numbers') }}</flux:table.column>
                <flux:table.column>{{ __('Twilio Account') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->companies as $company)
                    <flux:table.row wire:key="company-{{ $company->id }}">
                        <flux:table.cell>
                            <a href="{{ route('prospector.companies.show', $company->id) }}" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                {{ $company->name }}
                            </a>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $company->timezone }}</div>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($company->mainPhoneNumber)
                                <span class="font-mono text-sm">{{ $company->mainPhoneNumber->getDisplayNumber() }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('Not set') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $company->phone_numbers_count }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($company->twilioAccount)
                                <span class="text-sm">{{ $company->twilioAccount->name }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('None') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($company->is_active)
                                <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Inactive') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    :href="route('prospector.companies.show', $company->id)"
                                    title="{{ __('View Details') }}"
                                />
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $company->id }})" title="{{ __('Edit') }}" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $company->id }})" title="{{ __('Delete') }}" />
                                <flux:switch
                                    wire:click="toggleActive({{ $company->id }})"
                                    :checked="$company->is_active"
                                    size="sm"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-6">
            {{ $this->companies->links() }}
        </div>
    @endif

    {{-- Add/Edit Modal --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[420px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Company') : __('Create Company') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingId
                        ? __('Update the company details below.')
                        : __('Create a new company to organize phone numbers and prospecting.')
                    }}
                </flux:text>
            </div>

            <flux:input
                wire:model="name"
                :label="__('Company Name')"
                :placeholder="__('e.g., Denver Painting Co.')"
                required
            />

            <flux:select wire:model="twilioAccountId" :label="__('Twilio Account')" required>
                <flux:select.option value="">{{ __('Select an account...') }}</flux:select.option>
                @foreach ($this->twilioAccounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="timezone" :label="__('Timezone')" required>
                @foreach ($this->timezoneOptions as $tz => $label)
                    <flux:select.option value="{{ $tz }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingId ? __('Update Company') : __('Create Company') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Company') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingName }}</strong>{{ __('? This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="delete" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
