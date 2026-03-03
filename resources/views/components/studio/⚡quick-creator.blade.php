<?php

use App\Models\PropertyPhoto;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Quick Creator'])]
class extends Component {
    public VideoProject $videoProject;

    public string $title = '';

    public ?int $pipBackgroundIndex = null;

    public function mount(VideoProject $videoProject): void
    {
        abort_unless($videoProject->user_id === auth()->id(), 403);

        $this->videoProject = $videoProject;
        $this->title = $videoProject->title;

        if ($videoProject->scenes()->count() === 0) {
            $videoProject->scenes()->create([
                'sort_order' => 0,
                'name' => 'Main',
                'layout' => 'solo',
            ]);
        }
    }

    #[Computed]
    public function clips()
    {
        return $this->videoProject->scenes()
            ->with('clips.source')
            ->orderBy('sort_order')
            ->get()
            ->flatMap(fn ($scene) => $scene->clips->sortBy('sort_order'))
            ->values();
    }

    #[Computed]
    public function overlays()
    {
        return $this->videoProject->scenes()
            ->with('overlays')
            ->orderBy('sort_order')
            ->get()
            ->flatMap(fn ($scene) => $scene->overlays)
            ->values();
    }

    public function addClip(string $type, int $id): void
    {
        $typeMap = [
            'property-photo' => PropertyPhoto::class,
            'video-recording' => VideoRecording::class,
        ];

        $modelClass = $typeMap[$type] ?? null;
        if (! $modelClass) {
            return;
        }

        $scene = $this->videoProject->scenes()->firstOrCreate(
            [],
            ['sort_order' => 0, 'name' => 'Main', 'layout' => 'solo']
        );

        $nextOrder = ($scene->clips()->max('sort_order') ?? -1) + 1;

        $scene->clips()->create([
            'slot' => 'main',
            'source_type' => $modelClass,
            'source_id' => $id,
            'sort_order' => $nextOrder,
            'volume' => 100,
            'fit_mode' => 'cover',
        ]);

        unset($this->clips);
    }

    public function addRecordedClip(int $recordingId): void
    {
        $this->addClip('video-recording', $recordingId);
    }

    public function removeClip(int $index): void
    {
        $clips = $this->clips;

        if (isset($clips[$index])) {
            $clips[$index]->delete();
            unset($this->clips);
        }
    }

    public function reorderClips(array $orderedIds): void
    {
        foreach ($orderedIds as $sortOrder => $clipId) {
            $this->videoProject->scenes()
                ->first()
                ?->clips()
                ->where('id', $clipId)
                ->update(['sort_order' => $sortOrder]);
        }

        unset($this->clips);
    }

    public function saveTitle(): void
    {
        $this->validate(['title' => 'required|string|min:1']);

        $this->videoProject->title = $this->title;
        $this->videoProject->save();
    }

    public function setAspectRatio(string $ratio): void
    {
        if (! in_array($ratio, ['9:16', '16:9', '1:1'])) {
            return;
        }

        $this->videoProject->update(['aspect_ratio' => $ratio]);
    }

    #[On('media-selected')]
    public function onMediaSelected(string $type, int $id): void
    {
        $this->addClip($type, $id);
    }
};
?>

<div
    x-data="{
        activeClipIndex: 0,
        showMediaBrowser: false,
        previewUrl: null,
        previewType: null,

        setActiveClip(index, url, type) {
            this.activeClipIndex = index;
            this.previewUrl = url;
            this.previewType = type;
        }
    }"
    class="flex flex-col h-[calc(100vh-4rem)] bg-zinc-950"
>
    {{-- Top bar --}}
    <div class="flex items-center justify-between px-4 py-3 bg-zinc-900 border-b border-zinc-800">
        <a href="{{ route('studio') }}" wire:navigate class="text-zinc-400 hover:text-white transition-colors">
            <flux:icon name="arrow-left" class="size-5" />
        </a>
        <div class="flex-1 mx-4">
            <input
                type="text"
                wire:model.blur="title"
                wire:change="saveTitle"
                class="w-full bg-transparent text-white text-center text-sm font-medium border-0 focus:ring-0 focus:outline-none placeholder-zinc-500"
                placeholder="{{ __('Untitled Project') }}"
            />
        </div>
        <a href="{{ route('studio') }}" wire:navigate class="text-sm font-medium text-blue-400 hover:text-blue-300">
            {{ __('Done') }}
        </a>
    </div>

    {{-- Aspect ratio pills --}}
    <div class="flex items-center justify-center gap-2 px-4 py-2 bg-zinc-900/50">
        @foreach(['9:16' => '9:16', '16:9' => '16:9', '1:1' => '1:1'] as $ratio => $label)
            <button
                wire:click="setAspectRatio('{{ $ratio }}')"
                class="px-3 py-1 text-xs font-medium rounded-full transition-colors {{ $videoProject->aspect_ratio === $ratio ? 'bg-white text-zinc-900' : 'text-zinc-400 hover:text-white hover:bg-zinc-800' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Preview area --}}
    <div class="flex-1 flex items-center justify-center p-4 overflow-hidden">
        <div
            class="relative bg-zinc-800 rounded-lg overflow-hidden flex items-center justify-center
                {{ $videoProject->aspect_ratio === '9:16' ? 'h-full max-h-full aspect-[9/16]' : '' }}
                {{ $videoProject->aspect_ratio === '16:9' ? 'w-full max-w-full aspect-video' : '' }}
                {{ $videoProject->aspect_ratio === '1:1' ? 'h-full max-h-full aspect-square' : '' }}"
        >
            {{-- Preview content --}}
            <template x-if="previewUrl && previewType === 'image'">
                <img :src="previewUrl" alt="" class="w-full h-full object-cover" />
            </template>
            <template x-if="previewUrl && previewType === 'video'">
                <video :src="previewUrl" controls class="w-full h-full object-cover"></video>
            </template>
            <template x-if="!previewUrl">
                <div class="text-center p-8">
                    <flux:icon name="photo" class="mx-auto size-12 text-zinc-600" />
                    <p class="mt-3 text-sm text-zinc-500">{{ __('Add media to get started') }}</p>
                    <button
                        @click="showMediaBrowser = true"
                        class="mt-3 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-500 rounded-lg transition-colors"
                    >
                        {{ __('Browse Media') }}
                    </button>
                </div>
            </template>
        </div>
    </div>

    {{-- Clip strip --}}
    <div class="bg-zinc-900 border-t border-zinc-800 px-4 py-3">
        <div class="flex items-center gap-2 overflow-x-auto pb-1">
            @forelse($this->clips as $index => $clip)
                <div
                    wire:key="clip-{{ $clip->id }}"
                    class="shrink-0 relative group cursor-pointer rounded-lg overflow-hidden border-2 transition-colors
                        {{ $index === 0 ? 'border-blue-500' : 'border-zinc-700 hover:border-zinc-500' }}"
                    :class="{ 'border-blue-500': activeClipIndex === {{ $index }}, 'border-zinc-700 hover:border-zinc-500': activeClipIndex !== {{ $index }} }"
                    @click="setActiveClip(
                        {{ $index }},
                        '{{ $clip->source instanceof \App\Models\PropertyPhoto ? $clip->source->url : ($clip->source instanceof \App\Models\VideoRecording ? ($clip->source->getThumbnailUrl() ?? '') : '') }}',
                        '{{ $clip->source instanceof \App\Models\VideoRecording ? 'video' : 'image' }}'
                    )"
                >
                    <div class="w-16 h-16 bg-zinc-800">
                        @if($clip->source instanceof \App\Models\PropertyPhoto)
                            <img src="{{ $clip->source->url }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                        @elseif($clip->source instanceof \App\Models\VideoRecording && $clip->source->getThumbnailUrl())
                            <img src="{{ $clip->source->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <flux:icon name="film" class="size-6 text-zinc-600" />
                            </div>
                        @endif
                    </div>

                    {{-- Duration badge for video --}}
                    @if($clip->source instanceof \App\Models\VideoRecording && $clip->source->formatted_duration)
                        <span class="absolute bottom-0.5 right-0.5 bg-black/75 text-white text-[10px] px-1 rounded">
                            {{ $clip->source->formatted_duration }}
                        </span>
                    @endif

                    {{-- Delete button --}}
                    <button
                        wire:click.stop="removeClip({{ $index }})"
                        class="absolute top-0.5 right-0.5 bg-black/75 text-white rounded-full p-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
                    >
                        <flux:icon name="x-mark" class="size-3" />
                    </button>
                </div>
            @empty
            @endforelse

            {{-- Add clip button --}}
            <button
                @click="showMediaBrowser = true"
                class="shrink-0 w-16 h-16 rounded-lg border-2 border-dashed border-zinc-700 hover:border-zinc-500 flex items-center justify-center text-zinc-500 hover:text-zinc-400 transition-colors"
            >
                <flux:icon name="plus" class="size-6" />
            </button>
        </div>
    </div>

    {{-- Tool bar placeholder --}}
    <div class="bg-zinc-900 border-t border-zinc-800 px-4 py-3">
        <div class="flex items-center justify-center gap-4 text-zinc-600 text-xs">
            <span>{{ __('Tools coming soon') }}</span>
        </div>
    </div>

    {{-- Media browser --}}
    <div x-show="showMediaBrowser" x-cloak @media-selected.window="showMediaBrowser = false">
        <livewire:studio.media-browser />
    </div>
</div>
