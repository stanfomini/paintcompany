<?php

use App\Models\Customer;
use App\Models\ProjectPreview;
use App\Models\ServiceCategory;
use App\Notifications\CustomerPreviewNotification;
use App\Services\InvitationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $email = '';

    public string $invitedUserType = '';

    public ?int $serviceCategoryId = null;

    public bool $showModal = false;

    public array $invitableTypes = [];

    #[Computed]
    public function serviceCategories(): Collection
    {
        return ServiceCategory::active()->ordered()->get();
    }

    public function mount(): void
    {
        $invitationService = app(InvitationService::class);
        $user = Auth::user();

        if ($user) {
            $this->invitableTypes = $invitationService->getInvitableTypes($user);
            $this->invitedUserType = $this->invitableTypes[0] ?? '';
        }
    }

    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['email', 'invitedUserType', 'serviceCategoryId']);
        $this->invitedUserType = $this->invitableTypes[0] ?? '';
    }

    public function sendInvitation(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'invitedUserType' => ['required', 'in:internal,agent,customer,crew'],
            'serviceCategoryId' => $this->invitedUserType === 'crew' ? ['required', 'exists:service_categories,id'] : ['nullable'],
        ]);

        $invitationService = app(InvitationService::class);
        $user = Auth::user();

        try {
            if ($this->invitedUserType === 'customer') {
                // Customer: send preview page email instead of invite
                $customer = Customer::where('email', $this->email)->first();

                if (! $customer) {
                    $this->addError('email', __('No customer found with this email address. Use Quick Add Customer to create one first.'));

                    return;
                }

                $deal = $customer->deals()->latest()->first();
                $preview = null;

                if ($deal) {
                    $preview = ProjectPreview::where('customer_id', $customer->id)
                        ->where('deal_id', $deal->id)
                        ->first();

                    if (! $preview) {
                        $preview = ProjectPreview::create([
                            'customer_id' => $customer->id,
                            'deal_id' => $deal->id,
                            'estimate_id' => $deal->estimates()->first()?->id,
                            'first_name' => $customer->first_name,
                            'last_name' => $customer->last_name,
                            'email' => $customer->email,
                            'phone' => $customer->phone,
                        ]);
                    }
                }

                if (! $preview) {
                    $this->addError('email', __('This customer has no deals yet. Create a deal first.'));

                    return;
                }

                $customer->notify(new CustomerPreviewNotification(
                    preview: $preview,
                    customerName: $customer->first_name,
                ));
            } else {
                // Agent/Crew/Internal: standard invite flow
                $invitationService->sendInvitation($user, $this->email, $this->invitedUserType, $this->serviceCategoryId);
            }

            $this->dispatch('invitation-sent');
            $flashMessage = $this->invitedUserType === 'customer'
                ? __('Preview link sent to :email', ['email' => $this->email])
                : __('Invitation sent to :email', ['email' => $this->email]);
            session()->flash('success', $flashMessage);
            $this->closeModal();
        } catch (\InvalidArgumentException $e) {
            $this->addError('email', $e->getMessage());
        }
    }
}; ?>

<div>
    @if (count($invitableTypes) > 0)
        <flux:button wire:click="openModal" variant="ghost" size="sm" class="w-full justify-start">
            <flux:icon name="envelope" class="mr-2" />
            {{ __('Send Invitation') }}
        </flux:button>

        <flux:modal wire:model="showModal" class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Send Invitation') }}</flux:heading>
                    <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                        {{ __('Invite someone to join the platform.') }}
                    </flux:text>
                </div>

                <form wire:submit="sendInvitation" class="space-y-4">
                    <flux:field>
                        <flux:input
                            wire:model="email"
                            type="email"
                            label="{{ __('Email Address') }}"
                            placeholder="email@example.com"
                            required
                        />
                        @error('email')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:field>
                        <flux:select wire:model.live="invitedUserType" label="{{ __('User Type') }}">
                            @foreach ($invitableTypes as $type)
                                <option value="{{ $type }}">
                                    {{ match($type) { 'internal' => __('Team Member'), 'crew' => __('Crew Member'), default => __(ucfirst($type)) } }}
                                </option>
                            @endforeach
                        </flux:select>
                        @error('invitedUserType')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    @if ($invitedUserType === 'crew')
                        <flux:field>
                            <flux:select wire:model="serviceCategoryId" label="{{ __('Service Category') }}" placeholder="{{ __('Select service...') }}">
                                @foreach ($this->serviceCategories as $category)
                                    <flux:select.option value="{{ $category->id }}">{{ __($category->name) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('serviceCategoryId')
                                <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                            @enderror
                        </flux:field>
                    @endif

                    <div class="flex justify-end gap-3 pt-4">
                        <flux:button type="button" wire:click="closeModal" variant="ghost">
                            {{ __('Cancel') }}
                        </flux:button>
                        <flux:button type="submit" variant="primary">
                            <span wire:loading.remove wire:target="sendInvitation">{{ __('Send Invitation') }}</span>
                            <span wire:loading wire:target="sendInvitation">{{ __('Sending...') }}</span>
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</div>
