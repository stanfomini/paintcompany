<?php

use App\Models\VideoProject;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.watch', ['title' => 'Video'])]
class extends Component {
    public VideoProject $videoProject;

    public function mount(string $token): void
    {
        $this->videoProject = VideoProject::where('share_token', $token)
            ->where('status', 'rendered')
            ->whereIn('visibility', ['public', 'unlisted'])
            ->firstOrFail();
    }
};
?>

@section('robots', 'noindex, nofollow')

<div class="min-h-[calc(100vh-200px)] bg-zinc-900 flex items-center justify-center">
    <div class="max-w-4xl w-full mx-auto px-4 py-8">
        @if($videoProject->renderedVideo)
            <div class="aspect-video bg-black rounded-lg overflow-hidden shadow-2xl">
                <video
                    controls
                    autoplay
                    class="w-full h-full"
                    src="{{ $videoProject->renderedVideo->getVideoUrl() }}"
                    @if($videoProject->thumbnail_path)
                        poster="{{ $videoProject->thumbnail_path }}"
                    @endif
                >
                    {{ __('Your browser does not support video playback.') }}
                </video>
            </div>
            <div class="mt-4 text-center">
                <h1 class="text-lg font-semibold text-white">{{ $videoProject->title }}</h1>
                @if($videoProject->description)
                    <p class="text-sm text-zinc-400 mt-1">{{ $videoProject->description }}</p>
                @endif
                <p class="text-xs text-zinc-500 mt-2">
                    {{ $videoProject->created_at->format('M j, Y') }}
                    @if($videoProject->duration_seconds)
                        &middot; {{ gmdate('i:s', (int) $videoProject->duration_seconds) }}
                    @endif
                </p>
            </div>
        @else
            <div class="text-center py-16">
                <flux:icon name="video-camera-slash" class="mx-auto size-12 text-zinc-600" />
                <p class="mt-4 text-zinc-400">{{ __('This video is not yet available.') }}</p>
            </div>
        @endif
    </div>
</div>
