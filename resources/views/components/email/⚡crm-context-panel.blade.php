<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmailCrmLink;
use App\Models\EmailMessage;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $messageId;

    public string $linkSearch = '';

    public bool $showLinkModal = false;

    #[Computed]
    public function message(): ?EmailMessage
    {
        return EmailMessage::with('crmLinks.linkable')->find($this->messageId);
    }

    #[Computed]
    public function searchResults(): array
    {
        if (strlen($this->linkSearch) < 2) {
            return [];
        }

        $results = [];

        $customers = Customer::where('first_name', 'like', "%{$this->linkSearch}%")
            ->orWhere('last_name', 'like', "%{$this->linkSearch}%")
            ->orWhere('email', 'like', "%{$this->linkSearch}%")
            ->limit(5)
            ->get();

        foreach ($customers as $customer) {
            $results[] = [
                'type' => 'Customer',
                'id' => $customer->id,
                'class' => Customer::class,
                'name' => $customer->first_name.' '.$customer->last_name,
                'detail' => $customer->email,
            ];
        }

        $deals = Deal::where('title', 'like', "%{$this->linkSearch}%")
            ->limit(5)
            ->get();

        foreach ($deals as $deal) {
            $results[] = [
                'type' => 'Deal',
                'id' => $deal->id,
                'class' => Deal::class,
                'name' => $deal->title,
                'detail' => $deal->stage,
            ];
        }

        return $results;
    }

    public function addLink(string $class, int $id): void
    {
        $linkable = $class::find($id);
        if (! $linkable || ! $this->message) {
            return;
        }

        app(\App\Services\EmailCrmLinkingService::class)
            ->manualLink($this->message, $linkable, auth()->user());

        $this->showLinkModal = false;
        $this->linkSearch = '';
        unset($this->message);
    }

    public function removeLink(int $linkId): void
    {
        $link = EmailCrmLink::find($linkId);
        if ($link) {
            app(\App\Services\EmailCrmLinkingService::class)->unlink($link);
        }
        unset($this->message);
    }
};
?>

<div class="border-l border-zinc-200 dark:border-zinc-700">
    @if ($this->message)
        <div class="p-3">
            <h4 class="mb-3 text-sm font-medium text-zinc-900 dark:text-white">{{ __('CRM Links') }}</h4>

            {{-- Existing Links --}}
            <div class="space-y-2">
                @forelse ($this->message->crmLinks as $link)
                    <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
                        <div>
                            <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                {{ class_basename($link->linkable_type) }}
                            </p>
                            <p class="text-xs text-zinc-500">
                                {{ $link->linkable?->name ?? $link->linkable?->title ?? '#' . $link->linkable_id }}
                            </p>
                        </div>
                        <button wire:click="removeLink({{ $link->id }})" class="text-zinc-400 hover:text-red-500">
                            <flux:icon name="x-mark" class="size-3.5" />
                        </button>
                    </div>
                @empty
                    <p class="text-xs text-zinc-400">{{ __('No CRM links') }}</p>
                @endforelse
            </div>

            {{-- Add Link --}}
            <div class="mt-3">
                <flux:button size="xs" wire:click="$toggle('showLinkModal')" icon="plus">
                    {{ __('Link to CRM') }}
                </flux:button>
            </div>

            @if ($showLinkModal)
                <div class="mt-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:input wire:model.live.debounce.300ms="linkSearch" size="sm"
                        placeholder="{{ __('Search customers, deals...') }}" />

                    @if (count($this->searchResults) > 0)
                        <div class="mt-2 space-y-1">
                            @foreach ($this->searchResults as $result)
                                <button wire:click="addLink('{{ addslashes($result['class']) }}', {{ $result['id'] }})"
                                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                    <flux:badge size="sm">{{ $result['type'] }}</flux:badge>
                                    <span class="font-medium">{{ $result['name'] }}</span>
                                    <span class="text-zinc-400">{{ $result['detail'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
