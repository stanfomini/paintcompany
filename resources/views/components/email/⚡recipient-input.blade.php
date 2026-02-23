<?php

use App\Models\Customer;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Modelable;
use Livewire\Component;

new class extends Component {
    #[Modelable]
    public string $value = '';

    public string $search = '';

    public bool $showSuggestions = false;

    #[Computed]
    public function suggestions(): array
    {
        if (strlen($this->search) < 2) {
            return [];
        }

        $results = [];

        // Search customers
        $customers = Customer::where('email', 'like', "%{$this->search}%")
            ->orWhere('first_name', 'like', "%{$this->search}%")
            ->orWhere('last_name', 'like', "%{$this->search}%")
            ->limit(5)
            ->get();

        foreach ($customers as $customer) {
            $results[] = [
                'email' => $customer->email,
                'name' => $customer->first_name.' '.$customer->last_name,
                'type' => 'Customer',
            ];
        }

        // Search internal users
        $users = User::where('type', 'internal')
            ->where(function ($q) {
                $q->where('email', 'like', "%{$this->search}%")
                    ->orWhere('name', 'like', "%{$this->search}%");
            })
            ->limit(5)
            ->get();

        foreach ($users as $user) {
            $results[] = [
                'email' => $user->emailAccount?->full_address ?? $user->email,
                'name' => $user->name,
                'type' => 'Team',
            ];
        }

        // Search agents
        $agents = User::where('type', 'agent')
            ->where(function ($q) {
                $q->where('email', 'like', "%{$this->search}%")
                    ->orWhere('name', 'like', "%{$this->search}%");
            })
            ->limit(3)
            ->get();

        foreach ($agents as $agent) {
            $results[] = [
                'email' => $agent->email,
                'name' => $agent->name,
                'type' => 'Agent',
            ];
        }

        return $results;
    }

    public function selectSuggestion(string $email): void
    {
        $current = $this->value ? explode(', ', $this->value) : [];
        if (! in_array($email, $current)) {
            $current[] = $email;
        }
        $this->value = implode(', ', $current);
        $this->search = '';
        $this->showSuggestions = false;
    }
};
?>

<div class="relative" x-data="{ open: false }">
    <flux:input wire:model.live.debounce.300ms="search"
        x-on:focus="open = true" x-on:blur="setTimeout(() => open = false, 200)"
        size="sm" :placeholder="$value ? '' : __('Type to search...')" />

    @if ($this->showSuggestions || (count($this->suggestions) > 0 && $search))
        <div class="absolute left-0 right-0 top-full z-50 mt-1 rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
            x-show="open">
            @foreach ($this->suggestions as $suggestion)
                <button type="button"
                    wire:click="selectSuggestion('{{ $suggestion['email'] }}')"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    <flux:badge size="sm">{{ $suggestion['type'] }}</flux:badge>
                    <span>{{ $suggestion['name'] }}</span>
                    <span class="text-xs text-zinc-400">{{ $suggestion['email'] }}</span>
                </button>
            @endforeach
        </div>
    @endif
</div>
