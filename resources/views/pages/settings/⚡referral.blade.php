<?php

use App\Models\CompanySetting;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.referral')) {
            abort(403);
        }
    }

    public bool $referral_program_enabled = false;
    public string $referral_reward_amount = '50';

    public function mount(): void
    {
        $settings = CompanySetting::getInstance();
        $this->referral_program_enabled = (bool) $settings->referral_program_enabled;
        $this->referral_reward_amount = (string) ($settings->referral_reward_amount ?? '50');
    }

    public function save(): void
    {
        $this->validate([
            'referral_reward_amount' => 'required|numeric|min:0|max:10000',
        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'referral_program_enabled' => $this->referral_program_enabled,
            'referral_reward_amount' => $this->referral_reward_amount,
        ]);

        session()->flash('status', __('Referral settings saved.'));
    }
};
?>

<div>
    <div class="mx-auto max-w-lg px-4 py-8 sm:px-6 lg:px-8">
            @include('pages.settings.layout', [
                'heading' => __('Referral Program'),
                'subheading' => __('Configure your referral program settings.'),
            ])

            <form wire:submit="save" class="mt-6 space-y-6">
                <flux:field>
                    <flux:switch wire:model.live="referral_program_enabled" :label="__('Enable Referral Program')" :description="__('Allow users to earn rewards for referring new customers.')" />
                </flux:field>

                @if ($referral_program_enabled)
                    <flux:field>
                        <flux:label>{{ __('Reward Amount ($)') }}</flux:label>
                        <flux:input wire:model="referral_reward_amount" type="number" step="1" min="0" max="10000" />
                        <flux:description>{{ __('Amount paid to referrer when the referred customer pays their deposit.') }}</flux:description>
                        <flux:error name="referral_reward_amount" />
                    </flux:field>
                @endif

                <div class="flex items-center gap-4">
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>

                    @if (session('status'))
                        <flux:text class="text-sm text-emerald-600 dark:text-emerald-400">{{ session('status') }}</flux:text>
                    @endif
                </div>
            </form>
        </div>
</div>
