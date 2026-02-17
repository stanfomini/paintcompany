<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Media Hub'])]
class extends Component {
    #[Url]
    public string $tab = 'videos';
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between mb-6">
        <flux:heading size="xl">{{ __('Media Hub') }}</flux:heading>
    </div>

    <flux:text>{{ __('Media Hub coming soon.') }}</flux:text>
</div>
