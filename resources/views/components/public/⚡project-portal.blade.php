<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\AppointmentBooking;
use App\Models\ColorVisualization;
use App\Models\CompanySetting;
use App\Models\PaintBrand;
use App\Models\PaintColor;
use App\Models\ProjectPreview;
use App\Models\ProjectPreviewPhoto;
use App\Models\ProjectPreviewView;
use App\Models\ProjectPreviewVisualization;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Services\ProjectPreviewService;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth; // kept for recordView
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.public')] class extends Component {
    use WithFileUploads;

    public ProjectPreview $preview;

    public string $token = '';

    public string $activeStep = 'preview';

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
                'estimate',
                'appointmentBooking.estimator',
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

        $preview->recordView(Auth::user());
    }

    public function setStep(string $step): void
    {
        $this->activeStep = $step;
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

    #[Computed]
    public function booking(): ?AppointmentBooking
    {
        if ($this->preview->appointment_booking_id) {
            return AppointmentBooking::with('estimator')
                ->find($this->preview->appointment_booking_id);
        }

        if ($this->preview->deal_id) {
            return AppointmentBooking::where('deal_id', $this->preview->deal_id)
                ->with('estimator')
                ->latest()
                ->first();
        }

        if ($this->preview->customer_id) {
            return AppointmentBooking::where('customer_id', $this->preview->customer_id)
                ->with('estimator')
                ->latest()
                ->first();
        }

        return null;
    }

    #[Computed]
    public function currentEstimator(): ?User
    {
        // Prefer booking estimator (freshly queried), fall back to preview's estimator (re-queried to avoid stale data)
        if ($this->booking?->estimator) {
            return $this->booking->estimator;
        }

        if ($this->preview->estimator_id) {
            return User::find($this->preview->estimator_id);
        }

        return null;
    }

    #[Computed]
    public function googleCalendarUrl(): ?string
    {
        $b = $this->booking;
        if (! $b || ! $b->booked_date) {
            return null;
        }

        $start = Carbon::parse($b->booked_date->format('Y-m-d').' '.$b->booked_start_time);
        $end = Carbon::parse($b->booked_date->format('Y-m-d').' '.$b->booked_end_time);

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => 'Estimate Appointment',
            'dates' => $start->format('Ymd\THis').'/'.$end->format('Ymd\THis'),
            'location' => $b->prospect_address,
            'details' => 'Estimate appointment with '.($this->company->company_name ?? 'our team'),
        ]);
    }

    /**
     * Determine which estimate step is "current" based on deal/booking state.
     */
    #[Computed]
    public function currentEstimateStep(): string
    {
        $hasBooking = (bool) $this->booking;
        $hasPhotos = $this->preview->photos()->exists();

        if (! $hasBooking && ! $hasPhotos) {
            return 'book';
        }
        if ($hasBooking && ! $hasPhotos) {
            return 'preview';
        }

        return 'preview';
    }

    // --- Color Visualizer methods ---

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
        return $this->selectedBrandId
            ? PaintColor::getCachedPopularColors($this->selectedBrandId, 'interior')
            : collect();
    }

    #[Computed]
    public function exteriorPopularColors()
    {
        return $this->selectedBrandId
            ? PaintColor::getCachedPopularColors($this->selectedBrandId, 'exterior')
            : collect();
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
        if ($this->visualization && ($this->visualization->isCompleted() || $this->visualization->isFailed())) {
            $this->isGenerating = false;
        }
    }

    public function saveVisualizationToPage(): void
    {
        $viz = $this->visualization;
        if (! $viz || ! $viz->isCompleted()) {
            return;
        }

        $sourceDisk = $viz->disk ?? config('filesystems.default');
        $targetDisk = config('filesystems.default');

        // Create a ProjectPreviewPhoto from the original image
        $originalFilename = 'viz-original-' . $viz->uuid . '.png';
        $originalPath = "photos/previews/{$this->preview->id}/{$originalFilename}";
        $contents = Storage::disk($sourceDisk)->get($viz->original_image_path);
        Storage::disk($targetDisk)->put($originalPath, $contents);

        $maxSortOrder = $this->preview->photos()->max('sort_order') ?? 0;

        $photo = ProjectPreviewPhoto::create([
            'project_preview_id' => $this->preview->id,
            'original_path' => $originalPath,
            'disk' => $targetDisk,
            'room_label' => 'Color Visualization',
            'sort_order' => $maxSortOrder + 1,
        ]);

        // Create the visualization record linked to the photo
        $resultFilename = 'viz-result-' . $viz->uuid . '.png';
        $resultPath = "photos/previews/{$this->preview->id}/{$resultFilename}";
        $resultContents = Storage::disk($sourceDisk)->get($viz->result_image_path);
        Storage::disk($targetDisk)->put($resultPath, $resultContents);

        $colorName = collect($viz->parsed_colors)
            ->map(fn (array $c) => trim(($c['brand'] ?? '') . ' ' . ($c['name'] ?? '')))
            ->filter()
            ->first() ?? 'Custom Color';

        $colorHex = $viz->parsed_colors[0]['hex'] ?? null;
        $colorBrand = $viz->parsed_colors[0]['brand'] ?? null;

        ProjectPreviewVisualization::create([
            'project_preview_photo_id' => $photo->id,
            'result_image_path' => $resultPath,
            'disk' => $targetDisk,
            'color_name' => $colorName,
            'color_hex' => $colorHex,
            'color_brand' => $colorBrand,
            'is_primary' => true,
            'status' => ProjectPreviewVisualization::STATUS_COMPLETED,
            'sort_order' => 0,
        ]);

        // Clear cached computed so the gallery refreshes
        unset($this->photosWithVisualizations);
    }

    public function addVisualizationToEstimate(): void
    {
        $viz = $this->visualization;
        $estimate = $this->preview->estimate;

        if (! $viz || ! $viz->isCompleted() || ! $estimate) {
            return;
        }

        $sourceDisk = $viz->disk ?? config('filesystems.default');
        $targetDisk = config('filesystems.default');

        $colorNames = collect($viz->parsed_colors)
            ->map(fn (array $c) => trim(($c['brand'] ?? '') . ' ' . ($c['name'] ?? '')))
            ->filter()
            ->implode(', ');

        $filename = 'viz-' . $viz->uuid . '.png';
        $targetPath = "photos/estimates/{$estimate->id}/{$filename}";

        $contents = Storage::disk($sourceDisk)->get($viz->result_image_path);
        Storage::disk($targetDisk)->put($targetPath, $contents);

        $maxSortOrder = $estimate->photos()->max('sort_order') ?? 0;

        PropertyPhoto::create([
            'estimate_id' => $estimate->id,
            'property_id' => $estimate->deal?->property_id,
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
    }

    #[Computed]
    public function isAuthenticated(): bool
    {
        return auth('customer')->check() || auth()->check();
    }

    #[Computed]
    public function portalAccessStatus(): string
    {
        $customer = $this->preview->customer;
        if (! $customer) {
            return 'none';
        }
        if ($customer->hasPortalAccess()) {
            return 'has_account';
        }

        return 'no_account';
    }

    #[Computed]
    public function loginCtaText(): string
    {
        return match ($this->portalAccessStatus) {
            'has_account' => __('Login'),
            default => __('Enable Your Portal'),
        };
    }

    #[Computed]
    public function loginCtaUrl(): string
    {
        return route('customer.login') . '?return=' . urlencode(url("/preview/{$this->token}"));
    }

    #[On('echo-private:project-preview.{preview.uuid},VisualizationCompleted')]
    public function onVisualizationCompleted(): void
    {
        $this->preview->refresh();
        unset($this->photosWithVisualizations);
    }

    #[On('questionnaire-completed')]
    public function onQuestionnaireCompleted(): void
    {
        $this->preview->refresh();
    }
};
?>

<div class="max-w-4xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    {{-- Appointment Reminder Banner --}}
    @if($this->booking && $this->booking->booked_date && $this->booking->status === 'confirmed')
        @php
            $appointmentEnd = Carbon::parse($this->booking->booked_date->format('Y-m-d') . ' ' . $this->booking->booked_end_time);
        @endphp
        @if($appointmentEnd->isFuture())
            <div class="mb-6 bg-gradient-to-r from-blue-600 to-blue-700 rounded-xl p-4 sm:p-5 text-white shadow-lg">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                            <flux:icon name="calendar-days" class="w-5 h-5 text-white" />
                        </div>
                        <div>
                            <div class="text-sm font-medium text-blue-100">{{ __('Your Estimate Appointment') }}</div>
                            <div class="text-lg font-bold">
                                {{ $this->booking->booked_date->format('l, M j') }} &middot; {{ Carbon::parse($this->booking->booked_start_time)->format('g:i A') }}
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($this->booking->estimator)
                            <div class="hidden sm:flex items-center gap-2 bg-white/10 rounded-lg px-3 py-1.5">
                                <flux:avatar size="xs" :src="$this->booking->estimator->getAvatarUrl()" name="{{ $this->booking->estimator->name }}" />
                                <span class="text-sm">{{ $this->booking->estimator->name }}</span>
                            </div>
                        @endif
                        @if($this->googleCalendarUrl)
                            <a href="{{ $this->googleCalendarUrl }}" target="_blank" rel="noopener noreferrer" class="bg-white/20 hover:bg-white/30 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors">
                                <flux:icon name="calendar" class="w-4 h-4 inline -mt-0.5" /> {{ __('Add to Calendar') }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endif

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

    {{-- ================================================ --}}
    {{-- TIMELINE 1: Estimate Journey                     --}}
    {{-- ================================================ --}}
    @php
        $hasBooking = (bool) $this->booking;
        $hasVisualizations = $this->photosWithVisualizations->isNotEmpty();
        $questionnaireCompleted = $preview->isQuestionnaireCompleted();
        $hasEstimate = (bool) $preview->estimate_id;
        $estimateStatus = $hasEstimate ? $preview->estimate?->status : null;
        $estimateSentOrAccepted = in_array($estimateStatus, ['sent', 'accepted']);
        $estimateSteps = [
            'book'       => ['label' => 'Book',            'icon' => 'calendar-days',            'done' => $hasBooking],
            'preview'    => ['label' => 'Preview',          'icon' => 'eye',                      'done' => $questionnaireCompleted, 'active' => !$questionnaireCompleted],
            'estimate'   => ['label' => 'Estimate Visit',   'icon' => 'clipboard-document-check', 'done' => $estimateSentOrAccepted, 'active' => $questionnaireCompleted && !$estimateSentOrAccepted],
            'approval'   => ['label' => 'Approval',         'icon' => 'check-circle',             'done' => $estimateStatus === 'accepted', 'active' => $estimateSentOrAccepted && $estimateStatus !== 'accepted'],
        ];
        $estimateStepKeys = array_keys($estimateSteps);
    @endphp

    <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-6 mb-6 bg-white dark:bg-zinc-800">
        <flux:heading size="sm" class="mb-6">{{ __('Estimate Journey') }}</flux:heading>
        <div class="flex items-center justify-between">
            @foreach($estimateSteps as $stepKey => $step)
                @php
                    $isDone = $step['done'] ?? false;
                    $isActive = ($step['active'] ?? false) || $activeStep === $stepKey;
                    $stepIdx = array_search($stepKey, $estimateStepKeys);
                @endphp
                <button
                    wire:click="setStep('{{ $stepKey }}')"
                    wire:key="est-step-{{ $stepKey }}"
                    class="flex-1 flex flex-col items-center relative cursor-pointer group"
                >
                    @if(! $loop->first)
                        <div class="absolute top-5 -left-1/2 w-full h-0.5 {{ $isDone ? 'bg-green-500' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
                    @endif

                    <div class="relative z-10 flex items-center justify-center w-10 h-10 rounded-full border-2 transition-all
                        {{ $isDone ? 'bg-green-500 border-green-500 text-white' : '' }}
                        {{ $isActive && !$isDone ? 'bg-blue-500 border-blue-500 text-white ring-4 ring-blue-100 dark:ring-blue-900' : '' }}
                        {{ !$isDone && !$isActive ? 'bg-white dark:bg-zinc-800 border-zinc-300 dark:border-zinc-600 text-zinc-400 group-hover:border-blue-400 group-hover:text-blue-400' : '' }}
                    ">
                        @if($isDone)
                            <flux:icon name="check" class="w-5 h-5" />
                        @else
                            <flux:icon name="{{ $step['icon'] }}" class="w-5 h-5" />
                        @endif
                    </div>

                    <flux:text class="mt-2 text-xs text-center leading-tight
                        {{ $isActive ? 'font-semibold text-blue-600 dark:text-blue-400' : 'text-zinc-500 group-hover:text-zinc-700 dark:group-hover:text-zinc-300' }}
                    ">
                        {{ $step['label'] }}
                    </flux:text>
                </button>
            @endforeach
        </div>
    </div>

    {{-- ================================================ --}}
    {{-- STEP CONTENT                                     --}}
    {{-- ================================================ --}}

    {{-- BOOK --}}
    @if($activeStep === 'book')
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden bg-white dark:bg-zinc-800">
            @if($this->booking)
                <div class="p-6 space-y-6">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Your Booking') }}</flux:heading>
                        <flux:badge color="{{ match($this->booking->status) { 'confirmed' => 'green', 'completed' => 'blue', 'cancelled' => 'red', 'rescheduled' => 'amber', default => 'zinc' } }}">
                            {{ ucfirst($this->booking->status) }}
                        </flux:badge>
                    </div>

                    <div class="space-y-4">
                        @if($this->booking->booked_date)
                            <div class="flex items-start gap-3">
                                <flux:icon name="calendar-days" class="w-5 h-5 text-zinc-400 mt-0.5" />
                                <div>
                                    <flux:text class="font-medium">{{ $this->booking->booked_date->format('l, F j, Y') }}</flux:text>
                                    <flux:text size="sm" class="text-zinc-500">
                                        {{ Carbon::parse($this->booking->booked_start_time)->format('g:i A') }} - {{ Carbon::parse($this->booking->booked_end_time)->format('g:i A') }}
                                    </flux:text>
                                </div>
                            </div>
                        @endif

                        @if($this->booking->estimator)
                            <div class="flex items-center gap-3">
                                <flux:avatar size="sm" :src="$this->booking->estimator->getAvatarUrl()" name="{{ $this->booking->estimator->name }}" />
                                <div>
                                    <flux:text class="font-medium">{{ $this->booking->estimator->name }}</flux:text>
                                    <flux:text size="sm" class="text-zinc-500">{{ __('Your Estimator') }}</flux:text>
                                </div>
                            </div>
                        @endif

                        @if($this->booking->prospect_address)
                            <x-blur-overlay :show="!$this->isAuthenticated" :cta-text="$this->loginCtaText" :cta-url="$this->loginCtaUrl" :message="__('Log in to see the appointment address.')">
                                <div class="flex items-start gap-3">
                                    <flux:icon name="map-pin" class="w-5 h-5 text-zinc-400 mt-0.5" />
                                    <div>
                                        <flux:text class="font-medium">{{ $this->booking->prospect_address }}</flux:text>
                                        @if($this->booking->prospect_city || $this->booking->prospect_state)
                                            <flux:text size="sm" class="text-zinc-500">
                                                {{ collect([$this->booking->prospect_city, $this->booking->prospect_state])->filter()->join(', ') }} {{ $this->booking->prospect_zip }}
                                            </flux:text>
                                        @endif
                                    </div>
                                </div>
                            </x-blur-overlay>
                        @endif
                    </div>

                    @if($this->booking->status === 'confirmed' && $this->googleCalendarUrl)
                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4 space-y-3">
                            <a href="{{ $this->googleCalendarUrl }}" target="_blank" rel="noopener noreferrer">
                                <flux:button variant="primary" class="w-full" icon="calendar">{{ __('Add to Google Calendar') }}</flux:button>
                            </a>
                            @if($this->booking->booking_token)
                                <div class="text-center">
                                    <a href="{{ route('booking.cancel', $this->booking->booking_token) }}" class="text-sm text-zinc-500 hover:text-red-600 underline">{{ __('Need to cancel or reschedule?') }}</a>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- While You Wait — Color Visualizer promo --}}
                <div class="mt-6 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 text-center">
                    <flux:icon name="paint-brush" class="w-8 h-8 mx-auto text-blue-500 mb-3" />
                    <flux:heading size="md">{{ __('While You Wait...') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-600 dark:text-zinc-300 text-sm">
                        {{ __('Try our free Color Visualizer! Upload a photo of your space, pick your colors, and see what your project could look like before we arrive.') }}
                    </flux:text>
                    <flux:button variant="primary" wire:click="setStep('visualizer')" class="mt-4" icon="camera">
                        {{ __('Try the Color Visualizer') }}
                    </flux:button>
                </div>

                {{-- Contact info --}}
                <div class="mt-6 text-center">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ __('Questions? Contact us at') }}
                        @if($this->company->company_phone)
                            <a href="tel:{{ $this->company->company_phone }}" class="underline">{{ $this->company->company_phone }}</a>
                        @else
                            {{ $this->company->company_email ?? config('mail.from.address') }}
                        @endif
                    </flux:text>
                </div>
            @else
                <div class="p-6 text-center">
                    <flux:icon name="calendar-days" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading size="lg">{{ __('Schedule Your Free Estimate') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500 mb-4">{{ __('Pick a date and time that works for you.') }}</flux:text>
                    <flux:button variant="primary" href="{{ route('booking.general') }}" target="_blank" icon="calendar-days">
                        {{ __('Book Now') }}
                    </flux:button>
                </div>
            @endif
        </div>

    {{-- PREVIEW (Overview / Visualizations) --}}
    @elseif($activeStep === 'preview')
        {{-- Visualization Gallery --}}
        @if($this->photosWithVisualizations->isNotEmpty())
            <div class="space-y-8 mb-8">
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
                                    <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">{{ $photo->condition_notes }}</flux:text>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Project Questionnaire --}}
        <div class="mb-8">
            <livewire:public.project-questionnaire :preview="$preview" />
        </div>

        {{-- Ballpark Pricing --}}
        @if(!empty($this->ballpark['rooms']))
            <x-blur-overlay :show="!$this->isAuthenticated" :cta-text="$this->loginCtaText" :cta-url="$this->loginCtaUrl" :message="__('Log in to see your ballpark pricing.')">
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-xl p-6 border border-blue-200 dark:border-blue-800 mb-8">
                    <flux:heading size="lg" class="mb-4">{{ __('Transparent Ballpark Pricing') }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mb-4">{{ __('Rough estimates based on typical room sizes. Your estimator will provide an exact quote.') }}</flux:text>
                    <div class="space-y-3">
                        @foreach($this->ballpark['rooms'] as $room)
                            <div class="flex items-center justify-between py-2 border-b border-blue-200/50 dark:border-blue-700/50 last:border-0">
                                <flux:text class="font-medium">{{ $room['name'] }}</flux:text>
                                <flux:text class="font-semibold text-blue-700 dark:text-blue-300">${{ number_format($room['min'], 0) }} - ${{ number_format($room['max'], 0) }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 pt-4 border-t-2 border-blue-300 dark:border-blue-700 flex items-center justify-between">
                        <flux:text class="font-bold text-lg">{{ __('Estimated Range') }}</flux:text>
                        <flux:text class="font-bold text-lg text-blue-700 dark:text-blue-300">${{ number_format($this->ballpark['total_min'], 0) }} - ${{ number_format($this->ballpark['total_max'], 0) }}</flux:text>
                    </div>
                    <flux:text size="xs" class="mt-3 text-zinc-500 italic">{{ __('Ballpark only. Final pricing determined during your in-person estimate.') }}</flux:text>
                </div>
            </x-blur-overlay>
            @if(!$this->isAuthenticated)
                <div class="text-center mb-8">
                    <flux:button variant="primary" size="sm" :href="$this->loginCtaUrl" icon="currency-dollar">{{ __('See Your Exact Pricing') }}</flux:button>
                </div>
            @endif
        @endif

        {{-- Estimator --}}
        @if($this->currentEstimator)
            <div class="bg-white dark:bg-zinc-800 rounded-xl p-6 border border-zinc-200 dark:border-zinc-700 flex items-center gap-6 mb-8">
                <flux:avatar size="xl" :src="$this->currentEstimator->getAvatarUrl()" name="{{ $this->currentEstimator->name }}" class="shrink-0" />
                <div class="flex-1 min-w-0">
                    <flux:heading size="base">{{ $this->currentEstimator->name }}</flux:heading>
                    @if($this->company->company_phone)
                        <x-blur-overlay :show="!$this->isAuthenticated" :cta-text="$this->loginCtaText" :cta-url="$this->loginCtaUrl" :message="__('Log in to contact your estimator.')">
                            <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mt-1">Text us: {{ $this->company->company_phone }}</flux:text>
                        </x-blur-overlay>
                    @endif
                    @if($this->booking?->booked_date)
                        <div class="mt-2 flex items-center gap-1.5">
                            <flux:icon name="calendar-days" class="w-4 h-4 text-blue-500" />
                            <flux:text size="sm" class="text-blue-600 dark:text-blue-400 font-medium">
                                Appointment: {{ $this->booking->booked_date->format('l, M j') }} {{ Carbon::parse($this->booking->booked_start_time)->format('g:i A') }}
                            </flux:text>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- CTA to try colors --}}
        @if($this->photosWithVisualizations->isEmpty())
            <div class="text-center py-12 bg-white dark:bg-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-700">
                <flux:icon name="paint-brush" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                <flux:heading size="lg">{{ __('See Your Rooms in New Colors') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500 max-w-md mx-auto">{{ __('Upload a photo and our AI shows you what it looks like with new paint colors.') }}</flux:text>
                <flux:button variant="primary" wire:click="setStep('visualizer')" class="mt-4" icon="paint-brush">{{ __('Try the Color Visualizer') }}</flux:button>
            </div>
        @else
            <div class="text-center">
                <flux:button variant="ghost" wire:click="setStep('visualizer')" icon="paint-brush">{{ __('Try different colors') }}</flux:button>
            </div>
        @endif

    {{-- ESTIMATE VISIT --}}
    @elseif($activeStep === 'estimate')
        <div class="space-y-6">

            {{-- Section 1: Portal Access CTA --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-8 bg-white dark:bg-zinc-800">
                <flux:heading size="xl">{{ __('Your Project Command Center') }}</flux:heading>
                <flux:text class="text-lg text-zinc-600 dark:text-zinc-400 mt-2">
                    {{ __('Most painting companies leave you in the dark. We give you a portal where you see everything, control everything, and never have to wonder "what\'s happening with my project?"') }}
                </flux:text>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    {{-- Daily Walkaround Videos --}}
                    <div class="flex items-start gap-3 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-700/30">
                        <flux:icon name="video-camera" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                        <div>
                            <flux:text class="text-lg font-semibold">{{ __('Daily Walkaround Videos') }}</flux:text>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Your crew records video updates every day so you see exactly what got done — even if you\'re not home.') }}</flux:text>
                        </div>
                    </div>

                    {{-- Group Messaging --}}
                    <div class="flex items-start gap-3 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-700/30">
                        <flux:icon name="chat-bubble-left-right" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                        <div>
                            <flux:text class="text-lg font-semibold">{{ __('Group Chat With Your Team') }}</flux:text>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('One thread with your crew, your estimator, and your project coordinator. Ask questions, share ideas, get instant answers.') }}</flux:text>
                        </div>
                    </div>

                    {{-- Pay Online --}}
                    <div class="flex items-start gap-3 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-700/30">
                        <flux:icon name="credit-card" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                        <div>
                            <flux:text class="text-lg font-semibold">{{ __('Pay Invoices Online') }}</flux:text>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('View invoices, make payments, and track every dollar — all from your phone.') }}</flux:text>
                        </div>
                    </div>

                    {{-- Propose Dates --}}
                    <div class="flex items-start gap-3 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-700/30">
                        <flux:icon name="calendar-days" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                        <div>
                            <flux:text class="text-lg font-semibold">{{ __('Pick Your Start Date') }}</flux:text>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Propose the week that works for your family. No phone tag. No back-and-forth emails.') }}</flux:text>
                        </div>
                    </div>

                    {{-- Project Plan & Next Steps --}}
                    <div class="flex items-start gap-3 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-700/30">
                        <flux:icon name="clipboard-document-list" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                        <div>
                            <flux:text class="text-lg font-semibold">{{ __('Project Plan & Next Steps') }}</flux:text>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Always know what\'s happening today, what\'s next, and what\'s already done. Zero guesswork.') }}</flux:text>
                        </div>
                    </div>

                    {{-- Invite Family --}}
                    <div class="flex items-start gap-3 p-4 rounded-lg bg-zinc-50 dark:bg-zinc-700/30">
                        <flux:icon name="user-plus" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                        <div>
                            <flux:text class="text-lg font-semibold">{{ __('Invite Your Spouse & Family') }}</flux:text>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Give your partner their own login. Everyone stays informed, everyone can help manage the project.') }}</flux:text>
                        </div>
                    </div>
                </div>

                <flux:text class="text-lg font-medium text-zinc-700 dark:text-zinc-300 mt-6">
                    {{ __('This is what it looks like when a painting company actually respects your time.') }}
                </flux:text>

                <div class="mt-4">
                    @if($this->isAuthenticated)
                        <flux:button variant="primary" :href="route('customer.dashboard')" icon="arrow-right">{{ __('Go to Dashboard') }}</flux:button>
                    @else
                        <flux:button variant="primary" :href="$this->loginCtaUrl" icon="arrow-right">{{ __('Access Your Portal') }}</flux:button>
                    @endif
                </div>
            </div>

            {{-- Section 2: What to Expect --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-8 bg-white dark:bg-zinc-800">
                <div class="flex items-center gap-3 mb-4">
                    <flux:icon name="clock" class="w-7 h-7 text-zinc-500" />
                    <flux:heading size="lg">{{ __('What to Expect') }}</flux:heading>
                </div>
                <flux:text class="text-lg">
                    {{ __('Depending on your project size, the estimate takes') }} <strong>{{ __('30 minutes to 2 hours') }}</strong>.
                </flux:text>
                <flux:text class="text-lg text-zinc-600 dark:text-zinc-400 mt-3">
                    {{ __('Your estimator walks through every room, measures surfaces, and assesses conditions so your quote is accurate — no surprises.') }}
                </flux:text>

                {{-- We Build Options Around You --}}
                <div class="mt-6 border-t border-zinc-100 dark:border-zinc-700 pt-6">
                    <flux:heading size="lg">{{ __('We Build Options Around You') }}</flux:heading>
                    <flux:text class="text-lg text-zinc-600 dark:text-zinc-400 mt-2">
                        {{ __('Every homeowner has different priorities. Some want the full transformation — every room, every surface, premium finishes. Others want to start with the spaces that matter most and plan the rest over time. We build proposals that cover the full scope of what you\'re envisioning, with options at every level so you can choose what fits.') }}
                    </flux:text>
                </div>

                {{-- What Helps Us Give You Better Options --}}
                <div class="mt-6 border-t border-zinc-100 dark:border-zinc-700 pt-6">
                    <flux:heading size="base" class="text-zinc-700 dark:text-zinc-300">{{ __('What helps us give you the best options:') }}</flux:heading>
                    <ul class="mt-3 space-y-3">
                        <li class="flex items-start gap-3">
                            <flux:icon name="chat-bubble-bottom-center-text" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                            <flux:text class="text-lg">{{ __('Share what you\'re hoping to accomplish — even if it\'s just a feeling or a rough idea. We\'ll figure out the rest.') }}</flux:text>
                        </li>
                        <li class="flex items-start gap-3">
                            <flux:icon name="flag" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                            <flux:text class="text-lg">{{ __('Let us know which rooms or areas are the priority, and which ones can wait.') }}</flux:text>
                        </li>
                        <li class="flex items-start gap-3">
                            <flux:icon name="currency-dollar" class="w-6 h-6 text-blue-500 shrink-0 mt-0.5" />
                            <flux:text class="text-lg">{{ __('If you have a budget in mind, share it. We\'ll tailor the options so you get the most impact for what you\'re comfortable spending.') }}</flux:text>
                        </li>
                    </ul>
                    <flux:text class="text-lg text-zinc-500 dark:text-zinc-400 mt-4">
                        {{ __('There\'s no wrong answer here. The more we understand what matters to you, the better we can make the proposal feel like it was built just for you — because it is.') }}
                    </flux:text>
                </div>

                @if($this->currentEstimator)
                    <div class="mt-6 inline-flex items-center gap-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg px-4 py-3">
                        <flux:avatar size="sm" :src="$this->currentEstimator->getAvatarUrl()" name="{{ $this->currentEstimator->name }}" />
                        <div>
                            <flux:text class="font-medium">{{ $this->currentEstimator->name }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500">{{ __('Your Estimator') }}</flux:text>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Section 3: Payment Options --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-8 bg-white dark:bg-zinc-800">
                <flux:heading size="lg">{{ __('Payment Options') }}</flux:heading>
                <ul class="mt-4 space-y-3">
                    <li class="flex items-start gap-3">
                        <flux:icon name="check" class="w-6 h-6 text-green-500 shrink-0 mt-0.5" />
                        <flux:text class="text-lg">{{ __('Check at the appointment') }}</flux:text>
                    </li>
                    <li class="flex items-start gap-3">
                        <flux:icon name="check" class="w-6 h-6 text-green-500 shrink-0 mt-0.5" />
                        <flux:text class="text-lg">{{ __('ACH at the appointment') }}</flux:text>
                    </li>
                    <li class="flex items-start gap-3">
                        <flux:icon name="check" class="w-6 h-6 text-green-500 shrink-0 mt-0.5" />
                        <flux:text class="text-lg">{{ __('Cash') }}</flux:text>
                    </li>
                    <li class="flex items-start gap-3">
                        <flux:icon name="check" class="w-6 h-6 text-green-500 shrink-0 mt-0.5" />
                        <flux:text class="text-lg">{{ __('Credit card (up to $1,000)') }}</flux:text>
                    </li>
                </ul>
                <flux:text class="text-zinc-500 dark:text-zinc-400 mt-4 text-lg">
                    {{ __('Have questions about other ways to pay? Ask your estimator.') }}
                </flux:text>
            </div>

            {{-- Section 4: Brand Ambassador Counter --}}
            @php $spots = $this->company->brand_ambassador_spots ?? 10; @endphp
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-8 bg-amber-50/50 dark:bg-amber-900/10">
                @if($spots > 0)
                    <div class="text-center">
                        <span class="text-5xl font-bold text-zinc-900 dark:text-white">{{ $spots }}</span>
                        <flux:text class="text-lg mt-2 font-medium text-zinc-700 dark:text-zinc-300">
                            {{ __('Brand Ambassador spots remaining this month') }}
                        </flux:text>
                        @if($spots <= 3)
                            <flux:badge color="amber" class="mt-2">{{ __('Almost gone') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text class="text-lg text-zinc-600 dark:text-zinc-400 mt-4 text-center">
                        {{ __('Brand Ambassadors enjoy priority scheduling, exclusive pricing, and a dedicated project coordinator.') }}
                    </flux:text>
                @else
                    <div class="text-center">
                        <flux:text class="text-lg text-zinc-500 dark:text-zinc-400">
                            {{ __('Brand Ambassador applications are closed for this month.') }}
                        </flux:text>
                    </div>
                @endif
            </div>

            {{-- Section 5: Benefits of Deciding Day-Of --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-8 bg-white dark:bg-zinc-800">
                <flux:heading size="xl">{{ __('Why Homeowners Love Deciding at the Appointment') }}</flux:heading>

                <div class="mt-6 space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Lock in Today\'s Pricing') }}</flux:heading>
                        <flux:text class="text-lg mt-1 text-zinc-600 dark:text-zinc-400">
                            {{ __('Material and labor costs change. The price quoted today reflects current costs.') }}
                        </flux:text>
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('Priority Scheduling') }}</flux:heading>
                        <flux:text class="text-lg mt-1 text-zinc-600 dark:text-zinc-400">
                            {{ __('Homeowners who confirm at the appointment get first pick of dates. Calendar fills fast in peak season.') }}
                        </flux:text>
                    </div>
                    <div>
                        <flux:heading size="lg">{{ __('One Less Thing to Think About') }}</flux:heading>
                        <flux:text class="text-lg mt-1 text-zinc-600 dark:text-zinc-400">
                            {{ __('No back-and-forth, no second appointments. Walk through the estimate, ask questions, and check it off — all in one visit.') }}
                        </flux:text>
                    </div>
                </div>
            </div>

            {{-- Book CTA if no booking yet --}}
            @if(!$this->booking)
                <div class="text-center">
                    <flux:button variant="primary" wire:click="setStep('book')" icon="calendar-days">{{ __('Book Your Estimate') }}</flux:button>
                </div>
            @endif
        </div>

    {{-- APPROVAL --}}
    @elseif($activeStep === 'approval')
        <div class="space-y-6">
            @if($preview->estimate_id && $preview->estimate)
                @php $estimate = $preview->estimate; @endphp

                {{-- Estimate link + status --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-8 bg-white dark:bg-zinc-800">
                    @if($estimate->status === 'accepted')
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                                <flux:icon name="check-circle" class="w-6 h-6 text-green-600 dark:text-green-400" />
                            </div>
                            <div>
                                <flux:heading size="lg">{{ __('Estimate Accepted') }}</flux:heading>
                                <flux:text class="text-zinc-500">{{ __('Signed') }} {{ $estimate->accepted_at?->format('M j, Y') }}</flux:text>
                            </div>
                        </div>
                        <flux:text class="text-lg text-zinc-600 dark:text-zinc-400">
                            {{ __('You\'re all set. Your project is confirmed and your team is preparing everything.') }}
                        </flux:text>
                    @else
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                                <flux:icon name="document-text" class="w-6 h-6 text-blue-600 dark:text-blue-400" />
                            </div>
                            <flux:heading size="lg">{{ __('Your Estimate Is Ready') }}</flux:heading>
                        </div>
                        <flux:text class="text-lg text-zinc-600 dark:text-zinc-400">
                            {{ __('Review the full scope of work, pricing, and options. Ask questions, then sign when you\'re ready.') }}
                        </flux:text>
                    @endif

                    @if($estimate->public_token && $estimate->isPublicTokenValid())
                        <div class="mt-6">
                            <flux:button variant="primary" :href="$estimate->getPublicUrl()" icon="document-text">
                                {{ $estimate->status === 'accepted' ? __('View Your Estimate') : __('Review & Sign Your Estimate') }}
                            </flux:button>
                        </div>
                    @endif
                </div>

                {{-- Scheduling preference --}}
                @if($estimate->status === 'accepted')
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-8 bg-white dark:bg-zinc-800">
                        <div class="flex items-center gap-3 mb-4">
                            <flux:icon name="calendar-days" class="w-7 h-7 text-zinc-500" />
                            <flux:heading size="lg">{{ __('When Would You Like Us to Start?') }}</flux:heading>
                        </div>
                        <flux:text class="text-lg text-zinc-600 dark:text-zinc-400">
                            {{ __('Log in to your portal to propose the week that works best for your family. Your project coordinator will confirm availability and lock it in.') }}
                        </flux:text>
                        <div class="mt-6">
                            @if($this->isAuthenticated)
                                <flux:button variant="primary" :href="route('customer.dashboard')" icon="calendar-days">{{ __('Go to Dashboard') }}</flux:button>
                            @else
                                <flux:button variant="primary" :href="$this->loginCtaUrl" icon="calendar-days">{{ __('Log In to Schedule') }}</flux:button>
                            @endif
                        </div>
                    </div>
                @endif

            @else
                {{-- No estimate yet --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl p-8 text-center bg-white dark:bg-zinc-800">
                    <flux:icon name="clock" class="w-16 h-16 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading size="lg">{{ __('Approval & Scheduling') }}</flux:heading>
                    <flux:text class="text-lg text-zinc-500 mt-2 max-w-md mx-auto">
                        {{ __('After your estimate visit, your estimator will build out your proposal. Once it\'s ready, you\'ll be able to review it, sign digitally, and pick your start date — all from right here.') }}
                    </flux:text>
                </div>
            @endif
        </div>

    {{-- COLOR VISUALIZER --}}
    @elseif($activeStep === 'visualizer')
        <div class="text-center mb-8">
            <flux:heading size="xl">
                {{ __('See Your Home in') }}
                <span class="bg-gradient-to-r from-blue-500 to-purple-500 bg-clip-text text-transparent">{{ __('New Colors') }}</span>
            </flux:heading>
            <flux:text class="mt-2 text-zinc-500 dark:text-zinc-400">{{ __('Upload a photo. Pick your colors. See the transformation instantly.') }}</flux:text>
        </div>

        {{-- Result --}}
        @if($this->visualization)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-lg dark:border-zinc-700 dark:bg-zinc-800 mb-8"
                 @if($isGenerating) wire:poll.3s="checkStatus" @endif>
                @if($isGenerating)
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="mb-4 h-12 w-12 animate-spin rounded-full border-4 border-blue-200 border-t-blue-600"></div>
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Generating Your Visualization') }}</h3>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('Our AI is painting your room. This may take a minute...') }}</p>
                    </div>
                @elseif($this->visualization->isCompleted())
                    <h3 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Your Color Visualization') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="mb-2 text-sm font-medium text-zinc-500">{{ __('Original') }}</p>
                            <img src="{{ storage_url($this->visualization->original_image_path, diskName: $this->visualization->disk) }}" alt="Original" class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700" />
                        </div>
                        <div>
                            <p class="mb-2 text-sm font-medium text-zinc-500">{{ __('Visualized') }}</p>
                            <img src="{{ storage_url($this->visualization->result_image_path, diskName: $this->visualization->disk) }}" alt="Visualized" class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700" />
                        </div>
                    </div>
                    @if($this->visualization->parsed_colors)
                        <div class="mt-4">
                            <p class="mb-2 text-sm font-medium text-zinc-500">{{ __('Colors Used') }}</p>
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
                        <flux:button wire:click="saveVisualizationToPage" variant="primary" icon="bookmark">
                            {{ __('Save to My Page') }}
                        </flux:button>
                        @if($preview->estimate_id)
                            <flux:button wire:click="addVisualizationToEstimate" variant="filled" icon="plus">
                                {{ __('Add to Estimate') }}
                            </flux:button>
                        @endif
                        <flux:button wire:click="regenerate" variant="ghost">{{ __('Try Another Photo') }}</flux:button>
                    </div>
                @elseif($this->visualization->isFailed())
                    <div class="py-8 text-center">
                        <flux:icon name="exclamation-triangle" class="h-12 w-12 mx-auto text-red-400 mb-3" />
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Generation Failed') }}</h3>
                        <p class="mt-1 text-sm text-zinc-500">{{ __('Something went wrong. Please try again.') }}</p>
                        <flux:button wire:click="regenerate" variant="primary" class="mt-4">{{ __('Try Again') }}</flux:button>
                    </div>
                @endif
            </div>
        @endif

        {{-- Upload form --}}
        @if(!$this->visualization || $this->visualization->isFailed())
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Upload Your Photo') }}</h2>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Take a photo of any room and describe the colors you want.') }}</p>

                <form wire:submit="generateVisualization" class="mt-6 space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Photo') }}</label>
                        <div class="mt-1">
                            <label class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-zinc-300 bg-zinc-50 p-6 transition hover:border-blue-400 hover:bg-blue-50/50 dark:border-zinc-600 dark:bg-zinc-900 dark:hover:border-blue-500 dark:hover:bg-blue-950/20">
                                @if($uploadedPhoto)
                                    <img src="{{ $uploadedPhoto->temporaryUrl() }}" alt="Preview" class="mb-2 max-h-48 rounded-lg" />
                                    <span class="text-sm text-zinc-500">{{ __('Click to change photo') }}</span>
                                @else
                                    <flux:icon name="photo" class="mb-2 h-10 w-10 text-zinc-400" />
                                    <span class="text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Click to upload or drag and drop') }}</span>
                                    <span class="mt-1 text-xs text-zinc-400">JPG, PNG up to 10MB</span>
                                @endif
                                <input type="file" wire:model="uploadedPhoto" accept="image/*" class="hidden" />
                            </label>
                            @error('uploadedPhoto') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div x-data="{ open: false }" x-on:click.outside="open = false" class="relative">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Find a Color') }}</label>
                        <div class="mt-1">
                            <flux:input wire:model.live.debounce.300ms="colorSearch" x-on:focus="open = true" x-on:input="open = true"
                                placeholder="{{ $selectedBrandId ? __('Search :brand colors...', ['brand' => $this->availableBrands->firstWhere('id', $selectedBrandId)?->code ?? '']) : __('Search 12,000+ colors...') }}"
                                icon="magnifying-glass" />
                        </div>
                        @if($this->colorSearchResults->isNotEmpty())
                            <div x-show="open" x-transition.opacity class="absolute z-50 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800 max-h-72 overflow-y-auto">
                                @foreach($this->colorSearchResults as $result)
                                    <button type="button" wire:key="search-{{ $result->id }}" wire:click="selectSearchColor({{ $result->id }})" x-on:click="open = false"
                                        class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                        <span class="size-8 shrink-0 rounded-md border border-zinc-300 shadow-sm dark:border-zinc-600" style="background-color: {{ $result->hex_value }}"></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $result->name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $result->code }} &middot; {{ $result->hex_value }}</div>
                                        </div>
                                        <span class="shrink-0 text-xs text-zinc-400">{{ $result->brand->code ?? $result->brand->name }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div>
                        <p class="text-xs text-zinc-500 mb-1">{{ __('Tip: "Paint walls Agreeable Gray"') }}</p>
                        <flux:textarea wire:model="prompt" label="{{ __('Describe the colors you want') }}" rows="3" placeholder="e.g., Paint the walls Benjamin Moore White Dove" />
                        @error('prompt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Paint Brand') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($this->availableBrands as $brand)
                                <button type="button" wire:key="brand-{{ $brand->id }}" wire:click="selectBrand({{ $brand->id }})"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all {{ $selectedBrandId === $brand->id ? 'bg-blue-600 text-white shadow-md' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}">
                                    {{ $brand->name }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if($selectedBrandId)
                        @foreach(['interior' => 'Interior Popular', 'exterior' => 'Exterior Popular'] as $useType => $label)
                            @php $colors = $useType === 'interior' ? $this->interiorPopularColors : $this->exteriorPopularColors; @endphp
                            @if($colors->isNotEmpty())
                                <div>
                                    <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __($label) }} <span class="font-normal text-zinc-400">({{ __('click to add') }})</span></p>
                                    <div class="flex gap-3 overflow-x-auto scroll-smooth pb-1" style="scrollbar-width: none;">
                                        @foreach($colors as $color)
                                            <button type="button" wire:key="popular-{{ $useType }}-{{ $color->id }}" wire:click="insertColorName({{ $color->id }})"
                                                x-data="{ tapped: false }" x-on:click="tapped = true; setTimeout(() => tapped = false, 600)"
                                                class="snap-start shrink-0 w-32 rounded-lg border-2 overflow-hidden transition-all hover:shadow-md cursor-pointer"
                                                :class="tapped ? 'border-green-500 shadow-lg' : 'border-zinc-200 dark:border-zinc-700'"
                                                title="{{ $color->brand->name }} {{ $color->name }} ({{ $color->code }})">
                                                <div class="h-20 w-full" style="background-color: {{ $color->hex_value }}"></div>
                                                <div class="p-2 bg-white dark:bg-zinc-800">
                                                    <div class="text-xs font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $color->name }}</div>
                                                    <div class="text-[10px] text-zinc-500">{{ $color->code }}</div>
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    @else
                        <div class="text-center py-4 text-sm text-zinc-500">{{ __('Select a brand to see popular colors') }}</div>
                    @endif

                    <div class="flex items-center justify-end">
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="generateVisualization,uploadedPhoto">
                            <span wire:loading.remove wire:target="generateVisualization">{{ __('Generate Visualization') }}</span>
                            <span wire:loading wire:target="generateVisualization">{{ __('Generating...') }}</span>
                        </flux:button>
                    </div>
                </form>
            </div>
        @endif
    @endif

    {{-- Footer --}}
    <div class="text-center pt-8 mt-8 border-t border-zinc-200 dark:border-zinc-700">
        <flux:text size="sm" class="text-zinc-500">
            {{ __('Questions?') }} {{ __('Text us at') }} {{ $this->company->company_phone ?? __('our office') }}
        </flux:text>
        @if(!$this->isAuthenticated)
            <div class="mt-3">
                <flux:link size="sm" :href="$this->loginCtaUrl" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                    {{ __('Login to your portal') }}
                </flux:link>
            </div>
        @endif
    </div>
</div>
