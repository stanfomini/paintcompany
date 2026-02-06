<?php

use App\Models\ChatThread;
use App\Services\ChatService;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public ChatThread $thread;

    #[Validate('required|string|max:5000')]
    public string $message = '';

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    #[Validate(['attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt'])]
    public array $attachments = [];

    public function mount(ChatThread $thread): void
    {
        $this->thread = $thread;
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function send(): void
    {
        $this->validate();

        $user = auth('crew')->user();

        if (! $this->thread->hasParticipant($user)) {
            $this->addError('message', 'You are not authorized to send messages in this thread.');

            return;
        }

        $chatService = app(ChatService::class);
        $options = ['is_internal' => false];

        if (! empty($this->attachments)) {
            $uploadedFiles = [];
            foreach ($this->attachments as $attachment) {
                $uploadedFiles[] = $attachment;
            }

            $chatService->sendMessageWithAttachments(
                $this->thread,
                $user,
                $this->message,
                $uploadedFiles,
                $options
            );
        } else {
            $chatService->sendMessage(
                $this->thread,
                $user,
                $this->message,
                $options
            );
        }

        $this->reset(['message', 'attachments']);
        $this->resetValidation();
        $this->dispatch('message-sent');
    }
}; ?>

<div>
    {{-- Attachment previews --}}
    @if (!empty($attachments))
        <div class="mb-3 flex flex-wrap gap-2">
            @foreach ($attachments as $index => $attachment)
                <div
                    wire:key="attachment-{{ $index }}"
                    class="relative group bg-zinc-100 dark:bg-zinc-800 rounded-lg p-2"
                >
                    @if (str_starts_with($attachment->getMimeType(), 'image/'))
                        <img
                            src="{{ $attachment->temporaryUrl() }}"
                            class="h-16 w-16 object-cover rounded"
                            alt="Attachment preview"
                        />
                    @else
                        <div class="flex items-center gap-2 px-2">
                            <flux:icon name="document" class="h-6 w-6 text-zinc-500" />
                            <span class="text-sm text-zinc-600 dark:text-zinc-400 max-w-[100px] truncate">
                                {{ $attachment->getClientOriginalName() }}
                            </span>
                        </div>
                    @endif
                    <button
                        type="button"
                        wire:click="removeAttachment({{ $index }})"
                        class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity"
                    >
                        <flux:icon name="x-mark" class="h-3 w-3" />
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    <form wire:submit="send" class="space-y-3">
        <div class="flex gap-2 items-end">
            {{-- Attachment button --}}
            <div class="shrink-0">
                <label class="cursor-pointer">
                    <input
                        type="file"
                        wire:model="attachments"
                        multiple
                        accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
                        class="hidden"
                    />
                    <flux:button
                        type="button"
                        variant="ghost"
                        icon="paper-clip"
                        as="span"
                        wire:loading.attr="disabled"
                        wire:target="attachments"
                    />
                </label>
            </div>

            {{-- Message input --}}
            <div class="flex-1 min-w-0">
                <flux:textarea
                    wire:model="message"
                    rows="2"
                    placeholder="{{ __('Type your message...') }}"
                    wire:keydown.enter.prevent="send"
                    wire:keydown.shift.enter.stop
                    class="resize-none"
                />
                @error('message')
                    <flux:text class="text-sm text-red-500 mt-1">{{ $message }}</flux:text>
                @enderror
            </div>

            {{-- Send button --}}
            <div class="shrink-0 self-end pb-0.5">
                <flux:button
                    type="submit"
                    variant="primary"
                    icon="paper-airplane"
                    wire:loading.attr="disabled"
                    wire:target="send"
                >
                    <span wire:loading.remove wire:target="send">{{ __('Send') }}</span>
                    <span wire:loading wire:target="send">...</span>
                </flux:button>
            </div>
        </div>

        {{-- File upload progress --}}
        <div wire:loading wire:target="attachments" class="text-sm text-zinc-500">
            {{ __('Uploading files...') }}
        </div>

        {{-- Attachment errors --}}
        @error('attachments.*')
            <flux:callout variant="danger" class="text-sm">
                {{ $message }}
            </flux:callout>
        @enderror
    </form>
</div>
