<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'My Training'])]
class extends Component {
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <flux:heading size="xl">{{ __('My Training') }}</flux:heading>
    <flux:text>{{ __('Training center coming soon.') }}</flux:text>
</div>
