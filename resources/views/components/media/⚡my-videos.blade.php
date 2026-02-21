<?php

use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';

    public string $filterVisibility = 'all';

    public string $sortBy = 'latest';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterVisibility(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function videos()
    {
        return VideoRecording::query()
            ->where('user_id', auth()->id())
            ->whereNull('recordable_type')
            ->when($this->search, function ($query) {
                $query->where('title', 'like', "%{$this->search}%");
            })
            ->when($this->filterVisibility !== 'all', function ($query) {
                $query->where('visibility', $this->filterVisibility);
            })
            ->when($this->sortBy === 'latest', fn ($q) => $q->latest())
            ->when($this->sortBy === 'oldest', fn ($q) => $q->oldest())
            ->when($this->sortBy === 'title', fn ($q) => $q->orderBy('title'))
            ->withCount('views')
            ->paginate(12);
    }

    #[On('video-uploaded')]
    public function refreshVideos(): void
    {
        unset($this->videos);
    }

    public function deleteVideo(int $id): void
    {
        $video = VideoRecording::where('user_id', auth()->id())->findOrFail($id);
        $video->delete();
    }
};
?>

<div>
    <div class="flex flex-col sm:flex-row gap-3 mb-6">
        <div class="flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search videos...') }}" icon="magnifying-glass" />
        </div>
        <div class="flex gap-2">
            <flux:select wire:model.live="filterVisibility" class="w-36">
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                <flux:select.option value="public">{{ __('Public') }}</flux:select.option>
                <flux:select.option value="unlisted">{{ __('Unlisted') }}</flux:select.option>
                <flux:select.option value="internal">{{ __('Internal') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="sortBy" class="w-36">
                <flux:select.option value="latest">{{ __('Newest') }}</flux:select.option>
                <flux:select.option value="oldest">{{ __('Oldest') }}</flux:select.option>
                <flux:select.option value="title">{{ __('Title') }}</flux:select.option>
            </flux:select>
            <div x-data="videoUploader()" x-cloak>
                <flux:button variant="primary" icon="arrow-up-tray" x-on:click="openModal()">
                    {{ __('Upload Video') }}
                </flux:button>

                <template x-teleport="body">
                    <div x-show="showModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" x-on:keydown.escape.window="closeModal()">
                        <div x-show="showModal" x-transition x-on:click.outside="closeModal()" class="bg-white dark:bg-zinc-900 rounded-xl shadow-xl w-full max-w-md border border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center justify-between px-5 py-4 border-b border-zinc-200 dark:border-zinc-700">
                                <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Upload Video') }}</h3>
                                <button x-on:click="closeModal()" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>

                            <div class="p-5 space-y-4">
                                {{-- File Drop Zone --}}
                                <template x-if="!file && !uploading">
                                    <div>
                                        <label
                                            class="flex flex-col items-center justify-center gap-2 p-8 border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg cursor-pointer hover:border-zinc-400 dark:hover:border-zinc-500 transition-colors"
                                            x-on:dragover.prevent="$el.classList.add('border-blue-500')"
                                            x-on:dragleave.prevent="$el.classList.remove('border-blue-500')"
                                            x-on:drop.prevent="handleDrop($event)"
                                        >
                                            <svg class="size-10 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                                            <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Drop a video file or click to browse') }}</span>
                                            <span class="text-xs text-zinc-400">{{ __('MP4, WebM, MOV, MKV up to 5GB') }}</span>
                                            <input type="file" class="hidden" accept="video/mp4,video/webm,video/quicktime,video/x-matroska" x-on:change="selectFile($event)" />
                                        </label>
                                    </div>
                                </template>

                                {{-- Selected File Info --}}
                                <template x-if="file && !uploading">
                                    <div class="space-y-4">
                                        <div class="flex items-center gap-3 p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                                            <svg class="size-8 text-zinc-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate" x-text="file.name"></p>
                                                <p class="text-xs text-zinc-500" x-text="formatSize(file.size)"></p>
                                            </div>
                                            <button x-on:click="file = null" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Title (optional)') }}</label>
                                            <input type="text" x-model="title" placeholder="{{ __('e.g. Training Video - Cutting In') }}" class="w-full rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-2 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                                        </div>

                                        <template x-if="errorMessage">
                                            <p class="text-sm text-red-600 dark:text-red-400" x-text="errorMessage"></p>
                                        </template>
                                    </div>
                                </template>

                                {{-- Upload Progress --}}
                                <template x-if="uploading">
                                    <div class="space-y-3">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Uploading...') }}</span>
                                            <span class="font-medium text-zinc-900 dark:text-zinc-100" x-text="progress + '%'"></span>
                                        </div>
                                        <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-2.5 overflow-hidden">
                                            <div class="bg-blue-600 h-full rounded-full transition-all duration-300" x-bind:style="'width: ' + progress + '%'"></div>
                                        </div>
                                        <p class="text-xs text-zinc-500">
                                            <span x-text="file ? formatSize(file.size * progress / 100) + ' / ' + formatSize(file.size) : ''"></span>
                                        </p>
                                    </div>
                                </template>

                                {{-- Success --}}
                                <template x-if="uploadComplete">
                                    <div class="text-center py-4">
                                        <svg class="size-12 text-green-500 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Video uploaded successfully!') }}</p>
                                        <p class="text-xs text-zinc-500 mt-1">{{ __('Processing will begin shortly.') }}</p>
                                    </div>
                                </template>
                            </div>

                            <div class="flex justify-end gap-2 px-5 py-4 border-t border-zinc-200 dark:border-zinc-700">
                                <template x-if="!uploading && !uploadComplete">
                                    <div class="flex gap-2">
                                        <flux:button variant="ghost" x-on:click="closeModal()">{{ __('Cancel') }}</flux:button>
                                        <flux:button variant="primary" x-on:click="upload()" x-bind:disabled="!file" icon="arrow-up-tray">{{ __('Upload') }}</flux:button>
                                    </div>
                                </template>
                                <template x-if="uploadComplete">
                                    <flux:button variant="primary" x-on:click="closeModal()">{{ __('Done') }}</flux:button>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    @if($this->videos->count() > 0)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($this->videos as $video)
                <div wire:key="video-{{ $video->id }}" data-video-title="{{ $video->title }}">
                    @livewire('media.video-card', ['video' => $video], key('card-' . $video->id))
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $this->videos->links() }}
        </div>
    @else
        <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
            <flux:icon name="video-camera" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-3">{{ __('No videos found') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                @if($search || $filterVisibility !== 'all')
                    {{ __('Try adjusting your search or filters.') }}
                @else
                    {{ __('Record or upload your first video to get started.') }}
                @endif
            </flux:text>
        </div>
    @endif

    @script
    <script>
        Alpine.data('videoUploader', () => ({
            showModal: false,
            file: null,
            title: '',
            uploading: false,
            progress: 0,
            uploadComplete: false,
            errorMessage: '',
            abortController: null,
            cancelled: false,

            // Chunked upload config
            CHUNK_SIZE: 5 * 1024 * 1024, // 5MB chunks
            MAX_FILE_SIZE: 5 * 1024 * 1024 * 1024, // 5GB max
            MAX_RETRIES: 3,

            openModal() {
                this.showModal = true;
                this.resetState();
            },

            closeModal() {
                if (this.uploading) {
                    this.cancelled = true;
                    if (this.abortController) {
                        this.abortController.abort();
                    }
                }
                this.showModal = false;
                if (this.uploadComplete) {
                    $wire.dispatch('video-uploaded');
                }
            },

            resetState() {
                this.file = null;
                this.title = '';
                this.uploading = false;
                this.progress = 0;
                this.uploadComplete = false;
                this.errorMessage = '';
                this.abortController = null;
                this.cancelled = false;
            },

            selectFile(event) {
                const file = event.target.files[0];
                if (file) this.setFile(file);
            },

            handleDrop(event) {
                const file = event.dataTransfer.files[0];
                if (file) this.setFile(file);
            },

            setFile(file) {
                this.errorMessage = '';
                const allowedTypes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska'];

                if (!allowedTypes.includes(file.type)) {
                    this.errorMessage = '{{ __("Invalid file type. Please select MP4, WebM, MOV, or MKV.") }}';
                    return;
                }

                if (file.size > this.MAX_FILE_SIZE) {
                    this.errorMessage = '{{ __("File is too large. Maximum size is 5GB.") }}';
                    return;
                }

                this.file = file;
                if (!this.title) {
                    this.title = file.name.replace(/\.[^/.]+$/, '').replace(/[_-]/g, ' ');
                }
            },

            formatSize(bytes) {
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
                return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
            },

            async upload() {
                if (!this.file) return;

                this.uploading = true;
                this.progress = 0;
                this.errorMessage = '';
                this.cancelled = false;

                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                const totalChunks = Math.ceil(this.file.size / this.CHUNK_SIZE);

                try {
                    // Step 1: Start chunked upload session
                    const startResponse = await fetch('/api/video-recordings/start', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            mime_type: this.file.type,
                            title: this.title || null,
                        }),
                    });

                    if (!startResponse.ok) {
                        const err = await startResponse.json().catch(() => ({}));
                        throw new Error(err.message || 'Failed to start upload session.');
                    }

                    const { id: recordingId } = await startResponse.json();

                    // Step 2: Upload chunks sequentially
                    for (let i = 0; i < totalChunks; i++) {
                        if (this.cancelled) return;

                        const start = i * this.CHUNK_SIZE;
                        const end = Math.min(start + this.CHUNK_SIZE, this.file.size);
                        const chunk = this.file.slice(start, end);

                        let success = false;
                        for (let attempt = 0; attempt < this.MAX_RETRIES; attempt++) {
                            if (this.cancelled) return;

                            try {
                                this.abortController = new AbortController();
                                const formData = new FormData();
                                formData.append('chunk', chunk, `chunk_${i}`);
                                formData.append('chunk_index', i);

                                const chunkResponse = await fetch(`/api/video-recordings/${recordingId}/chunk`, {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-TOKEN': csrfToken,
                                        'Accept': 'application/json',
                                    },
                                    body: formData,
                                    signal: this.abortController.signal,
                                });

                                if (!chunkResponse.ok) {
                                    const err = await chunkResponse.json().catch(() => ({}));
                                    throw new Error(err.message || `Chunk ${i} upload failed.`);
                                }

                                success = true;
                                break;
                            } catch (e) {
                                if (e.name === 'AbortError') return;
                                if (attempt === this.MAX_RETRIES - 1) throw e;
                                // Wait before retry (exponential backoff)
                                await new Promise(r => setTimeout(r, 1000 * (attempt + 1)));
                            }
                        }

                        if (!success) throw new Error('Failed to upload chunk after retries.');

                        this.progress = Math.round(((i + 1) / totalChunks) * 100);
                    }

                    if (this.cancelled) return;

                    // Step 3: Finalize
                    const finalizeResponse = await fetch(`/api/video-recordings/${recordingId}/finalize`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                    });

                    if (!finalizeResponse.ok) {
                        const err = await finalizeResponse.json().catch(() => ({}));
                        throw new Error(err.message || 'Failed to finalize upload.');
                    }

                    this.uploading = false;
                    this.uploadComplete = true;

                } catch (e) {
                    if (this.cancelled) return;
                    this.uploading = false;
                    this.errorMessage = e.message || '{{ __("Upload failed. Please try again.") }}';
                }
            }
        }));
    </script>
    @endscript
</div>
