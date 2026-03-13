<?php

use App\Jobs\SendDirectSmsJob;
use App\Models\Customer;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public Customer $customer;

    public bool $showModal = false;

    #[Validate('required|string|max:1600')]
    public string $messageBody = '';

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
    }

    #[On('open-send-sms-modal')]
    public function openModal(): void
    {
        if (! $this->customer->phone) {
            session()->flash('error', __('This customer does not have a phone number on file.'));

            return;
        }

        $this->showModal = true;
    }

    public function send(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }

        if (! $this->customer->phone) {
            session()->flash('error', __('This customer does not have a phone number on file.'));
            $this->showModal = false;

            return;
        }

        $this->validate();

        SendDirectSmsJob::dispatch(
            phone: $this->customer->phone,
            message: $this->messageBody,
            sender: auth()->user(),
            customer: $this->customer,
        );

        $this->showModal = false;
        $this->messageBody = '';

        session()->flash('success', __('SMS message queued for delivery.'));
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->messageBody = '';
        $this->resetValidation();
    }
};
?>

<div>
    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Send SMS') }}</flux:heading>

            <div>
                <flux:text size="sm" class="text-zinc-500 mb-1">{{ __('To') }}</flux:text>
                <flux:input :value="$customer->phone" readonly size="sm" icon="phone" />
            </div>

            <flux:field>
                <flux:label>{{ __('Message') }}</flux:label>
                <flux:textarea
                    wire:model="messageBody"
                    rows="4"
                    :placeholder="__('Type your SMS message...')"
                />
                <flux:error name="messageBody" />
                <flux:text size="xs" class="text-zinc-400 mt-1">
                    {{ strlen($messageBody) }}/1600 {{ __('characters') }}
                </flux:text>
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="primary" wire:click="send" icon="paper-airplane" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="send">{{ __('Send SMS') }}</span>
                    <span wire:loading wire:target="send">{{ __('Sending...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
