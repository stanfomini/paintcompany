<?php

use App\Concerns\HandlesAvatarUpload;
use App\Concerns\ProfileValidationRules;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.crew', ['title' => 'Profile Settings'])] class extends Component {
    use HandlesAvatarUpload, ProfileValidationRules, WithFileUploads;

    public string $name = '';
    public string $email = '';

    public function mount(): void
    {
        $user = auth('crew')->user();
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updateProfileInformation(): void
    {
        $user = auth('crew')->user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->saveAvatar($user);

        $this->dispatch('profile-updated', name: $user->name);
    }

    protected function getAuthenticatedUser(): \App\Models\User
    {
        return auth('crew')->user();
    }
}; ?>

<section class="w-full">
    <div class="relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('Manage your profile and account settings') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <x-user-settings-layout route-prefix="crew.settings" user-type="crew" :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <x-avatar-upload :user="auth('crew')->user()" :avatar="$avatar" />

            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />
            <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-user-settings-layout>
</section>
