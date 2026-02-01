<?php

namespace App\Livewire\VideoCall;

use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Room extends Component
{
    public string $roomId = '';

    public string $userName = '';

    public bool $isConnected = false;

    public bool $isAudioEnabled = true;

    public bool $isVideoEnabled = true;

    /**
     * @var array<int, array{peer_id: string, user_id: string, display_name: string}>
     */
    public array $participants = [];

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

        return $wsUrl.'/media/ws/video';
    }

    public function toggleAudio(): void
    {
        $this->isAudioEnabled = ! $this->isAudioEnabled;
        $this->dispatch('video-call-audio-changed', enabled: $this->isAudioEnabled);
    }

    public function toggleVideo(): void
    {
        $this->isVideoEnabled = ! $this->isVideoEnabled;
        $this->dispatch('video-call-video-changed', enabled: $this->isVideoEnabled);
    }

    public function render()
    {
        return view('livewire.video-call.room');
    }
}
