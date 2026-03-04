<?php

use App\Models\ColorVisualization;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.guest')] #[Title('Color Visualization Result')] class extends Component {
    public ColorVisualization $visualization;

    public function mount(string $uuid): void
    {
        $this->visualization = ColorVisualization::where('uuid', $uuid)
            ->where('status', ColorVisualization::STATUS_COMPLETED)
            ->firstOrFail();
    }
};
?>

@push('meta')
<meta property="og:title" content="AI Color Visualization | Painting Made Easy Denver" />
<meta property="og:description" content="See how your home could look with new paint colors!" />
@if($visualization->result_image_path)
<meta property="og:image" content="{{ storage_url($visualization->result_image_path, diskName: $visualization->disk) }}" />
@endif
<meta property="og:type" content="website" />
<meta property="og:url" content="{{ route('visualize.result', $visualization->uuid) }}" />
@endpush

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-900">
    {{-- Header --}}
    <section class="bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900 py-10">
        <div class="mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold text-white sm:text-4xl">
                Color
                <span class="bg-gradient-to-r from-blue-400 to-purple-400 bg-clip-text text-transparent">Visualization</span>
            </h1>
            <p class="mt-2 text-zinc-400">AI-powered paint color preview</p>
        </div>
    </section>

    <section class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        {{-- Before / After --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="mb-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Before</p>
                    <img src="{{ storage_url($visualization->original_image_path, diskName: $visualization->disk) }}"
                         alt="Original photo"
                         class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700" />
                </div>
                <div>
                    <p class="mb-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">After</p>
                    <img src="{{ storage_url($visualization->result_image_path, diskName: $visualization->disk) }}"
                         alt="Visualized result"
                         class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700" />
                </div>
            </div>

            {{-- Parsed colors --}}
            @if($visualization->parsed_colors)
                <div class="mt-6">
                    <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Colors Used</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($visualization->parsed_colors as $color)
                            <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800">
                                <div class="h-5 w-5 rounded-full border border-zinc-300 dark:border-zinc-600"
                                     style="background-color: {{ $color['hex'] ?? '#ccc' }}"></div>
                                <div class="text-sm">
                                    <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $color['name'] ?? '' }}</span>
                                    @if(!empty($color['brand']))
                                        <span class="text-zinc-500 dark:text-zinc-400">by {{ $color['brand'] }}</span>
                                    @endif
                                    @if(!empty($color['code']))
                                        <span class="text-zinc-400">({{ $color['code'] }})</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Actions --}}
            <div class="mt-6 flex flex-wrap gap-3">
                @if($visualization->result_image_path)
                    <a href="{{ storage_url($visualization->result_image_path, diskName: $visualization->disk) }}"
                       download="color-visualization.png"
                       class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-700 dark:hover:bg-zinc-600">
                        <flux:icon.arrow-down-tray variant="micro" />
                        Download Image
                    </a>
                @endif
                <a href="{{ route('visualize') }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600">
                    Try Another Photo
                </a>
            </div>
        </div>

        {{-- CTA --}}
        <div class="mt-8 rounded-xl bg-gradient-to-r from-blue-600 to-purple-600 p-8 text-center shadow-lg">
            <h2 class="text-2xl font-bold text-white">Want This Look?</h2>
            <p class="mt-2 text-blue-100">Get a free, no-obligation painting estimate for your home.</p>
            <a href="{{ route('booking.general') }}"
               class="mt-4 inline-flex items-center gap-2 rounded-lg bg-white px-6 py-3 text-sm font-semibold text-blue-700 shadow hover:bg-blue-50">
                Book a Free Estimate
                <flux:icon.arrow-right variant="micro" />
            </a>
        </div>
    </section>
</div>
