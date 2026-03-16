<?php

use App\Concerns\HandlesAvatarUpload;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use HandlesAvatarUpload, ProfileValidationRules, WithFileUploads;

    public string $name = '';
    public string $email = '';
    public ?string $forwarding_number = '';
    public ?string $extension = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;

        if (Auth::user()->isInternal()) {
            $this->forwarding_number = Auth::user()->forwarding_number ?? '';
            $this->extension = Auth::user()->extension ?? '';
        }
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->saveAvatar($user);

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Update the phone settings for the currently authenticated user.
     */
    public function savePhoneSettings(): void
    {
        $user = Auth::user();

        if (! $user->isInternal()) {
            return;
        }

        $validated = $this->validate([
            'forwarding_number' => ['nullable', 'string', 'regex:/^\+?1?\d{10,15}$/'],
            'extension' => ['nullable', 'numeric', 'digits_between:3,4', Rule::unique(User::class, 'extension')->ignore($user->id)],
        ]);

        $user->update([
            'forwarding_number' => $validated['forwarding_number'] ?: null,
            'extension' => $validated['extension'] ?: null,
        ]);

        $this->dispatch('phone-settings-saved');
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <x-avatar-upload :user="Auth::user()" :avatar="$avatar" />

            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        @if (Auth::user()->isInternal())
            <flux:separator class="my-8" />

            <form wire:submit="savePhoneSettings" class="my-6 w-full space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Phone Settings') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Configure your call forwarding and extension settings.') }}</flux:text>
                </div>

                <flux:input
                    wire:model="forwarding_number"
                    :label="__('Forwarding Number')"
                    type="tel"
                    :description="__('Your cell phone where calls will be forwarded to you')"
                    placeholder="3035551234"
                />

                <flux:input
                    wire:model="extension"
                    :label="__('Extension')"
                    maxlength="4"
                    :description="__('Your direct dial extension (3-4 digits)')"
                    placeholder="101"
                />

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">
                        {{ __('Save Phone Settings') }}
                    </flux:button>

                    <x-action-message class="me-3" on="phone-settings-saved">
                        {{ __('Saved.') }}
                    </x-action-message>
                </div>
            </form>
        @endif

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
