<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use App\Models\Customer;
use App\Models\Deal;
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
    public ?int $visualizationId = null;
    public bool $isGenerating = false;
    public bool $showLeadForm = false;

    // Lead form fields
    public string $leadFirstName = '';
    public string $leadLastName = '';
    public string $leadEmail = '';
    public string $leadPhone = '';
    public string $leadAddress = '';
    public bool $leadSubmitted = false;

    #[Computed]
    public function visualization(): ?ColorVisualization
    {
        return $this->visualizationId
            ? ColorVisualization::find($this->visualizationId)
            : null;
    }

    #[Computed]
    public function popularColors()
    {
        return PaintColor::query()
            ->with('brand')
            ->popular()
            ->active()
            ->orderBy('paint_brand_id')
            ->get();
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

        $this->validate([
            'prompt' => 'required|string|min:10',
            'uploadedPhoto' => 'required|image|max:10240',
        ]);

        $path = $this->uploadedPhoto->store('visualizations/originals', 'photos');

        $visualization = ColorVisualization::create([
            'session_id' => session()->getId(),
            'original_image_path' => $path,
            'prompt' => $this->prompt,
            'disk' => 'photos',
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
                ]
            );

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

    public function regenerate(): void
    {
        $this->visualizationId = null;
        $this->isGenerating = false;
        $this->uploadedPhoto = null;
    }

    public function checkStatus(): void
    {
        if ($this->visualization) {
            if ($this->visualization->isCompleted() || $this->visualization->isFailed()) {
                $this->isGenerating = false;
            }
        }
    }
};
?>

<div class="min-h-screen">
    {{-- Hero Section --}}
    <section class="relative overflow-hidden bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900 py-16 sm:py-24">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute inset-0 bg-gradient-to-r from-blue-500/20 to-purple-500/20"></div>
        </div>
        <div class="relative mx-auto max-w-4xl px-4 text-center sm:px-6 lg:px-8">
            <h1 class="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">
                See Your Home in
                <span class="bg-gradient-to-r from-blue-400 to-purple-400 bg-clip-text text-transparent">New Colors</span>
            </h1>
            <p class="mt-4 text-lg text-zinc-300 sm:text-xl">
                Upload a photo. Pick your colors. Our AI shows you the transformation instantly.
            </p>
        </div>
    </section>

    <section class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
        {{-- Lead form modal --}}
        @if($showLeadForm)
        <div class="mb-8 rounded-xl border border-blue-200 bg-blue-50 p-6 shadow-lg dark:border-blue-800 dark:bg-blue-950/50">
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
                            <img src="{{ Storage::disk($this->visualization->disk)->url($this->visualization->original_image_path) }}"
                                 alt="Original photo"
                                 class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700" />
                        </div>
                        <div>
                            <p class="mb-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">Visualized</p>
                            <img src="{{ Storage::disk($this->visualization->disk)->url($this->visualization->result_image_path) }}"
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
                        <flux:button wire:click="regenerate" variant="primary">
                            Try Another Photo
                        </flux:button>
                    </div>

                    {{-- CTA --}}
                    <div class="mt-6 rounded-lg bg-gradient-to-r from-blue-50 to-purple-50 p-4 dark:from-blue-950/30 dark:to-purple-950/30">
                        <p class="font-medium text-zinc-900 dark:text-white">Love this look?</p>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Get a free, no-obligation painting estimate for your home.</p>
                        <a href="{{ route('booking.general') }}"
                           class="mt-3 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
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
                        <flux:button wire:click="regenerate" variant="primary" class="mt-4">
                            Try Again
                        </flux:button>
                    </div>
                @endif
            </div>
        @endif

        {{-- Upload & prompt form (hide when generating or showing result) --}}
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
                                <flux:icon.photo class="mb-2 h-10 w-10 text-zinc-400" />
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

                {{-- Color description --}}
                <div>
                    <flux:textarea wire:model="prompt" label="Describe the colors you want" rows="3"
                        placeholder="e.g., Paint the walls Benjamin Moore White Dove and the trim Sherwin-Williams Iron Ore" />
                    @error('prompt')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Popular colors --}}
                @if($this->popularColors->isNotEmpty())
                <div>
                    <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Popular Colors <span class="font-normal text-zinc-400">(click to add)</span></p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($this->popularColors as $color)
                            <button type="button"
                                    wire:click="insertColorName({{ $color->id }})"
                                    wire:key="color-{{ $color->id }}"
                                    title="{{ $color->brand->name }} {{ $color->name }} ({{ $color->code }})"
                                    class="group flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs transition hover:border-blue-300 hover:bg-blue-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-blue-600 dark:hover:bg-blue-950/30">
                                <span class="h-3.5 w-3.5 rounded-full border border-zinc-300 dark:border-zinc-600"
                                      style="background-color: {{ $color->hex_value }}"></span>
                                <span class="text-zinc-600 group-hover:text-blue-700 dark:text-zinc-400 dark:group-hover:text-blue-300">{{ $color->name }}</span>
                            </button>
                        @endforeach
                    </div>
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
               class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
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
