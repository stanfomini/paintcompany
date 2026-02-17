<?php

use App\Mail\VideoSharedMail;
use App\Models\VideoEmail;
use App\Models\VideoRecording;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $recordingId;

    public string $recipientEmail = '';

    public string $recipientName = '';

    public string $subject = '';

    public string $message = '';

    public bool $sent = false;

    public function mount(int $recordingId): void
    {
        $this->recordingId = $recordingId;
        $recording = VideoRecording::findOrFail($recordingId);
        $this->subject = __('Check out this video: :title', ['title' => $recording->title]);
    }

    #[Computed]
    public function sentEmails()
    {
        return VideoEmail::where('video_recording_id', $this->recordingId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    public function sendEmail(): void
    {
        $this->validate([
            'recipientEmail' => ['required', 'email', 'max:255'],
            'recipientName' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $recording = VideoRecording::findOrFail($this->recordingId);

        $videoEmail = VideoEmail::create([
            'video_recording_id' => $this->recordingId,
            'sent_by' => auth()->id(),
            'recipient_email' => $this->recipientEmail,
            'recipient_name' => $this->recipientName ?: null,
            'subject' => $this->subject,
            'message' => $this->message ?: null,
        ]);

        Mail::to($this->recipientEmail)->send(
            new VideoSharedMail($recording, $videoEmail, $this->message)
        );

        $this->reset('recipientEmail', 'recipientName', 'message');
        $this->subject = __('Check out this video: :title', ['title' => $recording->title]);
        $this->sent = true;
        unset($this->sentEmails);
    }
};
?>

<div>
    {{-- Send Form --}}
    <form wire:submit="sendEmail" class="max-w-xl mb-8">
        <div class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Recipient Email') }}</flux:label>
                    <flux:input type="email" wire:model="recipientEmail" placeholder="name@example.com" />
                    <flux:error name="recipientEmail" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Recipient Name') }}</flux:label>
                    <flux:input wire:model="recipientName" placeholder="{{ __('Optional') }}" />
                    <flux:error name="recipientName" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Subject') }}</flux:label>
                <flux:input wire:model="subject" />
                <flux:error name="subject" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Message') }}</flux:label>
                <flux:textarea wire:model="message" rows="3" placeholder="{{ __('Add a personal message...') }}" />
                <flux:error name="message" />
            </flux:field>

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary" icon="paper-airplane">
                    {{ __('Send Email') }}
                </flux:button>
                @if($sent)
                    <flux:text class="text-green-600 text-sm">{{ __('Email sent!') }}</flux:text>
                @endif
            </div>
        </div>
    </form>

    {{-- Sent History --}}
    <flux:heading size="lg" class="mb-3">{{ __('Sent Emails') }}</flux:heading>

    @if($this->sentEmails->isEmpty())
        <flux:text class="text-zinc-500">{{ __('No emails sent yet.') }}</flux:text>
    @else
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Recipient') }}</th>
                        <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Subject') }}</th>
                        <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</th>
                        <th class="text-left px-4 py-2 font-medium text-zinc-600 dark:text-zinc-400">{{ __('Sent') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($this->sentEmails as $email)
                        <tr wire:key="email-{{ $email->id }}">
                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $email->recipient_email }}</td>
                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300 truncate max-w-xs">{{ $email->subject }}</td>
                            <td class="px-4 py-2">
                                @if($email->clicked_at)
                                    <flux:badge size="sm" color="green">{{ __('Clicked') }}</flux:badge>
                                @elseif($email->opened_at)
                                    <flux:badge size="sm" color="blue">{{ __('Opened') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Sent') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-zinc-500">{{ $email->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
