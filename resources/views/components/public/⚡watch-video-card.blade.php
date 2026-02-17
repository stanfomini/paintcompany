<?php

use App\Models\VideoRecording;
use Livewire\Component;

new class extends Component {
    public VideoRecording $recording;
    public string $channelSlug;
};
?>

<div>
    <a href="{{ route('watch.player', [$channelSlug, $recording]) }}" class="group block rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors bg-white dark:bg-zinc-800">
        <div class="relative aspect-video bg-zinc-100 dark:bg-zinc-700">
            @if($recording->getThumbnailUrl())
                <img src="{{ $recording->getThumbnailUrl() }}" alt="{{ $recording->title }}" class="w-full h-full object-cover">
            @else
                <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-zinc-200 to-zinc-300 dark:from-zinc-600 dark:to-zinc-700">
                    <flux:icon name="film" class="size-10 text-zinc-400 dark:text-zinc-500" />
                </div>
            @endif
            <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/20">
                <flux:icon name="play-circle" class="size-14 text-white drop-shadow-lg" />
            </div>
            @if($recording->formatted_duration)
                <span class="absolute bottom-2 right-2 px-1.5 py-0.5 text-xs font-medium bg-black/75 text-white rounded">
                    {{ $recording->formatted_duration }}
                </span>
            @endif
        </div>
        <div class="p-3">
            <h3 class="font-medium text-sm text-zinc-900 dark:text-white line-clamp-2 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                {{ $recording->title }}
            </h3>
            @if($recording->views_count !== null)
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ number_format($recording->views_count) }} {{ trans_choice('view|views', $recording->views_count) }}
                </p>
            @endif
        </div>
    </a>
</div>
