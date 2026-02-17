<?php

use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $recordingId;

    public string $title = '';

    public string $description = '';

    public string $visibility = 'unlisted';

    /** @var array<int, bool> */
    public array $channelAssignments = [];

    public bool $saved = false;

    public function mount(int $recordingId): void
    {
        $recording = VideoRecording::findOrFail($recordingId);
        $this->title = $recording->title ?? '';
        $this->description = $recording->description ?? '';
        $this->visibility = $recording->visibility ?? 'unlisted';

        $assignedIds = VideoChannelItem::where('video_recording_id', $recordingId)
            ->pluck('video_channel_id')
            ->toArray();

        foreach (VideoChannel::orderBy('name')->pluck('id') as $channelId) {
            $this->channelAssignments[$channelId] = in_array($channelId, $assignedIds);
        }
    }

    #[Computed]
    public function channels()
    {
        return VideoChannel::orderBy('name')->get();
    }

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'visibility' => ['required', 'in:unlisted,public,internal'],
        ]);

        $recording = VideoRecording::findOrFail($this->recordingId);
        $recording->update([
            'title' => $this->title,
            'description' => $this->description ?: null,
            'visibility' => $this->visibility,
        ]);

        $this->syncChannelAssignments();

        $this->saved = true;
    }

    public function toggleChannel(int $channelId): void
    {
        $this->channelAssignments[$channelId] = ! ($this->channelAssignments[$channelId] ?? false);
    }

    private function syncChannelAssignments(): void
    {
        foreach ($this->channelAssignments as $channelId => $assigned) {
            $exists = VideoChannelItem::where('video_channel_id', $channelId)
                ->where('video_recording_id', $this->recordingId)
                ->exists();

            if ($assigned && ! $exists) {
                $maxSort = VideoChannelItem::where('video_channel_id', $channelId)->max('sort_order') ?? -1;
                VideoChannelItem::create([
                    'video_channel_id' => $channelId,
                    'video_recording_id' => $this->recordingId,
                    'sort_order' => $maxSort + 1,
                ]);
            } elseif (! $assigned && $exists) {
                VideoChannelItem::where('video_channel_id', $channelId)
                    ->where('video_recording_id', $this->recordingId)
                    ->delete();
            }
        }
    }
};
?>

<div>
    <form wire:submit="save">
        <div class="space-y-4 max-w-xl">
            <flux:field>
                <flux:label>{{ __('Title') }}</flux:label>
                <flux:input wire:model="title" />
                <flux:error name="title" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="description" rows="4" />
                <flux:error name="description" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Visibility') }}</flux:label>
                <flux:select wire:model="visibility">
                    <flux:select.option value="unlisted">{{ __('Unlisted') }}</flux:select.option>
                    <flux:select.option value="public">{{ __('Public') }}</flux:select.option>
                    <flux:select.option value="internal">{{ __('Internal') }}</flux:select.option>
                </flux:select>
            </flux:field>

            @if($this->channels->isNotEmpty())
                <flux:field>
                    <flux:label>{{ __('Channel Assignments') }}</flux:label>
                    <div class="space-y-2 mt-1">
                        @foreach($this->channels as $channel)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <flux:checkbox
                                    wire:click="toggleChannel({{ $channel->id }})"
                                    :checked="$channelAssignments[$channel->id] ?? false"
                                />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $channel->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </flux:field>
            @endif

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Save Changes') }}</flux:button>
                @if($saved)
                    <flux:text class="text-green-600 text-sm">{{ __('Saved!') }}</flux:text>
                @endif
            </div>
        </div>
    </form>
</div>
