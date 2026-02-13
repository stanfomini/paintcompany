<x-layouts.guest :title="($recording->title ?? __('Video Recording')) . ' - Apex Negotiations'">
    <div class="min-h-screen bg-zinc-900 flex flex-col items-center justify-center p-4">
        <div class="w-full max-w-5xl">
            <div class="mb-4">
                <h1 class="text-white text-xl font-semibold">{{ $recording->title ?? __('Video Recording') }}</h1>
                <p class="text-zinc-400 text-sm">
                    @if ($recording->formatted_duration)
                        {{ $recording->formatted_duration }}
                        &middot;
                    @endif
                    {{ __('Recorded') }} {{ $recording->created_at->format('M j, Y') }}
                </p>
            </div>

            <div class="aspect-video bg-black rounded-xl overflow-hidden">
                <video
                    src="{{ $recording->getVideoUrl() }}"
                    controls
                    autoplay
                    playsinline
                    class="w-full h-full"
                    @if ($recording->getThumbnailUrl())
                        poster="{{ $recording->getThumbnailUrl() }}"
                    @endif
                ></video>
            </div>

            <div class="mt-6 text-center">
                <p class="text-zinc-500 text-xs">
                    {{ __('Shared via') }} <span class="text-zinc-400 font-medium">Apex Negotiations</span>
                </p>
            </div>
        </div>
    </div>
</x-layouts.guest>
