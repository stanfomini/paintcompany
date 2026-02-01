<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="lg">Photos</flux:heading>
        <flux:button wire:click="openUploadModal" icon="arrow-up-tray" size="sm">
            Upload Photos
        </flux:button>
    </div>

    {{-- Category Filter Tabs --}}
    <div class="flex gap-2 mb-4 overflow-x-auto">
        <flux:button
            wire:click="setFilterCategory('all')"
            variant="{{ $filterCategory === 'all' ? 'filled' : 'ghost' }}"
            size="sm"
        >
            All ({{ $photos->count() }})
        </flux:button>
        @foreach ($categories as $key => $label)
            @php
                $count = $photos->where('category', $key)->count();
            @endphp
            <flux:button
                wire:click="setFilterCategory('{{ $key }}')"
                variant="{{ $filterCategory === $key ? 'filled' : 'ghost' }}"
                size="sm"
            >
                {{ $label }} ({{ $count }})
            </flux:button>
        @endforeach
    </div>

    {{-- Photo Grid --}}
    @if ($filteredPhotos->isEmpty())
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
            <flux:icon name="photo" class="w-12 h-12 mx-auto text-zinc-400 mb-3" />
            <flux:heading size="sm" class="mb-1">No photos yet</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400 mb-4">
                @if ($filterCategory === 'all')
                    Upload photos to document this estimate.
                @else
                    No {{ strtolower($categories[$filterCategory] ?? $filterCategory) }} photos found.
                @endif
            </flux:text>
            @if ($filterCategory === 'all')
                <flux:button wire:click="openUploadModal" icon="arrow-up-tray" size="sm">
                    Upload Photos
                </flux:button>
            @endif
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach ($filteredPhotos as $photo)
                <div wire:key="photo-{{ $photo->id }}" class="group relative border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                    {{-- Photo Thumbnail --}}
                    <div class="aspect-square bg-zinc-100 dark:bg-zinc-800">
                        <img
                            src="{{ $photo->url }}"
                            alt="{{ $photo->caption ?? $photo->filename }}"
                            class="w-full h-full object-cover"
                        />
                    </div>

                    {{-- Overlay with info --}}
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors">
                        {{-- Action Buttons (visible on hover) --}}
                        <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <flux:button
                                wire:click="openEditModal({{ $photo->id }})"
                                variant="filled"
                                size="xs"
                                icon="pencil"
                            />
                            <flux:button
                                wire:click="deletePhoto({{ $photo->id }})"
                                wire:confirm="Are you sure you want to delete this photo?"
                                variant="danger"
                                size="xs"
                                icon="trash"
                            />
                        </div>

                        {{-- Show on Proposal indicator --}}
                        @if ($photo->show_on_proposal)
                            <div class="absolute top-2 left-2">
                                <flux:tooltip content="Visible on proposal">
                                    <flux:badge color="green" size="sm">
                                        <flux:icon name="eye" class="w-3 h-3" />
                                    </flux:badge>
                                </flux:tooltip>
                            </div>
                        @endif
                    </div>

                    {{-- Photo Info --}}
                    <div class="p-2">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                @if ($photo->caption)
                                    <flux:text class="text-sm font-medium truncate">{{ $photo->caption }}</flux:text>
                                @else
                                    <flux:text class="text-sm text-zinc-400 truncate">{{ $photo->filename }}</flux:text>
                                @endif
                                @if ($photo->area)
                                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400 truncate">
                                        {{ $photo->area->name }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:badge size="sm" color="{{ match($photo->category) {
                                'before' => 'blue',
                                'during' => 'yellow',
                                'reference' => 'purple',
                                default => 'zinc',
                            } }}">
                                {{ $categories[$photo->category] ?? $photo->category }}
                            </flux:badge>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Upload Modal --}}
    <flux:modal wire:model="showUploadModal" class="max-w-2xl">
        <div class="space-y-6">
            <flux:heading size="lg">Upload Photos</flux:heading>

            {{-- Drag and Drop Area --}}
            <div
                x-data="{
                    isDragging: false,
                    handleDrop(e) {
                        this.isDragging = false;
                        const files = Array.from(e.dataTransfer.files);
                        @this.uploadMultiple('uploadedPhotos', files);
                    }
                }"
                x-on:dragover.prevent="isDragging = true"
                x-on:dragleave.prevent="isDragging = false"
                x-on:drop.prevent="handleDrop($event)"
                class="relative border-2 border-dashed rounded-lg p-8 text-center transition-colors"
                :class="isDragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-950' : 'border-zinc-300 dark:border-zinc-600'"
            >
                <flux:icon name="cloud-arrow-up" class="w-12 h-12 mx-auto text-zinc-400 mb-3" />
                <flux:text class="mb-2">
                    Drag and drop images here, or
                    <label class="text-blue-600 dark:text-blue-400 hover:underline cursor-pointer">
                        browse
                        <input
                            type="file"
                            wire:model="uploadedPhotos"
                            accept="image/*"
                            multiple
                            class="hidden"
                        />
                    </label>
                </flux:text>
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                    JPG, PNG, GIF, WebP up to 5MB each
                </flux:text>

                {{-- Loading indicator --}}
                <div wire:loading wire:target="uploadedPhotos" class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-zinc-900/80 rounded-lg">
                    <div class="flex items-center gap-2">
                        <flux:icon name="arrow-path" class="w-5 h-5 animate-spin" />
                        <flux:text>Uploading...</flux:text>
                    </div>
                </div>
            </div>

            @error('uploadedPhotos')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror
            @error('uploadedPhotos.*')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror

            {{-- Preview uploaded photos --}}
            @if (count($uploadedPhotos) > 0)
                <div>
                    <flux:text class="font-medium mb-2">Selected Photos ({{ count($uploadedPhotos) }})</flux:text>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach ($uploadedPhotos as $index => $photo)
                            <div wire:key="preview-{{ $index }}" class="relative aspect-square rounded-lg overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                                @if ($photo && method_exists($photo, 'temporaryUrl'))
                                    <img
                                        src="{{ $photo->temporaryUrl() }}"
                                        alt="Preview"
                                        class="w-full h-full object-cover"
                                    />
                                @endif
                                <button
                                    type="button"
                                    wire:click="removeUploadedPhoto({{ $index }})"
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
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Default Category</flux:label>
                    <flux:select wire:model="defaultCategory">
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model="defaultShowOnProposal" label="Show on Proposal" />
                </flux:field>
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeUploadModal" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button
                    wire:click="uploadPhotos"
                    variant="primary"
                    wire:loading.attr="disabled"
                    :disabled="count($uploadedPhotos) === 0"
                >
                    <span wire:loading.remove wire:target="uploadPhotos">
                        Upload {{ count($uploadedPhotos) }} Photo{{ count($uploadedPhotos) !== 1 ? 's' : '' }}
                    </span>
                    <span wire:loading wire:target="uploadPhotos">Uploading...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit Modal --}}
    <flux:modal wire:model="showEditModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">Edit Photo</flux:heading>

            {{-- Image Preview --}}
            @if ($editingPhotoId)
                @php
                    $editingPhoto = $photos->firstWhere('id', $editingPhotoId);
                @endphp
                @if ($editingPhoto)
                    <div class="aspect-video bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden">
                        <img
                            src="{{ $editingPhoto->url }}"
                            alt="{{ $editingPhoto->caption ?? $editingPhoto->filename }}"
                            class="w-full h-full object-contain"
                        />
                    </div>
                @endif
            @endif

            {{-- Form Fields --}}
            <div class="space-y-4">
                <flux:field>
                    <flux:label>Caption</flux:label>
                    <flux:input wire:model="photoCaption" placeholder="Enter a caption for this photo..." />
                    <flux:error name="photoCaption" />
                </flux:field>

                <flux:field>
                    <flux:label>Category</flux:label>
                    <flux:select wire:model="photoCategory">
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="photoCategory" />
                </flux:field>

                <flux:field>
                    <flux:label>Associated Area</flux:label>
                    <flux:select wire:model="photoAreaId">
                        <option value="">None</option>
                        @foreach ($areas as $area)
                            <option value="{{ $area->id }}">{{ $area->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="photoAreaId" />
                </flux:field>

                <flux:field>
                    <flux:checkbox wire:model="photoShowOnProposal" label="Show on Proposal" />
                </flux:field>
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeEditModal" variant="ghost">
                    Cancel
                </flux:button>
                <flux:button wire:click="savePhoto" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="savePhoto">Save Changes</span>
                    <span wire:loading wire:target="savePhoto">Saving...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
