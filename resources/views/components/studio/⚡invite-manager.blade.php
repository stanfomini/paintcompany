<?php

use App\Models\RecordingInvite;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string|max:1000')]
    public ?string $instructions = null;

    #[Validate('required|integer|min:10|max:300')]
    public int $maxDuration = 120;

    #[Validate('required|integer|min:1|max:168')]
    public int $expiresInHours = 24;

    public function createInvite(): void
    {
        abort_unless(auth()->check(), 403);

        $this->validate();

        RecordingInvite::create([
            'user_id' => auth()->id(),
            'title' => $this->title,
            'instructions' => $this->instructions,
            'max_duration_seconds' => $this->maxDuration,
            'expires_at' => now()->addHours($this->expiresInHours),
        ]);

        $this->reset('title', 'instructions', 'maxDuration', 'expiresInHours', 'showCreateForm');
        $this->dispatch('notify', message: __('Recording invite created!'));
    }

    public function deleteInvite(int $inviteId): void
    {
        RecordingInvite::where('id', $inviteId)
            ->where('user_id', auth()->id())
            ->delete();
    }

    #[Computed]
    public function invites()
    {
        return RecordingInvite::where('user_id', auth()->id())
            ->latest()
            ->limit(20)
            ->get();
    }
};
?>

<div class="space-y-6">
    {{-- Header with create button --}}
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="lg">{{ __('Recording Invites') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Send recording invites to customers and guests.') }}</flux:text>
        </div>
        <flux:button
            variant="primary"
            wire:click="$set('showCreateForm', true)"
            x-show="!$wire.showCreateForm"
        >
            <flux:icon name="plus" class="size-4 mr-1" />
            {{ __('New Invite') }}
        </flux:button>
    </div>

    {{-- Create form --}}
    @if($showCreateForm)
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-6 space-y-4">
            <flux:heading size="base">{{ __('Create Recording Invite') }}</flux:heading>

            <form wire:submit="createInvite" class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="title" placeholder="{{ __('e.g. Customer Testimonial') }}" />
                    @error('title') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Instructions (optional)') }}</flux:label>
                    <flux:textarea wire:model="instructions" rows="3" placeholder="{{ __('Tell the recipient what to record...') }}" />
                    @error('instructions') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Max Duration (seconds)') }}</flux:label>
                        <div class="flex items-center gap-3">
                            <input
                                type="range"
                                wire:model.live="maxDuration"
                                min="10"
                                max="300"
                                step="10"
                                class="flex-1 accent-indigo-600"
                            />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300 w-16 text-right">
                                {{ floor($maxDuration / 60) }}:{{ str_pad($maxDuration % 60, 2, '0', STR_PAD_LEFT) }}
                            </span>
                        </div>
                        @error('maxDuration') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Expires In') }}</flux:label>
                        <flux:select wire:model="expiresInHours">
                            <option value="1">{{ __('1 hour') }}</option>
                            <option value="6">{{ __('6 hours') }}</option>
                            <option value="12">{{ __('12 hours') }}</option>
                            <option value="24">{{ __('24 hours') }}</option>
                            <option value="48">{{ __('48 hours') }}</option>
                            <option value="72">{{ __('3 days') }}</option>
                            <option value="168">{{ __('7 days') }}</option>
                        </flux:select>
                        @error('expiresInHours') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    </flux:field>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="createInvite">{{ __('Create Invite') }}</span>
                        <span wire:loading wire:target="createInvite">{{ __('Creating...') }}</span>
                    </flux:button>
                    <flux:button type="button" variant="ghost" wire:click="$set('showCreateForm', false)">
                        {{ __('Cancel') }}
                    </flux:button>
                </div>
            </form>
        </div>
    @endif

    {{-- Invites list --}}
    <div class="space-y-3">
        @forelse($this->invites as $invite)
            <div wire:key="invite-{{ $invite->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <h3 class="font-semibold text-zinc-900 dark:text-white truncate">{{ $invite->title }}</h3>
                            @if($invite->isCompleted())
                                <flux:badge color="green" size="sm">{{ __('Completed') }}</flux:badge>
                            @elseif($invite->isExpired())
                                <flux:badge color="zinc" size="sm">{{ __('Expired') }}</flux:badge>
                            @else
                                <flux:badge color="yellow" size="sm">{{ __('Pending') }}</flux:badge>
                            @endif
                        </div>

                        @if($invite->instructions)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 line-clamp-2 mb-2">{{ $invite->instructions }}</p>
                        @endif

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-400 dark:text-zinc-500">
                            <span>{{ __('Max') }}: {{ floor($invite->max_duration_seconds / 60) }}:{{ str_pad($invite->max_duration_seconds % 60, 2, '0', STR_PAD_LEFT) }}</span>
                            @if($invite->expires_at)
                                <span>
                                    @if($invite->isExpired())
                                        {{ __('Expired') }} {{ $invite->expires_at->diffForHumans() }}
                                    @else
                                        {{ __('Expires') }} {{ $invite->expires_at->diffForHumans() }}
                                    @endif
                                </span>
                            @endif
                            <span>{{ __('Created') }} {{ $invite->created_at->diffForHumans() }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        @if($invite->isPending())
                            <div
                                x-data="{ copied: false, url: @js($invite->getInviteUrl()) }"
                                class="inline-flex"
                            >
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    @click="
                                        if (navigator.clipboard && navigator.clipboard.writeText) {
                                            navigator.clipboard.writeText(url).then(() => { copied = true; setTimeout(() => copied = false, 2000) });
                                        } else {
                                            window.prompt('{{ __('Copy this link:') }}', url);
                                        }
                                    "
                                >
                                    <flux:icon x-show="!copied" name="link" class="size-4" />
                                    <flux:icon x-show="copied" name="check" class="size-4 text-green-500" />
                                    <span x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy Link') }}'"></span>
                                </flux:button>
                            </div>
                        @endif

                        <flux:button
                            size="sm"
                            variant="ghost"
                            wire:click="deleteInvite({{ $invite->id }})"
                            wire:confirm="{{ __('Are you sure you want to delete this invite?') }}"
                            class="text-red-500 hover:text-red-600"
                        >
                            <flux:icon name="trash" class="size-4" />
                        </flux:button>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-12">
                <flux:icon name="video-camera" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No recording invites yet.') }}</flux:text>
                <flux:text class="text-sm text-zinc-400 dark:text-zinc-500 mt-1">{{ __('Create one to let customers or guests record videos for you.') }}</flux:text>
            </div>
        @endforelse
    </div>
</div>
