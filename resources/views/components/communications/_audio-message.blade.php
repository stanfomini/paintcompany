{{-- Audio message display partial - used in communications hub messengers --}}
@php
    $audioUrl = $message->getAudioUrl() ?? '';
@endphp
<div class="space-y-1.5">
    <div
        x-data="{
            audioError: false,
            audioLoaded: false,
            checkError(el) {
                if (this.audioLoaded) return;
                if (el.networkState === 3 || (el.error && el.readyState === 0)) {
                    this.audioError = true;
                }
            }
        }"
        class="relative"
    >
        <audio
            controls
            class="w-full max-w-[250px]"
            preload="metadata"
            x-on:loadedmetadata="audioLoaded = true; audioError = false"
            x-on:error="$nextTick(() => checkError($el))"
            x-show="!audioError"
        >
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
            @if ($message->needsTranslationFor(app()->getLocale()))
                <p class="text-xs italic {{ $isOwn ? 'text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}">
                    "{{ $message->getTranslation(app()->getLocale()) }}"
                </p>
                <div x-data="{ showOriginal: false }" class="mt-0.5">
                    <button @click="showOriginal = !showOriginal" class="text-[10px] underline opacity-70 hover:opacity-100 {{ $isOwn ? 'text-blue-200' : 'text-zinc-400' }}">
                        <span x-show="!showOriginal">{{ __('Show original') }}</span>
                        <span x-show="showOriginal" x-cloak>{{ __('Hide original') }}</span>
                    </button>
                    <div x-show="showOriginal" x-cloak class="mt-0.5 text-xs italic {{ $isOwn ? 'text-blue-200' : 'text-zinc-500' }}">
                        "{{ $message->metadata['transcription'] }}"
                        <span class="opacity-60">({{ strtoupper($message->getDetectedLanguage()) }})</span>
                    </div>
                </div>
            @else
                <p class="text-xs italic {{ $isOwn ? 'text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}">
                    "{{ $message->metadata['transcription'] }}"
                </p>
            @endif
        </div>
    @elseif (($message->metadata['transcription_status'] ?? '') === 'pending')
        <div class="text-[10px] {{ $isOwn ? 'text-blue-200' : 'text-zinc-400' }}">
            <span class="animate-pulse">{{ __('Transcribing...') }}</span>
        </div>
    @elseif (($message->metadata['transcription_status'] ?? '') === 'failed')
        <div class="text-[10px] {{ $isOwn ? 'text-blue-200' : 'text-zinc-400' }}">
            {{ __('Transcription unavailable') }}
        </div>
    @endif

    <div class="text-[10px] {{ $isOwn ? 'text-blue-200/60' : 'text-zinc-400/60' }}">
        <flux:icon name="clock" class="inline h-2.5 w-2.5 mr-0.5" />
        {{ __('Audio files are deleted after 30 days') }}
    </div>
</div>
