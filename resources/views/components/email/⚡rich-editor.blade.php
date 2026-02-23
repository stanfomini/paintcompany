<?php

use Livewire\Attributes\Modelable;
use Livewire\Component;

new class extends Component {
    #[Modelable]
    public string $content = '';

    public bool $minimal = false;

    public string $placeholder = 'Write something...';
};
?>

<div x-data="tiptapEditor({ content: $wire.entangle('content'), placeholder: '{{ $placeholder }}', minimal: {{ $minimal ? 'true' : 'false' }} })">
    @if (! $minimal)
        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center gap-1 rounded-t-lg border border-zinc-200 bg-zinc-50 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-800">
            <button type="button" @click="toggleBold()" :class="isActive('bold') ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700">
                <strong class="text-xs">B</strong>
            </button>
            <button type="button" @click="toggleItalic()" :class="isActive('italic') ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700">
                <em class="text-xs">I</em>
            </button>
            <button type="button" @click="toggleUnderline()" :class="isActive('underline') ? 'bg-zinc-200 dark:bg-zinc-700' : ''"
                class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700">
                <span class="text-xs underline">U</span>
            </button>
            <button type="button" @click="setLink()" class="rounded p-1 text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700">
                <flux:icon name="link" class="size-3.5" />
            </button>
        </div>
    @endif

    <div x-ref="editor"
        class="{{ $minimal ? 'rounded-lg border' : 'rounded-b-lg border border-t-0' }} border-zinc-200 dark:border-zinc-700"
        @tiptap-update.window="$wire.set('content', $event.detail.content)">
    </div>
</div>
