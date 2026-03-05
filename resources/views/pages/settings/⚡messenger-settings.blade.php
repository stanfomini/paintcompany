<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerSetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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

    // Default Settings
    public ?int $defaultAssignedTo = null;

    // Widget Settings
    public bool $websiteWidgetEnabled = false;
    public string $widgetGreetingLoggedIn = '';
    public string $widgetGreetingLoggedOut = '';
    public string $widgetThemeColor = '#0084ff';

    // Bot Flow Management
    public array $flows = [];
    public ?int $editingFlowId = null;
    public bool $showFlowEditor = false;

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
        $this->defaultAssignedTo = $settings->default_assigned_to;
        $this->websiteWidgetEnabled = (bool) $settings->website_widget_enabled;
        $this->widgetGreetingLoggedIn = $settings->widget_greeting_logged_in ?? '';
        $this->widgetGreetingLoggedOut = $settings->widget_greeting_logged_out ?? '';
        $this->widgetThemeColor = $settings->widget_theme_color ?? '#0084ff';

        $this->loadFlows();
    }

    #[Computed]
    public function webhookUrl(): string
    {
        return MessengerSetting::getInstance()->getWebhookUrl();
    }

    #[Computed]
    public function isConnected(): bool
    {
        return MessengerSetting::getInstance()->is_connected;
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

    public function saveDefaultSettings(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $validated = $this->validate([
            'defaultAssignedTo' => ['nullable', 'exists:users,id'],
        ]);

        $settings = MessengerSetting::getInstance();
        $settings->update([
            'default_assigned_to' => $validated['defaultAssignedTo'] ?: null,
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

    public function createFlow(): void
    {
        $this->editingFlowId = null;
        $this->showFlowEditor = true;
    }

    public function editFlow(int $flowId): void
    {
        $this->editingFlowId = $flowId;
        $this->showFlowEditor = true;
    }

    public function closeFlowEditor(): void
    {
        $this->showFlowEditor = false;
        $this->editingFlowId = null;
    }

    public function deleteFlow(int $flowId): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        MessengerBotFlow::findOrFail($flowId)->delete();
        $this->loadFlows();
    }

    #[On('flow-saved')]
    public function refreshFlows(): void
    {
        $this->loadFlows();
    }

    protected function loadFlows(): void
    {
        $this->flows = MessengerBotFlow::withCount('steps')
            ->orderByDesc('updated_at')
            ->get()
            ->toArray();
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

        {{-- Default Settings --}}
        <form wire:submit="saveDefaultSettings" class="my-6 w-full space-y-6">
            <flux:heading size="sm">{{ __('Default Settings') }}</flux:heading>

            <flux:select wire:model="defaultAssignedTo" :label="__('Default Assigned User')" placeholder="Select a user...">
                <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                @foreach ($this->internalUsers as $user)
                    <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">{{ __('Save Default Settings') }}</flux:button>
                <x-action-message class="me-3" on="messenger-settings-saved">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

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
                <flux:button variant="primary" size="sm" wire:click="createFlow" icon="plus">
                    {{ __('Create Flow') }}
                </flux:button>
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
                                <flux:button size="sm" variant="ghost" wire:click="editFlow({{ $flow['id'] }})" icon="pencil-square" />
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

            {{-- Flow Editor Modal --}}
            <flux:modal name="flow-editor" variant="flyout" class="max-w-xl" wire:model="showFlowEditor">
                @if ($showFlowEditor)
                    <livewire:settings.bot-flow-editor :flow-id="$editingFlowId" :key="'flow-editor-' . ($editingFlowId ?? 'new')" />
                @endif
            </flux:modal>
        </div>

    </x-pages::settings.layout>
</section>
