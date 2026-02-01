<?php

namespace App\Livewire\WalkieTalkie;

use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Room extends Component
{
    public string $roomId = '';

    public string $userName = '';

    public bool $isConnected = false;

    public bool $isMuted = false;

    public bool $isTransmitting = false;

    public bool $transcriptionEnabled = true;

    /**
     * @var array<int, array{user_id: string, user_name: string, is_speaking: bool, is_muted: bool}>
     */
    public array $participants = [];

    /**
     * @var array<int, array{id: string, user_id: string, user_name: string, text: string, timestamp: string}>
     */
    public array $messages = [];

    public function mount(?string $room = null): void
    {
        $this->roomId = $room ?? Str::uuid()->toString();
        $this->userName = auth()->user()?->name ?? 'Guest';
    }

    public function getWebSocketUrl(): string
    {
        $pythonUrl = config('python.service_url', 'http://127.0.0.1:8000');
        // Convert http to ws
        $wsUrl = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $pythonUrl);

        return $wsUrl.'/media/ws/audio';
    }

    public function toggleMute(): void
    {
        $this->isMuted = ! $this->isMuted;
        $this->dispatch('walkie-talkie-mute-changed', muted: $this->isMuted);
    }

    public function toggleTranscription(): void
    {
        $this->transcriptionEnabled = ! $this->transcriptionEnabled;
    }

    public function addMessage(string $userId, string $userName, string $text): void
    {
        $this->messages[] = [
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'user_name' => $userName,
            'text' => $text,
            'timestamp' => now()->format('H:i:s'),
        ];

        // Keep only last 50 messages
        if (count($this->messages) > 50) {
            array_shift($this->messages);
        }
    }

    public function clearMessages(): void
    {
        $this->messages = [];
    }

    public function render()
    {
        return view('livewire.walkie-talkie.room');
    }
}
