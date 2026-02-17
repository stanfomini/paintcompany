<?php

use App\Models\VideoChannel;
use Livewire\Component;

new class extends Component {
    public VideoChannel $channel;
};
?>

<div>
    <a href="{{ route('watch.channel', $channel->slug) }}" class="group block rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors bg-white dark:bg-zinc-800">
        <div class="aspect-video bg-gradient-to-br from-zinc-100 to-zinc-200 dark:from-zinc-700 dark:to-zinc-800 flex items-center justify-center">
            @if($channel->thumbnail_path)
                <img src="{{ storage_url($channel->thumbnail_path) }}" alt="{{ $channel->name }}" class="w-full h-full object-cover">
            @else
                <flux:icon name="play-circle" class="size-16 text-zinc-400 dark:text-zinc-500 group-hover:text-zinc-500 dark:group-hover:text-zinc-400 transition-colors" />
            @endif
        </div>
        <div class="p-4">
            <h3 class="font-semibold text-zinc-900 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                {{ $channel->name }}
            </h3>
            @if($channel->description)
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400 line-clamp-2">
                    {{ $channel->description }}
                </p>
            @endif
            <div class="mt-2">
                <flux:badge size="sm" variant="pill">
                    {{ $channel->items_count }} {{ trans_choice('video|videos', $channel->items_count) }}
                </flux:badge>
            </div>
        </div>
    </a>
</div>
