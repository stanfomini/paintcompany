<?php

use App\Models\VideoChannel;
use App\Models\VideoRecording;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest', ['title' => 'Watch Video'])]
class extends Component {
    public VideoChannel $channel;
    public VideoRecording $recording;

    public function mount(VideoChannel $channel, VideoRecording $recording): void
    {
        abort_unless($channel->is_active && $channel->visibility === 'public', 404);
    }
};
?>

<div class="py-12 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">{{ $recording->title }}</h1>
</div>
