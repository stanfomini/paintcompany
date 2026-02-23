<?php

use App\Models\EmailAccount;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('email.manage')) {
            abort(403);
        }
    }

    public string $search = '';

    // Create/Edit modal
    public bool $showModal = false;
    public ?int $editingId = null;
    public ?int $userId = null;
    public string $address = '';
    public string $displayName = '';
    public string $signatureHtml = '';
    public bool $isActive = true;

    // Reassign modal
    public bool $showReassignModal = false;
    public ?int $reassigningId = null;
    public ?int $reassignToUserId = null;
    public string $reassigningAddress = '';

    // Delete modal
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingAddress = '';

    public function updatedSearch(): void
    {
        // Reactivity handles the computed refresh
    }

    #[Computed]
    public function accounts(): \Illuminate\Database\Eloquent\Collection
    {
        $query = EmailAccount::query()->with('user')->orderBy('address');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('address', 'like', "%{$this->search}%")
                    ->orWhere('display_name', 'like', "%{$this->search}%")
                    ->orWhereHas('user', function ($uq) {
                        $uq->where('name', 'like', "%{$this->search}%");
                    });
            });
        }

        return $query->get();
    }

    #[Computed]
    public function availableUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->whereDoesntHave('emailAccount')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function allInternalUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->orderBy('name')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetModal();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $account = EmailAccount::findOrFail($id);
        $this->editingId = $account->id;
        $this->userId = $account->user_id;
        $this->address = $account->address;
        $this->displayName = $account->display_name ?? '';
        $this->signatureHtml = $account->signature_html ?? '';
        $this->isActive = $account->is_active;
        $this->showModal = true;
    }

    public function save(): void
    {
        $rules = [
            'userId' => 'required|exists:users,id',
            'address' => 'required|string|min:3|max:30|regex:/^[a-z0-9.]+$/',
            'displayName' => 'nullable|string|max:100',
            'signatureHtml' => 'nullable|string|max:5000',
            'isActive' => 'boolean',
        ];

        if ($this->editingId) {
            $rules['address'] .= '|unique:email_accounts,address,' . $this->editingId;
            $rules['userId'] .= '|unique:email_accounts,user_id,' . $this->editingId;
        } else {
            $rules['address'] .= '|unique:email_accounts,address';
            $rules['userId'] .= '|unique:email_accounts,user_id';
        }

        $this->validate($rules, [
            'userId.unique' => 'This user already has an email account.',
            'address.unique' => 'This email address is already taken.',
            'address.regex' => 'Only lowercase letters, numbers, and dots allowed.',
        ]);

        if ($this->editingId) {
            $account = EmailAccount::findOrFail($this->editingId);
            $account->update([
                'address' => strtolower($this->address),
                'display_name' => $this->displayName ?: null,
                'signature_html' => $this->signatureHtml ?: null,
                'is_active' => $this->isActive,
            ]);
        } else {
            EmailAccount::create([
                'user_id' => $this->userId,
                'address' => strtolower($this->address),
                'display_name' => $this->displayName ?: null,
                'signature_html' => $this->signatureHtml ?: null,
                'is_active' => $this->isActive,
            ]);
        }

        $this->showModal = false;
        $this->resetModal();
        unset($this->accounts, $this->availableUsers);
    }

    public function toggleActive(int $id): void
    {
        $account = EmailAccount::findOrFail($id);
        $account->update(['is_active' => ! $account->is_active]);
        unset($this->accounts);
    }

    public function openReassignModal(int $id): void
    {
        $account = EmailAccount::findOrFail($id);
        $this->reassigningId = $account->id;
        $this->reassigningAddress = $account->address;
        $this->reassignToUserId = null;
        $this->showReassignModal = true;
    }

    public function reassign(): void
    {
        $this->validate([
            'reassignToUserId' => 'required|exists:users,id|unique:email_accounts,user_id',
        ], [
            'reassignToUserId.unique' => 'That user already has an email account.',
        ]);

        $account = EmailAccount::findOrFail($this->reassigningId);
        $account->update([
            'user_id' => $this->reassignToUserId,
        ]);

        $this->showReassignModal = false;
        $this->reassigningId = null;
        $this->reassignToUserId = null;
        $this->reassigningAddress = '';
        unset($this->accounts, $this->availableUsers);
    }

    public function confirmDelete(int $id): void
    {
        $account = EmailAccount::findOrFail($id);
        $this->deletingId = $account->id;
        $this->deletingAddress = $account->address . '@' . config('email.domain');
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        EmailAccount::findOrFail($this->deletingId)->delete();
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingAddress = '';
        unset($this->accounts, $this->availableUsers);
    }

    protected function resetModal(): void
    {
        $this->editingId = null;
        $this->userId = null;
        $this->address = '';
        $this->displayName = '';
        $this->signatureHtml = '';
        $this->isActive = true;
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Email Accounts')" :subheading="__('Manage email accounts for your team. Create, edit, deactivate, or reassign accounts.')" :fullWidth="true">
        <div class="space-y-6">
            {{-- Header row --}}
            <div class="flex items-center justify-between gap-4">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search accounts...') }}" icon="magnifying-glass" class="max-w-xs" />
                <flux:button wire:click="openCreateModal" variant="primary" icon="plus">
                    {{ __('Create Account') }}
                </flux:button>
            </div>

            {{-- Accounts table --}}
            <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Email Address') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('User') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Display Name') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Created') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                        @forelse ($this->accounts as $account)
                            <tr wire:key="account-{{ $account->id }}" class="{{ ! $account->is_active ? 'opacity-50' : '' }}">
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $account->address }}@{{ config('email.domain') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ $account->user?->name ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ $account->display_name ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($account->is_active)
                                        <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $account->created_at->format('M j, Y') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button wire:click="openEditModal({{ $account->id }})" variant="ghost" size="sm" icon="pencil-square" />
                                        <flux:button wire:click="toggleActive({{ $account->id }})" variant="ghost" size="sm" icon="{{ $account->is_active ? 'pause' : 'play' }}" />
                                        <flux:button wire:click="openReassignModal({{ $account->id }})" variant="ghost" size="sm" icon="arrow-right-circle" />
                                        <flux:button wire:click="confirmDelete({{ $account->id }})" variant="ghost" size="sm" icon="trash" class="text-red-500 hover:text-red-700" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ __('No email accounts found.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Domain info --}}
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    <strong>{{ __('Email Domain') }}:</strong> {{ config('email.domain') }}
                </p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('All accounts use this domain. To change it, update the EMAIL_DOMAIN environment variable.') }}
                </p>
            </div>
        </div>

        {{-- Create/Edit Modal --}}
        <flux:modal wire:model="showModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading size="lg">{{ $editingId ? __('Edit Email Account') : __('Create Email Account') }}</flux:heading>

                <form wire:submit="save" class="space-y-4">
                    @unless ($editingId)
                        <flux:field>
                            <flux:label>{{ __('User') }}</flux:label>
                            <flux:select wire:model="userId">
                                <option value="">{{ __('Select a user...') }}</option>
                                @foreach ($this->availableUsers as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="userId" />
                        </flux:field>
                    @endunless

                    <flux:field>
                        <flux:label>{{ __('Email Address') }}</flux:label>
                        <div class="flex items-center gap-1">
                            <flux:input wire:model="address" placeholder="john.doe" />
                            <span class="shrink-0 text-sm text-zinc-500">@ {{ config('email.domain') }}</span>
                        </div>
                        <flux:description>{{ __('Lowercase letters, numbers, and dots only.') }}</flux:description>
                        <flux:error name="address" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Display Name') }}</flux:label>
                        <flux:input wire:model="displayName" placeholder="{{ __('John Doe') }}" />
                        <flux:description>{{ __('Shown as sender name on outgoing emails.') }}</flux:description>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Signature') }} <span class="text-zinc-400">({{ __('optional') }})</span></flux:label>
                        <flux:textarea wire:model="signatureHtml" rows="3" placeholder="{{ __('Best regards, Your Name') }}" />
                    </flux:field>

                    @if ($editingId)
                        <flux:field>
                            <flux:switch wire:model="isActive" label="{{ __('Account Active') }}" description="{{ __('Inactive accounts cannot send or receive email.') }}" />
                        </flux:field>
                    @endif

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:button wire:click="$set('showModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                        <flux:button type="submit" variant="primary">{{ $editingId ? __('Save Changes') : __('Create Account') }}</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        {{-- Reassign Modal --}}
        <flux:modal wire:model="showReassignModal" class="max-w-md">
            <div class="space-y-6">
                <flux:heading size="lg">{{ __('Reassign Email Account') }}</flux:heading>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Transfer') }} <strong>{{ $reassigningAddress }}@{{ config('email.domain') }}</strong> {{ __('to a different user. The current owner will lose access.') }}
                </p>

                <form wire:submit="reassign" class="space-y-4">
                    <flux:field>
                        <flux:label>{{ __('New Owner') }}</flux:label>
                        <flux:select wire:model="reassignToUserId">
                            <option value="">{{ __('Select a user...') }}</option>
                            @foreach ($this->availableUsers as $user)
                                <option value="{{ $user->id }}">{{ $user->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:description>{{ __('Only users without an email account are listed.') }}</flux:description>
                        <flux:error name="reassignToUserId" />
                    </flux:field>

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:button wire:click="$set('showReassignModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                        <flux:button type="submit" variant="primary">{{ __('Reassign') }}</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        {{-- Delete Confirmation Modal --}}
        <flux:modal wire:model="showDeleteModal" class="max-w-sm">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Delete Email Account') }}</flux:heading>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingAddress }}</strong>?
                    {{ __('All emails and threads for this account will be permanently deleted.') }}
                </p>
                <div class="flex justify-end gap-3">
                    <flux:button wire:click="$set('showDeleteModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button wire:click="delete" variant="danger">{{ __('Delete') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>
