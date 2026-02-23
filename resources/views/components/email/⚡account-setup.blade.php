<?php

use App\Models\EmailAccount;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('email.access')) {
            abort(403);
        }
    }

    #[Validate('required|string|min:3|max:30|regex:/^[a-z0-9.]+$/|unique:email_accounts,address')]
    public string $address = '';

    public string $displayName = '';

    public string $signatureHtml = '';

    public function mount(): void
    {
        // Redirect if already has account
        if (auth()->user()->emailAccount) {
            $this->redirect(route('email.inbox'));

            return;
        }

        // Pre-fill with a default based on name
        $user = auth()->user();
        $this->displayName = $user->name;
        $this->address = strtolower(
            str_replace(' ', '.', preg_replace('/[^a-zA-Z0-9 ]/', '', $user->name))
        );
    }

    public function create(): void
    {
        $this->validate();

        $account = EmailAccount::create([
            'user_id' => auth()->id(),
            'address' => strtolower($this->address),
            'display_name' => $this->displayName,
            'signature_html' => $this->signatureHtml ?: null,
        ]);

        $this->redirect(route('email.inbox'));
    }
};
?>

<div class="mx-auto max-w-lg px-4 py-12">
    <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-6 text-center">
            <flux:icon name="envelope" class="mx-auto mb-3 size-12 text-blue-500" />
            <flux:heading size="lg">{{ __('Set Up Your Email') }}</flux:heading>
            <p class="mt-1 text-sm text-zinc-500">{{ __('Choose your email address and configure your account.') }}</p>
        </div>

        <form wire:submit="create" class="space-y-6">
            {{-- Email Address --}}
            <div>
                <flux:field>
                    <flux:label>{{ __('Email Address') }}</flux:label>
                    <div class="flex items-center gap-1">
                        <flux:input wire:model="address" placeholder="matt" />
                        <span class="shrink-0 text-sm text-zinc-500">@ {{ config('email.domain') }}</span>
                    </div>
                    <flux:description>{{ __('Lowercase letters, numbers, and dots only. 3-30 characters.') }}</flux:description>
                    <flux:error name="address" />
                </flux:field>
            </div>

            {{-- Display Name --}}
            <flux:field>
                <flux:label>{{ __('Display Name') }}</flux:label>
                <flux:input wire:model="displayName" placeholder="{{ __('Your Name') }}" />
                <flux:description>{{ __('Shown as the sender name on outgoing emails.') }}</flux:description>
            </flux:field>

            {{-- Signature (optional) --}}
            <flux:field>
                <flux:label>{{ __('Email Signature') }} <span class="text-zinc-400">({{ __('optional') }})</span></flux:label>
                <flux:textarea wire:model="signatureHtml" rows="4" placeholder="{{ __('Best regards, Your Name') }}" />
                <flux:description>{{ __('Appended to all outgoing emails. HTML supported.') }}</flux:description>
            </flux:field>

            {{-- Preview --}}
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                <p class="mb-1 text-xs font-medium text-zinc-500">{{ __('Your email will be') }}:</p>
                <p class="text-lg font-medium text-zinc-900 dark:text-white">
                    <span x-text="$wire.address || 'you'">{{ $address ?: 'you' }}</span>@{{ config('email.domain') }}
                </p>
            </div>

            <flux:button variant="primary" type="submit" class="w-full">
                {{ __('Create Email Account') }}
            </flux:button>
        </form>
    </div>
</div>
