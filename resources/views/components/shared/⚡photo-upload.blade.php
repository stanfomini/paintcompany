<?php

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Services\PhotoService;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Property $property;

    public ?int $estimateId = null;

    public ?int $jobId = null;

    /** @var array<string> */
    public array $allowedCategories = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $photos = [];

    public string $category = 'general';

    public string $caption = '';

    public bool $showOnProposal = false;

    public bool $showModal = false;

    public function mount(
        Property $property,
        ?int $estimateId = null,
        ?int $jobId = null,
        array $allowedCategories = [],
    ): void {
        $this->property = $property;
        $this->estimateId = $estimateId;
        $this->jobId = $jobId;
        $this->allowedCategories = $allowedCategories ?: array_keys(PropertyPhoto::CATEGORIES);
        $this->category = $this->allowedCategories[0] ?? 'general';
    }

    protected function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['file', 'max:102400', 'mimes:jpg,jpeg,png,webp,pdf'],
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(PropertyPhoto::CATEGORIES))],
            'caption' => ['nullable', 'string', 'max:255'],
            'showOnProposal' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'photos.required' => __('Please select at least one file to upload.'),
            'photos.*.file' => __('Each file must be a valid file.'),
            'photos.*.max' => __('Each file must be less than 100MB.'),
            'photos.*.mimes' => __('Only JPG, PNG, WebP, and PDF files are allowed.'),
        ];
    }

    #[On('open-upload-modal')]
    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['photos', 'caption', 'showOnProposal']);
        $this->category = $this->allowedCategories[0] ?? 'general';
        $this->resetErrorBag();
    }

    public function removePhoto(int $index): void
    {
        if (isset($this->photos[$index])) {
            unset($this->photos[$index]);
            $this->photos = array_values($this->photos);
        }
    }

    public function uploadPhotos(): void
    {
        $this->validate();

        $user = auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();
        $photoService = app(PhotoService::class);

        $meta = [
            'category' => $this->category,
            'caption' => $this->caption ?: null,
            'show_on_proposal' => $this->showOnProposal,
            'estimate_id' => $this->estimateId,
            'job_id' => $this->jobId,
        ];

        foreach ($this->photos as $photo) {
            $photoService->upload($photo, $this->property, $user, $meta);
        }

        $this->dispatch('photos-uploaded');
        $this->closeModal();
    }
}; ?>

<div>
    <flux:modal wire:model="showModal" class="max-w-2xl" position="right">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Upload Photos') }}</flux:heading>

            {{-- Drag and Drop Zone --}}
            <div
                x-data="{
                    isDragging: false,
                    handleDrop(e) {
                        this.isDragging = false;
                        const files = Array.from(e.dataTransfer.files);
                        @this.uploadMultiple('photos', files);
                    }
                }"
                x-on:dragover.prevent="isDragging = true"
                x-on:dragleave.prevent="isDragging = false"
                x-on:drop.prevent="handleDrop($event)"
                class="relative border-2 border-dashed rounded-lg p-8 text-center transition-colors"
                :class="isDragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-950' : 'border-zinc-300 dark:border-zinc-600'"
            >
                <flux:icon name="cloud-arrow-up" class="w-12 h-12 mx-auto text-zinc-400 mb-3" />
                <flux:text class="mb-3">
                    {{ __('Drop photos here') }}
                </flux:text>

                <div class="flex items-center justify-center gap-3">
                    <label class="inline-flex items-center gap-2 px-4 py-2 bg-zinc-100 dark:bg-zinc-700 rounded-lg text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600 cursor-pointer transition-colors">
                        <flux:icon name="camera" class="w-4 h-4" />
                        {{ __('Take Photo') }}
                        <input
                            type="file"
                            wire:model="photos"
                            accept="image/*,.pdf,application/pdf"
                            capture="environment"
                            class="hidden"
                        />
                    </label>
                    <label class="inline-flex items-center gap-2 px-4 py-2 bg-zinc-100 dark:bg-zinc-700 rounded-lg text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600 cursor-pointer transition-colors">
                        <flux:icon name="folder-open" class="w-4 h-4" />
                        {{ __('Choose Files') }}
                        <input
                            type="file"
                            wire:model="photos"
                            accept="image/jpeg,image/png,image/webp,application/pdf,.pdf"
                            multiple
                            class="hidden"
                        />
                    </label>
                </div>

                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mt-3">
                    {{ __('JPG, PNG, WebP, PDF up to 20MB each') }}
                </flux:text>

                {{-- Loading Indicator --}}
                <div wire:loading wire:target="photos" class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-zinc-900/80 rounded-lg">
                    <div class="flex items-center gap-2">
                        <flux:icon name="arrow-path" class="w-5 h-5 animate-spin" />
                        <flux:text>{{ __('Processing...') }}</flux:text>
                    </div>
                </div>
            </div>

            @error('photos')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror
            @error('photos.*')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror

            {{-- Preview Grid --}}
            @if (count($photos) > 0)
                <div>
                    <flux:text class="font-medium mb-2">{{ __('Selected') }} ({{ count($photos) }})</flux:text>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($photos as $index => $photo)
                            <div wire:key="preview-{{ $index }}" class="relative aspect-square rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                                @if ($photo && method_exists($photo, 'temporaryUrl'))
                                    @if (str_ends_with(strtolower($photo->getClientOriginalName()), '.pdf'))
                                        <div class="w-full h-full flex flex-col items-center justify-center text-zinc-500">
                                            <flux:icon name="document" class="w-10 h-10 mb-1" />
                                            <span class="text-xs truncate max-w-full px-2">{{ $photo->getClientOriginalName() }}</span>
                                        </div>
                                    @else
                                        <img
                                            src="{{ $photo->temporaryUrl() }}"
                                            alt="{{ __('Preview') }}"
                                            class="w-full h-full object-cover"
                                        />
                                    @endif
                                @endif
                                <button
                                    type="button"
                                    wire:click="removePhoto({{ $index }})"
                                    class="absolute top-1 right-1 p-1 bg-red-500 text-white rounded-full hover:bg-red-600"
                                >
                                    <flux:icon name="x-mark" class="w-3 h-3" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Upload Options --}}
            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <flux:select wire:model="category">
                        @foreach ($allowedCategories as $cat)
                            <option value="{{ $cat }}">{{ \App\Models\PropertyPhoto::CATEGORIES[$cat] ?? ucfirst($cat) }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Caption') }}</flux:label>
                    <flux:input wire:model="caption" :placeholder="__('Optional caption for these photos...')" />
                </flux:field>

                @if ($estimateId)
                    <flux:field>
                        <flux:checkbox wire:model="showOnProposal" :label="__('Show on Proposal')" />
                    </flux:field>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeModal" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    wire:click="uploadPhotos"
                    variant="primary"
                    wire:loading.attr="disabled"
                    :disabled="count($photos) === 0"
                >
                    <span wire:loading.remove wire:target="uploadPhotos">
                        {{ trans_choice('{1} Upload :count Photo|[2,*] Upload :count Photos', count($photos), ['count' => count($photos)]) }}
                    </span>
                    <span wire:loading wire:target="uploadPhotos">{{ __('Uploading...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
