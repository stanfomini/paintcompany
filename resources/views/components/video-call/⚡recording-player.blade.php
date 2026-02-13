<?php

use App\Models\Job;
use App\Models\VideoCall;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Job $job;

    public ?int $playingRecordingId = null;

    public ?string $playbackUrl = null;

    #[Computed]
    public function recordings()
    {
        return VideoCall::where('job_id', $this->job->id)
            ->where('status', 'ended')
            ->whereNotNull('recording_path')
            ->orderByDesc('ended_at')
            ->get();
    }

    public function playRecording(int $videoCallId): void
    {
        $videoCall = VideoCall::find($videoCallId);

        if (! $videoCall || $videoCall->job_id !== $this->job->id) {
            return;
        }

        $this->playingRecordingId = $videoCallId;
        $this->playbackUrl = $videoCall->getRecordingUrl();
    }

    public function closePlayer(): void
    {
        $this->playingRecordingId = null;
        $this->playbackUrl = null;
    }

    public function getShareUrl(int $videoCallId): ?string
    {
        $videoCall = VideoCall::find($videoCallId);

        if (! $videoCall || $videoCall->job_id !== $this->job->id) {
            return null;
        }

        return $videoCall->getShareableUrl();
    }
};
?>

<div>
    @if ($this->recordings->isNotEmpty())
        <div class="mt-6">
            <flux:heading size="sm" class="mb-3">{{ __('Recordings') }}</flux:heading>
            <div class="space-y-2">
                @foreach ($this->recordings as $recording)
                    <div wire:key="recording-{{ $recording->id }}" class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
                                <flux:icon name="video-camera" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                            </div>
                            <div>
                                <flux:text class="text-sm font-medium">
                                    {{ $recording->ended_at?->format('M j, Y g:ia') }}
                                </flux:text>
                                <flux:text class="text-xs text-zinc-500">
                                    {{ $recording->initiator?->name ?? __('Unknown') }}
                                    @if ($recording->duration_seconds)
                                        &middot; {{ gmdate('H:i:s', $recording->duration_seconds) }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:button
                                wire:click="playRecording({{ $recording->id }})"
                                variant="ghost"
                                size="sm"
                                icon="play"
                            >
                                {{ __('Play') }}
                            </flux:button>
                            <div
                                x-data="{ copied: false }"
                                x-on:click="
                                    const url = $wire.getShareUrl({{ $recording->id }});
                                    url.then(u => {
                                        if (u) {
                                            navigator.clipboard.writeText(u);
                                            copied = true;
                                            setTimeout(() => copied = false, 2000);
                                        }
                                    });
                                "
                            >
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    x-bind:icon="copied ? 'check' : 'link'"
                                >
                                    <span x-text="copied ? 'Copied' : 'Share'"></span>
                                </flux:button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- HLS Playback Modal --}}
    @if ($playingRecordingId && $playbackUrl)
        <div class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
            <div class="relative w-full max-w-4xl bg-zinc-900 rounded-xl overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 bg-zinc-800 border-b border-zinc-700">
                    <flux:text class="text-white text-sm font-medium">{{ __('Recording Playback') }}</flux:text>
                    <button
                        type="button"
                        wire:click="closePlayer"
                        class="p-1.5 rounded-lg bg-zinc-700 text-zinc-300 hover:bg-zinc-600 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div
                    x-data="{
                        init() {
                            const video = this.$refs.videoPlayer;
                            const src = @js($playbackUrl);

                            if (src.endsWith('.m3u8') && video.canPlayType('application/vnd.apple.mpegurl') === '') {
                                // Use hls.js for non-Safari browsers
                                const script = document.createElement('script');
                                script.src = 'https://cdn.jsdelivr.net/npm/hls.js@latest';
                                script.onload = () => {
                                    if (Hls.isSupported()) {
                                        const hls = new Hls();
                                        hls.loadSource(src);
                                        hls.attachMedia(video);
                                    }
                                };
                                document.head.appendChild(script);
                            } else {
                                video.src = src;
                            }
                        }
                    }"
                    class="aspect-video bg-black"
                >
                    <video
                        x-ref="videoPlayer"
                        controls
                        autoplay
                        class="w-full h-full"
                    ></video>
                </div>
            </div>
        </div>
    @endif
</div>
