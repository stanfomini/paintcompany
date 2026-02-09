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

        session()->flash('status', 'Payment method saved.');
    }
};
?>

<div class="space-y-8">
    @if (!$this->settings->referral_program_enabled)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>Referral Program Unavailable</flux:callout.heading>
            <flux:callout.text>The referral program is currently disabled.</flux:callout.text>
        </flux:callout>
    @else
        {{-- Referral Link --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">Your Referral Link</flux:heading>
            <flux:subheading class="mb-4">Share this link with friends and family. You'll earn ${{ number_format($this->settings->referral_reward_amount, 0) }} for every referral that signs a contract and pays their deposit.</flux:subheading>

            <div class="flex items-center gap-2">
                <flux:input readonly :value="$this->referralLink" class="flex-1 font-mono text-sm" />
                <flux:button
                    variant="primary"
                    icon="clipboard-document"
                    x-on:click="navigator.clipboard.writeText('{{ $this->referralLink }}'); $wire.set('showCopied', true); setTimeout(() => $wire.set('showCopied', false), 2000)"
                >
                    {{ $showCopied ? 'Copied!' : 'Copy' }}
                </flux:button>
            </div>

            <div class="mt-3">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                    Your referral code: <span class="font-mono font-bold">{{ $this->referralCode }}</span>
                </flux:text>
            </div>
        </div>

        {{-- Stats --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Total Referrals</flux:text>
                <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $this->stats['total'] }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">In Progress</flux:text>
                <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $this->stats['leads'] + $this->stats['quoted'] + $this->stats['signed'] }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Total Earned</flux:text>
                <div class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($this->stats['total_earned'], 0) }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Pending Rewards</flux:text>
                <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">${{ number_format($this->stats['pending_rewards'], 0) }}</div>
            </div>
        </div>

        {{-- Payment Method --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">Payment Method</flux:heading>
            <flux:subheading class="mb-4">How would you like to receive your referral rewards?</flux:subheading>

            <form wire:submit="savePaymentMethod" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <flux:field class="sm:w-48">
                    <flux:label>Method</flux:label>
                    <flux:select wire:model="paymentMethod">
                        <option value="">Select...</option>
                        <option value="venmo">Venmo</option>
                        <option value="paypal">PayPal</option>
                        <option value="zelle">Zelle</option>
                        <option value="check">Check</option>
                    </flux:select>
                    <flux:error name="paymentMethod" />
                </flux:field>

                <flux:field class="flex-1">
                    <flux:label>Username / Email / Address</flux:label>
                    <flux:input wire:model="paymentHandle" placeholder="@username or email" />
                    <flux:error name="paymentHandle" />
                </flux:field>

                <flux:button type="submit" variant="primary">Save</flux:button>
            </form>
        </div>

        {{-- Referral Table --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">Your Referrals</flux:heading>

            @if ($this->referrals->isEmpty())
                <flux:text class="mt-4 text-zinc-500 dark:text-zinc-400">
                    No referrals yet. Share your link to get started!
                </flux:text>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <tr>
                                <th class="pb-3 pr-4 font-medium">Customer</th>
                                <th class="pb-3 pr-4 font-medium">Date</th>
                                <th class="pb-3 pr-4 font-medium">Status</th>
                                <th class="pb-3 font-medium">Reward</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->referrals as $referral)
                                <tr wire:key="referral-{{ $referral->id }}">
                                    <td class="py-3 pr-4 font-medium text-zinc-900 dark:text-white">
                                        {{ $referral->customer?->full_name ?? 'Unknown' }}
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
                                                <flux:badge color="emerald" size="sm" class="ml-1">Sent</flux:badge>
                                            @else
                                                <flux:badge color="amber" size="sm" class="ml-1">Pending</flux:badge>
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
    @endif
</div>
