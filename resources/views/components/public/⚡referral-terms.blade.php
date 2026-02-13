<?php

use App\Models\CompanySetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.public')] class extends Component {
    #[Computed]
    public function settings(): CompanySetting
    {
        return CompanySetting::getInstance();
    }
}; ?>

<div class="py-8 px-4 sm:px-6 lg:px-8">
    <div class="max-w-3xl mx-auto">
        {{-- Header --}}
        <div class="mb-8">
            <flux:heading size="2xl">{{ __('Referral Program Terms') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">
                {{ $this->settings->company_name ?? config('app.name') }}
            </flux:text>
        </div>

        {{-- Content --}}
        @php $referralContent = $this->settings->getLocalizedContract('referral_terms'); @endphp
        @if ($referralContent)
            <div class="prose prose-zinc dark:prose-invert max-w-none">
                {!! nl2br(e($referralContent)) !!}
            </div>
        @else
            <flux:callout variant="warning">
                <flux:callout.heading>{{ __('No Referral Terms Available') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Referral program terms have not been published yet.') }}</flux:callout.text>
            </flux:callout>
        @endif

        {{-- Print button --}}
        <div class="mt-8 flex justify-end print:hidden">
            <flux:button variant="ghost" icon="printer" onclick="window.print()">
                {{ __('Print') }}
            </flux:button>
        </div>
    </div>
</div>
