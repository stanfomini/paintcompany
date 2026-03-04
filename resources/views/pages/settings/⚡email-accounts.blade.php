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

    // Shared account fields
    public string $accountType = 'personal';
    public ?int $defaultNotifyUserId = null;
    public array $memberIds = [];

    // Reassign modal
    public bool $showReassignModal = false;
    public ?int $reassigningId = null;
    public ?int $reassignToUserId = null;
    public string $reassigningAddress = '';

    // Delete modal
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingAddress = '';

    // Members management modal
    public bool $showMembersModal = false;
    public ?int $managingMembersId = null;
    public array $currentMembers = [];
    public ?int $addMemberId = null;

    public function updatedSearch(): void
    {
        // Reactivity handles the computed refresh
    }

    #[Computed]
    public function accounts(): \Illuminate\Database\Eloquent\Collection
    {
        $query = EmailAccount::query()->with(['user', 'members'])->orderBy('address');

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
        $this->accountType = $account->type ?? 'personal';
        $this->userId = $account->user_id;
        $this->address = $account->address;
        $this->displayName = $account->display_name ?? '';
        $this->signatureHtml = $account->signature_html ?? '';
        $this->isActive = $account->is_active;
        $this->defaultNotifyUserId = $account->default_notify_user_id;
        $this->memberIds = $account->isShared() ? $account->members->pluck('id')->toArray() : [];
        $this->showModal = true;
    }

    public function save(): void
    {
        $isShared = $this->accountType === 'shared';

        $rules = [
            'accountType' => 'required|in:personal,shared',
            'address' => 'required|string|min:3|max:30|regex:/^[a-z0-9.]+$/',
            'displayName' => 'nullable|string|max:100',
            'signatureHtml' => 'nullable|string|max:50000',
            'isActive' => 'boolean',
        ];

        if ($isShared) {
            $rules['defaultNotifyUserId'] = 'nullable|exists:users,id';
            $rules['memberIds'] = 'required|array|min:1';
            $rules['memberIds.*'] = 'exists:users,id';
        } else {
            $rules['userId'] = 'required|exists:users,id';
        }

        if ($this->editingId) {
            $rules['address'] .= '|unique:email_accounts,address,' . $this->editingId;
            if (! $isShared) {
                $rules['userId'] .= '|unique:email_accounts,user_id,' . $this->editingId;
            }
        } else {
            $rules['address'] .= '|unique:email_accounts,address';
            if (! $isShared) {
                $rules['userId'] .= '|unique:email_accounts,user_id';
            }
        }

        $this->validate($rules, [
            'userId.unique' => 'This user already has an email account.',
            'address.unique' => 'This email address is already taken.',
            'address.regex' => 'Only lowercase letters, numbers, and dots allowed.',
            'memberIds.required' => 'Shared accounts must have at least one member.',
        ]);

        if ($this->editingId) {
            $account = EmailAccount::findOrFail($this->editingId);
            $data = [
                'address' => strtolower($this->address),
                'display_name' => $this->displayName ?: null,
                'signature_html' => $this->signatureHtml ?: null,
                'is_active' => $this->isActive,
            ];
            if ($isShared) {
                $data['default_notify_user_id'] = $this->defaultNotifyUserId;
            }
            $account->update($data);
            if ($isShared) {
                $account->members()->sync($this->memberIds);
            }
        } else {
            $data = [
                'type' => $this->accountType,
                'address' => strtolower($this->address),
                'display_name' => $this->displayName ?: null,
                'signature_html' => $this->signatureHtml ?: null,
                'is_active' => $this->isActive,
            ];
            if ($isShared) {
                $data['user_id'] = null;
                $data['default_notify_user_id'] = $this->defaultNotifyUserId;
            } else {
                $data['user_id'] = $this->userId;
            }
            $account = EmailAccount::create($data);
            if ($isShared && ! empty($this->memberIds)) {
                $account->members()->attach($this->memberIds);
            }
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
        $this->accountType = 'personal';
        $this->address = '';
        $this->displayName = '';
        $this->signatureHtml = '';
        $this->isActive = true;
        $this->defaultNotifyUserId = null;
        $this->memberIds = [];
    }

    public function openMembersModal(int $id): void
    {
        $account = EmailAccount::findOrFail($id);
        if (! $account->isShared()) {
            return;
        }
        $this->managingMembersId = $account->id;
        $this->currentMembers = $account->members()->withPivot('can_send', 'receives_notifications')->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'can_send' => (bool) $m->pivot->can_send,
                'receives_notifications' => (bool) $m->pivot->receives_notifications,
            ])->toArray();
        $this->addMemberId = null;
        $this->showMembersModal = true;
    }

    public function addMember(): void
    {
        if (! $this->addMemberId || ! $this->managingMembersId) {
            return;
        }

        $account = EmailAccount::findOrFail($this->managingMembersId);
        if (! $account->members()->where('users.id', $this->addMemberId)->exists()) {
            $account->members()->attach($this->addMemberId, [
                'can_send' => true,
                'receives_notifications' => true,
            ]);
        }

        $this->openMembersModal($this->managingMembersId);
    }

    public function removeMember(int $userId): void
    {
        if (! $this->managingMembersId) {
            return;
        }

        $account = EmailAccount::findOrFail($this->managingMembersId);
        $account->members()->detach($userId);
        $this->openMembersModal($this->managingMembersId);
    }

    public function toggleMemberPermission(int $userId, string $field): void
    {
        if (! $this->managingMembersId || ! in_array($field, ['can_send', 'receives_notifications'])) {
            return;
        }

        $account = EmailAccount::findOrFail($this->managingMembersId);
        $member = $account->members()->where('users.id', $userId)->first();
        if ($member) {
            $account->members()->updateExistingPivot($userId, [
                $field => ! $member->pivot->$field,
            ]);
        }

        $this->openMembersModal($this->managingMembersId);
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
                                    @if ($account->isShared())
                                        <flux:badge size="sm" color="purple" class="ml-1">{{ __('Shared') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">
                                    @if ($account->isShared())
                                        {{ $account->members->count() }} {{ __('members') }}
                                    @else
                                        {{ $account->user?->name ?? '—' }}
                                    @endif
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
                                        @if ($account->isShared())
                                            <flux:button wire:click="openMembersModal({{ $account->id }})" variant="ghost" size="sm" icon="user-group" />
                                        @else
                                            <flux:button wire:click="openReassignModal({{ $account->id }})" variant="ghost" size="sm" icon="arrow-right-circle" />
                                        @endif
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
                            <flux:label>{{ __('Account Type') }}</flux:label>
                            <flux:select wire:model.live="accountType">
                                <option value="personal">{{ __('Personal') }}</option>
                                <option value="shared">{{ __('Shared') }}</option>
                            </flux:select>
                        </flux:field>
                    @endunless

                    @if ($accountType === 'personal')
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
                    @else
                        <flux:field>
                            <flux:label>{{ __('Default Notification Recipient') }}</flux:label>
                            <flux:select wire:model="defaultNotifyUserId">
                                <option value="">{{ __('None (notify all members)') }}</option>
                                @foreach ($this->allInternalUsers as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:description>{{ __('Primary person notified on new emails. Others with notifications enabled also receive alerts.') }}</flux:description>
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Members') }}</flux:label>
                            <div class="space-y-2">
                                @foreach ($this->allInternalUsers as $user)
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" wire:model="memberIds" value="{{ $user->id }}" class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800" />
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $user->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <flux:error name="memberIds" />
                        </flux:field>
                    @endif

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
                        <flux:description class="mb-2">{{ __('Format your signature with bold, italic, colors, images, and links.') }}</flux:description>
                        <div
                            x-data="{
                                content: $wire.entangle('signatureHtml'),
                                doc: null,
                                updateIframe() {
                                    const iframe = this.$refs.sigFrame;
                                    if (!iframe) return;
                                    this.doc = iframe.contentDocument || iframe.contentWindow.document;
                                    this.doc.open();
                                    this.doc.write(`<!DOCTYPE html><html><head><style>
                                        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 13px; line-height: 1.5; color: #333; margin: 0; padding: 10px; word-wrap: break-word; min-height: 80px; }
                                        body:empty:before { content: 'Best regards, Your Name'; color: #999; }
                                        img { max-width: 200px; height: auto; }
                                        a { color: #2563eb; }
                                    </style></head><body contenteditable='true'>` + (this.content || '') + `</body></html>`);
                                    this.doc.close();
                                    this.doc.body.addEventListener('input', () => {
                                        this.content = this.doc.body.innerHTML;
                                    });
                                },
                                execCmd(cmd, value = null) {
                                    if (!this.doc) return;
                                    this.doc.body.focus();
                                    this.doc.execCommand(cmd, false, value);
                                    this.content = this.doc.body.innerHTML;
                                },
                                insertLink() {
                                    const url = prompt('{{ __('Enter URL') }}');
                                    if (url) this.execCmd('createLink', url);
                                },
                                insertImage() {
                                    const url = prompt('{{ __('Enter image URL (e.g. your company logo)') }}');
                                    if (url) this.execCmd('insertImage', url);
                                },
                                uploadImage() {
                                    this.$refs.sigImageUpload.click();
                                },
                                handleImageUpload(event) {
                                    const file = event.target.files[0];
                                    if (!file) return;
                                    if (!file.type.startsWith('image/')) { alert('Please select an image file.'); return; }
                                    if (file.size > 500 * 1024) { alert('Image must be under 500KB for email signatures.'); return; }
                                    const reader = new FileReader();
                                    reader.onload = (e) => {
                                        this.doc.body.focus();
                                        this.doc.execCommand('insertImage', false, e.target.result);
                                        this.content = this.doc.body.innerHTML;
                                    };
                                    reader.readAsDataURL(file);
                                    event.target.value = '';
                                },
                                init() {
                                    this.$nextTick(() => this.updateIframe());
                                    this.$watch('content', (value) => {
                                        if (!this.doc || !this.doc.body) return;
                                        if (this.doc.body.innerHTML !== value) {
                                            this.updateIframe();
                                        }
                                    });
                                }
                            }"
                        >
                            {{-- Signature toolbar --}}
                            <div class="flex flex-wrap items-center gap-0.5 rounded-t-lg border border-zinc-200 bg-zinc-50 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-800">
                                <button type="button" @click="execCmd('bold')" class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Bold">
                                    <strong class="text-xs">B</strong>
                                </button>
                                <button type="button" @click="execCmd('italic')" class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Italic">
                                    <em class="text-xs">I</em>
                                </button>
                                <button type="button" @click="execCmd('underline')" class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Underline">
                                    <span class="text-xs underline">U</span>
                                </button>
                                <div class="mx-0.5 h-4 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                <label class="relative rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700 cursor-pointer" title="Text Color">
                                    <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 3l6 15H6l6-15z"/><line x1="4" y1="21" x2="20" y2="21" stroke-width="3"/></svg>
                                    <input type="color" value="#000000" @input="execCmd('foreColor', $event.target.value)"
                                        class="absolute inset-0 h-full w-full cursor-pointer opacity-0" />
                                </label>
                                <div class="mx-0.5 h-4 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                                <button type="button" @click="insertLink()" class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Link">
                                    <flux:icon name="link" class="size-3.5" />
                                </button>
                                <button type="button" @click="insertImage()" class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Image from URL">
                                    <flux:icon name="globe-alt" class="size-3.5" />
                                </button>
                                <button type="button" @click="uploadImage()" class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700" title="Upload Image">
                                    <flux:icon name="photo" class="size-3.5" />
                                </button>
                                <input type="file" x-ref="sigImageUpload" accept="image/*" class="hidden" @change="handleImageUpload($event)" />
                            </div>

                            {{-- Signature editor --}}
                            <iframe x-ref="sigFrame" class="w-full rounded-b-lg border border-t-0 border-zinc-200 bg-white dark:border-zinc-700" style="height: 120px; border: none;"></iframe>
                        </div>
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

        {{-- Members Management Modal --}}
        <flux:modal wire:model="showMembersModal" class="max-w-lg">
            <div class="space-y-6">
                <flux:heading size="lg">{{ __('Manage Members') }}</flux:heading>

                {{-- Current Members --}}
                @if (count($currentMembers) > 0)
                    <div class="space-y-2">
                        @foreach ($currentMembers as $member)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $member['name'] }}</span>
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                        <input type="checkbox" {{ $member['can_send'] ? 'checked' : '' }}
                                            wire:click="toggleMemberPermission({{ $member['id'] }}, 'can_send')"
                                            class="rounded border-zinc-300 text-blue-600 dark:border-zinc-600" />
                                        {{ __('Send') }}
                                    </label>
                                    <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                        <input type="checkbox" {{ $member['receives_notifications'] ? 'checked' : '' }}
                                            wire:click="toggleMemberPermission({{ $member['id'] }}, 'receives_notifications')"
                                            class="rounded border-zinc-300 text-blue-600 dark:border-zinc-600" />
                                        {{ __('Notify') }}
                                    </label>
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeMember({{ $member['id'] }})" class="text-red-500" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-zinc-500">{{ __('No members yet.') }}</p>
                @endif

                {{-- Add Member --}}
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <flux:select wire:model="addMemberId" size="sm">
                            <option value="">{{ __('Select a user to add...') }}</option>
                            @foreach ($this->allInternalUsers as $user)
                                @if (! collect($currentMembers)->pluck('id')->contains($user->id))
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endif
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:button wire:click="addMember" size="sm" variant="primary" icon="plus">{{ __('Add') }}</flux:button>
                </div>

                <div class="flex justify-end">
                    <flux:button wire:click="$set('showMembersModal', false)" variant="ghost">{{ __('Close') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>
