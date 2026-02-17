<?php

use Livewire\Component;

new class extends Component {
};
?>

<div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
    <flux:icon name="chart-bar" class="mx-auto h-12 w-12 text-zinc-400" />
    <flux:heading size="sm" class="mt-3">{{ __('Analytics') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500">{{ __('Analytics overview coming soon.') }}</flux:text>
</div>
