<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<div
    x-data="{
        toasts: [],
        nextId: 0,
        add(toast) {
            const id = this.nextId++;
            const type = toast.type || toast.level || 'info';
            this.toasts.push({
                id,
                message: toast.message || toast.body || '',
                type,
                url: toast.url || null,
            });
            setTimeout(() => this.remove(id), toast.duration || 3000);
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
        colorClasses(type) {
            switch (type) {
                case 'success': return 'bg-green-50 dark:bg-green-900/80 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200';
                case 'error': return 'bg-red-50 dark:bg-red-900/80 border-red-200 dark:border-red-700 text-red-800 dark:text-red-200';
                case 'warning': return 'bg-yellow-50 dark:bg-yellow-900/80 border-yellow-200 dark:border-yellow-700 text-yellow-800 dark:text-yellow-200';
                default: return 'bg-blue-50 dark:bg-blue-900/80 border-blue-200 dark:border-blue-700 text-blue-800 dark:text-blue-200';
            }
        },
        iconColor(type) {
            switch (type) {
                case 'success': return 'text-green-500 dark:text-green-400';
                case 'error': return 'text-red-500 dark:text-red-400';
                case 'warning': return 'text-yellow-500 dark:text-yellow-400';
                default: return 'text-blue-500 dark:text-blue-400';
            }
        },
    }"
    x-on:toast.window="add($event.detail)"
    x-on:notify.window="add($event.detail)"
    class="fixed top-4 right-4 z-[60] space-y-2 max-w-sm w-full pointer-events-none"
    data-toast-container
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-4"
            :class="colorClasses(toast.type)"
            class="rounded-lg border shadow-lg p-4 pointer-events-auto"
        >
            <div class="flex items-start gap-3">
                {{-- Icon --}}
                <div class="shrink-0 mt-0.5" :class="iconColor(toast.type)">
                    <template x-if="toast.type === 'success'">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                    </template>
                    <template x-if="toast.type === 'error'">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                    </template>
                    <template x-if="toast.type === 'warning'">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                        </svg>
                    </template>
                    <template x-if="toast.type === 'info'">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" />
                        </svg>
                    </template>
                </div>

                {{-- Message --}}
                <div class="flex-1 min-w-0">
                    <template x-if="toast.url">
                        <a :href="toast.url" class="text-sm font-medium underline hover:no-underline" x-text="toast.message"></a>
                    </template>
                    <template x-if="!toast.url">
                        <p class="text-sm font-medium" x-text="toast.message"></p>
                    </template>
                </div>

                {{-- Close button --}}
                <button
                    x-on:click="remove(toast.id)"
                    class="shrink-0 opacity-60 hover:opacity-100 transition-opacity"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                    </svg>
                </button>
            </div>
        </div>
    </template>
</div>
