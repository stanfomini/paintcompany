<?php

use Livewire\Component;

new class extends Component {
    public string $query = '';

    public function search(): void
    {
        $this->dispatch('email-search', query: $this->query)->to('email.email-list');
    }

    public function clear(): void
    {
        $this->query = '';
        $this->dispatch('email-search', query: '')->to('email.email-list');
    }
};
?>

<div class="flex items-center gap-2">
    <div class="relative flex-1">
        <flux:input wire:model="query" wire:keydown.enter="search"
            icon="magnifying-glass" placeholder="{{ __('Search emails...') }}" size="sm" />
    </div>
    @if ($query)
        <flux:button size="xs" wire:click="clear" icon="x-mark" />
    @endif
</div>
