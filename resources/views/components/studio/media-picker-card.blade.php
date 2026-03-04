@props(['type', 'id', 'title' => '', 'thumbnailUrl' => null, 'duration' => null, 'isVideo' => true])

@php
    $slugMap = [
        \App\Models\PropertyPhoto::class => 'property-photo',
        \App\Models\VideoRecording::class => 'video-recording',
    ];
    $typeSlug = $slugMap[$type] ?? $type;
@endphp

<button
    type="button"
    wire:click="selectMedia('{{ $typeSlug }}', {{ $id }})"
    class="group relative rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:border-blue-500 dark:hover:border-blue-400 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 text-left"
>
    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 relative">
        @if($thumbnailUrl)
            <img src="{{ $thumbnailUrl }}" alt="{{ $title }}" class="w-full h-full object-cover" loading="lazy" />
        @else
            <div class="w-full h-full flex items-center justify-center">
                <flux:icon name="{{ $isVideo ? 'video-camera' : 'photo' }}" class="h-8 w-8 text-zinc-400" />
            </div>
        @endif
        @if($duration)
            <span class="absolute bottom-1 right-1 bg-black/75 text-white text-xs px-1 py-0.5 rounded">
                {{ $duration }}
            </span>
        @endif
    </div>
    <div class="p-2">
        <p class="text-xs text-zinc-700 dark:text-zinc-300 truncate">{{ $title ?: __('Untitled') }}</p>
    </div>
</button>
