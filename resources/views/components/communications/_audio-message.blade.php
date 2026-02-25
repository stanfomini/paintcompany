{{-- Audio message display partial - used in communications hub messengers --}}
@php
    $audioUrl = $message->metadata['audio_url'] ?? '';
    $audioMime = $message->metadata['audio_mime_type'] ?? null;
    $isM4a = $audioMime === 'audio/mp4' || str_ends_with($audioUrl, '.m4a');
@endphp
<div class="space-y-1.5">
    <div
        x-data="{ audioError: false }"
        class="relative"
    >
        <audio
            controls
            class="w-full max-w-[250px]"
            preload="metadata"
            x-on:error="audioError = true"
            x-show="!audioError"
        >
            @if ($isM4a)
                <source src="{{ $audioUrl }}" type="audio/mp4">
            @else
                <source src="{{ $audioUrl }}" type="audio/webm; codecs=opus">
                <source src="{{ $audioUrl }}" type="audio/webm">
            @endif
            <source src="{{ $audioUrl }}" type="audio/mp4">
            <source src="{{ $audioUrl }}">
            {{ __('Your browser does not support audio playback.') }}
        </audio>

        <div x-show="audioError" x-cloak class="flex items-center gap-2 text-xs {{ $isOwn ? 'text-blue-200' : 'text-zinc-500' }}">
            <flux:icon name="exclamation-circle" class="size-3.5" />
            <span>{{ __('Audio unavailable') }}</span>
            <a href="{{ $audioUrl }}" download class="underline hover:no-underline">{{ __('Download') }}</a>
        </div>
    </div>

    @if (! empty($message->metadata['audio_duration']))
        <div class="text-[10px] {{ $isOwn ? 'text-blue-200' : 'text-zinc-400' }}">
            {{ gmdate('i:s', (int) $message->metadata['audio_duration']) }}
        </div>
    @endif

    @if (! empty($message->metadata['transcription']))
        <div class="pt-1 border-t {{ $isOwn ? 'border-blue-400/30' : 'border-zinc-200 dark:border-zinc-600' }}">
            <p class="text-xs italic {{ $isOwn ? 'text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}">
                "{{ $message->metadata['transcription'] }}"
            </p>
        </div>
        @if ($message->needsTranslationFor(app()->getLocale()))
            <div x-data="{ showTranslation: false }">
                <button @click="showTranslation = !showTranslation" class="text-[10px] underline opacity-70 hover:opacity-100 {{ $isOwn ? 'text-blue-200' : 'text-zinc-400' }}">
                    <span x-show="!showTranslation">{{ __('See translation') }}</span>
                    <span x-show="showTranslation" x-cloak>{{ __('Show original') }}</span>
                </button>
                <div x-show="showTranslation" x-cloak class="mt-0.5 text-xs italic {{ $isOwn ? 'text-blue-200' : 'text-zinc-500' }}">
                    "{{ $message->getTranslation(app()->getLocale()) }}"
                    <span class="opacity-60">{{ __('Translated from :lang', ['lang' => strtoupper($message->getDetectedLanguage())]) }}</span>
                </div>
            </div>
        @endif
    @elseif (($message->metadata['transcription_status'] ?? '') === 'pending')
        <div class="text-[10px] {{ $isOwn ? 'text-blue-200' : 'text-zinc-400' }}">
            <span class="animate-pulse">{{ __('Transcribing...') }}</span>
        </div>
    @endif
</div>
