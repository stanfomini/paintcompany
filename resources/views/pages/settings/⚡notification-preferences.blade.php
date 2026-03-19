<?php

use App\Models\PushSubscription;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public array $preferences = [];

    public string $smsPhoneInput = '';

    public bool $saved = false;

    public function boot(): void
    {
        if (! auth()->check()) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $defaults = User::defaultNotificationPreferences();
        $saved = auth()->user()->notification_preferences ?? [];

        foreach ($defaults as $category => $channels) {
            $this->preferences[$category] = [
                'email' => $saved[$category]['email'] ?? $channels['email'],
                'push' => $saved[$category]['push'] ?? $channels['push'],
                'sms' => $saved[$category]['sms'] ?? $channels['sms'] ?? false,
            ];
        }

        // Pre-fill from wherever their phone lives (profile or users table)
        $user = auth()->user();
        $this->smsPhoneInput = $user->phone ?? match ($user->type) {
            'customer' => $user->customerProfile?->phone,
            'agent' => $user->agentProfile?->phone,
            'crew' => $user->crewProfile?->phone,
            'internal' => $user->employeeProfile?->phone,
            default => null,
        } ?? '';
    }

    #[Computed]
    public function hasPhone(): bool
    {
        $user = auth()->user();

        return ! empty($user->routeNotificationForTwilio());
    }

    #[Computed]
    public function smsPhone(): ?string
    {
        return auth()->user()->routeNotificationForTwilio();
    }

    #[Computed]
    public function hasPushSubscriptions(): bool
    {
        return auth()->user()->pushSubscriptions()->exists();
    }

    #[Computed]
    public function pushDevices()
    {
        return auth()->user()->pushSubscriptions()
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function categoryLabels(): array
    {
        return [
            'estimates' => __('Estimates'),
            'chat' => __('Chat Messages'),
            'messenger' => __('Messenger Leads'),
            'email_messages' => __('Email'),
            'appointments' => __('Appointments'),
            'jobs' => __('Jobs & Scheduling'),
            'tasks' => __('Tasks'),
            'payments' => __('Payments'),
            'phone_calls' => __('Phone Calls & Voicemails'),
            'documents' => __('Documents'),
            'general' => __('General'),
        ];
    }

    public function save(): void
    {
        auth()->user()->update([
            'notification_preferences' => $this->preferences,
        ]);

        $this->saved = true;
    }

    public function savePhone(): void
    {
        $this->validate([
            'smsPhoneInput' => ['required', 'string', 'min:10', 'max:20'],
        ]);

        auth()->user()->update(['phone' => $this->smsPhoneInput]);

        unset($this->hasPhone);
        unset($this->smsPhone);

        $this->saved = true;
    }

    public function removeDevice(int $subscriptionId): void
    {
        auth()->user()->pushSubscriptions()
            ->where('id', $subscriptionId)
            ->delete();

        unset($this->hasPushSubscriptions);
        unset($this->pushDevices);
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Notification Preferences') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notification Preferences')" :subheading="__('Choose how you want to be notified for each category.')" :fullWidth="true">
        <div class="space-y-8">
            {{-- Preferences Table --}}
            <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
                            <th class="px-4 py-3 font-medium text-zinc-700 dark:text-zinc-300">{{ __('Category') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-zinc-700 dark:text-zinc-300">{{ __('Email') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-zinc-700 dark:text-zinc-300">{{ __('Push') }}</th>
                            <th class="px-4 py-3 text-center font-medium text-zinc-700 dark:text-zinc-300">{{ __('SMS') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->categoryLabels as $key => $label)
                            <tr wire:key="pref-{{ $key }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $label }}</td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex justify-center">
                                        <flux:switch wire:model.live="preferences.{{ $key }}.email" />
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex justify-center">
                                        @if ($this->hasPushSubscriptions)
                                            <flux:switch wire:model.live="preferences.{{ $key }}.push" />
                                        @else
                                            <flux:switch disabled />
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex justify-center">
                                        @if ($this->hasPhone)
                                            <flux:switch wire:model.live="preferences.{{ $key }}.sms" />
                                        @else
                                            <flux:switch disabled />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @unless ($this->hasPushSubscriptions)
                <div
                    x-data="{
                        supported: window.pushNotifications?.isSupported() ?? false,
                        subscribing: false,
                        error: '',
                        denied: Notification.permission === 'denied',

                        async enablePush() {
                            this.subscribing = true;
                            this.error = '';
                            try {
                                const success = await window.pushNotifications.subscribe();
                                if (success) {
                                    $wire.$refresh();
                                } else if (Notification.permission === 'denied') {
                                    this.denied = true;
                                    this.error = '{{ __('Notifications were blocked. Please enable them in your browser settings.') }}';
                                } else {
                                    this.error = '{{ __('Could not enable push notifications. Please try again.') }}';
                                }
                            } catch (e) {
                                this.error = e.message || '{{ __('Failed to enable push notifications.') }}';
                            }
                            this.subscribing = false;
                        },
                    }"
                    class="rounded-lg border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
                >
                    <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="rounded-lg bg-blue-100 p-2 dark:bg-blue-900/30">
                                <flux:icon name="bell-alert" class="size-5 text-blue-600 dark:text-blue-400" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ __('Push Notifications') }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Get notified even when the app is closed.') }}</p>
                            </div>
                        </div>

                        <template x-if="supported && !denied">
                            <flux:button
                                variant="primary"
                                size="sm"
                                icon="bell"
                                x-on:click="enablePush()"
                                x-bind:disabled="subscribing"
                            >
                                <span x-show="!subscribing">{{ __('Enable Push') }}</span>
                                <span x-show="subscribing" x-cloak>{{ __('Enabling...') }}</span>
                            </flux:button>
                        </template>

                        <template x-if="denied">
                            <span class="text-xs text-red-600 dark:text-red-400">{{ __('Blocked in browser settings') }}</span>
                        </template>

                        <template x-if="!supported">
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Not supported on this browser') }}</span>
                        </template>
                    </div>

                    <p x-show="error" x-text="error" x-cloak class="mt-3 text-sm text-red-600 dark:text-red-400"></p>
                </div>
            @endunless

            {{-- Save Button --}}
            <div class="flex items-center gap-4">
                <flux:button variant="primary" wire:click="save">{{ __('Save') }}</flux:button>

                @if ($saved)
                    <span
                        x-data="{ show: true }"
                        x-init="setTimeout(() => show = false, 2000)"
                        x-show="show"
                        x-transition.out.opacity.duration.1500ms
                        class="text-sm text-zinc-600 dark:text-zinc-400"
                    >{{ __('Saved.') }}</span>
                @endif
            </div>

            {{-- Push Devices Section --}}
            @if ($this->pushDevices->isNotEmpty())
                <div class="space-y-3">
                    <flux:heading size="sm">{{ __('Push Devices') }}</flux:heading>
                    <flux:subheading>{{ __('Devices registered to receive push notifications.') }}</flux:subheading>

                    @foreach ($this->pushDevices as $device)
                        <div wire:key="device-{{ $device->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="flex items-center gap-3">
                                <div class="rounded-lg bg-zinc-100 p-2 dark:bg-zinc-800">
                                    <flux:icon name="device-phone-mobile" class="size-5 text-zinc-600 dark:text-zinc-400" />
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ Str::limit($device->user_agent ?? __('Unknown device'), 60) }}
                                    </p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ __('Registered') }} {{ $device->created_at->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="trash"
                                wire:click="removeDevice({{ $device->id }})"
                                wire:confirm="{{ __('Remove this device? It will no longer receive push notifications.') }}"
                            />
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- SMS Phone --}}
            <div class="space-y-3">
                <flux:heading size="sm">{{ __('SMS Phone Number') }}</flux:heading>
                <flux:subheading>{{ __('Your phone number for receiving SMS alerts.') }}</flux:subheading>

                <div class="flex items-center gap-3">
                    <flux:input
                        wire:model="smsPhoneInput"
                        placeholder="(555) 123-4567"
                        class="max-w-xs"
                    />
                    <flux:button variant="primary" size="sm" wire:click="savePhone">
                        {{ __('Save Phone') }}
                    </flux:button>
                </div>
                @error('smsPhoneInput')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </x-pages::settings.layout>
</section>
