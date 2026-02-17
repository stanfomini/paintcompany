<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest', ['title' => 'Watch'])]
class extends Component {
};
?>

<div class="py-12 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">{{ __('Video Library') }}</h1>
</div>
