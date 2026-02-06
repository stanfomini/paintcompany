<?php

use App\Models\Deal;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public bool $showModal = false;

    public ?int $selectedDealId = null;

    public string $dealSearch = '';

    public function openModal(): void
    {
        $this->reset(['selectedDealId', 'dealSearch']);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    #[Computed]
    public function availableDeals(): Collection
    {
        $user = auth('agent')->user();

        $query = Deal::query()
            ->with(['customer', 'property'])
            ->where('agent_id', $user->id) // Only agent's referred deals
            ->whereDoesntHave('chatThread') // Only deals without a chat thread
            ->orderByDesc('created_at');

        if ($this->dealSearch) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.$this->dealSearch.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', '%'.$this->dealSearch.'%')
                        ->orWhere('last_name', 'like', '%'.$this->dealSearch.'%'));
            });
        }

        return $query->take(20)->get();
    }

    public function selectDeal(int $dealId): void
    {
        $this->selectedDealId = $dealId;
    }

    public function createThread(): void
    {
        $user = auth('agent')->user();
        $chatService = app(ChatService::class);

        if (! $this->selectedDealId) {
            $this->addError('general', 'Please select a deal to start a conversation.');

            return;
        }

        $deal = Deal::where('agent_id', $user->id)
            ->findOrFail($this->selectedDealId);

        // Create deal thread
        $thread = $chatService->getOrCreateDealThread($deal, $user);

        // Add relevant participants
        if ($deal->customer?->user) {
            $thread->addParticipant($deal->customer->user);
        }
        if ($deal->assignedTo) {
            $thread->addParticipant($deal->assignedTo);
        }

        $this->closeModal();
        $this->dispatch('thread-created');
        $this->redirect(route('agent.chat.show', $thread), navigate: true);
    }
}; ?>

<div>
    <flux:button wire:click="openModal" icon="plus">
        New Conversation
    </flux:button>

    <flux:modal wire:model="showModal" name="create-thread" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Start a Deal Conversation</flux:heading>
                <flux:text class="mt-1 text-zinc-500">Select a deal to start a conversation with the customer and team.</flux:text>
            </div>

            @error('general')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror

            {{-- Deal Selection --}}
            <div class="space-y-4">
                <flux:input
                    wire:model.live.debounce.300ms="dealSearch"
                    placeholder="Search your deals..."
                    icon="magnifying-glass"
                />

                @if ($selectedDealId)
                    @php $selectedDeal = \App\Models\Deal::with('customer')->find($selectedDealId); @endphp
                    @if ($selectedDeal)
                        <div class="flex items-center justify-between p-3 border border-blue-500 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <div>
                                <flux:text class="font-medium">{{ $selectedDeal->title }}</flux:text>
                                <flux:text class="text-sm text-zinc-500">
                                    {{ $selectedDeal->customer?->full_name ?? 'No customer' }}
                                </flux:text>
                            </div>
                            <flux:button wire:click="$set('selectedDealId', null)" variant="ghost" size="sm" icon="x-mark" />
                        </div>
                    @endif
                @else
                    <div class="max-h-64 overflow-y-auto border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->availableDeals as $deal)
                            <button
                                wire:key="deal-{{ $deal->id }}"
                                wire:click="selectDeal({{ $deal->id }})"
                                class="w-full flex items-center justify-between p-3 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-left transition-colors"
                            >
                                <div class="min-w-0 flex-1">
                                    <flux:text class="font-medium truncate">{{ $deal->title }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500 truncate">
                                        {{ $deal->customer?->full_name ?? 'No customer' }}
                                    </flux:text>
                                </div>
                                <flux:badge size="sm" color="blue">{{ $deal->stage_label }}</flux:badge>
                            </button>
                        @empty
                            <div class="p-4 text-center">
                                <flux:text class="text-zinc-500">
                                    @if ($dealSearch)
                                        No deals found matching "{{ $dealSearch }}"
                                    @else
                                        All your deals already have chat threads
                                    @endif
                                </flux:text>
                            </div>
                        @endforelse
                    </div>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeModal" variant="ghost">Cancel</flux:button>
                <flux:button
                    wire:click="createThread"
                    variant="primary"
                    :disabled="!$selectedDealId"
                >
                    Start Conversation
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
