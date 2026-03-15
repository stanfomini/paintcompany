<?php

use App\Models\CompanySetting;
use App\Models\ProjectPreview;
use App\Models\ProjectPreviewView;
use App\Services\ProjectPreviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.public')] class extends Component {
    use WithFileUploads;

    public ProjectPreview $preview;

    public string $token = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile[] */
    public $photoUploads = [];

    public bool $uploading = false;

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

    public function updatedPhotoUploads(): void
    {
        $this->validate([
            'photoUploads.*' => 'image|max:10240',
        ]);

        foreach ($this->photoUploads as $upload) {
            $path = $upload->store('previews', 'public');

            $photo = app(ProjectPreviewService::class)->storePhoto(
                $this->preview,
                $path,
                'public',
                $upload->getMimeType(),
                $upload->getSize()
            );

            \App\Jobs\ProcessPreviewPhotoJob::dispatch($photo);
        }

        $this->photoUploads = [];
        $this->preview->refresh();
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
        <div class="mt-3">
            <flux:badge color="blue" size="sm">
                {{ ucfirst($preview->status) }}
            </flux:badge>
        </div>
    </div>

    {{-- Visualization Gallery --}}
    @if($this->photosWithVisualizations->isNotEmpty())
        <section class="mb-12">
            <flux:heading size="lg" class="mb-6">Your Rooms, Reimagined</flux:heading>

            <div class="space-y-8">
                @foreach($this->photosWithVisualizations as $photo)
                    <div wire:key="photo-{{ $photo->id }}" class="bg-white dark:bg-zinc-800 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                        {{-- Room Label --}}
                        @if($photo->room_label)
                            <div class="px-6 pt-4">
                                <flux:heading size="base">{{ $photo->room_label }}</flux:heading>
                            </div>
                        @endif

                        {{-- Original + Visualizations Grid --}}
                        <div class="p-6">
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                {{-- Original Photo --}}
                                <div class="relative">
                                    <img src="{{ $photo->getOriginalUrl() }}" alt="Original" class="w-full aspect-square object-cover rounded-lg border-2 border-zinc-300 dark:border-zinc-600">
                                    <span class="absolute bottom-2 left-2 bg-black/60 text-white text-xs px-2 py-0.5 rounded">Original</span>
                                </div>

                                {{-- Color Visualizations --}}
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

                        {{-- Condition Analysis --}}
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

    {{-- Upload More Photos --}}
    <section class="mb-12">
        <flux:heading size="lg" class="mb-4">Upload Photos</flux:heading>
        <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 mb-4">
            Share photos of rooms you'd like painted. We'll send back free color visualizations!
        </flux:text>

        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave="dragging = false"
            x-on:drop.prevent="dragging = false"
            class="border-2 border-dashed rounded-xl p-8 text-center transition-colors"
            :class="dragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-300 dark:border-zinc-600 hover:border-blue-400'"
        >
            <flux:icon name="camera" class="w-10 h-10 mx-auto text-zinc-400 mb-3" />
            <flux:text class="font-medium mb-1">Drag photos here or click to upload</flux:text>
            <flux:text size="sm" class="text-zinc-500">JPG, PNG up to 10MB each</flux:text>
            <input
                type="file"
                wire:model="photoUploads"
                multiple
                accept="image/*"
                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
            >
        </div>

        <div wire:loading wire:target="photoUploads" class="mt-4 text-center">
            <div class="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400">
                <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span>Uploading photos...</span>
            </div>
        </div>

        {{-- Photos without visualizations yet (processing) --}}
        @foreach($preview->photos()->doesntHave('visualizations')->orWhereHas('visualizations', fn($q) => $q->whereIn('status', ['pending', 'processing']))->get() as $pendingPhoto)
            <div wire:key="pending-{{ $pendingPhoto->id }}" class="mt-4 bg-zinc-100 dark:bg-zinc-800 rounded-lg p-4 flex items-center gap-3">
                <div class="w-16 h-16 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse shrink-0"></div>
                <div>
                    <flux:text size="sm" class="font-medium">Processing your photo...</flux:text>
                    <flux:text size="xs" class="text-zinc-500">Color visualizations are being generated</flux:text>
                </div>
            </div>
        @endforeach
    </section>

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

    {{-- Footer --}}
    <div class="text-center pt-4 border-t border-zinc-200 dark:border-zinc-700">
        <flux:text size="sm" class="text-zinc-500">
            Questions? Text us at {{ $this->company->company_phone ?? 'our office' }}
        </flux:text>
    </div>
</div>
