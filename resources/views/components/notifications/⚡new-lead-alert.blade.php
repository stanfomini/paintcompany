<?php

use Livewire\Component;

new class extends Component {
    public function getListeners(): array
    {
        // Only listen if user is internal
        if (! auth()->check() || ! auth()->user()->can('deals.view')) {
            return [];
        }

        return [
            'echo-private:internal-team,lead.created' => 'handleNewLead',
        ];
    }

    public function handleNewLead(array $data): void
    {
        $deal = $data['deal'] ?? null;

        if (! $deal) {
            return;
        }

        // Don't show alert for leads created by the current user
        if (isset($deal['created_by']['id']) && $deal['created_by']['id'] === auth()->id()) {
            return;
        }

        $customerName = $deal['customer']['full_name'] ?? 'Unknown';
        $createdBy = $deal['created_by']['name'] ?? 'Unknown';
        $message = __('New Lead: :customer (by :creator)', [
            'customer' => $customerName,
            'creator' => $createdBy,
        ]);

        $this->dispatch('toast', message: $message, type: 'success', url: route('deals.show', $deal['id']), duration: 5000);

        // Play notification sound (via browser)
        $this->dispatch('play-notification-sound');
    }
}; ?>

<div>
    @script
    <script>
        $wire.on('play-notification-sound', () => {
            // Try to play a notification sound
            try {
                const audio = new Audio('/sounds/notification.mp3');
                audio.volume = 0.5;
                audio.play().catch(() => {
                    // Ignore audio play errors (user hasn't interacted yet)
                });
            } catch (e) {
                // Audio not supported
            }
        });
    </script>
    @endscript
</div>
