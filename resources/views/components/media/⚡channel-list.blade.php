<?php

use App\Models\VideoChannel;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $showCreateModal = false;

    public string $newChannelName = '';

    public string $newChannelDescription = '';

    public string $newChannelVisibility = 'public';

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('media.manage_channels'), 403);
    }

    #[Computed]
    public function channels()
    {
        $query = VideoChannel::query();

        if (! auth()->user()?->can('media.manage')) {
            $query->where('user_id', auth()->id());
        } else {
            $query->with('user:id,name');
        }

        return $query
            ->withCount('items')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function createChannel(): void
    {
        $this->validate([
            'newChannelName' => ['required', 'string', 'max:255'],
            'newChannelDescription' => ['nullable', 'string', 'max:1000'],
            'newChannelVisibility' => ['required', 'in:public,private'],
        ]);

        VideoChannel::create([
            'user_id' => auth()->id(),
            'name' => $this->newChannelName,
            'description' => $this->newChannelDescription ?: null,
            'visibility' => $this->newChannelVisibility,
        ]);

        $this->reset('newChannelName', 'newChannelDescription', 'newChannelVisibility', 'showCreateModal');
        unset($this->channels);
    }

    public function toggleActive(int $channelId): void
    {
        $channel = VideoChannel::findOrFail($channelId);
        $this->authorize('update', $channel);
        $channel->update(['is_active' => ! $channel->is_active]);
        unset($this->channels);
    }

    public function deleteChannel(int $channelId): void
    {
        $channel = VideoChannel::findOrFail($channelId);
        $this->authorize('delete', $channel);
        $channel->delete();
        unset($this->channels);
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <flux:heading size="lg">{{ __('Channels') }}</flux:heading>
        <flux:button size="sm" variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">
            {{ __('New Channel') }}
        </flux:button>
    </div>

    @if($this->channels->count() > 0)
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach($this->channels as $channel)
                <div wire:key="channel-{{ $channel->id }}" class="px-6 py-4 flex items-center justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <flux:text class="font-medium">{{ $channel->name }}</flux:text>
                            @php
                                $visColors = ['public' => 'green', 'private' => 'zinc'];
                            @endphp
                            <flux:badge size="sm" color="{{ $visColors[$channel->visibility] ?? 'zinc' }}">
                                {{ ucfirst($channel->visibility) }}
                            </flux:badge>
                            @unless($channel->is_active)
                                <flux:badge size="sm" color="yellow">{{ __('Inactive') }}</flux:badge>
                            @endunless
                        </div>
                        <flux:text class="text-sm text-zinc-500 mt-0.5">
                            {{ $channel->items_count }} {{ trans_choice('video|videos', $channel->items_count) }}
                            &middot; /watch/{{ $channel->slug }}
                            @can('media.manage')
                                @if($channel->user)
                                    &middot; {{ $channel->user->name }}
                                @endif
                            @endcan
                        </flux:text>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 ml-4">
                        <flux:button size="sm" variant="ghost" icon="pencil" :href="route('media.channel.manage', $channel)" wire:navigate />
                        <flux:button size="sm" variant="ghost" icon="{{ $channel->is_active ? 'eye-slash' : 'eye' }}" wire:click="toggleActive({{ $channel->id }})" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteChannel({{ $channel->id }})" wire:confirm="{{ __('Delete this channel and remove all video associations?') }}" />
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
            <flux:icon name="rectangle-stack" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-3">{{ __('No channels yet') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Create a channel to organize your videos for the public /watch page.') }}</flux:text>
        </div>
    @endif

    {{-- Create Channel Modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-md">
        <form wire:submit="createChannel">
            <flux:heading size="lg" class="mb-4">{{ __('Create Channel') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="newChannelName" placeholder="{{ __('e.g. Our Best Work') }}" autofocus />
                    <flux:error name="newChannelName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:textarea wire:model="newChannelDescription" rows="3" />
                    <flux:error name="newChannelDescription" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Visibility') }}</flux:label>
                    <flux:select wire:model="newChannelVisibility">
                        <flux:select.option value="public">{{ __('Public') }}</flux:select.option>
                        <flux:select.option value="private">{{ __('Private') }}</flux:select.option>
                    </flux:select>
                </flux:field>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
