<?php

namespace App\Livewire\Customer\Chat;

use App\Models\ChatThread;
use App\Services\AudioMessageService;
use App\Services\ChatService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class MessageComposer extends Component
{
    use WithFileUploads;

    public ChatThread $thread;

    #[Validate('required|string|max:5000')]
    public string $message = '';

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    #[Validate(['attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt'])]
    public array $attachments = [];

    public bool $isRecording = false;

    public bool $isProcessingAudio = false;

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

        $user = auth('customer')->user();

        // Verify user is still a participant
        if (! $this->thread->hasParticipant($user)) {
            $this->addError('message', 'You are not authorized to send messages in this thread.');

            return;
        }

        $chatService = app(ChatService::class);
        $options = ['is_internal' => false];

        // Send with or without attachments
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

    /**
     * Process recorded audio and send as a message.
     */
    public function processRecordedAudio(string $audioBase64): void
    {
        $this->isProcessingAudio = true;

        try {
            $user = auth('customer')->user();

            // Verify user is still a participant
            if (! $this->thread->hasParticipant($user)) {
                $this->addError('audio', 'You are not authorized to send messages in this thread.');

                return;
            }

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
        $user = auth('customer')->user();

        $chatService->sendAudioMessage(
            $this->thread,
            $user,
            $audioData,
            ['is_internal' => false]
        );

        // Notify parent component
        $this->dispatch('message-sent');
    }

    public function render(): View
    {
        return view('livewire.customer.chat.message-composer');
    }
}
