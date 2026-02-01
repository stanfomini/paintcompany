<?php

namespace App\Livewire\Agent\Chat;

use App\Models\ChatThread;
use App\Services\AudioMessageService;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class MessageComposer extends Component
{
    use WithFileUploads;

    public ChatThread $thread;

    #[Validate('required|string|max:10000')]
    public string $content = '';

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    #[Validate(['attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt'])]
    public array $attachments = [];

    public ?int $replyToMessageId = null;

    public bool $isRecording = false;

    public bool $isProcessingAudio = false;

    public function mount(ChatThread $thread): void
    {
        $this->thread = $thread;
    }

    #[On('set-reply-to')]
    public function setReplyTo(int $messageId): void
    {
        $this->replyToMessageId = $messageId;
    }

    #[On('cancel-reply')]
    public function cancelReply(): void
    {
        $this->replyToMessageId = null;
    }

    public function clearReply(): void
    {
        $this->replyToMessageId = null;
        $this->dispatch('cancel-reply');
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function send(): void
    {
        $this->validate();

        $chatService = app(ChatService::class);
        $user = auth('agent')->user();

        // Agents cannot send internal messages
        $options = [
            'is_internal' => false,
            'parent_id' => $this->replyToMessageId,
        ];

        // Send with or without attachments
        if (! empty($this->attachments)) {
            $uploadedFiles = [];
            foreach ($this->attachments as $attachment) {
                $uploadedFiles[] = $attachment;
            }

            $chatService->sendMessageWithAttachments(
                $this->thread,
                $user,
                $this->content,
                $uploadedFiles,
                $options
            );
        } else {
            $chatService->sendMessage(
                $this->thread,
                $user,
                $this->content,
                $options
            );
        }

        // Reset form
        $this->reset(['content', 'attachments', 'replyToMessageId']);
        $this->resetValidation();

        // Notify parent component
        $this->dispatch('message-sent');
    }

    /**
     * Process recorded audio and send as a message.
     */
    public function processRecordedAudio(string $audioBase64): void
    {
        $this->isProcessingAudio = true;

        try {
            $audioService = app(AudioMessageService::class);
            $audioData = $audioService->processBase64Audio($audioBase64, 'audio/webm');

            $this->sendAudioMessage($audioData);
        } catch (\Exception $e) {
            $this->addError('audio', 'Failed to process audio recording: '.$e->getMessage());
        } finally {
            $this->isProcessingAudio = false;
            $this->isRecording = false;
        }
    }

    /**
     * Send an audio message.
     *
     * @param  array{audio_url: string, audio_path: string, audio_duration: float|null, transcription: string|null, transcription_status: string}  $audioData
     */
    private function sendAudioMessage(array $audioData): void
    {
        $chatService = app(ChatService::class);
        $user = auth('agent')->user();

        $chatService->sendAudioMessage(
            $this->thread,
            $user,
            $audioData,
            [
                'is_internal' => false,
                'parent_id' => $this->replyToMessageId,
            ]
        );

        // Reset form
        $this->reset(['replyToMessageId']);

        // Notify parent component
        $this->dispatch('message-sent');
    }

    public function render(): View
    {
        $replyingTo = null;
        if ($this->replyToMessageId) {
            $replyingTo = $this->thread->messages()
                ->with('user')
                ->find($this->replyToMessageId);
        }

        return view('livewire.agent.chat.message-composer', [
            'replyingTo' => $replyingTo,
        ]);
    }
}
