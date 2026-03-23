<x-layouts.guest :title="__('Video Processing') . ' - ' . ($company->company_name ?? config('app.name'))">
    @unless($failed ?? false)
        @push('meta')
        <meta http-equiv="refresh" content="5">
        @endpush
    @endunless

    <div class="min-h-screen bg-zinc-900 flex flex-col items-center p-4 sm:p-8">
        <div class="w-full max-w-5xl">
            {{-- Company Branding --}}
            <div class="flex items-center gap-3 mb-8">
                @if($company->logo_path)
                    <img src="{{ storage_url($company->logo_path) }}" alt="{{ $company->company_name }}" class="h-10 w-auto">
                @endif
                <span class="text-white text-lg font-semibold">{{ $company->company_name ?? config('app.name') }}</span>
            </div>

            {{-- Processing / Failed State --}}
            <div class="bg-zinc-800 rounded-xl overflow-hidden flex items-center justify-center" style="min-height: 40vh;">
                <div class="text-center px-6 py-12">
                    @if($failed ?? false)
                        <svg class="w-16 h-16 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                        <h2 class="text-white text-xl font-semibold mb-2">{{ __('Video Unavailable') }}</h2>
                        <p class="text-zinc-400 text-sm">{{ __('This video could not be processed. Please contact us for assistance.') }}</p>
                    @else
                        <div class="relative w-16 h-16 mx-auto mb-6">
                            <div class="absolute inset-0 rounded-full border-4 border-zinc-700"></div>
                            <div class="absolute inset-0 rounded-full border-4 border-t-white animate-spin"></div>
                        </div>
                        <h2 class="text-white text-xl font-semibold mb-2">{{ __('Your video is being processed') }}</h2>
                        <p class="text-zinc-400 text-sm">{{ __('This page will automatically refresh when your video is ready.') }}</p>
                    @endif
                </div>
            </div>

            {{-- Footer --}}
            <div class="mt-6 flex items-center justify-end">
                <p class="text-zinc-500 text-xs">
                    {{ __('Shared via') }} <span class="text-zinc-400 font-medium">{{ $company->company_name ?? config('app.name') }}</span>
                </p>
            </div>
        </div>
    </div>
</x-layouts.guest>
