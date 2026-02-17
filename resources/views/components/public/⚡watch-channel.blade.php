<?php

use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.watch', ['title' => 'Channel'])]
class extends Component {
    public VideoChannel $channel;

    public function mount(VideoChannel $channel): void
    {
        abort_unless($channel->is_active && $channel->visibility === 'public', 404);

        $this->channel = $channel;
    }

    #[Computed]
    public function videos()
    {
        return VideoChannelItem::query()
            ->where('video_channel_id', $this->channel->id)
            ->published()
            ->whereHas('video', fn ($q) => $q->where('status', 'ready'))
            ->with('video')
            ->orderBy('sort_order')
            ->get();
    }
};
?>

<div>
    <div class="bg-zinc-50 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
            <a href="{{ route('watch.index') }}" class="inline-flex items-center gap-1 text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white mb-4">
                <flux:icon name="arrow-left" class="size-4" />
                {{ __('Back to channels') }}
            </a>
            <h1 class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $channel->name }}</h1>
            @if($channel->description)
                <p class="mt-2 text-lg text-zinc-600 dark:text-zinc-400">{{ $channel->description }}</p>
            @endif
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
        @if($this->videos->isEmpty())
            <div class="text-center py-12">
                <flux:icon name="film" class="mx-auto size-12 text-zinc-400" />
                <p class="mt-4 text-lg text-zinc-500 dark:text-zinc-400">{{ __('No videos in this channel yet.') }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($this->videos as $item)
                    <livewire:public.watch-video-card
                        :recording="$item->video"
                        :channel-slug="$channel->slug"
                        :key="'video-'.$item->id"
                    />
                @endforeach
            </div>
        @endif
    </div>
</div>
