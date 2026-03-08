<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerResponder;
use App\Models\MessengerSetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('messenger.manage')) {
            abort(403);
        }
    }

    // Connection Settings
    public string $appId = '';
    public string $pageId = '';
    public string $pageAccessToken = '';
    public string $appSecret = '';
    public string $verifyToken = '';

    // Responder Roster
    public array $responders = [];
    public ?int $newResponderId = null;
    public string $newResponderRole = 'estimator';

    // Widget Settings
    public bool $websiteWidgetEnabled = false;
    public string $widgetGreetingLoggedIn = '';
    public string $widgetGreetingLoggedOut = '';
    public string $widgetThemeColor = '#0084ff';

    // Bot Flow Management
    public array $flows = [];

    // Ad Routing
    public string $adAccountId = '';
    public array $adMappings = [];
    public bool $syncing = false;

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $settings = MessengerSetting::getInstance();

        $this->appId = $settings->app_id ?? '';
        $this->pageId = $settings->page_id ?? '';
        $this->pageAccessToken = $settings->page_access_token ?? '';
        $this->appSecret = $settings->app_secret ?? '';
        $this->verifyToken = $settings->verify_token ?? '';
        $this->websiteWidgetEnabled = (bool) $settings->website_widget_enabled;
        $this->widgetGreetingLoggedIn = $settings->widget_greeting_logged_in ?? '';
        $this->widgetGreetingLoggedOut = $settings->widget_greeting_logged_out ?? '';
        $this->widgetThemeColor = $settings->widget_theme_color ?? '#0084ff';

        $this->adAccountId = $settings->ad_account_id ?? '';

        $this->loadResponders();
        $this->loadFlows();
        $this->loadAdMappings();
    }

    #[Computed]
    public function webhookUrl(): string
    {
        return MessengerSetting::getInstance()->getWebhookUrl();
    }

    #[Computed]
    public function isConnected(): bool
    {
        return (bool) MessengerSetting::getInstance()->is_connected;
    }

    #[Computed]
    public function isConfigured(): bool
    {
        return MessengerSetting::getInstance()->isConfigured();
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::where('type', 'internal')->orderBy('name')->get();
    }

    #[Computed]
    public function availableUsers(): \Illuminate\Database\Eloquent\Collection
    {
        $existingUserIds = MessengerResponder::pluck('user_id');

        return User::where('type', 'internal')
            ->whereNotIn('id', $existingUserIds)
            ->orderBy('name')
            ->get();
    }

    public function loadResponders(): void
    {
        $this->responders = MessengerResponder::query()
            ->with('user')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }

    public function addResponder(): void
    {
        $this->validate([
            'newResponderId' => ['required', 'exists:users,id'],
            'newResponderRole' => ['required', 'in:appointment_booker,estimator,both'],
        ]);

        $user = User::findOrFail($this->newResponderId);

        if ($user->type !== 'internal') {
            $this->addError('newResponderId', 'Only internal users can be responders.');
            return;
        }

        if (MessengerResponder::where('user_id', $user->id)->exists()) {
            $this->addError('newResponderId', 'This user is already a responder.');
            return;
        }

        $maxOrder = MessengerResponder::max('sort_order') ?? 0;

        MessengerResponder::create([
            'user_id' => $user->id,
            'role' => $this->newResponderRole,
            'is_active' => true,
            'sort_order' => $maxOrder + 1,
        ]);

        $this->newResponderId = null;
        $this->newResponderRole = 'estimator';
        $this->loadResponders();
        unset($this->availableUsers);
        $this->dispatch('messenger-settings-saved');
    }

    public function removeResponder(int $id): void
    {
        MessengerResponder::findOrFail($id)->delete();
        $this->loadResponders();
        unset($this->availableUsers);
        $this->dispatch('messenger-settings-saved');
    }

    public function updateResponderRole(int $id, string $role): void
    {
        if (! in_array($role, ['appointment_booker', 'estimator', 'both'])) {
            return;
        }

        MessengerResponder::findOrFail($id)->update(['role' => $role]);
        $this->loadResponders();
        $this->dispatch('messenger-settings-saved');
    }

    public function toggleResponderActive(int $id): void
    {
        $responder = MessengerResponder::findOrFail($id);
        $responder->update(['is_active' => ! $responder->is_active]);
        $this->loadResponders();
        $this->dispatch('messenger-settings-saved');
    }

    public function saveConnectionSettings(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $validated = $this->validate([
            'appId' => ['nullable', 'string', 'max:255'],
            'pageId' => ['nullable', 'string', 'max:255'],
            'pageAccessToken' => ['nullable', 'string', 'max:1000'],
            'appSecret' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = MessengerSetting::getInstance();
        $settings->update([
            'app_id' => $validated['appId'] ?: null,
            'page_id' => $validated['pageId'] ?: null,
            'page_access_token' => $validated['pageAccessToken'] ?: null,
            'app_secret' => $validated['appSecret'] ?: null,
        ]);

        $this->dispatch('messenger-settings-saved');
    }

    public function saveWidgetSettings(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $validated = $this->validate([
            'websiteWidgetEnabled' => ['boolean'],
            'widgetGreetingLoggedIn' => ['nullable', 'string', 'max:500'],
            'widgetGreetingLoggedOut' => ['nullable', 'string', 'max:500'],
            'widgetThemeColor' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $settings = MessengerSetting::getInstance();
        $settings->update([
            'website_widget_enabled' => $validated['websiteWidgetEnabled'],
            'widget_greeting_logged_in' => $validated['widgetGreetingLoggedIn'] ?: null,
            'widget_greeting_logged_out' => $validated['widgetGreetingLoggedOut'] ?: null,
            'widget_theme_color' => $validated['widgetThemeColor'],
        ]);

        $this->dispatch('messenger-settings-saved');
    }

    public function regenerateVerifyToken(): void
    {
        $settings = MessengerSetting::getInstance();
        $settings->update([
            'verify_token' => MessengerSetting::generateVerifyToken(),
        ]);

        $this->verifyToken = $settings->fresh()->verify_token;
        $this->dispatch('messenger-settings-saved');
    }

    public function deleteFlow(int $flowId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        MessengerBotFlow::findOrFail($flowId)->delete();
        $this->loadFlows();
    }

    protected function loadFlows(): void
    {
        $this->flows = MessengerBotFlow::withCount('steps')
            ->orderByDesc('updated_at')
            ->get()
            ->toArray();
    }

    public function loadAdMappings(): void
    {
        $this->adMappings = \App\Models\MessengerAdFlowMapping::query()
            ->orderBy('facebook_campaign_name')
            ->orderBy('facebook_ad_name')
            ->get()
            ->toArray();
    }

    public function saveAdAccountId(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'adAccountId' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = MessengerSetting::getInstance();
        $settings->update([
            'ad_account_id' => $this->adAccountId ?: null,
        ]);

        $this->dispatch('messenger-settings-saved');
    }

    public function syncAds(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $service = app(\App\Services\FacebookMarketingService::class);
        $count = $service->syncAds();

        $this->loadAdMappings();
        $this->dispatch('messenger-settings-saved');
        session()->flash('ad-sync-message', __(':count ads synced from Facebook.', ['count' => $count]));
    }

    public function updateAdFlowMapping(int $mappingId, string $flowId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $mapping = \App\Models\MessengerAdFlowMapping::findOrFail($mappingId);

        if ($flowId === '') {
            $mapping->update(['flow_id' => null]);
        } else {
            $mapping->update(['flow_id' => (int) $flowId]);
        }

        $this->loadAdMappings();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Messenger Settings')" :subheading="__('Configure Facebook Messenger integration and website widget')">

        {{-- Connection Status --}}
        <div class="mb-6 flex items-center gap-3">
            <div class="flex items-center gap-2">
                @if ($this->isConnected)
                    <div class="h-3 w-3 rounded-full bg-green-500"></div>
                    <flux:text class="text-green-700 dark:text-green-400">{{ __('Connected') }}</flux:text>
                @elseif ($this->isConfigured)
                    <div class="h-3 w-3 rounded-full bg-yellow-500"></div>
                    <flux:text class="text-yellow-700 dark:text-yellow-400">{{ __('Configured (not verified)') }}</flux:text>
                @else
                    <div class="h-3 w-3 rounded-full bg-zinc-400 dark:bg-zinc-600"></div>
                    <flux:text class="text-zinc-500">{{ __('Not configured') }}</flux:text>
                @endif
            </div>
        </div>

        {{-- Flash Messages --}}
        @if (session('success'))
            <flux:callout variant="success" class="mb-4">{{ session('success') }}</flux:callout>
        @endif
        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">{{ session('error') }}</flux:callout>
        @endif

        {{-- Quick Connect --}}
        <div class="my-6 w-full space-y-4">
            <flux:heading size="sm">{{ __('Quick Connect') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">
                {{ __('Enter your App ID and App Secret below, save, then click "Connect with Facebook" to automatically set up your Page Access Token and webhook subscription.') }}
            </flux:text>

            @if ($this->isConnected)
                <flux:callout variant="success">
                    {{ __('Connected and ready to receive messages.') }}
                </flux:callout>
            @endif

            <flux:button variant="primary" href="/settings/messenger/connect" icon="link">
                {{ __('Connect with Facebook') }}
            </flux:button>
        </div>

        <flux:separator />

        {{-- Connection Settings --}}
        <form wire:submit="saveConnectionSettings" class="my-6 w-full space-y-6">
            <flux:heading size="sm">{{ __('Facebook App Credentials') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">{{ __('These come from your Facebook App Dashboard at developers.facebook.com.') }}</flux:text>

            <flux:input wire:model="appId" :label="__('App ID')" type="text" placeholder="2005322070020060" />

            <flux:input wire:model="appSecret" :label="__('App Secret')" type="password" placeholder="Enter app secret..." />

            <flux:separator />

            <flux:heading size="sm">{{ __('Manual Override (optional)') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">{{ __('These are set automatically when you use Connect with Facebook. Only edit manually if needed.') }}</flux:text>

            <flux:input wire:model="pageId" :label="__('Facebook Page ID')" type="text" placeholder="123456789012345" />

            <flux:textarea wire:model="pageAccessToken" :label="__('Page Access Token')" rows="3" placeholder="EAABsbCS..." />

            <flux:separator />

            {{-- Verify Token (read-only) --}}
            <div>
                <flux:heading size="sm">{{ __('Webhook Configuration') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500">{{ __('Use these values when configuring the webhook in your Facebook App settings.') }}</flux:text>
            </div>

            <div class="space-y-4">
                <div>
                    <flux:text class="mb-1 text-sm font-medium">{{ __('Verify Token') }}</flux:text>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">{{ $verifyToken }}</code>
                        <flux:button size="sm" variant="ghost" x-on:click="navigator.clipboard.writeText('{{ $verifyToken }}'); $flux.toast('Copied!')">
                            {{ __('Copy') }}
                        </flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="regenerateVerifyToken" wire:confirm="Are you sure you want to regenerate the verify token? You will need to update it in Facebook.">
                            {{ __('Regenerate') }}
                        </flux:button>
                    </div>
                </div>

                <div>
                    <flux:text class="mb-1 text-sm font-medium">{{ __('Webhook URL') }}</flux:text>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">{{ $this->webhookUrl }}</code>
                        <flux:button size="sm" variant="ghost" x-on:click="navigator.clipboard.writeText('{{ $this->webhookUrl }}'); $flux.toast('Copied!')">
                            {{ __('Copy') }}
                        </flux:button>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">{{ __('Save Connection Settings') }}</flux:button>
                <x-action-message class="me-3" on="messenger-settings-saved">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <flux:separator />

        {{-- Messenger Responders Roster --}}
        <div class="my-6 w-full space-y-6">
            <div>
                <flux:heading size="sm">{{ __('Messenger Responders') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">
                    {{ __('Employees who receive and answer Facebook Messenger leads. Estimators are assigned leads in round-robin order.') }}
                </flux:text>
            </div>

            {{-- Add Responder Form --}}
            <form wire:submit="addResponder" class="flex items-end gap-3">
                <div class="flex-1">
                    <flux:select wire:model="newResponderId" :label="__('Employee')" placeholder="Select an employee...">
                        <flux:select.option value="">{{ __('Select an employee...') }}</flux:select.option>
                        @foreach ($this->availableUsers as $user)
                            <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="w-48">
                    <flux:select wire:model="newResponderRole" :label="__('Role')">
                        <flux:select.option value="estimator">{{ __('Estimator') }}</flux:select.option>
                        <flux:select.option value="appointment_booker">{{ __('Appt Booker') }}</flux:select.option>
                        <flux:select.option value="both">{{ __('Both') }}</flux:select.option>
                    </flux:select>
                </div>
                <flux:button type="submit" variant="primary" icon="plus">
                    {{ __('Add') }}
                </flux:button>
            </form>

            @error('newResponderId')
                <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
            @enderror

            {{-- Responder List --}}
            @if (count($responders) > 0)
                <div class="space-y-2">
                    @foreach ($responders as $responder)
                        <div wire:key="responder-{{ $responder['id'] }}" class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-2">
                                    @if ($responder['is_active'])
                                        <div class="h-2.5 w-2.5 rounded-full bg-green-500"></div>
                                    @else
                                        <div class="h-2.5 w-2.5 rounded-full bg-zinc-400"></div>
                                    @endif
                                    <flux:text class="font-medium text-sm">{{ $responder['user']['name'] ?? 'Unknown' }}</flux:text>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <flux:select
                                    size="sm"
                                    wire:change="updateResponderRole({{ $responder['id'] }}, $event.target.value)"
                                    class="w-40"
                                >
                                    <flux:select.option value="estimator" :selected="$responder['role'] === 'estimator'">{{ __('Estimator') }}</flux:select.option>
                                    <flux:select.option value="appointment_booker" :selected="$responder['role'] === 'appointment_booker'">{{ __('Appt Booker') }}</flux:select.option>
                                    <flux:select.option value="both" :selected="$responder['role'] === 'both'">{{ __('Both') }}</flux:select.option>
                                </flux:select>
                                <flux:switch
                                    wire:click="toggleResponderActive({{ $responder['id'] }})"
                                    :checked="$responder['is_active']"
                                />
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="removeResponder({{ $responder['id'] }})"
                                    wire:confirm="{{ __('Remove this responder?') }}"
                                    icon="x-mark"
                                    class="text-red-500 hover:text-red-700"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-lg border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-600">
                    <flux:text class="text-sm text-zinc-500">{{ __('No responders added yet. Add employees to receive Messenger leads.') }}</flux:text>
                </div>
            @endif

            <x-action-message class="me-3" on="messenger-settings-saved">
                {{ __('Saved.') }}
            </x-action-message>
        </div>

        <flux:separator />

        {{-- Widget Settings --}}
        <form wire:submit="saveWidgetSettings" class="my-6 w-full space-y-6">
            <flux:heading size="sm">{{ __('Website Widget') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">{{ __('Configure the Messenger chat widget on your website.') }}</flux:text>

            <flux:switch wire:model="websiteWidgetEnabled" :label="__('Enable Website Widget')" />

            <flux:textarea wire:model="widgetGreetingLoggedIn" :label="__('Greeting (Logged-in Users)')" rows="2" :placeholder="__('Hi! How can we help you today?')" />

            <flux:textarea wire:model="widgetGreetingLoggedOut" :label="__('Greeting (Visitors)')" rows="2" :placeholder="__('Welcome! Send us a message to get started.')" />

            <flux:input wire:model="widgetThemeColor" :label="__('Widget Theme Color')" type="color" />

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">{{ __('Save Widget Settings') }}</flux:button>
                <x-action-message class="me-3" on="messenger-settings-saved">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <flux:separator />

        {{-- Bot Flows --}}
        <div class="my-6 w-full space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="sm">{{ __('Bot Flows') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">{{ __('Define automated conversation flows for lead qualification.') }}</flux:text>
                </div>
                <a href="{{ route('messenger.flows.create') }}" wire:navigate>
                    <flux:button variant="primary" size="sm" icon="plus">
                        {{ __('Create Flow') }}
                    </flux:button>
                </a>
            </div>

            @if (count($flows) > 0)
                <div class="space-y-3">
                    @foreach ($flows as $flow)
                        <div wire:key="flow-{{ $flow['id'] }}" class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="flex items-center gap-3">
                                <div>
                                    <div class="font-medium text-sm">{{ $flow['name'] }}</div>
                                    <flux:text class="text-xs text-zinc-500">
                                        {{ $flow['steps_count'] ?? 0 }} {{ __('steps') }}
                                        @if ($flow['business_hours_only'])
                                            &middot; {{ __('Business hours only') }}
                                        @endif
                                    </flux:text>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:badge size="sm" :color="match($flow['status']) { 'active' => 'green', 'inactive' => 'red', default => 'zinc' }">
                                    {{ ucfirst($flow['status']) }}
                                </flux:badge>
                                <a href="{{ route('messenger.flows.edit', $flow['id']) }}" wire:navigate>
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" />
                                </a>
                                <flux:button size="sm" variant="ghost" wire:click="deleteFlow({{ $flow['id'] }})" wire:confirm="{{ __('Are you sure you want to delete this flow?') }}" icon="trash" class="text-red-500 hover:text-red-700" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-lg border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-600">
                    <flux:text class="text-sm text-zinc-500">{{ __('No bot flows created yet. Create one to automate lead qualification.') }}</flux:text>
                </div>
            @endif

            {{-- Flow editor is now a full-page component at /settings/messenger/flows --}}
        </div>

        <flux:separator />

        {{-- Ad Routing --}}
        <div class="my-6 w-full space-y-6">
            <div>
                <flux:heading size="sm">{{ __('Ad Routing') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">
                    {{ __('Assign different bot flows to different Facebook ads. Ads without an assignment use the default active flow.') }}
                </flux:text>
            </div>

            {{-- Ad Account ID --}}
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <flux:input wire:model="adAccountId" :label="__('Ad Account ID')" placeholder="act_123456789"
                        :description="__('Found in Facebook Ads Manager under Account Settings.')" />
                </div>
                <flux:button wire:click="saveAdAccountId" variant="primary">{{ __('Save') }}</flux:button>
                @if ($adAccountId)
                    <flux:button wire:click="syncAds" wire:loading.attr="disabled" wire:target="syncAds">
                        <span wire:loading.remove wire:target="syncAds">{{ __('Sync Ads') }}</span>
                        <span wire:loading wire:target="syncAds">{{ __('Syncing...') }}</span>
                    </flux:button>
                @endif
            </div>

            @if (session('ad-sync-message'))
                <flux:callout variant="success">{{ session('ad-sync-message') }}</flux:callout>
            @endif

            <x-action-message class="me-3" on="messenger-settings-saved">
                {{ __('Saved.') }}
            </x-action-message>

            {{-- Ad list grouped by campaign --}}
            @if (count($adMappings) > 0)
                @php
                    $grouped = collect($adMappings)->groupBy('facebook_campaign_name');
                @endphp

                <div class="space-y-4">
                    @foreach ($grouped as $campaignName => $ads)
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                            <div class="bg-zinc-50 dark:bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ $campaignName ?: __('Unknown Campaign') }}
                            </div>
                            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($ads as $mapping)
                                    <div wire:key="ad-{{ $mapping['id'] }}" class="flex items-center justify-between px-4 py-3">
                                        <div class="flex items-center gap-3 min-w-0 flex-1">
                                            <flux:badge size="sm" :color="$mapping['facebook_ad_status'] === 'ACTIVE' ? 'green' : 'zinc'">
                                                {{ $mapping['facebook_ad_status'] ?? 'UNKNOWN' }}
                                            </flux:badge>
                                            <div class="min-w-0">
                                                <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate">
                                                    {{ $mapping['facebook_ad_name'] ?? $mapping['facebook_ad_id'] }}
                                                </div>
                                                @if ($mapping['facebook_adset_name'])
                                                    <div class="text-xs text-zinc-400 truncate">{{ $mapping['facebook_adset_name'] }}</div>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="w-52 shrink-0">
                                            <flux:select
                                                size="sm"
                                                wire:change="updateAdFlowMapping({{ $mapping['id'] }}, $event.target.value)"
                                            >
                                                <flux:select.option value="" :selected="empty($mapping['flow_id'])">{{ __('Default (active flow)') }}</flux:select.option>
                                                @foreach ($flows as $flow)
                                                    <flux:select.option value="{{ $flow['id'] }}" :selected="($mapping['flow_id'] ?? null) == $flow['id']">
                                                        {{ $flow['name'] }}
                                                    </flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (($lastSync = collect($adMappings)->max('last_synced_at')))
                    <flux:text size="xs" class="text-zinc-400">
                        {{ __('Last synced: :time', ['time' => \Carbon\Carbon::parse($lastSync)->diffForHumans()]) }}
                    </flux:text>
                @endif
            @elseif ($adAccountId)
                <div class="rounded-lg border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-600">
                    <flux:text class="text-sm text-zinc-500">{{ __('No ads synced yet. Click "Sync Ads" to pull your ads from Facebook.') }}</flux:text>
                </div>
            @endif
        </div>

    </x-pages::settings.layout>
</section>
