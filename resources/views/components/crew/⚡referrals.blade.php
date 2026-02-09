<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.crew', ['title' => 'Referrals'])] class extends Component {
    //
};
?>

<div>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <flux:heading size="xl" class="mb-2">Referral Program</flux:heading>
        <flux:subheading class="mb-8">Refer friends and earn rewards when they book with us.</flux:subheading>

        @livewire('shared.referral-dashboard')
    </div>
</div>
