<?php

use App\Services\GeoLocationService;
use Livewire\Component;

new class extends Component
{
    public ?string $city = null;

    public ?string $territoryName = null;

    public ?string $managerName = null;

    public ?string $managerTitle = null;

    public array $estimatorNames = [];

    public bool $hasLocalTeam = false;

    public function mount(): void
    {
        try {
            $geoService = app(GeoLocationService::class);
            $location = $geoService->detectFromIp(request()->ip());

            if (! $location) {
                return;
            }

            $this->city = $location['city'];

            $territory = $geoService->findTerritoryForCoordinates($location['lat'], $location['lng']);

            if (! $territory) {
                return;
            }

            $team = $geoService->getLocalTeam($territory);
            $this->territoryName = $territory->name;

            if ($team['manager']) {
                $this->managerName = $team['manager']->name;
                $this->managerTitle = $team['manager']->employeeProfile?->job_title ?? __('Territory Manager');
                $this->hasLocalTeam = true;
            }

            if ($team['estimators']->isNotEmpty()) {
                $this->estimatorNames = $team['estimators']->pluck('name')->toArray();
                $this->hasLocalTeam = true;
            }
        } catch (\Exception $e) {
            // Fail silently - show default content
        }
    }
};
?>

<section class="bg-white py-16 sm:py-20">
    <div class="max-w-5xl mx-auto px-4 sm:px-6">
        @if($hasLocalTeam && $city)
            {{-- Personalized local team --}}
            <div class="text-center mb-12">
                <div class="inline-flex items-center gap-2 bg-emerald-100 text-emerald-800 px-4 py-1.5 rounded-full text-sm font-semibold mb-4">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                    {{ __('We have a team in :city!', ['city' => $city]) }}
                </div>
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Meet Your Local Team') }}</h2>
                <p class="text-lg text-zinc-600 max-w-2xl mx-auto">{{ __('Our :city crew is ready to transform your home with expert precision and dedicated attention.', ['city' => $city]) }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 max-w-3xl mx-auto">
                @if($managerName)
                    <div class="bg-gradient-to-br from-emerald-50 to-white rounded-2xl p-6 border border-emerald-100">
                        <div class="flex items-center gap-4">
                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-500 text-white font-bold text-lg">
                                {{ strtoupper(substr($managerName, 0, 1)) }}
                            </div>
                            <div>
                                <h3 class="font-bold text-zinc-900">{{ $managerName }}</h3>
                                <p class="text-sm text-zinc-500">{{ $managerTitle }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                @foreach($estimatorNames as $name)
                    <div class="bg-gradient-to-br from-sky-50 to-white rounded-2xl p-6 border border-sky-100">
                        <div class="flex items-center gap-4">
                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-sky-500 text-white font-bold text-lg">
                                {{ strtoupper(substr($name, 0, 1)) }}
                            </div>
                            <div>
                                <h3 class="font-bold text-zinc-900">{{ $name }}</h3>
                                <p class="text-sm text-zinc-500">{{ __('Estimator') }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- Default generic content --}}
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-bold text-zinc-900 mb-4">{{ __('Meet Your Local Team') }}</h2>
                <p class="text-lg text-zinc-600 max-w-2xl mx-auto">{{ __('Serving the Denver Metro Area with dedicated crews, expert estimators, and a commitment to doing things right.') }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 max-w-4xl mx-auto">
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-100 text-emerald-600 mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    </div>
                    <h3 class="font-bold text-zinc-900 mb-1">{{ __('Dedicated Crews') }}</h3>
                    <p class="text-sm text-zinc-500">{{ __('Your project gets a single crew from start to finish. No rotating strangers.') }}</p>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-sky-100 text-sky-600 mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.745 3.745 0 011.043 3.296A3.745 3.745 0 0121 12z" /></svg>
                    </div>
                    <h3 class="font-bold text-zinc-900 mb-1">{{ __('Expert Estimators') }}</h3>
                    <p class="text-sm text-zinc-500">{{ __('Transparent pricing from trained professionals who know Denver homes.') }}</p>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-amber-100 text-amber-600 mb-4">
                        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                    </div>
                    <h3 class="font-bold text-zinc-900 mb-1">{{ __('Locally Based') }}</h3>
                    <p class="text-sm text-zinc-500">{{ __('We live where you live. Fast response, neighborhood knowledge.') }}</p>
                </div>
            </div>
        @endif
    </div>
</section>
