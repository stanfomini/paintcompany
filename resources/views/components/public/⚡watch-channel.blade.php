<?php

use App\Models\VideoChannel;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest', ['title' => 'Channel'])]
class extends Component {
    public VideoChannel $channel;

    public function mount(VideoChannel $channel): void
    {
        abort_unless($channel->is_active && $channel->visibility === 'public', 404);
    }
};
?>

<div class="py-12 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">{{ $channel->name }}</h1>
</div>
