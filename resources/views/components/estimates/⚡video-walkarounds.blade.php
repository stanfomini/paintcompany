<?php

use App\Models\Estimate;
use App\Models\Job;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $recordableId;
    public string $recordableType;
    public ?int $authUserId = null;
    public ?int $estimateId = null;
    public bool $readOnly = false;

    public function mount(Model $recordable, bool $readOnly = false): void
    {
        $this->readOnly = $readOnly;
        $this->recordableId = $recordable->getKey();
        $this->recordableType = get_class($recordable);
        $this->authUserId = auth()->id() ?? auth('customer')->id() ?? auth('agent')->id() ?? auth('crew')->id();

        // Cache the estimate ID for jobs so we can include estimate videos
        if ($this->recordableType === Job::class) {
            $this->estimateId = Job::where('id', $this->recordableId)->value('estimate_id');
        }
    }

    public function getListeners(): array
    {
        $listeners = [];

        if ($this->recordableType === Estimate::class) {
            $listeners["echo-private:estimate.{$this->recordableId},.estimate.video.updated"] = 'handleVideoUpdated';
        }

        return $listeners;
    }

    public function handleVideoUpdated(): void
    {
        unset($this->recordings);
    }

    #[Computed]
    public function recordings()
    {
        $query = VideoRecording::where('recordable_type', $this->recordableType)
            ->where('recordable_id', $this->recordableId);

        // When viewing a Job, also include videos from the parent estimate
        if ($this->recordableType === Job::class && $this->estimateId) {
            $query = VideoRecording::where(function ($q) {
                $q->where('recordable_type', $this->recordableType)
                  ->where('recordable_id', $this->recordableId);
            })->orWhere(function ($q) {
                $q->where('recordable_type', Estimate::class)
                  ->where('recordable_id', $this->estimateId);
            });
        }

        return $query->orderByDesc('created_at')->get();
    }

    #[Computed]
    public function hasPendingRecordings(): bool
    {
        // Only poll for recent pending recordings (< 10 min old) to avoid
        // infinite polling from orphaned/stale uploads that will never complete
        return $this->recordings->contains(fn ($r) => in_array($r->status, ['uploading', 'processing'])
            && $r->created_at->greaterThan(now()->subMinutes(10))
        );
    }

    public function updateTitle(int $recordingId, string $title): void
    {
        if ($this->readOnly) {
            return;
        }

        $recording = VideoRecording::findOrFail($recordingId);

        if ($recording->user_id !== $this->authUserId) {
            return;
        }

        $recording->update(['title' => $title]);
        unset($this->recordings);
    }

    public function toggleProposalVisibility(int $recordingId): void
    {
        if ($this->readOnly) {
            return;
        }

        $recording = VideoRecording::findOrFail($recordingId);

        // Allow the recording owner OR internal team members to toggle visibility
        $isOwner = $recording->user_id === $this->authUserId;
        $isInternal = auth()->check() && auth()->user()->type === 'internal';

        if (! $isOwner && ! $isInternal) {
            return;
        }

        $recording->update(['show_on_proposal' => ! $recording->show_on_proposal]);
        unset($this->recordings);
    }

    public function toggleHubAvailability(int $recordingId): void
    {
        if ($this->readOnly) {
            return;
        }

        // Only internal users can promote videos to the hub
        if (! auth()->check() || auth()->user()->type !== 'internal') {
            return;
        }

        $recording = VideoRecording::findOrFail($recordingId);
        $recording->update(['available_in_hub' => ! $recording->available_in_hub]);
        unset($this->recordings);
    }

    public function deleteRecording(int $recordingId): void
    {
        if ($this->readOnly) {
            return;
        }

        $recording = VideoRecording::findOrFail($recordingId);

        if ($recording->user_id !== $this->authUserId) {
            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk($recording->disk);

        if ($recording->original_path && $disk->exists($recording->original_path)) {
            $disk->delete($recording->original_path);
        }
        if ($recording->video_path && $disk->exists($recording->video_path)) {
            $disk->delete($recording->video_path);
        }
        if ($recording->thumbnail_path && $disk->exists($recording->thumbnail_path)) {
            $disk->delete($recording->thumbnail_path);
        }

        $recording->delete();
        unset($this->recordings);
    }
};
?>

<div
    @if ($this->hasPendingRecordings) wire:poll.2s.visible @endif
    @if (! $readOnly)
        x-data="walkaroundRecorder({
            recordableId: @js($recordableId),
            recordableType: @js($recordableType),
            csrfToken: @js(csrf_token()),
        })"
    @endif
>
    {{-- Section Header --}}
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="sm">{{ __('Video Walkarounds') }}</flux:heading>
        @if (! $readOnly)
            <button
                type="button"
                x-show="stage === 'idle'"
                @click="startCamera()"
                class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors"
            >
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"></circle></svg>
                {{ __('Record Walkaround') }}
            </button>
        @endif
    </div>

    @if (! $readOnly)
    {{-- Fullscreen Recorder Overlay --}}
    <template x-teleport="body">
        <div
            x-show="stage !== 'idle'"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-effect="
                if (stage !== 'idle') {
                    document.body.style.overflow = 'hidden';
                    document.body.style.position = 'fixed';
                    document.body.style.width = '100%';
                } else {
                    document.body.style.overflow = '';
                    document.body.style.position = '';
                    document.body.style.width = '';
                }
            "
            class="fixed inset-0 z-[9999] bg-black flex flex-col"
            style="touch-action: none;"
            @touchmove.prevent
        >
            {{-- Video fills the screen --}}
            <div class="relative flex-1 overflow-hidden">
                <video
                    x-ref="videoPreview"
                    autoplay
                    muted
                    playsinline
                    x-show="stage === 'previewing' || stage === 'recording'"
                    class="absolute inset-0 w-full h-full object-cover"
                ></video>

                <video
                    x-ref="videoReview"
                    playsinline
                    controls
                    x-show="stage === 'review'"
                    class="absolute inset-0 w-full h-full object-cover"
                ></video>

                {{-- Top bar: safe area + status indicators --}}
                <div class="absolute top-0 left-0 right-0 pt-[env(safe-area-inset-top,12px)] px-4 pb-2 bg-gradient-to-b from-black/60 to-transparent z-10">
                    <div class="flex items-center justify-between h-12">
                        {{-- Close button (previewing only) --}}
                        <button
                            x-show="stage === 'previewing'"
                            type="button"
                            @click="cancelRecorder()"
                            class="w-10 h-10 flex items-center justify-center rounded-full bg-black/40 backdrop-blur-sm text-white active:bg-black/60"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                        <div x-show="stage !== 'previewing'" class="w-10"></div>

                        {{-- Recording time badge --}}
                        <div x-show="stage === 'recording'" class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-red-600/90 backdrop-blur-sm text-white text-sm font-semibold">
                            <div class="w-2.5 h-2.5 rounded-full bg-white animate-pulse"></div>
                            <span x-text="recordingTime"></span>
                        </div>
                        <div x-show="stage !== 'recording'" class="h-8"></div>

                        {{-- Flip camera button (previewing / recording) --}}
                        <button
                            x-show="stage === 'previewing' || stage === 'recording'"
                            type="button"
                            @click="flipCamera()"
                            class="w-10 h-10 flex items-center justify-center rounded-full bg-black/40 backdrop-blur-sm text-white active:bg-black/60"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        </button>
                        <div x-show="stage !== 'previewing' && stage !== 'recording'" class="w-10"></div>
                    </div>
                </div>

                {{-- Upload / Finalizing / Processing overlays --}}
                <div x-show="stage === 'uploading'" class="absolute inset-0 flex items-center justify-center bg-black/80 z-20">
                    <div class="text-center">
                        <div class="w-20 h-20 mx-auto mb-4 relative">
                            <svg class="w-20 h-20 transform -rotate-90" viewBox="0 0 80 80">
                                <circle cx="40" cy="40" r="34" fill="none" stroke="#3f3f46" stroke-width="5" />
                                <circle cx="40" cy="40" r="34" fill="none" stroke="#ef4444" stroke-width="5" stroke-linecap="round"
                                    :stroke-dasharray="'213.63'"
                                    :stroke-dashoffset="213.63 - (213.63 * uploadProgress / 100)"
                                />
                            </svg>
                            <span class="absolute inset-0 flex items-center justify-center text-white text-lg font-bold" x-text="Math.round(uploadProgress) + '%'"></span>
                        </div>
                        <p class="text-white text-base font-medium">{{ __('Uploading...') }}</p>
                    </div>
                </div>

                <div x-show="stage === 'finalizing'" class="absolute inset-0 flex items-center justify-center bg-black/80 z-20">
                    <div class="text-center">
                        <div class="animate-spin w-14 h-14 border-4 border-white border-t-transparent rounded-full mx-auto mb-4"></div>
                        <p class="text-white text-base font-medium" x-text="uploadQueue.length > 0 ? '{{ __('Sending final chunks...') }}' : '{{ __('Finalizing...') }}'"></p>
                    </div>
                </div>

                <div x-show="stage === 'processing'" class="absolute inset-0 flex items-center justify-center bg-black/80 z-20">
                    <div class="text-center">
                        <div class="animate-spin w-14 h-14 border-4 border-white border-t-transparent rounded-full mx-auto mb-4"></div>
                        <p class="text-white text-base font-medium">{{ __('Processing video...') }}</p>
                        <p class="text-zinc-400 text-sm mt-1">{{ __('This may take a moment') }}</p>
                    </div>
                </div>
            </div>

            {{-- Bottom controls bar --}}
            <div class="shrink-0 bg-black pb-[env(safe-area-inset-bottom,16px)]">
                {{-- Previewing: big record button --}}
                <div x-show="stage === 'previewing'" class="flex items-center justify-center py-6">
                    <button
                        type="button"
                        @click="startRecording()"
                        class="w-20 h-20 rounded-full border-[5px] border-white flex items-center justify-center active:scale-95 transition-transform"
                    >
                        <div class="w-14 h-14 rounded-full bg-red-600"></div>
                    </button>
                </div>

                {{-- Recording: stop button --}}
                <div x-show="stage === 'recording'" class="flex items-center justify-center py-6">
                    <button
                        type="button"
                        @click="stopRecording()"
                        class="w-20 h-20 rounded-full border-[5px] border-white flex items-center justify-center active:scale-95 transition-transform"
                    >
                        <div class="w-8 h-8 rounded-sm bg-red-600"></div>
                    </button>
                </div>

                {{-- Review: retake / title / save --}}
                <div x-show="stage === 'review'" class="px-4 py-4 space-y-3">
                    <input
                        type="text"
                        x-model="videoTitle"
                        placeholder="{{ __('Add a title (optional)') }}"
                        class="w-full px-4 py-3 text-base rounded-xl bg-zinc-900 border border-zinc-700 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-red-500"
                    />
                    <div class="flex items-center gap-3">
                        <button type="button" @click="retake()" class="flex-1 py-3 rounded-xl bg-zinc-800 text-white font-semibold text-base active:bg-zinc-700 transition-colors">
                            {{ __('Retake') }}
                        </button>
                        <button type="button" @click="saveRecording()" class="flex-[2] py-3 rounded-xl bg-red-600 text-white font-semibold text-base active:bg-red-700 transition-colors flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                            {{ __('Save') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
    @endif

    {{-- Video List --}}
    @if ($this->recordings->isNotEmpty())
        <div class="space-y-3">
            @foreach ($this->recordings as $recording)
                <div
                    wire:key="recording-{{ $recording->id }}"
                    x-data="{ playing: false, editing: false, editTitle: @js($recording->title ?? '') }"
                    class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden"
                >
                    @if ($recording->isReady())
                        {{-- Ready video card --}}
                        <div class="flex items-start gap-4 p-3">
                            {{-- Thumbnail with play overlay --}}
                            <div class="relative w-32 aspect-video shrink-0 rounded-md overflow-hidden bg-zinc-200 dark:bg-zinc-700 cursor-pointer" @click="playing = !playing">
                                @if ($recording->getThumbnailUrl())
                                    <img src="{{ $recording->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover" />
                                @else
                                    <div class="w-full h-full flex items-center justify-center">
                                        <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                    </div>
                                @endif
                                <div class="absolute inset-0 flex items-center justify-center bg-black/30 hover:bg-black/40 transition-colors">
                                    <svg x-show="!playing" class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"></path></svg>
                                    <svg x-show="playing" class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"></path></svg>
                                </div>
                                @if ($recording->formatted_duration)
                                    <div class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded bg-black/70 text-white text-xs font-medium">
                                        {{ $recording->formatted_duration }}
                                    </div>
                                @endif
                            </div>

                            {{-- Info --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        {{-- Editable title --}}
                                        @if ($readOnly)
                                            <flux:text class="font-medium text-sm truncate">
                                                {{ $recording->title ?: __('Untitled Walkaround') }}
                                            </flux:text>
                                        @else
                                            <div x-show="!editing" @dblclick="editing = true; $nextTick(() => $refs['titleInput{{ $recording->id }}']?.focus())" class="cursor-pointer">
                                                <flux:text class="font-medium text-sm truncate">
                                                    {{ $recording->title ?: __('Untitled Walkaround') }}
                                                </flux:text>
                                            </div>
                                            <div x-show="editing" x-cloak>
                                                <input
                                                    x-ref="titleInput{{ $recording->id }}"
                                                    type="text"
                                                    x-model="editTitle"
                                                    @blur="editing = false; if (editTitle.trim()) $wire.updateTitle({{ $recording->id }}, editTitle.trim())"
                                                    @keydown.enter="editing = false; if (editTitle.trim()) $wire.updateTitle({{ $recording->id }}, editTitle.trim())"
                                                    @keydown.escape="editing = false"
                                                    class="w-full px-2 py-0.5 text-sm rounded border border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800"
                                                />
                                            </div>
                                        @endif
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <flux:text class="text-xs text-zinc-500">
                                                {{ $recording->created_at->diffForHumans() }}
                                                · {{ $recording->user?->name }}
                                            </flux:text>
                                            @if ($this->recordableType === \App\Models\Job::class && $recording->recordable_type === \App\Models\Estimate::class)
                                                <flux:badge size="sm" variant="outline" class="text-xs">{{ __('From estimate') }}</flux:badge>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Actions --}}
                                    <div class="flex items-center gap-1 shrink-0">
                                        @if (! $readOnly && $recording->recordable_type === \App\Models\Estimate::class && ($recording->user_id === $authUserId || (auth()->check() && auth()->user()->type === 'internal')))
                                            <button
                                                type="button"
                                                wire:click="toggleProposalVisibility({{ $recording->id }})"
                                                class="p-1.5 rounded transition-colors {{ $recording->show_on_proposal ? 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30' : 'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                                                :title="$recording->show_on_proposal ? __('Visible on proposal') : __('Hidden from proposal')"
                                            >
                                                @if ($recording->show_on_proposal)
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                @else
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>
                                                @endif
                                            </button>
                                        @endif
                                        @if (! $readOnly && auth()->check() && auth()->user()->type === 'internal')
                                            <button
                                                type="button"
                                                wire:click="toggleHubAvailability({{ $recording->id }})"
                                                class="p-1.5 rounded transition-colors {{ $recording->available_in_hub ? 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30' : 'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                                                title="{{ $recording->available_in_hub ? __('Available in Media Hub') : __('Add to Media Hub') }}"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"></path></svg>
                                            </button>
                                        @endif
                                        @if (! $readOnly)
                                            <button
                                                type="button"
                                                x-data="{ copied: false }"
                                                @click="navigator.clipboard.writeText(@js($recording->getShareableUrl())).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                                class="p-1.5 rounded text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                                title="Copy share link"
                                            >
                                                <svg x-show="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                                                <svg x-show="copied" class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                            </button>
                                            <button
                                                type="button"
                                                x-data="{ canShare: !!navigator.share }"
                                                x-show="canShare"
                                                x-cloak
                                                @click="navigator.share({ title: @js($recording->title ?: 'Walkaround Video'), url: @js($recording->getShareableUrl()) }).catch(() => {})"
                                                class="p-1.5 rounded text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                                title="Share"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                                            </button>
                                        @endif
                                        @if (! $readOnly && $recording->user_id === $authUserId)
                                            <button
                                                type="button"
                                                wire:click="deleteRecording({{ $recording->id }})"
                                                wire:confirm="{{ __('Are you sure you want to delete this recording?') }}"
                                                class="p-1.5 rounded text-zinc-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                                title="Delete"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Inline video player --}}
                        <div x-show="playing" x-collapse>
                            <div class="border-t border-zinc-200 dark:border-zinc-700">
                                <video
                                    x-ref="player{{ $recording->id }}"
                                    x-init="$watch('playing', function(v) { if(v) { $el.play() } else { $el.pause(); $el.currentTime = 0 } })"
                                    @click="$el.paused ? $el.play() : $el.pause()"
                                    controls
                                    playsinline
                                    preload="metadata"
                                    class="w-full"
                                    src="{{ $recording->getVideoUrl() }}"
                                ></video>
                            </div>
                        </div>
                    @elseif ($recording->isProcessing())
                        {{-- Processing card --}}
                        <div class="flex items-center gap-4 p-3">
                            <div class="w-32 aspect-video shrink-0 rounded-md bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <div class="animate-spin w-6 h-6 border-2 border-zinc-400 border-t-transparent rounded-full"></div>
                            </div>
                            <div>
                                <flux:text class="font-medium text-sm">{{ $recording->title ?: __('Untitled Walkaround') }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 mt-0.5">
                                    {{ $recording->status === 'uploading' ? __('Uploading...') : __('Processing video...') }}
                                </flux:text>
                            </div>
                        </div>
                    @elseif ($recording->isFailed())
                        {{-- Failed card --}}
                        <div class="flex items-center justify-between gap-4 p-3 bg-red-50 dark:bg-red-900/10">
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path></svg>
                                <div>
                                    <flux:text class="font-medium text-sm text-red-700 dark:text-red-400">{{ $recording->title ?: __('Untitled Walkaround') }}</flux:text>
                                    <flux:text class="text-xs text-red-600/70 dark:text-red-400/70">{{ __('Processing failed') }}</flux:text>
                                </div>
                            </div>
                            @if (! $readOnly && $recording->user_id === $authUserId)
                                <button
                                    type="button"
                                    wire:click="deleteRecording({{ $recording->id }})"
                                    class="text-xs text-red-600 hover:underline"
                                >
                                    {{ __('Remove') }}
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif ($this->recordings->isEmpty())
        <div class="text-center py-6" x-show="stage === 'idle'">
            <svg class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
            <flux:text class="text-zinc-500 text-sm">{{ __('No walkaround videos yet. Record one to share with the team.') }}</flux:text>
        </div>
    @endif
</div>

@script
<script>
Alpine.data('walkaroundRecorder', (config) => ({
    stage: 'idle', // idle, previewing, recording, review, finalizing, uploading, processing
    stream: null,
    mediaRecorder: null,
    recordedChunks: [], // kept for local review playback
    recordedBlob: null,
    videoTitle: '',
    uploadProgress: 0,
    recordingSeconds: 0,
    recordingTimer: null,
    useFrontCamera: false,
    maxDuration: 120,

    // Raw stream reference (not wrapped by Alpine's Proxy, safe for MediaRecorder)
    _rawStream: null,

    // Chunked streaming properties
    recordingId: null,
    chunkIndex: 0,
    uploadQueue: [],
    isUploading: false,
    totalUploaded: 0,
    chunkRetries: {},
    maxRetries: 3,
    useChunkedUpload: true, // will fallback to legacy if start fails

    init() {
        // Auto-finalize chunked uploads if user navigates away during review
        this._beforeUnloadHandler = (e) => {
            if (this.useChunkedUpload && this.recordingId && (this.stage === 'review' || this.stage === 'recording')) {
                // Use sendBeacon for reliable fire-and-forget finalize
                navigator.sendBeacon(
                    `/api/video-recordings/${this.recordingId}/finalize`,
                    new Blob([JSON.stringify({ _token: config.csrfToken })], { type: 'application/json' })
                );
            }
        };
        window.addEventListener('beforeunload', this._beforeUnloadHandler);

        // If user exits fullscreen via Escape or swipe, cancel the recorder
        this._fullscreenChangeHandler = () => {
            const isFullscreen = document.fullscreenElement || document.webkitFullscreenElement;
            if (!isFullscreen && this.stage === 'previewing') {
                // User exited fullscreen while previewing - cancel back to idle
                this.cancelRecorder();
            }
        };
        document.addEventListener('fullscreenchange', this._fullscreenChangeHandler);
        document.addEventListener('webkitfullscreenchange', this._fullscreenChangeHandler);
    },

    destroy() {
        window.removeEventListener('beforeunload', this._beforeUnloadHandler);
        document.removeEventListener('fullscreenchange', this._fullscreenChangeHandler);
        document.removeEventListener('webkitfullscreenchange', this._fullscreenChangeHandler);
    },

    get recordingTime() {
        const m = Math.floor(this.recordingSeconds / 60);
        const s = this.recordingSeconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    },

    formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    },

    async enterFullscreen() {
        try {
            const el = document.documentElement;
            if (el.requestFullscreen) {
                await el.requestFullscreen();
            } else if (el.webkitRequestFullscreen) {
                await el.webkitRequestFullscreen();
            }
        } catch (_) {
            // Fullscreen not supported or denied - continue without it
        }

        // Lock orientation to portrait on mobile if supported
        try {
            if (screen.orientation && screen.orientation.lock) {
                await screen.orientation.lock('portrait');
            }
        } catch (_) {
            // Orientation lock not supported - continue
        }
    },

    async exitFullscreen() {
        // Unlock orientation
        try {
            if (screen.orientation && screen.orientation.unlock) {
                screen.orientation.unlock();
            }
        } catch (_) {}

        // Exit fullscreen
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else if (document.webkitFullscreenElement) {
                await document.webkitExitFullscreen();
            }
        } catch (_) {}
    },

    async startCamera(options = {}) {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: this.useFrontCamera ? 'user' : 'environment',
                    width: { ideal: 1280, max: 1280 },
                    height: { ideal: 720, max: 720 },
                    frameRate: { ideal: 30, max: 30 },
                },
                audio: true,
            });

            // Store raw reference outside Alpine reactivity for MediaRecorder
            this._rawStream = stream;
            this.stream = stream;
            this.stage = 'previewing';

            // Enter fullscreen for native camera feel (skip if already fullscreen, e.g. flip)
            if (!options.skipFullscreen) {
                await this.enterFullscreen();
            }

            this.$nextTick(() => {
                if (this.$refs.videoPreview) {
                    this.$refs.videoPreview.srcObject = this._rawStream;
                }
            });
        } catch (e) {
            console.error('Camera access failed:', e);
            alert('Could not access camera. Please check permissions.');
        }
    },

    async flipCamera() {
        this.useFrontCamera = !this.useFrontCamera;
        if (this._rawStream) {
            this._rawStream.getTracks().forEach(t => t.stop());
            this._rawStream = null;
            this.stream = null;
        }
        await this.startCamera({ skipFullscreen: true });
    },

    async startRecording() {
        if (!this._rawStream) return;

        this.recordedChunks = [];
        this.recordingSeconds = 0;
        this.chunkIndex = 0;
        this.uploadQueue = [];
        this.isUploading = false;
        this.totalUploaded = 0;
        this.chunkRetries = {};
        this.recordingId = null;
        this.useChunkedUpload = true;

        const mimeType = this.getSupportedMimeType();
        if (!mimeType) {
            alert('Your browser does not support video recording.');
            return;
        }

        // Start chunked upload session on the server
        try {
            const response = await fetch('/api/video-recordings/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    recordable_type: config.recordableType,
                    recordable_id: config.recordableId,
                    mime_type: mimeType,
                }),
            });

            if (!response.ok) throw new Error('Failed to start upload session');

            const data = await response.json();
            this.recordingId = data.id;
        } catch (e) {
            console.warn('Chunked upload init failed, will use legacy upload:', e);
            this.useChunkedUpload = false;
        }

        // Use raw stream reference to avoid Alpine Proxy issues with MediaRecorder
        this.mediaRecorder = new MediaRecorder(this._rawStream, {
            mimeType,
            videoBitsPerSecond: 2500000,
        });

        this.mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) {
                // Always keep for local playback
                this.recordedChunks.push(e.data);

                // Stream chunk to server if chunked upload is active
                if (this.useChunkedUpload && this.recordingId) {
                    this.uploadQueue.push(e.data);
                    this.processUploadQueue();
                }
            }
        };

        this.mediaRecorder.onstop = () => {
            this.recordedBlob = new Blob(this.recordedChunks, { type: mimeType });
            this.stage = 'review';

            this.$nextTick(() => {
                if (this.$refs.videoReview) {
                    this.$refs.videoReview.src = URL.createObjectURL(this.recordedBlob);
                }
            });
        };

        this.mediaRecorder.start(1000);
        this.stage = 'recording';

        this.recordingTimer = setInterval(() => {
            this.recordingSeconds++;
            if (this.recordingSeconds >= this.maxDuration) {
                this.stopRecording();
            }
        }, 1000);
    },

    async processUploadQueue() {
        if (this.isUploading || this.uploadQueue.length === 0) return;

        this.isUploading = true;

        while (this.uploadQueue.length > 0) {
            const chunk = this.uploadQueue.shift();
            const currentIndex = this.chunkIndex;
            const retryKey = `chunk_${currentIndex}`;

            try {
                const formData = new FormData();
                formData.append('chunk', chunk, `chunk_${currentIndex}.webm`);
                formData.append('chunk_index', currentIndex);

                const response = await fetch(`/api/video-recordings/${this.recordingId}/chunk`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                if (!response.ok) throw new Error('Chunk upload failed');

                this.chunkIndex++;
                this.totalUploaded += chunk.size;
                this.chunkRetries[retryKey] = 0;
            } catch (e) {
                const retries = (this.chunkRetries[retryKey] || 0) + 1;
                this.chunkRetries[retryKey] = retries;

                if (retries <= this.maxRetries) {
                    // Push back to front of queue and retry after delay
                    this.uploadQueue.unshift(chunk);
                    await new Promise(r => setTimeout(r, 1000 * retries));
                } else {
                    console.error(`Chunk ${currentIndex} failed after ${this.maxRetries} retries`);
                    // Continue with next chunks - finalize will catch missing data
                }
            }
        }

        this.isUploading = false;
    },

    stopRecording() {
        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
            this.recordingTimer = null;
        }

        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }

        if (this._rawStream) {
            this._rawStream.getTracks().forEach(t => t.stop());
            this._rawStream = null;
            this.stream = null;
        }
    },

    retake() {
        // If we had a chunked session going, tell the server to discard it
        if (this.recordingId) {
            fetch(`/api/video-recordings/${this.recordingId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': config.csrfToken,
                    'Accept': 'application/json',
                },
            }).catch(() => {}); // Fire-and-forget cleanup
        }

        this.recordedBlob = null;
        this.recordedChunks = [];
        this.videoTitle = '';
        this.recordingId = null;
        this.chunkIndex = 0;
        this.uploadQueue = [];
        this.totalUploaded = 0;

        this.startCamera({ skipFullscreen: true });
    },

    async saveRecording() {
        if (this.useChunkedUpload && this.recordingId) {
            await this.finalizeChunkedUpload();
        } else {
            await this.uploadRecordingLegacy();
        }
    },

    async finalizeChunkedUpload() {
        this.stage = 'finalizing';

        // Wait for any remaining chunks in the queue to finish uploading
        while (this.uploadQueue.length > 0 || this.isUploading) {
            await new Promise(r => setTimeout(r, 200));
        }

        try {
            // Update title if set (parallel with finalize)
            const promises = [];

            if (this.videoTitle.trim()) {
                promises.push(
                    fetch(`/api/video-recordings/${this.recordingId}/title`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ title: this.videoTitle.trim() }),
                    })
                );
            }

            promises.push(
                fetch(`/api/video-recordings/${this.recordingId}/finalize`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                })
            );

            const results = await Promise.all(promises);
            const finalizeResponse = results[results.length - 1];

            if (!finalizeResponse.ok) throw new Error('Finalize failed');

            // Save succeeded - clean up state
            this.stage = 'processing';
            this.recordedBlob = null;
            this.recordedChunks = [];
            this.videoTitle = '';
            this.recordingId = null;
            this.totalUploaded = 0;

            // Refresh Livewire component (non-critical, don't let it block)
            try { this.$wire.$refresh(); } catch (_) {}

            setTimeout(async () => {
                this.stage = 'idle';
                await this.exitFullscreen();
            }, 1500);
        } catch (e) {
            console.error('Finalize failed:', e);
            alert('Failed to save recording. Please try again.');
            this.stage = 'review';
        }
    },

    async uploadRecordingLegacy() {
        if (!this.recordedBlob) return;

        this.stage = 'uploading';
        this.uploadProgress = 0;

        const ext = this.recordedBlob.type.includes('mp4') ? 'mp4' : 'webm';
        const formData = new FormData();
        formData.append('file', this.recordedBlob, `walkaround.${ext}`);
        formData.append('recordable_type', config.recordableType);
        formData.append('recordable_id', config.recordableId);
        if (this.videoTitle.trim()) {
            formData.append('title', this.videoTitle.trim());
        }

        try {
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    this.uploadProgress = (e.loaded / e.total) * 100;
                }
            });

            const response = await new Promise((resolve, reject) => {
                xhr.onload = () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve(JSON.parse(xhr.responseText));
                    } else {
                        reject(new Error('Upload failed: ' + xhr.statusText));
                    }
                };
                xhr.onerror = () => reject(new Error('Upload failed'));

                xhr.open('POST', '/api/video-recordings');
                xhr.setRequestHeader('X-CSRF-TOKEN', config.csrfToken);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.send(formData);
            });

            this.stage = 'processing';
            this.recordedBlob = null;
            this.recordedChunks = [];
            this.videoTitle = '';

            this.$wire.$refresh();

            setTimeout(async () => {
                this.stage = 'idle';
                await this.exitFullscreen();
            }, 1500);

        } catch (e) {
            console.error('Upload failed:', e);
            alert('Upload failed. Please try again.');
            this.stage = 'review';
        }
    },

    async cancelRecorder() {
        if (this._rawStream) {
            this._rawStream.getTracks().forEach(t => t.stop());
            this._rawStream = null;
            this.stream = null;
        }
        if (this.recordingTimer) {
            clearInterval(this.recordingTimer);
            this.recordingTimer = null;
        }
        this.mediaRecorder = null;
        this.recordedBlob = null;
        this.recordedChunks = [];
        this.videoTitle = '';
        this.recordingId = null;
        this.chunkIndex = 0;
        this.uploadQueue = [];
        this.totalUploaded = 0;
        this.stage = 'idle';

        await this.exitFullscreen();
    },

    getSupportedMimeType() {
        const types = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp9,opus',
            'video/webm',
            'video/mp4;codecs=h264,aac',
            'video/mp4',
        ];
        for (const type of types) {
            if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(type)) return type;
        }
        return '';
    },
}));
</script>
@endscript
