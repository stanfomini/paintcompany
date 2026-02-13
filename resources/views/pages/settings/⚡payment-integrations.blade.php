<?php

use App\Models\CompanySetting;
use App\Models\PaymentSplit;
use App\Services\IncreaseService;
use App\Services\PaymentSplitService;
use App\Services\StripePaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.payment_integrations')) {
            abort(403);
        }
    }

    public bool $stripe_connect_enabled = false;
    public bool $stripe_ach_enabled = false;
    public bool $increase_enabled = false;
    public string $increase_environment = 'sandbox';
    public bool $payment_split_enabled = false;
    public array $split_destinations = [];

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $settings = CompanySetting::getInstance();

        $this->stripe_connect_enabled = (bool) $settings->stripe_connect_enabled;
        $this->stripe_ach_enabled = (bool) $settings->stripe_ach_enabled;
        $this->increase_enabled = (bool) $settings->increase_enabled;
        $this->increase_environment = $settings->increase_environment ?? 'sandbox';
        $this->payment_split_enabled = (bool) $settings->payment_split_enabled;
        $this->split_destinations = $settings->payment_split_config ?? [];
    }

    public function saveSplitConfig(): void
    {
        if (! empty($this->split_destinations)) {
            foreach ($this->split_destinations as $index => $dest) {
                $this->validate([
                    "split_destinations.{$index}.name" => ['required', 'string', 'max:255'],
                    "split_destinations.{$index}.percentage" => ['required', 'numeric', 'gt:0', 'max:100'],
                ]);
            }

            $total = array_sum(array_column($this->split_destinations, 'percentage'));
            if (abs($total - 100) > 0.01) {
                $this->addError('split_destinations', 'Percentages must total 100%. Current total: ' . number_format($total, 2) . '%');

                return;
            }
        }

        $settings = CompanySetting::getInstance();
        $settings->update([
            'payment_split_config' => $this->split_destinations,
            'payment_split_enabled' => $this->payment_split_enabled,
        ]);

        $this->dispatch('split-config-saved');
    }

    public function saveStripeSettings(): void
    {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'stripe_connect_enabled' => $this->stripe_connect_enabled,
            'stripe_ach_enabled' => $this->stripe_ach_enabled,
        ]);

        $this->dispatch('stripe-settings-saved');
    }

    public function saveIncreaseSettings(): void
    {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'increase_enabled' => $this->increase_enabled,
            'increase_environment' => $this->increase_environment,
        ]);

        $this->dispatch('increase-settings-saved');
    }

    public function addDestination(): void
    {
        $this->split_destinations[] = [
            'name' => '',
            'percentage' => '',
            'stripe_account_id' => null,
            'increase_account_id' => null,
        ];
    }

    public function removeDestination(int $index): void
    {
        array_splice($this->split_destinations, $index, 1);
    }

    public function connectIncrease(): void
    {
        try {
            $state = bin2hex(random_bytes(32));
            $settings = CompanySetting::getInstance();
            $settings->update(['increase_oauth_state' => $state]);

            $increaseService = app(IncreaseService::class);
            $url = $increaseService->getAuthorizationUrl(
                route('increase.oauth.callback'),
                $state,
            );

            $this->redirect($url);
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to connect with Increase: :error', ['error' => $e->getMessage()]));
        }
    }

    public function disconnectIncrease(): void
    {
        $settings = CompanySetting::getInstance();
        $settings->update(['increase_access_token' => null]);

        $this->dispatch('increase-disconnected');
    }

    public function createStripeAccount(int $index): void
    {
        try {
            $settings = CompanySetting::getInstance();
            $stripeService = app(StripePaymentService::class);

            $account = $stripeService->createConnectedAccount(
                email: $settings->company_email ?? '',
                businessName: $this->split_destinations[$index]['name'] ?? 'Split Account',
            );

            $this->split_destinations[$index]['stripe_account_id'] = $account->id;
            $this->saveSplitConfig();
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to create Stripe account: :error', ['error' => $e->getMessage()]));
        }
    }

    public function completeStripeOnboarding(int $index): void
    {
        try {
            $stripeService = app(StripePaymentService::class);
            $accountId = $this->split_destinations[$index]['stripe_account_id'];

            $accountLink = $stripeService->createAccountLink(
                accountId: $accountId,
                returnUrl: route('payment-integrations.edit'),
                refreshUrl: route('payment-integrations.edit'),
            );

            $this->redirect($accountLink->url);
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to start Stripe onboarding: :error', ['error' => $e->getMessage()]));
        }
    }

    public function retrySplit(int $splitId): void
    {
        try {
            $split = PaymentSplit::findOrFail($splitId);
            $payment = $split->payment;

            $splitService = app(PaymentSplitService::class);
            $splitService->retryFailedSplits($payment);

            $this->dispatch('split-retried');
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to retry split: :error', ['error' => $e->getMessage()]));
        }
    }

    #[Computed]
    public function splitPercentageTotal(): float
    {
        return (float) array_sum(array_map(fn ($d) => (float) ($d['percentage'] ?? 0), $this->split_destinations));
    }

    #[Computed]
    public function increaseConnected(): bool
    {
        return ! empty(CompanySetting::getInstance()->increase_access_token);
    }

    #[Computed]
    public function recentSplits(): \Illuminate\Database\Eloquent\Collection
    {
        return PaymentSplit::with('payment')
            ->latest()
            ->take(20)
            ->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Payment Integrations')" :subheading="__('Configure Stripe Connect and Increase for payment splitting')" :fullWidth="true">
        <div class="my-6 w-full space-y-8">

            {{-- Section A: How It Works --}}
            <flux:callout variant="info" icon="information-circle">
                <flux:callout.heading>{{ __('How Payment Splitting Works') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('When a customer pays an invoice, the payment is automatically split across your bank accounts by percentage. Stripe Connect handles card and ACH payments. Increase handles check deposits with next-day availability and ACH splitting.') }}
                </flux:callout.text>
            </flux:callout>

            <flux:separator />

            {{-- Section B: Split Destinations --}}
            <flux:heading size="sm">{{ __('Split Destinations') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Define how payments are split across your bank accounts. Percentages must total 100%.') }}
            </flux:text>

            <div class="mt-4 space-y-4">
                <div class="flex items-center gap-3">
                    <flux:switch wire:model.live="payment_split_enabled" />
                    <flux:text>{{ __('Enable Auto-Splitting') }}</flux:text>
                </div>

                @if ($payment_split_enabled)
                    <div class="space-y-3">
                        @foreach ($split_destinations as $index => $dest)
                            <div wire:key="dest-{{ $index }}" class="flex items-end gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                <div class="flex-1">
                                    <flux:input wire:model="split_destinations.{{ $index }}.name" :label="__('Account Name')" :placeholder="__('e.g., Operating, Owner Pay')" />
                                </div>
                                <div class="w-28">
                                    <flux:input wire:model="split_destinations.{{ $index }}.percentage" label="%" type="number" step="0.01" min="0" max="100" />
                                </div>
                                <div>
                                    <flux:button variant="danger" size="sm" wire:click="removeDestination({{ $index }})">{{ __('Remove') }}</flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-4">
                        <flux:button size="sm" wire:click="addDestination">{{ __('+ Add Destination') }}</flux:button>
                        <flux:text size="sm" class="{{ abs($this->splitPercentageTotal - 100) < 0.01 ? 'text-emerald-600' : 'text-red-500' }}">
                            {{ __('Total:') }} {{ number_format($this->splitPercentageTotal, 2) }}%
                        </flux:text>
                    </div>

                    @error('split_destinations')
                        <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text>
                    @enderror

                    <div class="flex items-center gap-4">
                        <flux:button variant="primary" wire:click="saveSplitConfig">{{ __('Save Split Configuration') }}</flux:button>
                        <x-action-message on="split-config-saved">{{ __('Saved.') }}</x-action-message>
                    </div>
                @endif
            </div>

            <flux:separator />

            {{-- Section C: Stripe Connect --}}
            <flux:heading size="sm">{{ __('Stripe Connect') }}</flux:heading>

            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <flux:switch wire:model.live="stripe_connect_enabled" />
                    <flux:text>{{ __('Enable Stripe Connect') }}</flux:text>
                </div>
                <div class="flex items-center gap-3">
                    <flux:switch wire:model.live="stripe_ach_enabled" />
                    <flux:text>{{ __('Enable ACH Bank Payments') }}</flux:text>
                </div>

                <flux:callout variant="warning" icon="wrench">
                    <flux:callout.heading>{{ __('Setup Instructions') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ol class="list-inside list-decimal space-y-1">
                            <li>{{ __('Ensure STRIPE_KEY, STRIPE_SECRET, and STRIPE_WEBHOOK_SECRET are set in your .env file') }}</li>
                            <li>{{ __('Click "Create Connected Account" for each split destination below') }}</li>
                            <li>{{ __('Complete Stripe\'s onboarding flow (links bank accounts, KYC verification)') }}</li>
                            <li>{{ __('Once all accounts are active, payments will auto-split via Stripe Transfers') }}</li>
                        </ol>
                    </flux:callout.text>
                </flux:callout>

                @if ($stripe_connect_enabled && count($split_destinations) > 0)
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Destination') }}</th>
                                    <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">%</th>
                                    <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Stripe Account') }}</th>
                                    <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($split_destinations as $index => $dest)
                                    <tr wire:key="stripe-{{ $index }}">
                                        <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100">{{ $dest['name'] ?: __('Unnamed') }}</td>
                                        <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $dest['percentage'] }}%</td>
                                        <td class="px-4 py-2">
                                            @if (!empty($dest['stripe_account_id']))
                                                <flux:badge color="green">{{ $dest['stripe_account_id'] }}</flux:badge>
                                            @else
                                                <flux:badge color="zinc">{{ __('Not connected') }}</flux:badge>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2">
                                            @if (empty($dest['stripe_account_id']))
                                                <flux:button size="xs" wire:click="createStripeAccount({{ $index }})">{{ __('Create Account') }}</flux:button>
                                            @else
                                                <flux:button size="xs" variant="ghost" wire:click="completeStripeOnboarding({{ $index }})">{{ __('Manage') }}</flux:button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="flex items-center gap-4">
                    <flux:button wire:click="saveStripeSettings">{{ __('Save Stripe Settings') }}</flux:button>
                    <x-action-message on="stripe-settings-saved">{{ __('Saved.') }}</x-action-message>
                </div>
            </div>

            <flux:separator />

            {{-- Section D: Increase --}}
            <flux:heading size="sm">{{ __('Increase (Check Deposits & ACH)') }}</flux:heading>

            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <flux:switch wire:model.live="increase_enabled" />
                    <flux:text>{{ __('Enable Increase') }}</flux:text>
                </div>

                <flux:select wire:model="increase_environment" :label="__('Environment')">
                    <option value="sandbox">{{ __('Sandbox (Testing)') }}</option>
                    <option value="production">{{ __('Production') }}</option>
                </flux:select>

                <flux:callout variant="warning" icon="wrench">
                    <flux:callout.heading>{{ __('Setup Instructions') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ol class="list-inside list-decimal space-y-1">
                            <li>{{ __('Create an account at') }} <a href="https://increase.com" target="_blank" class="underline">increase.com</a></li>
                            <li>{{ __('Create an OAuth Application in the Increase Dashboard') }}</li>
                            <li>{{ __('Set the Redirect URI to:') }} <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">{{ route('increase.oauth.callback') }}</code></li>
                            <li>{{ __('Add INCREASE_CLIENT_ID and INCREASE_CLIENT_SECRET to your .env file') }}</li>
                            <li>{{ __('Click "Connect with Increase" below') }}</li>
                        </ol>
                    </flux:callout.text>
                </flux:callout>

                <div class="flex items-center gap-4">
                    @if ($this->increaseConnected)
                        <flux:badge color="green" size="lg">{{ __('Connected') }}</flux:badge>
                        <flux:button variant="danger" size="sm" wire:click="disconnectIncrease" wire:confirm="{{ __('Are you sure you want to disconnect Increase?') }}">{{ __('Disconnect') }}</flux:button>
                    @else
                        <flux:badge color="zinc" size="lg">{{ __('Not Connected') }}</flux:badge>
                        @if ($increase_enabled)
                            <flux:button variant="primary" wire:click="connectIncrease">{{ __('Connect with Increase') }}</flux:button>
                        @endif
                    @endif
                </div>

                <div class="flex items-center gap-4">
                    <flux:button wire:click="saveIncreaseSettings">{{ __('Save Increase Settings') }}</flux:button>
                    <x-action-message on="increase-settings-saved">{{ __('Saved.') }}</x-action-message>
                </div>
            </div>

            <flux:separator />

            {{-- Section E: Recent Split Activity --}}
            <flux:heading size="sm">{{ __('Recent Split Activity') }}</flux:heading>

            @if ($this->recentSplits->isEmpty())
                <flux:text size="sm" class="text-zinc-400">{{ __('No payment splits yet.') }}</flux:text>
            @else
                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Date') }}</th>
                                <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Payment') }}</th>
                                <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Destination') }}</th>
                                <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Amount') }}</th>
                                <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Status') }}</th>
                                <th class="px-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->recentSplits as $split)
                                <tr wire:key="split-{{ $split->id }}">
                                    <td class="px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ $split->created_at->format('M j, g:ia') }}</td>
                                    <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100">#{{ $split->payment_id }}</td>
                                    <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100">{{ $split->destination_name }}</td>
                                    <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100">${{ number_format((float) $split->amount, 2) }}</td>
                                    <td class="px-4 py-2">
                                        <flux:badge :color="match($split->status) { 'completed' => 'green', 'failed' => 'red', default => 'yellow' }">
                                            {{ ucfirst($split->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="px-4 py-2">
                                        @if ($split->status === 'failed')
                                            <flux:button size="xs" wire:click="retrySplit({{ $split->id }})">{{ __('Retry') }}</flux:button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        </div>
    </x-pages::settings.layout>
</section>
