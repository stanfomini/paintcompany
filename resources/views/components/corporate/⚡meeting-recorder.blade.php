<?php

use App\Jobs\ProcessMeetingAudioJob;
use App\Models\Corporation;
use App\Models\MeetingMinutes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public int $corporationId;

    // Form fields
    public string $title = '';
    public string $meeting_date = '';
    public string $meeting_type = 'annual';
    public array $attendees = [];
    public string $newAttendee = '';
    public string $notes = '';

    // Audio file uploaded via Livewire temp upload
    public $audioFile = null;

    // Save state
    public bool $isSaving = false;

    public function boot(): void
    {
        if (! Auth::user()?->can('business_plan.manage')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $this->meeting_date = now()->format('Y-m-d');
    }

    #[Computed]
    public function corporation(): Corporation
    {
        return Corporation::findOrFail($this->corporationId);
    }

    public function addAttendee(): void
    {
        $name = trim($this->newAttendee);
        if ($name && ! in_array($name, $this->attendees)) {
            $this->attendees[] = $name;
        }
        $this->newAttendee = '';
    }

    public function removeAttendee(int $index): void
    {
        unset($this->attendees[$index]);
        $this->attendees = array_values($this->attendees);
    }

    public function saveWithAudio(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'meeting_date' => ['required', 'date'],
            'meeting_type' => ['required', 'in:annual,special,board,emergency'],
            'audioFile' => ['required', 'file', 'max:51200'],
        ]);

        $this->isSaving = true;

        // Store the uploaded audio to a temp local location so the job can access it
        $extension = $this->audioFile->getClientOriginalExtension() ?: 'webm';
        $tempPath = 'meeting-audio-pending/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($tempPath, file_get_contents($this->audioFile->getRealPath()));

        // Create the meeting record immediately
        $minutes = MeetingMinutes::create([
            'corporation_id' => $this->corporationId,
            'recorded_by' => Auth::id(),
            'title' => $this->title,
            'meeting_date' => $this->meeting_date,
            'meeting_type' => $this->meeting_type,
            'attendees' => $this->attendees ?: null,
            'notes' => $this->notes ?: null,
            'transcript_status' => 'processing',
            'summary_status' => 'pending',
        ]);

        // Dispatch the audio processing job
        ProcessMeetingAudioJob::dispatch(
            $minutes,
            $tempPath,
            Auth::user()->name ?? 'Unknown',
        );

        $this->audioFile = null;
        $this->isSaving = false;
        $this->dispatch('recorder-saved');
    }

    public function saveWithoutAudio(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'meeting_date' => ['required', 'date'],
            'meeting_type' => ['required', 'in:annual,special,board,emergency'],
        ]);

        MeetingMinutes::create([
            'corporation_id' => $this->corporationId,
            'recorded_by' => Auth::id(),
            'title' => $this->title,
            'meeting_date' => $this->meeting_date,
            'meeting_type' => $this->meeting_type,
            'attendees' => $this->attendees ?: null,
            'notes' => $this->notes ?: null,
            'transcript_status' => 'none',
            'summary_status' => 'pending',
        ]);

        $this->dispatch('recorder-saved');
    }
};
?>

<div
    x-data="{
        isRecording: false,
        audioBlob: null,
        audioUrl: null,
        mediaRecorder: null,
        chunks: [],
        stream: null,
        duration: 0,
        timer: null,
        mimeType: 'audio/webm',

        async startRecording() {
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const mimeTypes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
                this.mimeType = mimeTypes.find(t => MediaRecorder.isTypeSupported(t)) || 'audio/webm';
                this.mediaRecorder = new MediaRecorder(this.stream, { mimeType: this.mimeType });
                this.chunks = [];
                this.duration = 0;

                this.mediaRecorder.ondataavailable = (e) => {
                    if (e.data.size > 0) this.chunks.push(e.data);
                };

                this.mediaRecorder.onstop = () => {
                    this.audioBlob = new Blob(this.chunks, { type: this.mimeType });
                    this.audioUrl = URL.createObjectURL(this.audioBlob);
                    this.stream.getTracks().forEach(t => t.stop());
                    clearInterval(this.timer);
                };

                this.mediaRecorder.start(1000);
                this.isRecording = true;
                this.timer = setInterval(() => this.duration++, 1000);
            } catch (err) {
                console.error('Microphone access denied:', err);
            }
        },

        stopRecording() {
            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                this.mediaRecorder.stop();
                this.isRecording = false;
            }
        },

        resetRecording() {
            this.audioBlob = null;
            this.audioUrl = null;
            this.chunks = [];
            this.duration = 0;
        },

        async confirmRecording() {
            if (!this.audioBlob) return;
            const ext = this.mimeType.includes('mp4') ? 'mp4' : this.mimeType.includes('ogg') ? 'ogg' : 'webm';
            const file = new File([this.audioBlob], 'recording.' + ext, { type: this.mimeType });
            @this.upload('audioFile', file, () => {
                $wire.saveWithAudio();
            }, () => {
                console.error('Audio upload failed');
            });
        },

        formatTime(seconds) {
            const m = Math.floor(seconds / 60).toString().padStart(2, '0');
            const s = (seconds % 60).toString().padStart(2, '0');
            return m + ':' + s;
        }
    }"
    x-on:recorder-saved.window="audioBlob = null; audioUrl = null; uploading = false;"
>
    <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg" class="mb-4">{{ __('Record Meeting') }}</flux:heading>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <flux:field>
                    <flux:label>{{ __('Meeting Title') }}</flux:label>
                    <flux:input wire:model="title" placeholder="Annual Members Meeting 2026" />
                    <flux:error name="title" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Meeting Date') }}</flux:label>
                <flux:input wire:model="meeting_date" type="date" />
                <flux:error name="meeting_date" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Meeting Type') }}</flux:label>
                <flux:select wire:model="meeting_type">
                    @foreach (MeetingMinutes::MEETING_TYPES as $key => $label)
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Attendees --}}
            <div class="sm:col-span-2">
                <flux:label class="mb-1">{{ __('Attendees') }}</flux:label>
                <div class="flex gap-2">
                    <flux:input wire:model="newAttendee" wire:keydown.enter.prevent="addAttendee" placeholder="Add attendee name" size="sm" class="flex-1" />
                    <flux:button size="sm" variant="ghost" wire:click="addAttendee" icon="plus">{{ __('Add') }}</flux:button>
                </div>
                @if (count($attendees) > 0)
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach ($attendees as $i => $attendee)
                            <flux:badge size="sm" variant="outline" class="gap-1">
                                {{ $attendee }}
                                <button wire:click="removeAttendee({{ $i }})" class="ml-1 text-zinc-400 hover:text-zinc-600">
                                    <flux:icon name="x-mark" variant="mini" class="size-3" />
                                </button>
                            </flux:badge>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Audio Recorder --}}
            <div class="sm:col-span-2">
                <flux:label class="mb-2">{{ __('Audio Recording') }}</flux:label>
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <template x-if="!audioUrl">
                        <div class="flex items-center gap-4">
                            <template x-if="!isRecording">
                                <button @click="startRecording()" class="flex size-12 items-center justify-center rounded-full bg-red-500 text-white transition hover:bg-red-600">
                                    <flux:icon name="microphone" class="size-6" />
                                </button>
                            </template>
                            <template x-if="isRecording">
                                <button @click="stopRecording()" class="flex size-12 items-center justify-center rounded-full bg-red-600 text-white transition hover:bg-red-700">
                                    <flux:icon name="stop" class="size-6" />
                                </button>
                            </template>
                            <div>
                                <template x-if="!isRecording">
                                    <flux:text class="text-sm text-zinc-500">{{ __('Click to start recording') }}</flux:text>
                                </template>
                                <template x-if="isRecording">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="size-2 animate-pulse rounded-full bg-red-500"></span>
                                            <flux:text class="text-sm font-medium text-red-600">{{ __('Recording') }}</flux:text>
                                        </div>
                                        <flux:text class="text-sm text-zinc-500" x-text="formatTime(duration)"></flux:text>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                    <template x-if="audioUrl">
                        <div class="space-y-3">
                            <div class="flex items-center gap-3">
                                <audio :src="audioUrl" controls class="flex-1"></audio>
                                <flux:text class="shrink-0 text-sm text-zinc-500" x-text="formatTime(duration)"></flux:text>
                            </div>
                            <div class="flex gap-2">
                                <flux:button size="sm" variant="ghost" @click="resetRecording()">{{ __('Re-record') }}</flux:button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="sm:col-span-2">
                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="3" placeholder="Additional notes about the meeting..." />
                </flux:field>
            </div>
        </div>

        {{-- Actions --}}
        <div class="mt-6 flex items-center justify-end gap-2">
            <flux:button variant="ghost" wire:click="saveWithoutAudio" wire:loading.attr="disabled">
                {{ __('Save Without Audio') }}
            </flux:button>
            <flux:button variant="primary" @click="confirmRecording()" x-show="audioUrl" x-cloak wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="saveWithAudio">{{ __('Save with Recording') }}</span>
                <span wire:loading wire:target="saveWithAudio" class="flex items-center gap-2">
                    <span class="size-4 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
                    {{ __('Saving...') }}
                </span>
            </flux:button>
        </div>
    </div>
</div>
