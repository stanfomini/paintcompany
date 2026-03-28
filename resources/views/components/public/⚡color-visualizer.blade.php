<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\PaintBrand;
use App\Models\PaintColor;
use App\Models\User;
use App\Services\ColorVisualizationService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.guest')] #[Title('AI Color Visualizer | See Your Home in New Colors')] class extends Component {
    use WithFileUploads;

    public $uploadedPhoto;
    public string $prompt = '';
    public string $colorSearch = '';
    public ?int $selectedBrandId = null;
    public ?int $visualizationId = null;
    public bool $isGenerating = false;
    public bool $showLeadForm = false;
    public ?string $reuseImagePath = null;
    public ?string $reuseImageDisk = null;

    // Lead form fields
    public string $leadFirstName = '';
    public string $leadLastName = '';
    public string $leadEmail = '';
    public string $leadPhone = '';
    public string $leadAddress = '';
    public bool $smsServiceConsent = false;
    public bool $smsMarketingConsent = false;
    public bool $leadSubmitted = false;

    #[Computed]
    public function visualization(): ?ColorVisualization
    {
        return $this->visualizationId
            ? ColorVisualization::find($this->visualizationId)
            : null;
    }

    #[Computed]
    public function sessionHistory()
    {
        return ColorVisualization::where('session_id', session()->getId())
            ->where('status', ColorVisualization::STATUS_COMPLETED)
            ->whereNotNull('result_image_path')
            ->latest()
            ->limit(20)
            ->get();
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
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$this->colorSearch . '%', '%' . $this->colorSearch . '%'])
            ->limit(12)
            ->get();
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

    #[Computed]
    public function remainingFreeUses(): int
    {
        $service = app(ColorVisualizationService::class);
        return $service->getRemainingFreeUses(session()->getId());
    }

    #[Computed]
    public function canGenerate(): bool
    {
        $service = app(ColorVisualizationService::class);
        return $service->canGenerateForSession(session()->getId()) || $this->leadSubmitted;
    }

    public function generateVisualization(): void
    {
        if (! $this->canGenerate) {
            $this->showLeadForm = true;
            return;
        }

        $hasReuse = $this->reuseImagePath && $this->reuseImageDisk;

        $this->validate([
            'prompt' => 'required|string|min:10',
            'uploadedPhoto' => $hasReuse ? 'nullable' : 'required|image|max:10240',
        ]);

        if ($hasReuse) {
            $disk = $this->reuseImageDisk;
            $path = $this->reuseImagePath;
        } else {
            $disk = config('filesystems.default');
            $path = $this->uploadedPhoto->store('visualizations/originals', $disk);
        }

        $visualization = ColorVisualization::create([
            'session_id' => session()->getId(),
            'original_image_path' => $path,
            'prompt' => $this->prompt,
            'disk' => $disk,
            'lead_name' => $this->leadFirstName ? trim("{$this->leadFirstName} {$this->leadLastName}") : null,
            'lead_email' => $this->leadEmail ?: null,
            'lead_phone' => $this->leadPhone ?: null,
            'lead_address' => $this->leadAddress ?: null,
        ]);

        $this->visualizationId = $visualization->id;
        $this->isGenerating = true;

        GenerateColorVisualizationJob::dispatch($visualization);
    }

    public function submitLeadForm(): void
    {
        $this->validate([
            'leadFirstName' => 'required|string|max:255',
            'leadLastName' => 'required|string|max:255',
            'leadEmail' => 'required|email|max:255',
            'leadPhone' => 'nullable|string|max:20',
            'leadAddress' => 'nullable|string|max:500',
            'smsServiceConsent' => ['nullable', 'boolean'],
            'smsMarketingConsent' => ['nullable', 'boolean'],
        ]);

        try {
            $firstInternal = User::where('type', 'internal')->first();

            $customer = Customer::firstOrCreate(
                ['email' => $this->leadEmail],
                [
                    'first_name' => $this->leadFirstName,
                    'last_name' => $this->leadLastName,
                    'phone' => $this->leadPhone ?: null,
                    'address' => $this->leadAddress ?: null,
                    'source' => 'online',
                    'created_by' => $firstInternal?->id,
                    'sms_marketing_consent_at' => $this->smsMarketingConsent ? now() : null,
                ]
            );

            if (! $customer->wasRecentlyCreated && $this->smsMarketingConsent && ! $customer->sms_marketing_consent_at) {
                $customer->update(['sms_marketing_consent_at' => now()]);
            }

            if ($customer->wasRecentlyCreated) {
                Deal::create([
                    'customer_id' => $customer->id,
                    'title' => "Color Visualizer Lead - {$this->leadFirstName} {$this->leadLastName}",
                    'stage' => 'lead',
                    'lead_source' => 'website',
                    'created_by' => $firstInternal?->id,
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Color visualizer lead capture failed', [
                'error' => $e->getMessage(),
                'email' => $this->leadEmail,
            ]);
        }

        $this->leadSubmitted = true;
        $this->showLeadForm = false;

        $this->dispatch('lead-captured');
    }

    public function insertColorName(int $colorId): void
    {
        $color = PaintColor::with('brand')->find($colorId);
        if (!$color) {
            return;
        }

        $colorText = "{$color->brand->name} {$color->name}";
        $this->prompt = trim($this->prompt . ($this->prompt ? ', ' : '') . $colorText);
    }

    public function tryDifferentColors(): void
    {
        $viz = $this->visualization;
        $this->reuseImagePath = $viz?->original_image_path;
        $this->reuseImageDisk = $viz?->disk;
        $this->visualizationId = null;
        $this->isGenerating = false;
        $this->prompt = '';
        $this->uploadedPhoto = null;
        unset($this->sessionHistory);
    }

    public function startFresh(): void
    {
        $this->visualizationId = null;
        $this->isGenerating = false;
        $this->uploadedPhoto = null;
        $this->reuseImagePath = null;
        $this->reuseImageDisk = null;
        $this->prompt = '';
        unset($this->sessionHistory);
    }

    public function viewHistory(int $id): void
    {
        $viz = ColorVisualization::where('id', $id)
            ->where('session_id', session()->getId())
            ->first();

        if ($viz) {
            $this->visualizationId = $viz->id;
            $this->isGenerating = false;
            unset($this->visualization);
        }
    }

    public function reusePhoto(int $id): void
    {
        $viz = ColorVisualization::where('id', $id)
            ->where('session_id', session()->getId())
            ->first();

        if ($viz) {
            $this->reuseImagePath = $viz->original_image_path;
            $this->reuseImageDisk = $viz->disk;
            $this->visualizationId = null;
            $this->isGenerating = false;
            $this->prompt = '';
            $this->uploadedPhoto = null;
            unset($this->sessionHistory);
        }
    }

    public function clearReuse(): void
    {
        $this->reuseImagePath = null;
        $this->reuseImageDisk = null;
    }

    public function checkStatus(): void
    {
        if ($this->visualization) {
            if ($this->visualization->isCompleted() || $this->visualization->isFailed()) {
                $this->isGenerating = false;
                unset($this->sessionHistory);
            }
        }
    }
};
?>

<div class="min-h-screen">
    {{-- Breadcrumb --}}
    <div class="bg-brand-900 pt-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 pt-4">
            @include('partials.public-breadcrumb', ['items' => [['label' => 'Color Visualizer']]])
        </div>
    </div>

    {{-- Hero Section --}}
    <section class="relative overflow-hidden bg-brand-900 py-16 sm:py-24">
        <div class="relative mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
            <h1 class="font-heading text-4xl tracking-tight text-white sm:text-5xl lg:text-6xl">
                See Your Home in
                <span class="text-accent-gold">New Colors</span>
            </h1>
            <p class="mt-4 text-lg text-zinc-300 sm:text-xl">
                Upload a photo. Pick your colors. Our AI shows you the transformation instantly.
            </p>
        </div>
    </section>

    <section class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
        {{-- Session History Strip --}}
        @if($this->sessionHistory->isNotEmpty())
            <div class="mb-8">
                <p class="mb-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Your Visualizations</p>
                <div class="flex gap-3 overflow-x-auto pb-2" style="scrollbar-width: thin;">
                    @foreach($this->sessionHistory as $history)
                        <button
                            wire:key="history-{{ $history->id }}"
                            wire:click="viewHistory({{ $history->id }})"
                            class="shrink-0 group relative rounded-lg overflow-hidden border-2 transition-all hover:shadow-md
                                {{ $visualizationId === $history->id ? 'border-blue-500 shadow-md' : 'border-zinc-200 dark:border-zinc-700' }}"
                        >
                            <img src="{{ storage_url($history->result_image_path, diskName: $history->disk) }}"
                                 alt="Visualization"
                                 class="h-20 w-28 object-cover" />
                            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors"></div>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Lead form modal --}}
        @if($showLeadForm)
        <div class="mb-8 rounded-xl border border-warm-200 bg-warm-50 p-6 shadow-lg dark:border-zinc-700 dark:bg-zinc-800/50">
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">Get Unlimited Visualizations + a Free Estimate</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Share your details to continue using the visualizer and receive a personalized painting estimate.</p>

            <form wire:submit="submitLeadForm" class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <flux:input wire:model="leadFirstName" label="First Name" required />
                </div>
                <div>
                    <flux:input wire:model="leadLastName" label="Last Name" required />
                </div>
                <div>
                    <flux:input wire:model="leadEmail" type="email" label="Email" required />
                </div>
                <div>
                    <flux:input wire:model="leadPhone" label="Phone" />
                </div>
                <div class="sm:col-span-2">
                    <flux:input wire:model="leadAddress" label="Property Address" placeholder="Where would you like the painting done?" />
                </div>

                {{-- SMS Service Consent (optional) --}}
                <div class="sm:col-span-2">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="smsServiceConsent" class="mt-0.5 text-emerald-600" />
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                            I agree to receive service-related text messages from {{ config('app.name') }} (appointment updates, estimate notifications, project info).
                            Message frequency varies. Msg &amp; data rates may apply. Reply STOP to cancel. Reply HELP for help.
                            <a href="{{ route('legal.privacy') }}" class="underline">Privacy Policy</a> |
                            <a href="{{ route('legal.terms') }}" class="underline">Terms</a>
                        </span>
                    </label>
                </div>

                {{-- SMS Marketing Consent (optional) --}}
                <div class="sm:col-span-2">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="smsMarketingConsent" class="mt-0.5 text-emerald-600" />
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                            I'd also like to receive promotional offers, seasonal discounts, and painting tips from {{ config('app.name') }} via text.
                            Message frequency varies. Msg &amp; data rates may apply. Reply STOP to cancel. Reply HELP for help.
                            <a href="{{ route('legal.privacy') }}" class="underline">Privacy Policy</a> |
                            <a href="{{ route('legal.terms') }}" class="underline">Terms</a>
                        </span>
                    </label>
                </div>

                <div class="sm:col-span-2">
                    <flux:button type="submit" variant="primary" class="w-full">
                        <span wire:loading.remove wire:target="submitLeadForm">Unlock Unlimited Visualizations</span>
                        <span wire:loading wire:target="submitLeadForm">Submitting...</span>
                    </flux:button>
                </div>
            </form>
        </div>
        @endif

        {{-- Results section (show when visualization exists) --}}
        @if($this->visualization)
            <div class="mb-8 rounded-xl border border-zinc-200 bg-white p-6 shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
                 @if($isGenerating) wire:poll.3s="checkStatus" @endif>

                @if($isGenerating)
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="mb-4 h-12 w-12 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Generating Your Visualization</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Our AI is painting your home with the selected colors. This may take a minute...</p>
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

                    {{-- Parsed colors --}}
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

                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="{{ route('visualize.result', $this->visualization->uuid) }}"
                           class="inline-flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600">
                            <flux:icon.share variant="micro" />
                            Share Result
                        </a>
                        <flux:button wire:click="tryDifferentColors" variant="primary">
                            <flux:icon.paint-brush variant="micro" class="mr-1" />
                            Try Different Colors
                        </flux:button>
                        <flux:button wire:click="startFresh" variant="ghost">
                            <flux:icon.photo variant="micro" class="mr-1" />
                            Upload New Photo
                        </flux:button>
                    </div>

                    {{-- CTA --}}
                    <div class="mt-6 rounded-lg bg-warm-50 p-4 dark:bg-zinc-900/50">
                        <p class="font-medium text-zinc-900 dark:text-white">Love this look?</p>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Get a free, no-obligation painting estimate for your home.</p>
                        <a href="{{ route('booking.general') }}"
                           class="mt-3 inline-flex items-center gap-2 rounded-lg bg-cta px-4 py-2 text-sm font-medium text-white hover:bg-cta-hover">
                            Book a Free Estimate
                            <flux:icon.arrow-right variant="micro" />
                        </a>
                    </div>
                @elseif($this->visualization->isFailed())
                    <div class="py-8 text-center">
                        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                            <flux:icon.exclamation-triangle class="h-6 w-6 text-red-600 dark:text-red-400" />
                        </div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Generation Failed</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Something went wrong. Please try again with a different photo.</p>
                        <div class="mt-4 flex justify-center gap-3">
                            <flux:button wire:click="tryDifferentColors" variant="primary">
                                Try Again (Same Photo)
                            </flux:button>
                            <flux:button wire:click="startFresh" variant="ghost">
                                Upload New Photo
                            </flux:button>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Upload & prompt form (hide when generating or showing result) --}}
        @if(!$this->visualization || $this->visualization->isFailed())
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">
                {{ $reuseImagePath ? 'Try Different Colors' : 'Upload Your Photo' }}
            </h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $reuseImagePath ? 'Describe the new colors you want for this photo.' : 'Take a photo of any room or exterior and describe the colors you want.' }}
            </p>

            <form wire:submit="generateVisualization" class="mt-6 space-y-6">
                {{-- Photo upload / reuse indicator --}}
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Photo</label>
                    <div class="mt-1">
                        @if($reuseImagePath)
                            {{-- Reusing a previous photo --}}
                            <div class="relative rounded-lg border-2 border-blue-300 bg-blue-50/50 p-4 dark:border-blue-600 dark:bg-blue-950/20">
                                <div class="flex items-center gap-4">
                                    <img src="{{ storage_url($reuseImagePath, diskName: $reuseImageDisk) }}"
                                         alt="Reusing photo"
                                         class="h-24 w-auto rounded-lg border border-zinc-200 dark:border-zinc-700" />
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-zinc-900 dark:text-white">Using previous photo</p>
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Describe your new color choices below.</p>
                                        <button
                                            type="button"
                                            wire:click="clearReuse"
                                            class="mt-2 inline-flex items-center gap-1 text-xs text-red-600 hover:text-red-700 dark:text-red-400"
                                        >
                                            <flux:icon.x-mark variant="micro" class="size-3" />
                                            Upload a different photo instead
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @else
                            <label class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-zinc-300 bg-zinc-50 p-6 transition hover:border-blue-400 hover:bg-blue-50/50 dark:border-zinc-600 dark:bg-zinc-900 dark:hover:border-blue-500 dark:hover:bg-blue-950/20">
                                @if($uploadedPhoto)
                                    <img src="{{ $uploadedPhoto->temporaryUrl() }}" alt="Preview" class="mb-2 max-h-48 rounded-lg" />
                                    <span class="text-sm text-zinc-500">Click to change photo</span>
                                @else
                                    <flux:icon.photo class="mb-2 h-10 w-10 text-zinc-400" />
                                    <span class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Click to upload or drag and drop</span>
                                    <span class="mt-1 text-xs text-zinc-400">JPG, PNG up to 10MB</span>
                                @endif
                                <input type="file" wire:model="uploadedPhoto" accept="image/*" class="hidden" />
                            </label>
                        @endif
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
                        <flux:icon.arrow-path class="size-4 animate-spin text-zinc-400" />
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
                                        ? 'bg-brand-700 text-white shadow-md'
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
                                <div
                                    x-data="{ scrollContainer: null }"
                                    x-init="scrollContainer = $refs.pub_carousel_{{ $useType }}"
                                    class="relative group"
                                >
                                    @if ($colors->count() > 2)
                                    <button
                                        type="button"
                                        x-on:click="scrollContainer.scrollBy({ left: -280, behavior: 'smooth' })"
                                        class="hidden sm:flex absolute -left-3 top-1/2 -translate-y-1/2 z-10 items-center justify-center size-8 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-600 shadow-md opacity-0 group-hover:opacity-100 transition-opacity"
                                    >
                                        <flux:icon.chevron-left class="size-4" />
                                    </button>
                                    @endif

                                    <div
                                        x-ref="pub_carousel_{{ $useType }}"
                                        class="flex gap-3 overflow-x-auto scroll-smooth snap-x snap-mandatory pb-1"
                                        style="-ms-overflow-style: none; scrollbar-width: none;"
                                    >
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

                                    @if ($colors->count() > 2)
                                    <button
                                        type="button"
                                        x-on:click="scrollContainer.scrollBy({ left: 280, behavior: 'smooth' })"
                                        class="hidden sm:flex absolute -right-3 top-1/2 -translate-y-1/2 z-10 items-center justify-center size-8 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-600 shadow-md opacity-0 group-hover:opacity-100 transition-opacity"
                                    >
                                        <flux:icon.chevron-right class="size-4" />
                                    </button>
                                    @endif
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
                <div class="flex items-center justify-between">
                    <div>
                        @if(!$leadSubmitted)
                            <flux:badge color="blue" size="sm">
                                {{ $this->remainingFreeUses }} free {{ Str::plural('visualization', $this->remainingFreeUses) }} remaining
                            </flux:badge>
                        @else
                            <flux:badge color="green" size="sm">
                                Unlimited visualizations unlocked
                            </flux:badge>
                        @endif
                    </div>
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

        {{-- Bottom CTA for visitors without a result --}}
        @if(!$this->visualization)
        <div class="mt-12 text-center">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Want to skip ahead?</p>
            <a href="{{ route('booking.general') }}"
               class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-cta hover:text-cta-hover dark:text-accent-gold dark:hover:text-white">
                Book a free painting estimate
                <flux:icon.arrow-right variant="micro" />
            </a>
        </div>
        @endif
    </section>

    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('lead-captured', () => {
                if (typeof fbq !== 'undefined') {
                    fbq('track', 'Lead', { content_name: 'Color Visualizer' });
                }
            });
        });
    </script>
</div>
