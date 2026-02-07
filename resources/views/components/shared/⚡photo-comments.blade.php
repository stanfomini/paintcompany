<?php

use App\Models\PhotoComment;
use App\Models\PropertyPhoto;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public PropertyPhoto $photo;

    public string $newComment = '';

    public ?int $replyingTo = null;

    #[Computed]
    public function comments(): Collection
    {
        return $this->photo->comments()
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->orderBy('created_at')
            ->get();
    }

    public function addComment(): void
    {
        $this->validate([
            'newComment' => ['required', 'string', 'max:1000'],
        ]);

        PhotoComment::create([
            'property_photo_id' => $this->photo->id,
            'user_id' => auth()->id(),
            'body' => $this->newComment,
            'parent_id' => $this->replyingTo,
        ]);

        $this->newComment = '';
        $this->replyingTo = null;
        unset($this->comments);
    }

    public function startReply(int $commentId): void
    {
        $this->replyingTo = $commentId;
    }

    public function cancelReply(): void
    {
        $this->replyingTo = null;
    }
}; ?>

<div class="w-96 bg-white dark:bg-zinc-900 border-l border-zinc-200 dark:border-zinc-700 flex flex-col h-full">
    {{-- Header --}}
    <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
        <flux:heading size="sm">Comments</flux:heading>
        <button wire:click="$parent.toggleComments" class="p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800 rounded transition-colors">
            <flux:icon name="x-mark" class="w-4 h-4 text-zinc-500" />
        </button>
    </div>

    {{-- Comment List --}}
    <div class="flex-1 overflow-y-auto px-4 py-3 space-y-4">
        @forelse ($this->comments as $comment)
            <div wire:key="comment-{{ $comment->id }}">
                {{-- Main Comment --}}
                <div class="flex gap-3">
                    {{-- Avatar --}}
                    <div class="shrink-0 w-8 h-8 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center text-xs font-medium text-zinc-600 dark:text-zinc-400">
                        {{ strtoupper(substr($comment->user?->name ?? '?', 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline gap-2">
                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $comment->user?->name ?? 'Unknown' }}
                            </span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $comment->created_at->diffForHumans() }}
                            </span>
                        </div>
                        <p class="text-sm text-zinc-700 dark:text-zinc-300 mt-0.5 break-words">
                            {{ $comment->body }}
                        </p>
                        <button
                            wire:click="startReply({{ $comment->id }})"
                            class="text-xs text-blue-600 dark:text-blue-400 hover:underline mt-1"
                        >
                            Reply
                        </button>
                    </div>
                </div>

                {{-- Replies --}}
                @if ($comment->replies->isNotEmpty())
                    <div class="pl-8 mt-3 space-y-3">
                        @foreach ($comment->replies as $reply)
                            <div wire:key="reply-{{ $reply->id }}" class="flex gap-3">
                                <div class="shrink-0 w-6 h-6 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                    {{ strtoupper(substr($reply->user?->name ?? '?', 0, 1)) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $reply->user?->name ?? 'Unknown' }}
                                        </span>
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $reply->created_at->diffForHumans() }}
                                        </span>
                                    </div>
                                    <p class="text-sm text-zinc-700 dark:text-zinc-300 mt-0.5 break-words">
                                        {{ $reply->body }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center py-8">
                <flux:icon name="chat-bubble-left" class="w-8 h-8 mx-auto text-zinc-400 mb-2" />
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">No comments yet</flux:text>
            </div>
        @endforelse
    </div>

    {{-- New Comment Input --}}
    <div class="border-t border-zinc-200 dark:border-zinc-700 px-4 py-3">
        @if ($replyingTo)
            @php
                $replyTarget = $this->comments->firstWhere('id', $replyingTo);
            @endphp
            <div class="flex items-center justify-between mb-2 px-2 py-1 bg-zinc-100 dark:bg-zinc-800 rounded text-xs">
                <span class="text-zinc-600 dark:text-zinc-400">
                    Replying to {{ $replyTarget?->user?->name ?? 'comment' }}
                </span>
                <button wire:click="cancelReply" class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    <flux:icon name="x-mark" class="w-3 h-3" />
                </button>
            </div>
        @endif

        <form wire:submit="addComment" class="flex gap-2">
            <flux:input
                wire:model="newComment"
                placeholder="Add a comment..."
                size="sm"
                class="flex-1"
            />
            <flux:button type="submit" variant="primary" size="sm" icon="paper-airplane" wire:loading.attr="disabled" />
        </form>
        @error('newComment')
            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
        @enderror
    </div>
</div>
