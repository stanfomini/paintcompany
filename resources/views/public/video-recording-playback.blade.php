<x-layouts.guest :title="($recording->title ?? __('Video Recording')) . ' - ' . ($company->company_name ?? config('app.name'))">
    @push('meta')
    <meta property="og:title" content="{{ $recording->title ?? __('Video Recording') }}">
    <meta property="og:description" content="{{ $recording->description ?? __('Watch this video from :company', ['company' => $company->company_name ?? config('app.name')]) }}">
    <meta property="og:type" content="video.other">
    <meta property="og:url" content="{{ $recording->getShareableUrl() }}">
    @if($recording->getThumbnailUrl())
    <meta property="og:image" content="{{ $recording->getThumbnailUrl() }}">
    <meta property="og:image:width" content="{{ $recording->width ?? 1280 }}">
    <meta property="og:image:height" content="{{ $recording->height ?? 720 }}">
    @endif
    <meta property="og:video" content="{{ $recording->getVideoUrl() }}">
    <meta property="og:video:type" content="{{ $recording->mime_type ?? 'video/mp4' }}">
    <meta property="og:site_name" content="{{ $company->company_name ?? config('app.name') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $recording->title ?? __('Video Recording') }}">
    <meta name="twitter:description" content="{{ $recording->description ?? __('Watch this video from :company', ['company' => $company->company_name ?? config('app.name')]) }}">
    @if($recording->getThumbnailUrl())
    <meta name="twitter:image" content="{{ $recording->getThumbnailUrl() }}">
    @endif
    @endpush

    <div class="min-h-screen bg-zinc-900 flex flex-col items-center p-4 sm:p-8">
        <div class="w-full max-w-5xl">
            {{-- Company Branding --}}
            <div class="flex items-center gap-3 mb-8">
                @if($company->logo_path)
                    <img src="{{ storage_url($company->logo_path) }}" alt="{{ $company->company_name }}" class="h-10 w-auto">
                @endif
                <span class="text-white text-lg font-semibold">{{ $company->company_name ?? config('app.name') }}</span>
            </div>

            {{-- Video Title & Metadata --}}
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

            {{-- Video Player --}}
            <div class="bg-black rounded-xl overflow-hidden" style="max-height: 80vh;">
                <video
                    src="{{ $recording->getVideoUrl() }}"
                    controls
                    autoplay
                    playsinline
                    data-track-id="{{ $recording->id }}"
                    data-track-source="{{ request()->query('ref') === 'email' ? 'email' : 'link' }}"
                    class="w-full h-full object-contain"
                    @if ($recording->getThumbnailUrl())
                        poster="{{ $recording->getThumbnailUrl() }}"
                    @endif
                ></video>
            </div>

            {{-- Video Description --}}
            @if($recording->description)
                <div class="mt-4">
                    <p class="text-zinc-400 text-sm leading-relaxed">{{ $recording->description }}</p>
                </div>
            @endif

            {{-- Footer: See More Videos & Branding --}}
            <div class="mt-6 flex items-center justify-between">
                @if($recording->channels()->where('is_active', true)->where('visibility', 'public')->exists())
                    <a href="{{ route('watch.index') }}" class="text-blue-400 hover:text-blue-300 text-sm font-medium transition-colors">
                        {{ __('See more videos') }} &rarr;
                    </a>
                @else
                    <div></div>
                @endif
                <p class="text-zinc-500 text-xs">
                    {{ __('Shared via') }} <span class="text-zinc-400 font-medium">{{ $company->company_name ?? config('app.name') }}</span>
                </p>
            </div>
        </div>
    </div>

    {{-- Video View Tracker --}}
    <script>
        (function () {
            class VideoTracker {
                constructor(videoElement, videoId, source) {
                    this.video = videoElement;
                    this.videoId = videoId;
                    this.source = source || 'link';
                    this.sessionId = Date.now().toString(36) + Math.random().toString(36).substr(2);
                    this.reportedMilestones = new Set();
                    this.lastReportTime = 0;

                    this.video.addEventListener('timeupdate', () => this.onTimeUpdate());
                    this.video.addEventListener('ended', () => this.onEnded());
                    window.addEventListener('beforeunload', () => this.onBeforeUnload());
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

                onBeforeUnload() {
                    if (this.video.currentTime > 0) {
                        const percentage = this.video.duration
                            ? Math.floor((this.video.currentTime / this.video.duration) * 100)
                            : 0;
                        this.report(Math.floor(this.video.currentTime), percentage);
                    }
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
                        el.dataset.trackSource || 'link'
                    );
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTrackers);
            } else {
                initTrackers();
            }
        })();
    </script>
</x-layouts.guest>
