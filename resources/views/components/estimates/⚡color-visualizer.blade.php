<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use App\Models\Estimate;
use App\Models\PaintColor;
use App\Models\PropertyPhoto;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Color Visualizer')] class extends Component {
    use WithFileUploads;

    public ?Estimate $estimate = null;

    public $uploadedPhoto;

    public string $prompt = '';

    public ?int $visualizationId = null;

    public bool $isGenerating = false;

    public bool $addedToEstimate = false;

    public string $colorSearch = '';

    public function mount(?Estimate $estimate = null): void
    {
        $this->estimate = $estimate;
    }

    #[Computed]
    public function colorSearchResults()
    {
        if (strlen($this->colorSearch) < 2) {
            return collect();
        }

        return PaintColor::query()
            ->with('brand')
            ->active()
            ->search($this->colorSearch)
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$this->colorSearch . '%'])
            ->limit(8)
            ->get();
    }

    public function selectSearchColor(int $colorId): void
    {
        $this->insertColorName($colorId);
        $this->colorSearch = '';
    }

    #[Computed]
    public function popularColors()
    {
        return PaintColor::getCachedPopularColors();
    }

    #[Computed]
    public function estimatePhotos()
    {
        if (! $this->estimate) {
            return collect();
        }

        return $this->estimate->photos()->latest()->get();
    }

    #[Computed]
    public function visualization(): ?ColorVisualization
    {
        if (! $this->visualizationId) {
            return null;
        }

        return ColorVisualization::find($this->visualizationId);
    }

    public function generateVisualization(): void
    {
        $this->validate([
            'prompt' => 'required|string|min:10',
            'uploadedPhoto' => 'required|image|max:10240',
        ]);

        $disk = config('filesystems.default');
        $path = $this->uploadedPhoto->store('visualizations/originals', $disk);

        $visualization = ColorVisualization::create([
            'user_id' => auth()->id(),
            'estimate_id' => $this->estimate?->id,
            'original_image_path' => $path,
            'prompt' => $this->prompt,
            'disk' => $disk,
        ]);

        $this->visualizationId = $visualization->id;
        $this->isGenerating = true;

        GenerateColorVisualizationJob::dispatch($visualization);
    }

    public function selectEstimatePhoto(int $photoId): void
    {
        $photo = $this->estimate?->photos()->find($photoId);
        if (! $photo) {
            return;
        }

        $visualization = ColorVisualization::create([
            'user_id' => auth()->id(),
            'estimate_id' => $this->estimate->id,
            'original_image_path' => $photo->path,
            'prompt' => $this->prompt ?: 'Awaiting color description...',
            'disk' => $photo->disk ?? config('filesystems.default'),
        ]);

        $this->visualizationId = $visualization->id;
    }

    public function insertColorName(int $colorId): void
    {
        $color = PaintColor::with('brand')->find($colorId);
        if (! $color) {
            return;
        }

        $colorText = "{$color->brand->name} {$color->name}";
        $this->prompt = trim($this->prompt . ($this->prompt ? ', ' : '') . $colorText);
    }

    public function regenerate(): void
    {
        $this->visualizationId = null;
        $this->isGenerating = false;
        $this->addedToEstimate = false;
        $this->uploadedPhoto = null;
    }

    public function addToEstimate(): void
    {
        if (! $this->estimate?->exists || ! $this->visualization?->isCompleted()) {
            return;
        }

        $viz = $this->visualization;
        $sourceDisk = $viz->disk ?? config('filesystems.default');
        $targetDisk = config('filesystems.default');

        $colorNames = collect($viz->parsed_colors)
            ->map(fn (array $c) => trim(($c['brand'] ?? '') . ' ' . ($c['name'] ?? '')))
            ->filter()
            ->implode(', ');

        // Copy the visualization result to the estimate photos storage path
        $filename = 'viz-' . $viz->uuid . '.png';
        $targetPath = "photos/estimates/{$this->estimate->id}/{$filename}";

        $contents = Storage::disk($sourceDisk)->get($viz->result_image_path);
        Storage::disk($targetDisk)->put($targetPath, $contents);

        $maxSortOrder = $this->estimate->photos()->max('sort_order') ?? 0;

        PropertyPhoto::create([
            'estimate_id' => $this->estimate->id,
            'property_id' => $this->estimate->deal?->property_id,
            'uploaded_by' => auth()->id(),
            'path' => $targetPath,
            'filename' => $filename,
            'original_filename' => $filename,
            'caption' => $colorNames ? "AI Visualization: {$colorNames}" : 'AI Color Visualization',
            'category' => 'reference',
            'disk' => $targetDisk,
            'show_on_proposal' => true,
            'mime_type' => 'image/png',
            'sort_order' => $maxSortOrder + 1,
        ]);

        $this->addedToEstimate = true;
    }

    public function checkStatus(): void
    {
        if ($this->visualizationId) {
            $viz = ColorVisualization::find($this->visualizationId);
            if ($viz && ($viz->isCompleted() || $viz->isFailed())) {
                $this->isGenerating = false;
            }
        }
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
            @if ($estimate?->exists)
                <a href="{{ route('estimates.show', $estimate) }}" wire:navigate class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    <flux:icon.arrow-left class="size-5" />
                </a>
            @endif
            <flux:heading size="xl">{{ __('AI Color Visualizer') }}</flux:heading>
        </div>
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ __('Upload a photo and describe the colors you want to see. Our AI will generate a visualization of your space with the new paint colors.') }}
        </flux:text>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- Left Column: Upload & Prompt --}}
        <div class="space-y-6">
            {{-- Photo Upload --}}
            <div>
                <flux:heading size="sm" class="mb-3">{{ __('Photo') }}</flux:heading>

                @if ($uploadedPhoto)
                    <div class="relative rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700">
                        <img src="{{ $uploadedPhoto->temporaryUrl() }}" alt="Uploaded photo" class="w-full h-auto max-h-80 object-contain bg-zinc-100 dark:bg-zinc-800" />
                        <button
                            type="button"
                            wire:click="$set('uploadedPhoto', null)"
                            class="absolute top-2 right-2 p-1.5 bg-red-500 text-white rounded-full hover:bg-red-600"
                        >
                            <flux:icon.x-mark class="size-4" />
                        </button>
                    </div>
                @else
                    <div
                        x-data="{ isDragging: false }"
                        x-on:dragover.prevent="isDragging = true"
                        x-on:dragleave.prevent="isDragging = false"
                        x-on:drop.prevent="isDragging = false; $refs.fileInput.files = $event.dataTransfer.files; $refs.fileInput.dispatchEvent(new Event('change'))"
                        class="relative border-2 border-dashed rounded-lg p-10 text-center transition-colors cursor-pointer"
                        :class="isDragging ? 'border-purple-500 bg-purple-50 dark:bg-purple-950' : 'border-zinc-300 dark:border-zinc-600 hover:border-zinc-400'"
                    >
                        <label class="cursor-pointer">
                            <flux:icon.photo class="size-12 mx-auto text-zinc-400 mb-3" />
                            <flux:text class="font-medium mb-1">{{ __('Drop your photo here or click to browse') }}</flux:text>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('JPG, PNG up to 10MB') }}</flux:text>
                            <input
                                type="file"
                                x-ref="fileInput"
                                wire:model="uploadedPhoto"
                                accept="image/*"
                                class="hidden"
                            />
                        </label>

                        <div wire:loading wire:target="uploadedPhoto" class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-zinc-900/80 rounded-lg">
                            <div class="flex items-center gap-2">
                                <flux:icon.arrow-path class="size-5 animate-spin" />
                                <flux:text>{{ __('Uploading...') }}</flux:text>
                            </div>
                        </div>
                    </div>
                @endif

                @error('uploadedPhoto')
                    <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                @enderror
            </div>

            {{-- Estimate Photos --}}
            @if ($estimate?->exists && $this->estimatePhotos->isNotEmpty())
                <div>
                    <flux:heading size="sm" class="mb-3">{{ __('Or Select from Estimate Photos') }}</flux:heading>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($this->estimatePhotos as $photo)
                            <button
                                wire:key="est-photo-{{ $photo->id }}"
                                wire:click="selectEstimatePhoto({{ $photo->id }})"
                                class="aspect-square rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 hover:border-purple-500 transition-colors"
                            >
                                <img src="{{ $photo->url }}" alt="{{ $photo->caption ?? $photo->filename }}" class="w-full h-full object-cover" />
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Color Search --}}
            <div
                x-data="{ open: false }"
                x-on:click.outside="open = false"
                class="relative"
            >
                <flux:heading size="sm" class="mb-3">{{ __('Find a Color') }}</flux:heading>
                <flux:input
                    wire:model.live.debounce.300ms="colorSearch"
                    x-on:focus="open = true"
                    x-on:input="open = true"
                    placeholder="{{ __('Search 12,000+ colors by name or code...') }}"
                    icon="magnifying-glass"
                />
                <div wire:loading wire:target="colorSearch" class="absolute right-3 top-[2.85rem]">
                    <flux:icon.arrow-path class="size-4 animate-spin text-zinc-400" />
                </div>

                @if ($this->colorSearchResults->isNotEmpty())
                    <div
                        x-show="open"
                        x-transition.opacity
                        class="absolute z-50 mt-1 w-full bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 shadow-lg max-h-72 overflow-y-auto"
                    >
                        @foreach ($this->colorSearchResults as $result)
                            <button
                                wire:key="search-{{ $result->id }}"
                                wire:click="selectSearchColor({{ $result->id }})"
                                x-on:click="open = false"
                                class="w-full flex items-center gap-3 px-3 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 transition-colors text-left"
                            >
                                <span
                                    class="size-8 rounded-md border border-zinc-300 dark:border-zinc-600 shrink-0 shadow-sm"
                                    style="background-color: {{ $result->hex_value }}"
                                ></span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $result->name }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $result->code }} &middot; {{ $result->hex_value }}</div>
                                </div>
                                <span class="text-xs font-medium text-zinc-400 dark:text-zinc-500 shrink-0">{{ $result->brand->code ?? $result->brand->name }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Color Prompt --}}
            <div>
                <flux:heading size="sm" class="mb-3">{{ __('Describe Your Colors') }}</flux:heading>
                <flux:textarea
                    wire:model="prompt"
                    placeholder="{{ __('e.g., Benjamin Moore White Dove on the walls, Sherwin-Williams Iron Ore on the trim') }}"
                    rows="3"
                />
                @error('prompt')
                    <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                @enderror
            </div>

            {{-- Popular Colors --}}
            @if ($this->popularColors->isNotEmpty())
                <div>
                    <flux:heading size="sm" class="mb-3">{{ __('Popular Colors') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Click a color to add it to your prompt.') }}</flux:text>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->popularColors as $color)
                            <button
                                wire:key="color-{{ $color->id }}"
                                wire:click="insertColorName({{ $color->id }})"
                                class="group flex items-center gap-2 px-3 py-1.5 rounded-full border border-zinc-200 dark:border-zinc-700 hover:border-purple-400 transition-colors text-sm"
                                title="{{ $color->brand->name }} {{ $color->name }}"
                            >
                                <span
                                    class="size-4 rounded-full border border-zinc-300 dark:border-zinc-600 shrink-0"
                                    style="background-color: {{ $color->hex_value }}"
                                ></span>
                                <span class="text-zinc-700 dark:text-zinc-300 group-hover:text-purple-600 dark:group-hover:text-purple-400">{{ $color->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Generate Button --}}
            <flux:button
                wire:click="generateVisualization"
                variant="primary"
                class="w-full"
                wire:loading.attr="disabled"
                wire:target="generateVisualization"
            >
                <span wire:loading.remove wire:target="generateVisualization">
                    <flux:icon.sparkles class="size-4 mr-1.5 inline" />
                    {{ __('Generate Visualization') }}
                </span>
                <span wire:loading wire:target="generateVisualization">
                    <flux:icon.arrow-path class="size-4 mr-1.5 inline animate-spin" />
                    {{ __('Starting...') }}
                </span>
            </flux:button>
        </div>

        {{-- Right Column: Results --}}
        <div>
            @if ($this->visualization)
                @if ($isGenerating)
                    {{-- Processing state --}}
                    <div wire:poll.3s="checkStatus" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-12 text-center">
                        <flux:icon.arrow-path class="size-12 mx-auto text-purple-500 mb-4 animate-spin" />
                        <flux:heading size="sm" class="mb-2">{{ __('Generating Your Visualization') }}</flux:heading>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Our AI is painting your space with the selected colors. This may take a minute...') }}
                        </flux:text>
                    </div>
                @elseif ($this->visualization->isCompleted())
                    {{-- Completed state --}}
                    <div class="space-y-4">
                        <flux:heading size="sm">{{ __('Result') }}</flux:heading>

                        {{-- Before/After --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Before') }}</flux:text>
                                <div class="rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700">
                                    <img src="{{ Storage::disk($this->visualization->disk ?? config('filesystems.default'))->url($this->visualization->original_image_path) }}" alt="Original" class="w-full h-auto" />
                                </div>
                            </div>
                            <div>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mb-1">{{ __('After') }}</flux:text>
                                <div class="rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700">
                                    <img src="{{ Storage::disk($this->visualization->disk ?? config('filesystems.default'))->url($this->visualization->result_image_path) }}" alt="Visualization" class="w-full h-auto" />
                                </div>
                            </div>
                        </div>

                        {{-- Parsed Colors --}}
                        @if ($this->visualization->parsed_colors)
                            <div>
                                <flux:text class="text-sm font-medium mb-2">{{ __('Identified Colors') }}</flux:text>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($this->visualization->parsed_colors as $parsedColor)
                                        <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800 text-sm">
                                            <span class="size-4 rounded-full border border-zinc-300 dark:border-zinc-600" style="background-color: {{ $parsedColor['hex'] ?? '#ccc' }}"></span>
                                            <span>{{ $parsedColor['brand'] ?? '' }} {{ $parsedColor['name'] ?? '' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Actions --}}
                        <div class="flex flex-wrap gap-3">
                            <flux:button wire:click="regenerate" variant="ghost" icon="arrow-path">
                                {{ __('New Visualization') }}
                            </flux:button>
                            <a href="{{ Storage::disk($this->visualization->disk ?? config('filesystems.default'))->url($this->visualization->result_image_path) }}" download class="inline-flex">
                                <flux:button variant="ghost" icon="arrow-down-tray">
                                    {{ __('Download') }}
                                </flux:button>
                            </a>
                            @if ($estimate?->exists)
                                @if ($addedToEstimate)
                                    <flux:button variant="ghost" icon="check" disabled>
                                        {{ __('Added to Estimate') }}
                                    </flux:button>
                                @else
                                    <flux:button wire:click="addToEstimate" variant="primary" icon="plus">
                                        {{ __('Add to Estimate') }}
                                    </flux:button>
                                @endif
                            @endif
                        </div>
                    </div>
                @elseif ($this->visualization->isFailed())
                    {{-- Failed state --}}
                    <div class="border border-red-200 dark:border-red-800 rounded-lg p-8 text-center">
                        <flux:icon.exclamation-triangle class="size-12 mx-auto text-red-500 mb-4" />
                        <flux:heading size="sm" class="mb-2">{{ __('Generation Failed') }}</flux:heading>
                        <flux:text class="text-zinc-500 dark:text-zinc-400 mb-4">
                            {{ $this->visualization->error_message ?? __('Something went wrong. Please try again.') }}
                        </flux:text>
                        <flux:button wire:click="regenerate" variant="primary" icon="arrow-path">
                            {{ __('Try Again') }}
                        </flux:button>
                    </div>
                @else
                    {{-- Pending state (selected estimate photo, waiting for prompt) --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                        <flux:icon.photo class="size-12 mx-auto text-zinc-400 mb-4" />
                        <flux:heading size="sm" class="mb-2">{{ __('Photo Selected') }}</flux:heading>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Enter your color description and click Generate to see the visualization.') }}
                        </flux:text>
                    </div>
                @endif
            @else
                {{-- Empty state --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-12 text-center">
                    <flux:icon.paint-brush class="size-12 mx-auto text-zinc-400 mb-4" />
                    <flux:heading size="sm" class="mb-2">{{ __('Your Visualization Will Appear Here') }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ __('Upload a photo, describe your desired colors, and click Generate to see an AI preview of your space.') }}
                    </flux:text>
                </div>
            @endif
        </div>
    </div>
</div>
