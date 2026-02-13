<x-layouts.guest :title="__('Video Recording')">
    <div class="min-h-screen bg-zinc-900 flex flex-col items-center justify-center p-4">
        <div class="w-full max-w-5xl">
            <div class="mb-4">
                <h1 class="text-white text-xl font-semibold">{{ $jobTitle }}</h1>
                <p class="text-zinc-400 text-sm">
                    @if ($recordedAt)
                        {{ __('Recorded') }} {{ $recordedAt }}
                    @endif
                    @if ($duration)
                        &middot; {{ gmdate('H:i:s', $duration) }}
                    @endif
                </p>
            </div>

            <div
                x-data="{
                    init() {
                        const video = this.$refs.videoPlayer;
                        const src = @js($playbackUrl);

                        if (src.endsWith('.m3u8') && video.canPlayType('application/vnd.apple.mpegurl') === '') {
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
                class="aspect-video bg-black rounded-xl overflow-hidden"
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
</x-layouts.guest>
