<?php

use App\Models\VideoProject;
use Livewire\Component;

new class extends Component {
    public VideoProject $videoProject;
};
?>

<div
    x-data="voiceoverRecorder({ csrfToken: @js(csrf_token()), projectId: @js($videoProject->id) })"
    class="space-y-3"
>
    {{-- Record Button --}}
    <div x-show="stage === 'idle'">
        <flux:button
            size="sm"
            variant="ghost"
            @click="requestMicAccess()"
            class="w-full justify-start"
        >
            <flux:icon name="microphone" class="size-4 text-red-500" />
            {{ __('Record Voiceover') }}
        </flux:button>
    </div>

    {{-- Requesting Mic Access --}}
    <div x-show="stage === 'requesting'" x-cloak class="text-center py-3">
        <div class="animate-spin w-5 h-5 border-2 border-zinc-400 border-t-transparent rounded-full mx-auto mb-2"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Requesting microphone access...') }}</p>
    </div>

    {{-- Ready to Record / Recording --}}
    <div x-show="stage === 'ready' || stage === 'recording'" x-cloak class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 space-y-3">
        {{-- Audio Level Meter --}}
        <div class="flex items-center gap-2">
            <span :class="stage === 'recording' ? 'text-red-500' : 'text-zinc-400'">
                <flux:icon name="microphone" class="size-4 shrink-0" />
            </span>
            <div class="flex-1 h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-75"
                    :class="stage === 'recording' ? 'bg-red-500' : 'bg-green-500'"
                    :style="'width: ' + Math.min(100, audioLevel) + '%'"
                ></div>
            </div>
        </div>

        {{-- Timer --}}
        <div class="text-center">
            <span
                class="text-lg font-mono font-semibold"
                :class="stage === 'recording' ? 'text-red-500' : 'text-zinc-600 dark:text-zinc-400'"
                x-text="recordingTime"
            ></span>
            <div x-show="stage === 'recording'" class="flex items-center justify-center gap-1.5 mt-1">
                <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                <span class="text-xs text-red-500 font-medium">{{ __('Recording') }}</span>
            </div>
        </div>

        {{-- Controls --}}
        <div class="flex items-center justify-center gap-3">
            <template x-if="stage === 'ready'">
                <div class="flex items-center gap-3">
                    <flux:button size="sm" variant="ghost" @click="cancelRecorder()">
                        {{ __('Cancel') }}
                    </flux:button>
                    <button
                        type="button"
                        @click="startRecording()"
                        class="w-12 h-12 rounded-full border-[3px] border-red-500 flex items-center justify-center hover:border-red-600 transition-colors"
                    >
                        <div class="w-8 h-8 rounded-full bg-red-500"></div>
                    </button>
                </div>
            </template>
            <template x-if="stage === 'recording'">
                <button
                    type="button"
                    @click="stopRecording()"
                    class="w-12 h-12 rounded-full border-[3px] border-red-500 flex items-center justify-center hover:border-red-600 transition-colors"
                >
                    <div class="w-5 h-5 rounded-sm bg-red-500"></div>
                </button>
            </template>
        </div>
    </div>

    {{-- Finalizing --}}
    <div x-show="stage === 'finalizing'" x-cloak class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
        <div class="animate-spin w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-2"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400" x-text="uploadProgressText || '{{ __('Saving voiceover...') }}'"></p>
    </div>

    {{-- Error --}}
    <div x-show="errorMessage" x-cloak class="text-xs text-red-500 text-center py-2" x-text="errorMessage"></div>
</div>

