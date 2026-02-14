@props(['user', 'avatar' => null])

<div x-data="avatarCropper" class="space-y-3">
    {{-- Current Avatar Display --}}
    <div class="flex items-center gap-4">
        @if ($avatar && $avatar->isPreviewable())
            <flux:avatar size="lg" :src="$avatar->temporaryUrl()" />
        @elseif ($user->hasAvatar())
            <flux:avatar size="lg" :src="$user->getAvatarUrl()" />
        @else
            <flux:avatar size="lg" :name="$user->name" :initials="$user->initials()" />
        @endif

        <div class="flex flex-col gap-2">
            <label class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-700 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-600 cursor-pointer transition-colors">
                {{ __('Change Avatar') }}
                <input type="file" @change="selectFile($event)" accept="image/jpg,image/jpeg,image/png,image/webp" class="sr-only" />
            </label>

            @if ($user->hasAvatar())
                <flux:button wire:click="removeAvatar" variant="subtle" size="sm">
                    {{ __('Remove') }}
                </flux:button>
            @endif
        </div>

        @error('avatar')
            <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text>
        @enderror
    </div>

    {{-- Crop Modal --}}
    <template x-teleport="body">
        <div
            x-show="showModal"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @keydown.escape.window="close()"
        >
            <div
                class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl max-w-lg w-full mx-4 overflow-hidden"
                @click.outside="close()"
            >
                <div class="px-6 py-4 border-b border-zinc-200 dark:border-zinc-700">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ __('Crop Avatar') }}</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Drag to reposition, scroll to zoom, then save.') }}</p>
                </div>

                <div class="p-6">
                    <div class="max-h-96 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-900">
                        <img x-ref="cropImage" :src="imageSrc" class="block max-w-full" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button variant="subtle" @click="close()">
                        {{ __('Cancel') }}
                    </flux:button>
                    <button
                        type="button"
                        @click="cropAndUpload()"
                        x-bind:disabled="uploading"
                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 hover:bg-zinc-700 dark:hover:bg-zinc-200 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        <span x-show="!uploading">{{ __('Save Avatar') }}</span>
                        <span x-show="uploading" x-cloak>{{ __('Uploading...') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
