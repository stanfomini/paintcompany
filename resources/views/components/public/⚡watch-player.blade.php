<?php

use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.watch', ['title' => 'Watch Video'])]
class extends Component {
    public VideoChannel $channel;
    public VideoRecording $recording;

    public function mount(VideoChannel $channel, VideoRecording $recording): void
    {
        abort_unless($channel->is_active && $channel->visibility === 'public', 404);

        $inChannel = VideoChannelItem::query()
            ->where('video_channel_id', $channel->id)
            ->where('video_recording_id', $recording->id)
            ->published()
            ->exists();

        abort_unless($inChannel && $recording->isReady(), 404);

        $this->channel = $channel;
        $this->recording = $recording;
    }

    #[Computed]
    public function upNext()
    {
        return VideoChannelItem::query()
            ->where('video_channel_id', $this->channel->id)
            ->where('video_recording_id', '!=', $this->recording->id)
            ->published()
            ->whereHas('video', fn ($q) => $q->where('status', 'ready'))
            ->with('video')
            ->orderBy('sort_order')
            ->limit(4)
            ->get();
    }
};
?>

<div>
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
        <a href="{{ route('watch.channel', $channel->slug) }}" class="inline-flex items-center gap-1 text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white mb-6">
            <flux:icon name="arrow-left" class="size-4" />
            {{ __('Back to :channel', ['channel' => $channel->name]) }}
        </a>

        <div class="lg:grid lg:grid-cols-3 lg:gap-8">
            <div class="lg:col-span-2">
                <div class="rounded-lg overflow-hidden bg-black aspect-video">
                    <video
                        controls
                        data-track-id="{{ $recording->id }}"
                        data-track-source="channel"
                        @if($recording->getThumbnailUrl())
                            poster="{{ $recording->getThumbnailUrl() }}"
                        @endif
                        class="w-full h-full"
                        preload="metadata"
                    >
                        @if($recording->getVideoUrl())
                            <source src="{{ $recording->getVideoUrl() }}" type="video/mp4">
                        @endif
                        {{ __('Your browser does not support the video tag.') }}
                    </video>
                </div>

                <div class="mt-4">
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $recording->title }}</h1>
                    @if($recording->description)
                        <p class="mt-2 text-zinc-600 dark:text-zinc-400">{{ $recording->description }}</p>
                    @endif
                </div>
            </div>

            <div class="mt-8 lg:mt-0">
                @if($this->upNext->isNotEmpty())
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">{{ __('Up Next') }}</h2>
                    <div class="space-y-4">
                        @foreach($this->upNext as $item)
                            <a href="{{ route('watch.player', [$channel->slug, $item->video]) }}" class="group flex gap-3" wire:navigate>
                                <div class="relative shrink-0 w-40 aspect-video rounded overflow-hidden bg-zinc-100 dark:bg-zinc-700">
                                    @if($item->video->getThumbnailUrl())
                                        <img src="{{ $item->video->getThumbnailUrl() }}" alt="{{ $item->video->title }}" class="w-full h-full object-cover">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <flux:icon name="film" class="size-6 text-zinc-400" />
                                        </div>
                                    @endif
                                    @if($item->video->formatted_duration)
                                        <span class="absolute bottom-1 right-1 px-1 py-0.5 text-xs bg-black/75 text-white rounded">
                                            {{ $item->video->formatted_duration }}
                                        </span>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-sm font-medium text-zinc-900 dark:text-white line-clamp-2 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                                        {{ $item->video->title }}
                                    </h3>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script>
        (function () {
            class VideoTracker {
                constructor(videoElement, videoId, source) {
                    this.video = videoElement;
                    this.videoId = videoId;
                    this.source = source || 'channel';
                    this.sessionId = Date.now().toString(36) + Math.random().toString(36).substr(2);
                    this.reportedMilestones = new Set();
                    this.lastReportTime = 0;

                    this.video.addEventListener('timeupdate', () => this.onTimeUpdate());
                    this.video.addEventListener('ended', () => this.onEnded());
                }

                onTimeUpdate() {
                    if (!this.video.duration) return;

                    const percentage = Math.floor((this.video.currentTime / this.video.duration) * 100);
                    const now = Date.now();
                    const milestones = [25, 50, 75];
                    const hitMilestone = milestones.some(m => percentage >= m && !this.reportedMilestones.has(m));
                    const intervalElapsed = now - this.lastReportTime > 30000;

                    if (hitMilestone || intervalElapsed) {
                        milestones.filter(m => percentage >= m).forEach(m => this.reportedMilestones.add(m));
                        this.report(Math.floor(this.video.currentTime), percentage);
                    }
                }

                onEnded() {
                    this.report(Math.floor(this.video.duration), 100);
                }

                report(duration, percentage) {
                    this.lastReportTime = Date.now();
                    const data = {
                        video_id: this.videoId,
                        duration: duration,
                        percentage: percentage,
                        source: this.source,
                        session_id: this.sessionId,
                    };
                    const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
                    navigator.sendBeacon('/api/video-views', blob);
                }
            }

            function initTrackers() {
                document.querySelectorAll('video[data-track-id]').forEach(function (el) {
                    if (el._tracker) return;
                    el._tracker = new VideoTracker(
                        el,
                        parseInt(el.dataset.trackId),
                        el.dataset.trackSource || 'channel'
                    );
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTrackers);
            } else {
                initTrackers();
            }

            // Re-initialize after Livewire navigation
            document.addEventListener('livewire:navigated', initTrackers);
        })();
    </script>
</div>
