<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use App\Models\CompanySetting;
use App\Models\PaintBrand;
use App\Models\PaintColor;
use App\Models\ProjectPreview;
use App\Models\ProjectPreviewView;
use App\Services\ColorVisualizationService;
use App\Services\ProjectPreviewService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.public')] class extends Component {
    use WithFileUploads;

    public ProjectPreview $preview;

    public string $token = '';

    // Color visualizer state
    public $uploadedPhoto;

    public string $prompt = '';

    public string $colorSearch = '';

    public ?int $selectedBrandId = null;

    public ?int $visualizationId = null;

    public bool $isGenerating = false;

    public function mount(string $token): void
    {
        $preview = ProjectPreview::where('public_token', $token)
            ->with([
                'photos.visualizations' => fn ($q) => $q->where('status', 'completed')->orderBy('sort_order'),
                'estimator',
                'customer',
            ])
            ->first();

        if (! $preview) {
            abort(404, 'Project preview not found.');
        }

        if ($preview->status === 'expired') {
            abort(410, 'This project preview has expired.');
        }

        $this->preview = $preview;
        $this->token = $token;

        // Record view
        $preview->recordView(Auth::user());
    }

    #[Computed]
    public function company(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function ballpark(): array
    {
        return app(ProjectPreviewService::class)->calculateBallpark($this->preview);
    }

    #[Computed]
    public function photosWithVisualizations(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->preview->photos()
            ->has('visualizations')
            ->with(['visualizations' => fn ($q) => $q->completed()->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();
    }

    // --- Color Visualizer methods (from ⚡color-visualizer.blade.php) ---

    #[Computed]
    public function visualization(): ?ColorVisualization
    {
        return $this->visualizationId
            ? ColorVisualization::find($this->visualizationId)
            : null;
    }

    #[Computed]
    public function colorSearchResults()
    {
        if (strlen($this->colorSearch) < 2) {
            return collect();
        }

        $query = PaintColor::query()
            ->with('brand')
            ->active()
            ->search($this->colorSearch);

        if ($this->selectedBrandId) {
            $query->where('paint_brand_id', $this->selectedBrandId);
        }

        return $query
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$this->colorSearch.'%', '%'.$this->colorSearch.'%'])
            ->limit(12)
            ->get();
    }

    #[Computed]
    public function availableBrands()
    {
        return PaintBrand::active()->ordered()->get();
    }

    #[Computed]
    public function interiorPopularColors()
    {
        if (! $this->selectedBrandId) {
            return collect();
        }

        return PaintColor::getCachedPopularColors($this->selectedBrandId, 'interior');
    }

    #[Computed]
    public function exteriorPopularColors()
    {
        if (! $this->selectedBrandId) {
            return collect();
        }

        return PaintColor::getCachedPopularColors($this->selectedBrandId, 'exterior');
    }

    public function selectSearchColor(int $colorId): void
    {
        $this->insertColorName($colorId);
        $this->colorSearch = '';
    }

    public function selectBrand(int $brandId): void
    {
        $this->selectedBrandId = $this->selectedBrandId === $brandId ? null : $brandId;
        unset($this->interiorPopularColors, $this->exteriorPopularColors, $this->colorSearchResults);
    }

    public function insertColorName(int $colorId): void
    {
        $color = PaintColor::with('brand')->find($colorId);
        if (! $color) {
            return;
        }

        $colorText = "{$color->brand->name} {$color->name}";
        $this->prompt = trim($this->prompt.($this->prompt ? ', ' : '').$colorText);
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
            'session_id' => session()->getId(),
            'original_image_path' => $path,
            'prompt' => $this->prompt,
            'disk' => $disk,
            'lead_name' => $this->preview->first_name,
            'lead_email' => $this->preview->email,
            'lead_phone' => $this->preview->phone,
            'lead_address' => $this->preview->address,
        ]);

        $this->visualizationId = $visualization->id;
        $this->isGenerating = true;

        GenerateColorVisualizationJob::dispatch($visualization);
    }

    public function regenerate(): void
    {
        $this->visualizationId = null;
        $this->isGenerating = false;
        $this->uploadedPhoto = null;
        $this->prompt = '';
    }

    public function checkStatus(): void
    {
        if ($this->visualization) {
            if ($this->visualization->isCompleted() || $this->visualization->isFailed()) {
                $this->isGenerating = false;
            }
        }
    }

    #[On('echo-private:project-preview.{preview.uuid},VisualizationCompleted')]
    public function onVisualizationCompleted(): void
    {
        $this->preview->refresh();
        unset($this->photosWithVisualizations);
    }
};
?>

<div class="max-w-4xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    {{-- Hero Header --}}
    <div class="text-center mb-10">
        @if($this->company->logo_path)
            <img src="{{ Storage::url($this->company->logo_path) }}" alt="{{ $this->company->company_name }}" class="h-12 mx-auto mb-4">
        @endif
        <flux:heading size="xl">Your Project Preview</flux:heading>
        @if($preview->first_name)
            <flux:text size="lg" class="mt-2 text-zinc-600 dark:text-zinc-400">
                Welcome, {{ $preview->first_name }}!
            </flux:text>
        @endif
    </div>

    {{-- Visualization Gallery --}}
    @if($this->photosWithVisualizations->isNotEmpty())
        <section class="mb-12">
            <flux:heading size="lg" class="mb-6">Your Rooms, Reimagined</flux:heading>

            <div class="space-y-8">
                @foreach($this->photosWithVisualizations as $photo)
                    <div wire:key="photo-{{ $photo->id }}" class="bg-white dark:bg-zinc-800 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                        @if($photo->room_label)
                            <div class="px-6 pt-4">
                                <flux:heading size="base">{{ $photo->room_label }}</flux:heading>
                            </div>
                        @endif

                        <div class="p-6">
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div class="relative">
                                    <img src="{{ $photo->getOriginalUrl() }}" alt="Original" class="w-full aspect-square object-cover rounded-lg border-2 border-zinc-300 dark:border-zinc-600">
                                    <span class="absolute bottom-2 left-2 bg-black/60 text-white text-xs px-2 py-0.5 rounded">Original</span>
                                </div>

                                @foreach($photo->visualizations as $viz)
                                    <div wire:key="viz-{{ $viz->id }}" class="relative group">
                                        <img src="{{ $viz->getResultUrl() }}" alt="{{ $viz->color_name }}" class="w-full aspect-square object-cover rounded-lg border-2 border-transparent hover:border-blue-500 transition-colors cursor-pointer">
                                        <div class="absolute bottom-2 left-2 right-2 bg-black/60 text-white text-xs px-2 py-1 rounded flex items-center gap-1.5">
                                            <span class="w-3 h-3 rounded-full shrink-0 border border-white/30" style="background-color: {{ $viz->color_hex }}"></span>
                                            <span class="truncate">{{ $viz->color_name }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        @if($photo->condition_notes)
                            <div class="px-6 pb-4 border-t border-zinc-100 dark:border-zinc-700 pt-3">
                                <div class="flex items-start gap-2">
                                    <flux:icon name="eye" class="w-4 h-4 text-amber-500 mt-0.5 shrink-0" />
                                    <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                        {{ $photo->condition_notes }}
                                    </flux:text>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Ballpark Pricing --}}
    @if(!empty($this->ballpark['rooms']))
        <section class="mb-12">
            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-xl p-6 border border-blue-200 dark:border-blue-800">
                <flux:heading size="lg" class="mb-4">Transparent Ballpark Pricing</flux:heading>
                <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mb-4">
                    These are rough estimates based on typical room sizes. Your estimator will provide an exact quote during the visit.
                </flux:text>

                <div class="space-y-3">
                    @foreach($this->ballpark['rooms'] as $room)
                        <div class="flex items-center justify-between py-2 border-b border-blue-200/50 dark:border-blue-700/50 last:border-0">
                            <flux:text class="font-medium">{{ $room['name'] }}</flux:text>
                            <flux:text class="font-semibold text-blue-700 dark:text-blue-300">
                                ${{ number_format($room['min'], 0) }} - ${{ number_format($room['max'], 0) }}
                            </flux:text>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 pt-4 border-t-2 border-blue-300 dark:border-blue-700">
                    <div class="flex items-center justify-between">
                        <flux:text class="font-bold text-lg">Estimated Range</flux:text>
                        <flux:text class="font-bold text-lg text-blue-700 dark:text-blue-300">
                            ${{ number_format($this->ballpark['total_min'], 0) }} - ${{ number_format($this->ballpark['total_max'], 0) }}
                        </flux:text>
                    </div>
                </div>

                <flux:text size="xs" class="mt-3 text-zinc-500 dark:text-zinc-500 italic">
                    This is a ballpark estimate for planning purposes only. Final pricing will be determined during your in-person estimate.
                </flux:text>
            </div>
        </section>
    @endif

    {{-- What to Expect Guide --}}
    <section class="mb-12">
        <flux:heading size="lg" class="mb-6">What to Expect</flux:heading>

        <div class="grid gap-4 sm:grid-cols-2">
            @php
                $steps = [
                    ['icon' => 'calendar-days', 'title' => 'Book', 'desc' => 'Schedule your free estimate at a time that works for you.', 'active' => true],
                    ['icon' => 'eye', 'title' => 'Preview', 'desc' => "You're here! See your rooms reimagined with new colors.", 'active' => true],
                    ['icon' => 'clipboard-document-check', 'title' => 'Estimate Visit', 'desc' => 'Your estimator visits in person to measure and provide an exact quote.', 'active' => false],
                    ['icon' => 'document-text', 'title' => 'Proposal', 'desc' => 'Receive a detailed proposal with transparent pricing and scope.', 'active' => false],
                    ['icon' => 'check-circle', 'title' => 'Approval', 'desc' => 'Review, sign digitally, and lock in your project dates.', 'active' => false],
                    ['icon' => 'paint-brush', 'title' => 'Paint Day', 'desc' => 'Our crew arrives prepared with premium materials and protects your home.', 'active' => false],
                ];
            @endphp

            @foreach($steps as $step)
                <div class="flex gap-3 p-4 rounded-lg {{ $step['active'] ? 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800' : 'bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700' }}">
                    <flux:icon name="{{ $step['icon'] }}" class="w-6 h-6 shrink-0 {{ $step['active'] ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400' }}" />
                    <div>
                        <flux:text class="font-semibold {{ $step['active'] ? 'text-blue-700 dark:text-blue-300' : '' }}">
                            {{ $step['title'] }}
                            @if($step['active'])
                                <flux:badge color="blue" size="sm" class="ml-1">Current</flux:badge>
                            @endif
                        </flux:text>
                        <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mt-0.5">{{ $step['desc'] }}</flux:text>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Your Estimator --}}
    @if($preview->estimator)
        <section class="mb-12">
            <flux:heading size="lg" class="mb-4">Your Estimator</flux:heading>

            <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 border border-zinc-200 dark:border-zinc-700 flex items-center gap-6">
                <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white text-xl font-bold shrink-0">
                    {{ strtoupper(substr($preview->estimator->name, 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <flux:heading size="base">{{ $preview->estimator->name }}</flux:heading>
                    @if($this->company->company_phone)
                        <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mt-1">
                            Text us: {{ $this->company->company_phone }}
                        </flux:text>
                    @endif
                    @if($preview->appointmentBooking)
                        <div class="mt-2 flex items-center gap-1.5">
                            <flux:icon name="calendar-days" class="w-4 h-4 text-blue-500" />
                            <flux:text size="sm" class="text-blue-600 dark:text-blue-400 font-medium">
                                Appointment: {{ $preview->appointmentBooking->scheduled_at?->format('l, M j \a\t g:ia') ?? 'TBD' }}
                            </flux:text>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{-- Login Incentive CTAs --}}
    @guest
        <section class="mb-12">
            <div class="bg-gradient-to-br from-zinc-50 to-zinc-100 dark:from-zinc-800 dark:to-zinc-800/50 rounded-xl p-6 border border-zinc-200 dark:border-zinc-700">
                <flux:heading size="lg" class="mb-4">Unlock More Features</flux:heading>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="flex gap-3 p-4 bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="chat-bubble-left-right" class="w-6 h-6 text-blue-500 shrink-0" />
                        <div>
                            <flux:text size="sm" class="font-semibold">Chat with your estimator</flux:text>
                            <flux:text size="xs" class="text-zinc-500 mt-0.5">Get answers to your questions directly</flux:text>
                        </div>
                    </div>
                    <div class="flex gap-3 p-4 bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="swatch" class="w-6 h-6 text-purple-500 shrink-0" />
                        <div>
                            <flux:text size="sm" class="font-semibold">Explore 100+ colors</flux:text>
                            <flux:text size="xs" class="text-zinc-500 mt-0.5">Try any color on your actual rooms</flux:text>
                        </div>
                    </div>
                    <div class="flex gap-3 p-4 bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="bell" class="w-6 h-6 text-green-500 shrink-0" />
                        <div>
                            <flux:text size="sm" class="font-semibold">Track your project</flux:text>
                            <flux:text size="xs" class="text-zinc-500 mt-0.5">Real-time updates from estimate to completion</flux:text>
                        </div>
                    </div>
                </div>

                <div class="mt-6 text-center">
                    <flux:button variant="primary" href="{{ route('customer.login') }}">
                        Create Free Account
                    </flux:button>
                    <flux:text size="xs" class="text-zinc-500 mt-2">Already have an account? <a href="{{ route('customer.login') }}" class="text-blue-600 hover:underline">Sign in</a></flux:text>
                </div>
            </div>
        </section>
    @endguest

    <flux:separator class="my-12" />

    {{-- ============================================ --}}
    {{-- COLOR VISUALIZER TOOL                        --}}
    {{-- Copied from ⚡color-visualizer.blade.php     --}}
    {{-- ============================================ --}}
    <section class="mb-12">
        <div class="text-center mb-8">
            <flux:heading size="xl">
                See Your Home in
                <span class="bg-gradient-to-r from-blue-500 to-purple-500 bg-clip-text text-transparent">New Colors</span>
            </flux:heading>
            <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">
                Upload a photo of any room. Pick your colors. See the transformation instantly.
            </flux:text>
        </div>

        {{-- Visualization Result --}}
        @if($this->visualization)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-lg dark:border-zinc-700 dark:bg-zinc-800 mb-8"
                 @if($isGenerating) wire:poll.3s="checkStatus" @endif>

                @if($isGenerating)
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="mb-4 h-12 w-12 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Generating Your Visualization</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Our AI is painting your room with the selected colors. This may take a minute...</p>
                    </div>
                @elseif($this->visualization->isCompleted())
                    <h3 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">Your Color Visualization</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="mb-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Original</p>
                            <img src="{{ storage_url($this->visualization->original_image_path, diskName: $this->visualization->disk) }}"
                                 alt="Original photo"
                                 class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700" />
                        </div>
                        <div>
                            <p class="mb-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Visualized</p>
                            <img src="{{ storage_url($this->visualization->result_image_path, diskName: $this->visualization->disk) }}"
                                 alt="Visualized result"
                                 class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700" />
                        </div>
                    </div>

                    @if($this->visualization->parsed_colors)
                        <div class="mt-4">
                            <p class="mb-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Colors Used</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($this->visualization->parsed_colors as $color)
                                    <div class="flex items-center gap-2 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 dark:border-zinc-700 dark:bg-zinc-800">
                                        <div class="h-4 w-4 rounded-full border border-zinc-300 dark:border-zinc-600" style="background-color: {{ $color['hex'] ?? '#ccc' }}"></div>
                                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $color['brand'] ?? '' }} {{ $color['name'] ?? '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mt-6">
                        <flux:button wire:click="regenerate" variant="primary">
                            Try Another Photo
                        </flux:button>
                    </div>
                @elseif($this->visualization->isFailed())
                    <div class="py-8 text-center">
                        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                            <flux:icon name="exclamation-triangle" class="h-6 w-6 text-red-600 dark:text-red-400" />
                        </div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Generation Failed</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Something went wrong. Please try again with a different photo.</p>
                        <flux:button wire:click="regenerate" variant="primary" class="mt-4">
                            Try Again
                        </flux:button>
                    </div>
                @endif
            </div>
        @endif

        {{-- Upload & Color Selection Form --}}
        @if(!$this->visualization || $this->visualization->isFailed())
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">Upload Your Photo</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Take a photo of any room or exterior and describe the colors you want.</p>

                <form wire:submit="generateVisualization" class="mt-6 space-y-6">
                    {{-- Photo upload --}}
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Photo</label>
                        <div class="mt-1">
                            <label class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-zinc-300 bg-zinc-50 p-6 transition hover:border-blue-400 hover:bg-blue-50/50 dark:border-zinc-600 dark:bg-zinc-900 dark:hover:border-blue-500 dark:hover:bg-blue-950/20">
                                @if($uploadedPhoto)
                                    <img src="{{ $uploadedPhoto->temporaryUrl() }}" alt="Preview" class="mb-2 max-h-48 rounded-lg" />
                                    <span class="text-sm text-zinc-500">Click to change photo</span>
                                @else
                                    <flux:icon name="photo" class="mb-2 h-10 w-10 text-zinc-400" />
                                    <span class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Click to upload or drag and drop</span>
                                    <span class="mt-1 text-xs text-zinc-400">JPG, PNG up to 10MB</span>
                                @endif
                                <input type="file" wire:model="uploadedPhoto" accept="image/*" class="hidden" />
                            </label>
                            @error('uploadedPhoto')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    {{-- Color search --}}
                    <div
                        x-data="{ open: false }"
                        x-on:click.outside="open = false"
                        class="relative"
                    >
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Find a Color</label>
                        <div class="mt-1">
                            <flux:input
                                wire:model.live.debounce.300ms="colorSearch"
                                x-on:focus="open = true"
                                x-on:input="open = true"
                                placeholder="{{ $selectedBrandId ? __('Search :brand colors...', ['brand' => $this->availableBrands->firstWhere('id', $selectedBrandId)?->code ?? '']) : __('Search 12,000+ colors by name or code...') }}"
                                icon="magnifying-glass"
                            />
                        </div>
                        <div wire:loading wire:target="colorSearch" class="absolute right-3 top-[2.35rem]">
                            <svg class="size-4 animate-spin text-zinc-400" viewBox="0 0 24 24"><path fill="currentColor" d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8Z"/></svg>
                        </div>

                        @if ($this->colorSearchResults->isNotEmpty())
                            <div
                                x-show="open"
                                x-transition.opacity
                                class="absolute z-50 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800 max-h-72 overflow-y-auto"
                            >
                                @foreach ($this->colorSearchResults as $result)
                                    <button
                                        type="button"
                                        wire:key="search-{{ $result->id }}"
                                        wire:click="selectSearchColor({{ $result->id }})"
                                        x-on:click="open = false"
                                        class="w-full flex items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                                    >
                                        <span
                                            class="size-8 shrink-0 rounded-md border border-zinc-300 shadow-sm dark:border-zinc-600"
                                            style="background-color: {{ $result->hex_value }}"
                                        ></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $result->name }}</div>
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $result->code }} &middot; {{ $result->hex_value }}</div>
                                        </div>
                                        <span class="shrink-0 text-xs font-medium text-zinc-400 dark:text-zinc-500">{{ $result->brand->code ?? $result->brand->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if ($this->colorSearchResults->isEmpty() && strlen($colorSearch) >= 2)
                            <div
                                x-show="open"
                                class="absolute z-50 mt-1 w-full bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 shadow-lg p-4 text-center"
                            >
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                    @if ($selectedBrandId)
                                        {{ __('No colors found for ":query" in :brand', ['query' => $colorSearch, 'brand' => $this->availableBrands->firstWhere('id', $selectedBrandId)?->name ?? '']) }}
                                    @else
                                        {{ __('No colors found for ":query"', ['query' => $colorSearch]) }}
                                    @endif
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- Color description --}}
                    <div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">
                            {{ __('Tip: "Paint [surface] [color name]" — e.g. "Paint walls Agreeable Gray"') }}
                        </p>
                        <flux:textarea wire:model="prompt" label="Describe the colors you want" rows="3"
                            placeholder="e.g., Paint the walls Benjamin Moore White Dove and the trim Sherwin-Williams Iron Ore" />
                        @error('prompt')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Brand Selector --}}
                    <div>
                        <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Paint Brand') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->availableBrands as $brand)
                                <button
                                    type="button"
                                    wire:key="brand-{{ $brand->id }}"
                                    wire:click="selectBrand({{ $brand->id }})"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all
                                        {{ $selectedBrandId === $brand->id
                                            ? 'bg-blue-600 text-white shadow-md'
                                            : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}"
                                >
                                    {{ $brand->name }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Popular Colors Carousels --}}
                    @if ($selectedBrandId)
                        @foreach (['interior' => 'Interior Popular', 'exterior' => 'Exterior Popular'] as $useType => $label)
                            @php $colors = $useType === 'interior' ? $this->interiorPopularColors : $this->exteriorPopularColors; @endphp
                            @if ($colors->isNotEmpty())
                                <div>
                                    <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __($label) }} <span class="font-normal text-zinc-400">({{ __('click to add') }})</span></p>
                                    <div class="relative group">
                                        <div class="flex gap-3 overflow-x-auto scroll-smooth snap-x snap-mandatory pb-1" style="-ms-overflow-style: none; scrollbar-width: none;">
                                            @foreach ($colors as $color)
                                                <button
                                                    type="button"
                                                    wire:key="popular-{{ $useType }}-{{ $color->id }}"
                                                    wire:click="insertColorName({{ $color->id }})"
                                                    x-data="{ tapped: false }"
                                                    x-on:click="tapped = true; setTimeout(() => tapped = false, 600)"
                                                    class="snap-start shrink-0 w-32 rounded-lg border-2 overflow-hidden transition-all hover:shadow-md cursor-pointer"
                                                    :class="tapped ? 'border-green-500 shadow-lg' : 'border-zinc-200 dark:border-zinc-700'"
                                                    title="{{ $color->brand->name }} {{ $color->name }} ({{ $color->code }})"
                                                >
                                                    <div class="h-20 w-full" style="background-color: {{ $color->hex_value }}"></div>
                                                    <div class="p-2 bg-white dark:bg-zinc-800">
                                                        <div class="text-xs font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $color->name }}</div>
                                                        <div class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ $color->code }}</div>
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    @else
                        <div class="text-center py-4 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('Select a brand to see popular colors') }}
                        </div>
                    @endif

                    {{-- Submit --}}
                    <div class="flex items-center justify-end">
                        <flux:button type="submit" variant="primary"
                            wire:loading.attr="disabled"
                            wire:target="generateVisualization,uploadedPhoto">
                            <span wire:loading.remove wire:target="generateVisualization">Generate Visualization</span>
                            <span wire:loading wire:target="generateVisualization">Generating...</span>
                        </flux:button>
                    </div>
                </form>
            </div>
        @endif
    </section>

    {{-- Footer --}}
    <div class="text-center pt-4 border-t border-zinc-200 dark:border-zinc-700">
        <flux:text size="sm" class="text-zinc-500">
            Questions? Text us at {{ $this->company->company_phone ?? 'our office' }}
        </flux:text>
    </div>
</div>
