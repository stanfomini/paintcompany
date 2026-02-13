<?php

use App\Models\CompanySetting;
use App\Models\Referral;
use App\Services\ReferralService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $paymentMethod = '';
    public string $paymentHandle = '';
    public bool $showCopied = false;
    public string $acceptInitials = '';
    public bool $acceptCheckbox = false;

    public function mount(): void
    {
        $user = auth()->user();
        $this->paymentMethod = $user->referral_payment_method ?? '';
        $this->paymentHandle = $user->referral_payment_handle ?? '';
    }

    #[Computed]
    public function settings(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function referralCode(): string
    {
        return auth()->user()->getOrCreateReferralCode();
    }

    #[Computed]
    public function referralLink(): string
    {
        return auth()->user()->referralLink();
    }

    #[Computed]
    public function stats(): array
    {
        return app(ReferralService::class)->getStatsForUser(auth()->user());
    }

    #[Computed]
    public function referrals()
    {
        return auth()->user()->referrals()
            ->with('customer', 'deal')
            ->latest()
            ->get();
    }

    public function acceptReferralTerms(): void
    {
        $this->validate([
            'acceptInitials' => 'required|string|min:2|max:10',
            'acceptCheckbox' => 'accepted',
        ], [
            'acceptInitials.required' => __('Please enter your initials.'),
            'acceptInitials.min' => __('Initials must be at least 2 characters.'),
            'acceptCheckbox.accepted' => __('You must accept the referral program terms.'),
        ]);

        auth()->user()->update([
            'referral_terms_accepted_at' => now(),
            'referral_terms_ip_address' => request()->ip(),
            'referral_terms_initials' => strtoupper($this->acceptInitials),
        ]);

        $this->reset(['acceptInitials', 'acceptCheckbox']);
    }

    #[Computed]
    public function referralTermsText(): ?string
    {
        return CompanySetting::getInstance()->referral_terms;
    }

    public function savePaymentMethod(): void
    {
        $this->validate([
            'paymentMethod' => 'required|in:venmo,paypal,zelle,check',
            'paymentHandle' => 'required|string|max:255',
        ]);

        auth()->user()->update([
            'referral_payment_method' => $this->paymentMethod,
            'referral_payment_handle' => $this->paymentHandle,
        ]);

        session()->flash('status', __('Payment method saved.'));
    }
};
?>

<div class="space-y-8">
    @if (!$this->settings->referral_program_enabled)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Referral Program Unavailable') }}</flux:callout.heading>
            <flux:callout.text>{{ __('The referral program is currently disabled.') }}</flux:callout.text>
        </flux:callout>
    @else
        @if (auth()->user()->hasAcceptedReferralTerms())
            {{-- Referral Link --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg">{{ __('Your Referral Link') }}</flux:heading>
                <flux:subheading class="mb-4">{{ __('Share this link with friends and family. You\'ll earn $:amount for every referral that signs a contract and pays their deposit.', ['amount' => number_format($this->settings->referral_reward_amount, 0)]) }}</flux:subheading>

                <div class="flex items-center gap-2">
                    <flux:input readonly :value="$this->referralLink" class="flex-1 font-mono text-sm" />
                    <flux:button
                        variant="primary"
                        icon="clipboard-document"
                        x-on:click="navigator.clipboard.writeText('{{ $this->referralLink }}'); $wire.set('showCopied', true); setTimeout(() => $wire.set('showCopied', false), 2000)"
                    >
                        {{ $showCopied ? __('Copied!') : __('Copy') }}
                    </flux:button>
                </div>

                <div class="mt-3">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ __('Your referral code:') }} <span class="font-mono font-bold">{{ $this->referralCode }}</span>
                    </flux:text>
                </div>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Total Referrals') }}</flux:text>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $this->stats['total'] }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('In Progress') }}</flux:text>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $this->stats['leads'] + $this->stats['quoted'] + $this->stats['signed'] }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Total Earned') }}</flux:text>
                    <div class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($this->stats['total_earned'], 0) }}</div>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Pending Rewards') }}</flux:text>
                    <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">${{ number_format($this->stats['pending_rewards'], 0) }}</div>
                </div>
            </div>

            {{-- Payment Method --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg">{{ __('Payment Method') }}</flux:heading>
                <flux:subheading class="mb-4">{{ __('How would you like to receive your referral rewards?') }}</flux:subheading>

                <form wire:submit="savePaymentMethod" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <flux:field class="sm:w-48">
                        <flux:label>{{ __('Method') }}</flux:label>
                        <flux:select wire:model="paymentMethod">
                            <option value="">{{ __('Select...') }}</option>
                            <option value="venmo">Venmo</option>
                            <option value="paypal">PayPal</option>
                            <option value="zelle">Zelle</option>
                            <option value="check">{{ __('Check') }}</option>
                        </flux:select>
                        <flux:error name="paymentMethod" />
                    </flux:field>

                    <flux:field class="flex-1">
                        <flux:label>{{ __('Username / Email / Address') }}</flux:label>
                        <flux:input wire:model="paymentHandle" :placeholder="__('@username or email')" />
                        <flux:error name="paymentHandle" />
                    </flux:field>

                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </form>
            </div>

            {{-- Referral Table --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg">{{ __('Your Referrals') }}</flux:heading>

                @if ($this->referrals->isEmpty())
                    <flux:text class="mt-4 text-zinc-500 dark:text-zinc-400">
                        {{ __('No referrals yet. Share your link to get started!') }}
                    </flux:text>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <tr>
                                    <th class="pb-3 pr-4 font-medium">{{ __('Customer') }}</th>
                                    <th class="pb-3 pr-4 font-medium">{{ __('Date') }}</th>
                                    <th class="pb-3 pr-4 font-medium">{{ __('Status') }}</th>
                                    <th class="pb-3 font-medium">{{ __('Reward') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                @foreach ($this->referrals as $referral)
                                    <tr wire:key="referral-{{ $referral->id }}">
                                        <td class="py-3 pr-4 font-medium text-zinc-900 dark:text-white">
                                            {{ $referral->customer?->full_name ?? __('Unknown') }}
                                        </td>
                                        <td class="py-3 pr-4 text-zinc-500 dark:text-zinc-400">
                                            {{ $referral->created_at->format('M j, Y') }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            @php
                                                $badgeColor = match($referral->status) {
                                                    'lead' => 'zinc',
                                                    'quoted' => 'sky',
                                                    'signed' => 'indigo',
                                                    'deposit_paid' => 'emerald',
                                                    'reward_sent' => 'emerald',
                                                    default => 'zinc',
                                                };
                                            @endphp
                                            <flux:badge color="{{ $badgeColor }}" size="sm">{{ $referral->status_label }}</flux:badge>
                                        </td>
                                        <td class="py-3">
                                            @if ($referral->isRewardEarned())
                                                <span class="font-medium text-emerald-600 dark:text-emerald-400">${{ number_format($referral->reward_amount, 0) }}</span>
                                                @if ($referral->isRewardSent())
                                                    <flux:badge color="emerald" size="sm" class="ml-1">{{ __('Sent') }}</flux:badge>
                                                @else
                                                    <flux:badge color="amber" size="sm" class="ml-1">{{ __('Pending') }}</flux:badge>
                                                @endif
                                            @else
                                                <span class="text-zinc-400 dark:text-zinc-500">--</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @else
            {{-- Terms Acceptance Gate --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-2">{{ __('Referral Program Terms') }}</flux:heading>
                <flux:subheading class="mb-4">{{ __('Please review and accept the referral program terms before accessing your referral link.') }}</flux:subheading>

                @if ($this->referralTermsText)
                    <div class="mb-6 max-h-96 overflow-y-auto rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="prose prose-sm prose-zinc dark:prose-invert max-w-none">
                            {!! nl2br(e($this->referralTermsText)) !!}
                        </div>
                    </div>
                @else
                    <flux:callout variant="warning" class="mb-6">
                        <flux:callout.text>{{ __('Referral terms have not been published yet. Please contact the company for details.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <form wire:submit="acceptReferralTerms" class="space-y-4">
                    <flux:field>
                        <div class="flex items-start gap-3">
                            <flux:checkbox wire:model.live="acceptCheckbox" id="acceptReferralTerms" />
                            <label for="acceptReferralTerms" class="text-sm text-zinc-600 dark:text-zinc-400 cursor-pointer">
                                {{ __('I have read and agree to the') }} <a href="/referral-terms" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">{{ __('Referral Program Terms') }}</a>.
                                <span class="text-red-500">*</span>
                            </label>
                        </div>
                        <flux:error name="acceptCheckbox" />
                    </flux:field>

                    <div class="flex items-center gap-4">
                        <flux:field class="w-32">
                            <flux:label>{{ __('Your Initials') }} <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="acceptInitials" :placeholder="__('e.g. JD')" class="uppercase" maxlength="10" />
                            <flux:error name="acceptInitials" />
                        </flux:field>

                        <flux:button type="submit" variant="primary" class="mt-6" :disabled="!$acceptCheckbox">
                            {{ __('Accept & Continue') }}
                        </flux:button>
                    </div>
                </form>

                <div class="mt-4">
                    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500">
                        {{ __('Your initials and IP address will be recorded for compliance purposes.') }}
                    </flux:text>
                </div>
            </div>
        @endif
    @endif
</div>
