<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\MessagingService;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\PhoneNumberService;
use App\Services\Prospector\TwilioComplianceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Prospector Settings')]
class extends Component
{
    // Tab state
    public string $activeTab = 'accounts';

    // Form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $accountSid = '';
    public string $authToken = '';

    // Sync results
    public ?array $syncResults = null;
    public ?int $syncingAccountId = null;

    // Connection test
    public ?array $testResult = null;
    public ?int $testingAccountId = null;

    // Delete
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';

    // A2P Compliance
    public bool $showCreateServiceModal = false;
    public string $serviceName = '';
    public ?int $serviceAccountId = null;
    public ?int $serviceCompanyId = null;

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function accounts(): \Illuminate\Support\Collection
    {
        return TwilioAccount::query()
            ->withCount('phoneNumbers')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function webhookUrls(): array
    {
        return app(PhoneNumberService::class)->getWebhookUrls();
    }

    #[Computed]
    public function messagingServices(): \Illuminate\Support\Collection
    {
        return MessagingService::with(['twilioAccount', 'company'])
            ->orderBy('friendly_name')
            ->get();
    }

    #[Computed]
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::where('is_active', true)->orderBy('name')->get();
    }

    public function openCreateServiceModal(): void
    {
        $this->serviceName = '';
        $this->serviceAccountId = null;
        $this->serviceCompanyId = null;
        $this->showCreateServiceModal = true;
    }

    public function closeCreateServiceModal(): void
    {
        $this->showCreateServiceModal = false;
        $this->serviceName = '';
        $this->serviceAccountId = null;
        $this->serviceCompanyId = null;
    }

    public function createMessagingService(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'serviceName' => ['required', 'string', 'max:100'],
            'serviceAccountId' => ['required', 'exists:prospector_twilio_accounts,id'],
            'serviceCompanyId' => ['nullable', 'exists:prospector_companies,id'],
        ]);

        try {
            $account = TwilioAccount::findOrFail($this->serviceAccountId);
            $service = app(TwilioComplianceService::class);
            $service->createMessagingService($account, $this->serviceName, $this->serviceCompanyId);
            $this->closeCreateServiceModal();
            unset($this->messagingServices);
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to create messaging service: ') . $e->getMessage());
            $this->closeCreateServiceModal();
        }
    }

    public function refreshComplianceStatus(int $serviceId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $messagingService = MessagingService::findOrFail($serviceId);
        $service = app(TwilioComplianceService::class);
        $service->refreshStatus($messagingService);
        unset($this->messagingServices);
    }

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $account = TwilioAccount::findOrFail($id);
        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->accountSid = '';
        $this->authToken = '';
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

        $rules = [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('prospector_twilio_accounts', 'name')->ignore($this->editingId),
            ],
        ];

        if (! $this->editingId) {
            $rules['accountSid'] = ['required', 'string', 'max:100'];
            $rules['authToken'] = ['required', 'string', 'max:100'];
        } else {
            $rules['accountSid'] = ['nullable', 'string', 'max:100'];
            $rules['authToken'] = ['nullable', 'string', 'max:100'];
        }

        $this->validate($rules);

        if ($this->editingId) {
            $account = TwilioAccount::findOrFail($this->editingId);
            $data = ['name' => $this->name];

            if (! empty($this->accountSid)) {
                $data['account_sid'] = $this->accountSid;
            }
            if (! empty($this->authToken)) {
                $data['auth_token'] = $this->authToken;
            }

            $account->update($data);
        } else {
            TwilioAccount::create([
                'name' => $this->name,
                'account_sid' => $this->accountSid,
                'auth_token' => $this->authToken,
                'is_active' => true,
            ]);
        }

        $this->closeModal();
        unset($this->accounts);
    }

    public function syncNumbers(int $accountId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $account = TwilioAccount::findOrFail($accountId);
        $this->syncingAccountId = $accountId;
        $this->syncResults = null;

        try {
            $service = app(\App\Services\Prospector\TwilioSyncService::class);
            $this->syncResults = $service->syncNumbers($account);
            unset($this->accounts);
        } catch (\Throwable $e) {
            $this->syncResults = [
                'error' => $e->getMessage(),
                'created' => 0,
                'updated' => 0,
                'removed' => 0,
            ];
        }
    }

    public function dismissSyncResults(): void
    {
        $this->syncResults = null;
        $this->syncingAccountId = null;
    }

    public function testConnection(int $accountId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $account = TwilioAccount::findOrFail($accountId);
        $this->testingAccountId = $accountId;

        try {
            $client = new \Twilio\Rest\Client($account->account_sid, $account->auth_token);
            $twilioAccount = $client->api->v2010->accounts($account->account_sid)->fetch();

            $this->testResult = [
                'success' => true,
                'friendly_name' => $twilioAccount->friendlyName,
                'status' => $twilioAccount->status,
            ];
        } catch (\Throwable $e) {
            $this->testResult = [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function dismissTestResult(): void
    {
        $this->testResult = null;
        $this->testingAccountId = null;
    }

    public function toggleActive(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $account = TwilioAccount::findOrFail($id);
        $account->update(['is_active' => ! $account->is_active]);
        unset($this->accounts);
    }

    public function confirmDelete(int $id): void
    {
        $account = TwilioAccount::findOrFail($id);
        $this->deletingId = $id;
        $this->deletingName = $account->name;
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

        $account = TwilioAccount::findOrFail($this->deletingId);

        if ($account->phoneNumbers()->exists()) {
            session()->flash('error', __('Cannot delete account with phone numbers. Remove numbers first.'));
            $this->cancelDelete();

            return;
        }

        $account->delete();
        $this->cancelDelete();
        unset($this->accounts);
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
        $this->accountSid = '';
        $this->authToken = '';
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Prospector Settings') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Connect your Twilio accounts to manage phone numbers for prospecting.') }}</flux:text>
        </div>

        <div class="flex items-center gap-4">
            @php
                $hasActiveAccount = $this->accounts->contains(fn ($a) => $a->is_active);
            @endphp
            <div class="flex items-center gap-2 text-sm">
                <span class="inline-flex size-2.5 rounded-full {{ $hasActiveAccount ? 'bg-green-500' : 'bg-red-500' }}"></span>
                <span class="text-zinc-600 dark:text-zinc-400">
                    {{ $hasActiveAccount ? __('Twilio Connected') : __('No Active Account') }}
                </span>
            </div>
        </div>
    </div>

    @if (session('error'))
        <flux:callout color="red" icon="exclamation-triangle" class="mb-4">
            <flux:callout.text>{{ session('error') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Tabs --}}
    <div class="mb-6 flex items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
        <button
            wire:click="$set('activeTab', 'accounts')"
            @class([
                'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                'border-blue-500 text-blue-600 dark:text-blue-400' => $activeTab === 'accounts',
                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'accounts',
            ])
        >
            {{ __('Twilio Accounts') }}
        </button>
        <button
            wire:click="$set('activeTab', 'compliance')"
            @class([
                'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                'border-blue-500 text-blue-600 dark:text-blue-400' => $activeTab === 'compliance',
                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'compliance',
            ])
        >
            {{ __('A2P Compliance') }}
        </button>
        <button
            wire:click="$set('activeTab', 'webhooks')"
            @class([
                'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                'border-blue-500 text-blue-600 dark:text-blue-400' => $activeTab === 'webhooks',
                'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeTab !== 'webhooks',
            ])
        >
            {{ __('Webhook URLs') }}
        </button>
    </div>

    {{-- ===================== Twilio Accounts Tab ===================== --}}
    @if ($activeTab === 'accounts')
        {{-- Sync Results Banner --}}
        @if ($syncResults && $syncingAccountId)
            <div class="mb-4">
                @if (isset($syncResults['error']))
                    <flux:callout color="red" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Sync Failed') }}</flux:callout.heading>
                        <flux:callout.text>{{ $syncResults['error'] }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout color="green" icon="check-circle">
                        <flux:callout.heading>{{ __('Sync Complete') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __(':created created, :updated updated, :removed removed', [
                                'created' => $syncResults['created'] ?? 0,
                                'updated' => $syncResults['updated'] ?? 0,
                                'removed' => $syncResults['removed'] ?? 0,
                            ]) }}
                        </flux:callout.text>
                    </flux:callout>
                @endif
                <div class="mt-2">
                    <flux:button size="sm" variant="ghost" wire:click="dismissSyncResults">{{ __('Dismiss') }}</flux:button>
                </div>
            </div>
        @endif

        {{-- Test Connection Result Banner --}}
        @if ($testResult && $testingAccountId)
            <div class="mb-4">
                @if ($testResult['success'] ?? false)
                    <flux:callout color="green" icon="check-circle">
                        <flux:callout.heading>{{ __('Connection Successful') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('Account ":name" is :status.', [
                                'name' => $testResult['friendly_name'] ?? 'Unknown',
                                'status' => $testResult['status'] ?? 'active',
                            ]) }}
                        </flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout color="red" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Connection Failed') }}</flux:callout.heading>
                        <flux:callout.text>{{ $testResult['error'] ?? __('Unknown error') }}</flux:callout.text>
                    </flux:callout>
                @endif
                <div class="mt-2">
                    <flux:button size="sm" variant="ghost" wire:click="dismissTestResult">{{ __('Dismiss') }}</flux:button>
                </div>
            </div>
        @endif

        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openAddModal" wire:loading.attr="disabled">
                {{ __('Add Account') }}
            </flux:button>
        </div>

        @if ($this->accounts->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="phone" class="mx-auto mb-3 size-12 text-zinc-400" />
                <flux:heading size="lg">{{ __('No Twilio accounts connected') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add a Twilio account to start managing phone numbers.') }}</flux:text>
                <div class="mt-4">
                    <flux:button variant="primary" icon="plus" wire:click="openAddModal" wire:loading.attr="disabled">
                        {{ __('Add Account') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($this->accounts as $account)
                    <div
                        wire:key="acct-{{ $account->id }}"
                        @class([
                            'flex items-center justify-between rounded-lg border p-4 bg-white dark:bg-zinc-800',
                            'border-zinc-200 dark:border-zinc-700' => $account->is_active,
                            'border-zinc-200 dark:border-zinc-700 opacity-50' => ! $account->is_active,
                        ])
                    >
                        <div class="flex items-center gap-4 min-w-0 flex-1">
                            <div class="shrink-0 flex size-10 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">
                                <flux:icon name="server" variant="mini" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium truncate">{{ $account->name }}</span>
                                    @if ($account->is_primary)
                                        <flux:badge size="sm" color="green">{{ __('Primary') }}</flux:badge>
                                    @endif
                                    @if (! $account->is_active)
                                        <flux:badge size="sm" color="amber">{{ __('Inactive') }}</flux:badge>
                                    @endif
                                </div>

                                <div class="mt-1 flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                                    <span class="flex items-center gap-1">
                                        <flux:icon name="key" variant="micro" />
                                        {{ $account->getMaskedSid() }}
                                    </span>

                                    <span class="flex items-center gap-1">
                                        <flux:icon name="phone" variant="micro" />
                                        {{ $account->phone_numbers_count }} {{ __('numbers') }}
                                    </span>

                                    @if ($account->last_synced_at)
                                        <span class="flex items-center gap-1">
                                            <flux:icon name="arrow-path" variant="micro" />
                                            {{ __('Synced') }} {{ $account->last_synced_at->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="flex items-center gap-1 text-amber-500">
                                            <flux:icon name="exclamation-triangle" variant="micro" />
                                            {{ __('Never synced') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-1 shrink-0">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="signal"
                                wire:click="testConnection({{ $account->id }})"
                                wire:loading.attr="disabled"
                                wire:target="testConnection({{ $account->id }})"
                                title="{{ __('Test Connection') }}"
                            >
                                <span wire:loading.remove wire:target="testConnection({{ $account->id }})">{{ __('Test') }}</span>
                                <span wire:loading wire:target="testConnection({{ $account->id }})">{{ __('Testing...') }}</span>
                            </flux:button>

                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-path"
                                wire:click="syncNumbers({{ $account->id }})"
                                wire:loading.attr="disabled"
                                wire:target="syncNumbers({{ $account->id }})"
                                title="{{ __('Sync Numbers') }}"
                            >
                                <span wire:loading.remove wire:target="syncNumbers({{ $account->id }})">{{ __('Sync') }}</span>
                                <span wire:loading wire:target="syncNumbers({{ $account->id }})">{{ __('Syncing...') }}</span>
                            </flux:button>

                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $account->id }})" wire:loading.attr="disabled" title="{{ __('Edit') }}" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $account->id }})" wire:loading.attr="disabled" title="{{ __('Delete') }}" />

                            <flux:switch
                                wire:click="toggleActive({{ $account->id }})"
                                :checked="$account->is_active"
                                size="sm"
                            />
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ===================== A2P Compliance Tab ===================== --}}
    @if ($activeTab === 'compliance')
        <div class="mb-4 flex items-center justify-between">
            <div>
                <flux:text>{{ __('Manage A2P 10DLC brand and campaign registrations for SMS compliance.') }}</flux:text>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateServiceModal" wire:loading.attr="disabled">
                {{ __('Create Messaging Service') }}
            </flux:button>
        </div>

        @if ($this->messagingServices->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="shield-check" class="mx-auto mb-3 size-12 text-zinc-400" />
                <flux:heading size="lg">{{ __('No messaging services') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Create a Twilio Messaging Service to start A2P 10DLC registration.') }}</flux:text>
                <div class="mt-4">
                    <flux:button variant="primary" icon="plus" wire:click="openCreateServiceModal" wire:loading.attr="disabled">
                        {{ __('Create Messaging Service') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($this->messagingServices as $ms)
                    <div wire:key="ms-{{ $ms->id }}" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                <div class="shrink-0 flex size-10 items-center justify-center rounded-lg bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">
                                    <flux:icon name="shield-check" variant="mini" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-medium truncate">{{ $ms->friendly_name }}</span>
                                        @if ($ms->isFullyCompliant())
                                            <flux:badge size="sm" color="green">{{ __('Compliant') }}</flux:badge>
                                        @endif
                                    </div>

                                    <div class="mt-1 flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                                        @if ($ms->twilioAccount)
                                            <span class="flex items-center gap-1">
                                                <flux:icon name="server" variant="micro" />
                                                {{ $ms->twilioAccount->name }}
                                            </span>
                                        @endif

                                        @if ($ms->company)
                                            <span class="flex items-center gap-1">
                                                <flux:icon name="building-office" variant="micro" />
                                                {{ $ms->company->name }}
                                            </span>
                                        @endif

                                        <span class="flex items-center gap-1">
                                            <flux:icon name="phone" variant="micro" />
                                            {{ $ms->phone_number_count }} {{ __('numbers') }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                {{-- Brand Status --}}
                                <div class="text-center">
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Brand') }}</div>
                                    <flux:badge size="sm" :color="$ms->getBrandStatusColor()">
                                        {{ $ms->brand_status ?? __('Not Started') }}
                                    </flux:badge>
                                </div>

                                {{-- Campaign Status --}}
                                <div class="text-center">
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Campaign') }}</div>
                                    <flux:badge size="sm" :color="$ms->getCampaignStatusColor()">
                                        {{ $ms->campaign_status ?? __('Not Started') }}
                                    </flux:badge>
                                </div>

                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-path"
                                    wire:click="refreshComplianceStatus({{ $ms->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshComplianceStatus({{ $ms->id }})"
                                    title="{{ __('Refresh Status') }}"
                                >
                                    <span wire:loading.remove wire:target="refreshComplianceStatus({{ $ms->id }})">{{ __('Refresh') }}</span>
                                    <span wire:loading wire:target="refreshComplianceStatus({{ $ms->id }})">{{ __('Refreshing...') }}</span>
                                </flux:button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ===================== Webhook URLs Tab ===================== --}}
    @if ($activeTab === 'webhooks')
        <flux:text class="mb-4">{{ __('These are the endpoints Twilio numbers are configured to send requests to. Webhooks are auto-configured when numbers are assigned.') }}</flux:text>

        <div class="space-y-2 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center gap-3">
                <flux:badge size="sm" color="blue">{{ __('Voice') }}</flux:badge>
                <code class="text-xs text-zinc-600 dark:text-zinc-300 break-all">{{ $this->webhookUrls['voiceUrl'] }}</code>
                <flux:badge size="sm" color="zinc">{{ $this->webhookUrls['voiceMethod'] }}</flux:badge>
            </div>

            <div class="flex items-center gap-3">
                <flux:badge size="sm" color="green">{{ __('SMS') }}</flux:badge>
                <code class="text-xs text-zinc-600 dark:text-zinc-300 break-all">{{ $this->webhookUrls['smsUrl'] }}</code>
                <flux:badge size="sm" color="zinc">{{ $this->webhookUrls['smsMethod'] }}</flux:badge>
            </div>

            <div class="flex items-center gap-3">
                <flux:badge size="sm" color="purple">{{ __('Status') }}</flux:badge>
                <code class="text-xs text-zinc-600 dark:text-zinc-300 break-all">{{ $this->webhookUrls['statusCallback'] }}</code>
                <flux:badge size="sm" color="zinc">{{ $this->webhookUrls['statusCallbackMethod'] }}</flux:badge>
            </div>
        </div>
    @endif

    {{-- Add/Edit Modal --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[420px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Account') : __('Add Twilio Account') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingId
                        ? __('Update the account details. Leave SID and token blank to keep existing values.')
                        : __('Enter your Twilio account credentials. These are encrypted at rest.')
                    }}
                </flux:text>
            </div>

            <flux:input
                wire:model="name"
                :label="__('Account Name')"
                :placeholder="__('e.g., Main Account, Denver Office')"
                required
            />

            <flux:input
                wire:model="accountSid"
                :label="__('Account SID')"
                :placeholder="$editingId ? __('Leave blank to keep current') : __('ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')"
                :required="! $editingId"
            />

            <flux:input
                wire:model="authToken"
                type="password"
                :label="__('Auth Token')"
                :placeholder="$editingId ? __('Leave blank to keep current') : __('Your Twilio auth token')"
                :required="! $editingId"
            />

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingId ? __('Update Account') : __('Add Account') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Account') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingName }}</strong>{{ __('? This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="delete" class="flex-1" wire:loading.attr="disabled" wire:target="delete">
                    <span wire:loading.remove wire:target="delete">{{ __('Delete') }}</span>
                    <span wire:loading wire:target="delete">{{ __('Deleting...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Create Messaging Service Modal --}}
    <flux:modal wire:model="showCreateServiceModal" variant="flyout" class="w-[420px]">
        <form wire:submit="createMessagingService" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create Messaging Service') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Create a Twilio Messaging Service for A2P 10DLC registration.') }}</flux:text>
            </div>

            <flux:input
                wire:model="serviceName"
                :label="__('Service Name')"
                :placeholder="__('e.g., Denver Painting Outreach')"
                required
            />

            <div>
                <flux:label>{{ __('Twilio Account') }}</flux:label>
                <select wire:model="serviceAccountId" class="mt-1 block w-full rounded-md border-zinc-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 sm:text-sm" required>
                    <option value="">{{ __('Select account...') }}</option>
                    @foreach ($this->accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <flux:label>{{ __('Company (optional)') }}</flux:label>
                <select wire:model="serviceCompanyId" class="mt-1 block w-full rounded-md border-zinc-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 sm:text-sm">
                    <option value="">{{ __('No company') }}</option>
                    @foreach ($this->companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeCreateServiceModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1" wire:loading.attr="disabled" wire:target="createMessagingService">
                    <span wire:loading.remove wire:target="createMessagingService">{{ __('Create Service') }}</span>
                    <span wire:loading wire:target="createMessagingService">{{ __('Creating...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
